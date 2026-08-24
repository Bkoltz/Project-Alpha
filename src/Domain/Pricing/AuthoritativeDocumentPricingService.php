<?php
declare(strict_types=1);

namespace App\Domain\Pricing;

use DomainException;
use PDO;

/**
 * Applies the immutable inherited-adjustment snapshot to a mutable document.
 *
 * Calculation order is deliberately fixed: base subtotal, inherited pricing
 * adjustment, existing manual document discount, then tax.  All monetary and
 * percentage arithmetic is integer based; the legacy discount columns remain
 * untouched so disabling the feature restores the pre-existing behaviour.
 */
final class AuthoritativeDocumentPricingService
{
    private const TABLES = [
        'quote' => 'quotes',
        'contract' => 'contracts',
        'invoice' => 'invoices',
    ];

    public function __construct(private readonly PDO $pdo) {}

    public function apply(
        int $organizationId,
        string $documentType,
        int $documentId,
        int $revision,
        string $currency,
        ?int $actor,
        ?string $asOf = null,
        ?array $sourceSnapshot = null,
    ): array {
        $table = self::TABLES[$documentType] ?? null;
        if ($table === null) {
            throw new DomainException('Unsupported authoritative pricing document type.');
        }

        $owns = !$this->pdo->inTransaction();
        $savepoint = 'authoritative_document_pricing';
        if ($owns) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT ' . $savepoint);
        }
        try {
            $result = $this->applyLocked(
                $organizationId,
                $documentType,
                $documentId,
                $revision,
                $currency,
                $actor,
                $asOf,
                $table,
                $sourceSnapshot,
            );
            if ($owns) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            return $result;
        } catch (\Throwable $error) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } elseif (!$owns) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            }
            throw $error;
        }
    }

    private function applyLocked(
        int $organizationId,
        string $documentType,
        int $documentId,
        int $revision,
        string $currency,
        ?int $actor,
        ?string $asOf,
        string $table,
        ?array $sourceSnapshot,
    ): array {
        $repository=new DocumentPricingSnapshotRepository($this->pdo);
        $snapshot=$sourceSnapshot===null
            ?$repository->createAuthoritative($organizationId,$documentType,$documentId,$revision,$currency,$actor,$asOf)
            :$repository->createDerivedFromSnapshot(
                $organizationId,$documentType,$documentId,$revision,$currency,$actor,
                (string)$sourceSnapshot['document_type'],(int)$sourceSnapshot['document_id'],(int)$sourceSnapshot['document_revision']
            );

        $suffix = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $paidColumn = $documentType === 'invoice' ? ',amount_paid,credit_applied' : '';
        $statement = $this->pdo->prepare(
            "SELECT discount_type,discount_value,tax_percent{$paidColumn} FROM {$table} WHERE id=? AND organization_id=?" . $suffix
        );
        $statement->execute([$documentId, $organizationId]);
        $document = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$document) {
            throw new DomainException('Pricing document does not belong to this organization.');
        }

        $adjustedMinor = (int)$snapshot['adjusted_minor'];
        $manualMinor = $this->manualDiscountMinor(
            $adjustedMinor,
            (string)($document['discount_type'] ?? 'none'),
            (string)($document['discount_value'] ?? '0'),
        );
        $taxableMinor = $adjustedMinor - $manualMinor;
        $taxMinor = $this->percentageMinor($taxableMinor, (string)($document['tax_percent'] ?? '0'), 1_000_000_000);
        if ($taxMinor > PHP_INT_MAX - $taxableMinor) {
            throw new DomainException('Calculated document total exceeds the supported range.');
        }
        $baseTotalMinor = $taxableMinor + $taxMinor;
        $invoiceAdjustmentMinor = $documentType === 'invoice'
            ? $this->totalAffectingInvoiceAdjustmentsMinor($documentId)
            : 0;
        if ($invoiceAdjustmentMinor > 0 && $invoiceAdjustmentMinor > PHP_INT_MAX - $baseTotalMinor) {
            throw new DomainException('Calculated invoice adjustments exceed the supported range.');
        }
        $totalMinor = max(0, $baseTotalMinor + $invoiceAdjustmentMinor);

        if ($documentType === 'invoice') {
            $paidMinor = $this->moneyToMinor((string)($document['amount_paid'] ?? '0'));
            $creditMinor = $this->moneyToMinor((string)($document['credit_applied'] ?? '0'));
            if ($paidMinor > 0 || $creditMinor > 0) {
                throw new DomainException('A paid or credited invoice cannot be repriced. Create an explicit adjustment document instead.');
            }
            $balanceMinor = max(0, $totalMinor - $paidMinor - $creditMinor);
            $update = $this->pdo->prepare(
                'UPDATE invoices SET tax_amount=?,total=?,balance_due=? WHERE id=? AND organization_id=?'
            );
            $update->execute([
                $this->minorToMoney($taxMinor),
                $this->minorToMoney($totalMinor),
                $this->minorToMoney($balanceMinor),
                $documentId,
                $organizationId,
            ]);
        } else {
            $update = $this->pdo->prepare("UPDATE {$table} SET tax_amount=?,total=? WHERE id=? AND organization_id=?");
            $update->execute([$this->minorToMoney($taxMinor),$this->minorToMoney($totalMinor),$documentId,$organizationId]);
        }

        return $snapshot + [
            'manual_adjustment_minor' => $manualMinor,
            'taxable_minor' => $taxableMinor,
            'tax_minor' => $taxMinor,
            'invoice_adjustment_minor' => $invoiceAdjustmentMinor,
            'total_minor' => $totalMinor,
        ];
    }

    private function totalAffectingInvoiceAdjustmentsMinor(int $invoiceId): int
    {
        $suffix = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            'SELECT adjustment_type,amount FROM invoice_adjustments '
            . 'WHERE invoice_id=? AND affects_total=1 AND superseded_at IS NULL ORDER BY id' . $suffix
        );
        $statement->execute([$invoiceId]);
        $signed = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $minor = $this->moneyToMinor((string)$row['amount']);
            $delta = (string)$row['adjustment_type'] === 'credit' ? -$minor : $minor;
            if ($delta > 0 && $signed > PHP_INT_MAX - $delta) {
                throw new DomainException('Calculated invoice adjustments exceed the supported range.');
            }
            if ($delta < 0 && $signed < PHP_INT_MIN - $delta) {
                throw new DomainException('Calculated invoice adjustments exceed the supported range.');
            }
            $signed += $delta;
        }
        return $signed;
    }

    private function manualDiscountMinor(int $basisMinor, string $type, string $value): int
    {
        if ($type === 'percent') {
            return min($basisMinor, $this->percentageMinor($basisMinor, $value, 100));
        }
        if ($type === 'fixed') {
            if ($this->isNonPositive($value)) {
                return 0;
            }
            return min($basisMinor, $this->moneyToMinor($value));
        }
        return 0;
    }

    private function percentageMinor(int $basisMinor, string $rate, int $maximumWhole): int
    {
        if ($basisMinor < 0) {
            throw new DomainException('Percentage basis cannot be negative.');
        }
        $rate = trim($rate);
        if ($this->isNonPositive($rate)) {
            return 0;
        }
        if (!preg_match('/^(\d{1,9})(?:\.(\d{1,4}))?$/', $rate, $match)) {
            throw new DomainException('Percentage must have at most four decimal places.');
        }
        $whole = (int)$match[1];
        if ($whole > $maximumWhole || ($whole === $maximumWhole && !empty($match[2]))) {
            $whole = $maximumWhole;
            $fraction = 0;
        } else {
            $fraction = (int)str_pad($match[2] ?? '', 4, '0');
        }
        $units = ($whole * 10_000) + $fraction;
        if ($units === 0 || $basisMinor === 0) {
            return 0;
        }
        $denominator = 1_000_000;
        $wholeBasis = intdiv($basisMinor, $denominator);
        if ($wholeBasis > intdiv(PHP_INT_MAX, $units)) {
            throw new DomainException('Calculated percentage amount exceeds the supported range.');
        }
        return ($wholeBasis * $units)
            + intdiv((($basisMinor % $denominator) * $units) + intdiv($denominator, 2), $denominator);
    }

    private function moneyToMinor(string $amount): int
    {
        $amount = trim($amount);
        if (!preg_match('/^(\d{1,16})(?:\.(\d{1,2}))?$/', $amount, $match)) {
            throw new DomainException('Document money value is invalid.');
        }
        $whole = (int)$match[1];
        $fraction = (int)str_pad($match[2] ?? '', 2, '0');
        if ($whole > intdiv(PHP_INT_MAX - 99, 100)) {
            throw new DomainException('Document money value exceeds the supported range.');
        }
        return ($whole * 100) + $fraction;
    }

    private function minorToMoney(int $minor): string
    {
        return intdiv($minor, 100) . '.' . str_pad((string)($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private function isNonPositive(string $number): bool
    {
        $number = trim($number);
        return $number === '' || $number === '0' || $number === '0.0' || $number === '0.00'
            || preg_match('/^-/', $number) === 1
            || preg_match('/^0+(?:\.0+)?$/', $number) === 1;
    }
}
