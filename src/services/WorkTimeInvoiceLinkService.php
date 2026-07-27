<?php

declare(strict_types=1);

namespace App\Services;

use App\Modules\Timekeeping\WorkforceSettings;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDO;
use Throwable;

/**
 * Attaches confirmed Workforce time to an existing invoice without rewriting
 * the immutable approval snapshot. The live time entry receives the invoice's
 * client/Project/Job context and the billing projection becomes the invoice
 * line's source record.
 */
final class WorkTimeInvoiceLinkService
{
    private ?DateTimeZone $displayTimezone = null;

    public function __construct(private readonly PDO $pdo) {}

    public function link(
        int $actorId,
        string $entryId,
        int $invoiceId,
        ?string $requestedRate,
        bool $canManageAll
    ): array {
        if ($actorId <= 0 || $entryId === '' || $invoiceId <= 0) {
            throw new DomainException('Choose a time entry and invoice.');
        }

        $this->pdo->beginTransaction();
        try {
            $entryStmt = $this->pdo->prepare(
                "SELECT t.*,
                        s.id approval_snapshot_id,s.billing_rate snapshot_billing_rate,s.currency snapshot_currency,
                        c.billing_time_entry_id,bt.billed billing_projection_billed,
                        bt.invoice_item_id billing_projection_invoice_item_id,bt.rate billing_projection_rate,
                        ba.id billing_allocation_id,ba.status billing_allocation_status,ba.rate billing_allocation_rate,
                        wt.name work_type_name,
                        COALESCE(jwc.item_library_id,(
                            SELECT jwc2.item_library_id FROM job_work_components jwc2
                            WHERE jwc2.job_id=t.job_id AND jwc2.work_type_id=t.work_type_id
                            ORDER BY jwc2.id LIMIT 1
                        )) service_item_id,
                        COALESCE(il.item_name,(
                            SELECT il2.item_name FROM job_work_components jwc2
                            JOIN item_library il2 ON il2.id=jwc2.item_library_id
                            WHERE jwc2.job_id=t.job_id AND jwc2.work_type_id=t.work_type_id
                            ORDER BY jwc2.id LIMIT 1
                        )) service_name
                 FROM work_time_entries t
                 LEFT JOIN work_approval_snapshots s ON s.id=(
                     SELECT s2.id FROM work_approval_snapshots s2
                     WHERE s2.time_entry_id=t.id AND s2.entry_revision<=t.revision AND s2.voided_at IS NULL
                     ORDER BY s2.entry_revision DESC LIMIT 1
                 )
                 LEFT JOIN work_billing_consumptions c ON c.approval_snapshot_id=s.id
                    AND c.consumption_type IN ('approved','correction')
                 LEFT JOIN time_entries bt ON bt.id=c.billing_time_entry_id
                 LEFT JOIN work_time_billing_allocations ba ON ba.id=(
                     SELECT ba2.id FROM work_time_billing_allocations ba2
                     WHERE ba2.time_entry_id=t.id AND ba2.entry_revision=t.revision
                       AND ba2.treatment='hourly' AND ba2.status IN ('rate_needed','ready')
                     ORDER BY ba2.id DESC LIMIT 1
                 )
                 LEFT JOIN work_types wt ON wt.id=t.work_type_id
                 LEFT JOIN work_assignments wa ON wa.id=t.work_assignment_id
                 LEFT JOIN job_work_components jwc ON jwc.id=wa.job_work_component_id
                 LEFT JOIN item_library il ON il.id=jwc.item_library_id
                 WHERE t.id=? FOR UPDATE"
            );
            $entryStmt->execute([$entryId]);
            $entry = $entryStmt->fetch(PDO::FETCH_ASSOC);
            if (!$entry) {
                throw new DomainException('Time entry not found.');
            }
            if ((int)$entry['user_id'] !== $actorId && !$canManageAll) {
                throw new DomainException('You cannot link another user\'s time entry.');
            }
            if ((string)$entry['status'] !== 'approved'
                || (string)($entry['workflow_status'] ?? '') !== 'confirmed'
                || empty($entry['end_time'])) {
                throw new DomainException('Confirm the time entry before adding it to an invoice.');
            }
            if ((int)$entry['billable'] !== 1) {
                throw new DomainException('Mark this entry as billable before adding it to an invoice.');
            }
            $billingTimeEntryId = (int)($entry['billing_time_entry_id'] ?? 0);
            if ($billingTimeEntryId <= 0) {
                throw new DomainException('The billing copy for this time entry is not ready. Save the entry and try again.');
            }
            if ((int)($entry['billing_projection_billed'] ?? 0) === 1
                || !empty($entry['billing_projection_invoice_item_id'])) {
                throw new DomainException('This time entry is already attached to an invoice.');
            }
            (new WorkTimeInvoiceEligibilityService($this->pdo))->assertUnattached($entryId);

            $invoiceStmt = $this->pdo->prepare(
                "SELECT * FROM invoices WHERE id=? AND status='draft' AND finalized_at IS NULL FOR UPDATE"
            );
            $invoiceStmt->execute([$invoiceId]);
            $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) {
                throw new DomainException('Choose a mutable draft invoice.');
            }
            \DocumentPolicy::assertMutable($this->pdo, 'invoice', $invoiceId, 'monetary_adjustment');
            WorkTimeInvoiceEligibilityService::assertCompatibleDestination($entry, $invoice);

            $rate = $this->resolveRate($invoiceId, $requestedRate, $entry);
            $durationSeconds = (int)$entry['duration_seconds'];
            if ($durationSeconds <= 0) {
                throw new DomainException('The time entry must contain a positive duration.');
            }
            $hours = round($durationSeconds / 3600, 2);
            $lineTotal = round(($durationSeconds / 3600) * $rate, 2);
            $currency = strtoupper((string)($entry['snapshot_currency'] ?? 'USD'));
            $serviceItemId = !empty($entry['service_item_id']) ? (int)$entry['service_item_id'] : null;
            $workTypeId = !empty($entry['work_type_id']) ? (int)$entry['work_type_id'] : null;
            $itemLabel = $this->trackedTimeItemLabel($entry);
            $allocationService = new TimeBillingAllocationService($this->pdo);
            $allocationId = (int)($entry['billing_allocation_id'] ?? 0);
            $allocationRate = (float)($entry['billing_allocation_rate'] ?? 0);
            if ($allocationId > 0 && (
                (string)($entry['billing_allocation_status'] ?? '') !== 'ready'
                || abs($allocationRate - $rate) > 0.0001
            )) {
                $allocationService->reverse($allocationId, $actorId, 'Billing rate resolved while linking the invoice.');
                $allocationId = 0;
            }
            if ($allocationId <= 0) {
                $allocation = $allocationService->allocate(
                    $entryId,
                    (int)$entry['revision'],
                    'hourly',
                    (int)$entry['duration_seconds'],
                    number_format($rate, 4, '.', ''),
                    $currency,
                    $actorId,
                    [
                        'client_id' => (int)$invoice['client_id'],
                        'project_id' => $invoice['project_id'],
                        'job_id' => $invoice['job_id'],
                        'invoice_id' => $invoiceId,
                    ],
                    'invoice-link:' . $entryId . ':' . (int)$entry['revision'] . ':' . $invoiceId . ':' . number_format($rate, 4, '.', '')
                );
                $allocationId = (int)$allocation['id'];
            }

            $invoiceItemId = $this->matchingTrackedTimeLine(
                $invoiceId,
                $serviceItemId,
                $workTypeId,
                $rate,
                $currency
            );
            if ($invoiceItemId <= 0) {
                $item = $this->pdo->prepare(
                    'INSERT INTO invoice_items
                     (invoice_id,item_library_id,item,description,quantity,unit_price,line_total,billing_unit,is_extra_charge,time_entry_id,hours)
                     VALUES (?,?,?,?,?,?,?,\'hour\',0,?,?)'
                );
                $item->execute([
                    $invoiceId,
                    $serviceItemId,
                    $itemLabel,
                    '',
                    $hours,
                    $rate,
                    $lineTotal,
                    $billingTimeEntryId,
                    $hours,
                ]);
                $invoiceItemId = (int)$this->pdo->lastInsertId();
            }

            $markBilled = $this->pdo->prepare(
                'UPDATE time_entries
                 SET client_id=?,project_id=?,project_code=?,rate=?,billed=1,invoice_id=?,invoice_item_id=?
                 WHERE id=? AND billed=0'
            );
            $markBilled->execute([
                (int)$invoice['client_id'],
                !empty($invoice['project_id']) ? (int)$invoice['project_id'] : null,
                (string)($invoice['project_code'] ?? ''),
                $rate,
                $invoiceId,
                $invoiceItemId,
                $billingTimeEntryId,
            ]);
            if ($markBilled->rowCount() !== 1) {
                throw new DomainException('This time entry was linked by another request. Refresh and try again.');
            }
            $allocationService->markInvoiced($allocationId, $invoiceId, $invoiceItemId);
            $line = $this->refreshTrackedTimeLine($invoiceItemId, $itemLabel, $serviceItemId, $rate);

            (new WorkTimeBillingContextService($this->pdo))->synchronizeInvoice($invoiceId, $actorId);

            $subtotal = (float)$this->pdo->query(
                'SELECT COALESCE(SUM(line_total),0) FROM invoice_items WHERE invoice_id=' . $invoiceId
                . " AND COALESCE(pricing_status,'standard')='standard'"
            )->fetchColumn();
            $discount = match ((string)($invoice['discount_type'] ?? 'none')) {
                'percent' => max(0.0, min(100.0, (float)$invoice['discount_value'])) * $subtotal / 100,
                'fixed' => min($subtotal, max(0.0, (float)$invoice['discount_value'])),
                default => 0.0,
            };
            $tax = max(0.0, (float)($invoice['tax_percent'] ?? 0)) * max(0.0, $subtotal - $discount) / 100;
            $total = max(0.0, $subtotal - $discount + $tax);
            $this->pdo->prepare(
                'UPDATE invoices
                 SET subtotal=?,tax_amount=?,total=?,balance_due=GREATEST(0,?-COALESCE(amount_paid,0))
                 WHERE id=?'
            )->execute([$subtotal, $tax, $total, $total, $invoiceId]);

            $nextRevision = max(1, (int)($invoice['revision_number'] ?? 1)) + 1;
            $this->pdo->prepare(
                'INSERT INTO invoice_adjustments
                 (invoice_id,adjustment_type,label,description,quantity,unit_price,amount,revision_number,created_by)
                 VALUES (?,\'charge\',\'Tracked time\',?,?,?,?,?,?)'
            )->execute([
                $invoiceId,
                $this->formatTimeToken((string)$entry['start_time'], (int)$entry['duration_seconds']),
                $hours,
                $rate,
                $lineTotal,
                $nextRevision,
                $actorId,
            ]);
            if (!empty($invoice['finalized_at']) || \invoice_effective_paid_total($this->pdo, $invoiceId) > 0.005) {
                \invoice_refresh_payment_totals($this->pdo, $invoiceId, false);
            }
            \DocumentRevisionService::snapshotAndSave($this->pdo, 'invoice', $invoiceId, $actorId);
            if (strtolower((string)$invoice['status']) !== 'draft') {
                $this->pdo->prepare(
                    'UPDATE public_links SET revoked=1 WHERE document_type=\'invoice\' AND document_id=?'
                )->execute([$invoiceId]);
            }

            if (function_exists('audit_log')) {
                \audit_log($this->pdo, 'time_entry.invoice_linked', 'work_time_entry', null, [
                    'time_entry_id' => $entryId,
                    'billing_time_entry_id' => $billingTimeEntryId,
                    'invoice_id' => $invoiceId,
                    'invoice_item_id' => $invoiceItemId,
                    'billing_allocation_id' => $allocationId,
                    'rate' => $rate,
                    'hours' => $hours,
                    'aggregated_quantity' => $line['quantity'],
                ]);
            }
            $this->pdo->commit();

            return [
                'invoice_id' => $invoiceId,
                'invoice_item_id' => $invoiceItemId,
                'billing_allocation_id' => $allocationId,
                'hours' => $hours,
                'rate' => $rate,
                'amount' => $lineTotal,
            ];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function resolveRate(int $invoiceId, ?string $requestedRate, array $entry): float
    {
        $requested = trim((string)$requestedRate);
        if ($requested !== '' && is_numeric($requested) && (float)$requested > 0) {
            return round((float)$requested, 2);
        }
        foreach (['snapshot_billing_rate', 'billing_projection_rate'] as $key) {
            if (isset($entry[$key]) && (float)$entry[$key] > 0) {
                return round((float)$entry[$key], 2);
            }
        }
        $rates = $this->pdo->prepare(
            "SELECT DISTINCT unit_price FROM invoice_items
             WHERE invoice_id=? AND billing_unit='hour' AND unit_price>0
             ORDER BY unit_price"
        );
        $rates->execute([$invoiceId]);
        $values = $rates->fetchAll(PDO::FETCH_COLUMN);
        if (count($values) === 1) {
            return round((float)$values[0], 2);
        }
        throw new DomainException('Enter the hourly billing rate to add this time to the invoice.');
    }

    private function matchingTrackedTimeLine(
        int $invoiceId,
        ?int $serviceItemId,
        ?int $workTypeId,
        float $rate,
        string $currency
    ): int {
        $statement = $this->pdo->prepare(
            "SELECT a.invoice_item_id
             FROM work_time_billing_allocations a
             JOIN work_time_entries t ON t.id=a.time_entry_id
             JOIN invoice_items ii ON ii.id=a.invoice_item_id AND ii.invoice_id=a.invoice_id
             LEFT JOIN work_assignments wa ON wa.id=t.work_assignment_id
             LEFT JOIN job_work_components jwc ON jwc.id=wa.job_work_component_id
             WHERE a.invoice_id=? AND a.status='invoiced' AND a.treatment='hourly'
               AND ii.billing_unit='hour' AND a.rate=? AND a.currency=?
               AND t.work_type_id <=> ?
               AND COALESCE(jwc.item_library_id,(
                    SELECT jwc2.item_library_id FROM job_work_components jwc2
                    WHERE jwc2.job_id=t.job_id AND jwc2.work_type_id=t.work_type_id
                    ORDER BY jwc2.id LIMIT 1
               )) <=> ?
             ORDER BY a.invoice_item_id
             LIMIT 1
             FOR UPDATE"
        );
        $statement->execute([$invoiceId, $rate, $currency, $workTypeId, $serviceItemId]);
        return (int)($statement->fetchColumn() ?: 0);
    }

    /** @return array{quantity:float,amount:float,description:string} */
    private function refreshTrackedTimeLine(
        int $invoiceItemId,
        string $itemLabel,
        ?int $serviceItemId,
        float $rate
    ): array {
        $statement = $this->pdo->prepare(
            "SELECT t.start_time,a.duration_seconds
             FROM work_time_billing_allocations a
             JOIN work_time_entries t ON t.id=a.time_entry_id
             WHERE a.invoice_item_id=? AND a.status='invoiced' AND a.treatment='hourly'
             ORDER BY t.start_time,a.id"
        );
        $statement->execute([$invoiceItemId]);
        $seconds = 0;
        $tokens = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $source) {
            $duration = max(0, (int)($source['duration_seconds'] ?? 0));
            $seconds += $duration;
            $tokens[] = $this->formatTimeToken((string)$source['start_time'], $duration);
        }
        if ($seconds <= 0) {
            throw new DomainException('Tracked-time sources are unavailable for this invoice line.');
        }
        $quantity = round($seconds / 3600, 2);
        $amount = round(($seconds / 3600) * $rate, 2);
        $descriptionLines = [];
        foreach (array_chunk($tokens, 3) as $chunk) {
            $descriptionLines[] = implode(' | ', $chunk);
        }
        $description = implode("\n", $descriptionLines);
        $this->pdo->prepare(
            'UPDATE invoice_items
             SET item_library_id=?,item=?,description=?,quantity=?,hours=?,unit_price=?,line_total=?
             WHERE id=?'
        )->execute([
            $serviceItemId,
            $itemLabel,
            $description,
            $quantity,
            $quantity,
            $rate,
            $amount,
            $invoiceItemId,
        ]);
        return ['quantity' => $quantity, 'amount' => $amount, 'description' => $description];
    }

    private function trackedTimeItemLabel(array $entry): string
    {
        $service = trim((string)($entry['service_name'] ?? ''));
        $activity = trim((string)($entry['work_type_name'] ?? ''));
        if ($service !== '' && $activity !== '' && strcasecmp($service, $activity) !== 0) {
            return $service . ' — ' . $activity;
        }
        return $service !== '' ? $service : ($activity !== '' ? $activity : 'Tracked time');
    }

    private function formatTimeToken(string $startTime, int $durationSeconds): string
    {
        if ($this->displayTimezone === null) {
            $timezoneName = (string)(WorkforceSettings::load($this->pdo)['timezone'] ?? 'UTC');
            try {
                $this->displayTimezone = new DateTimeZone($timezoneName ?: 'UTC');
            } catch (\Throwable) {
                $this->displayTimezone = new DateTimeZone('UTC');
            }
        }
        $date = (new DateTimeImmutable($startTime, new DateTimeZone('UTC')))
            ->setTimezone($this->displayTimezone)
            ->format('m-d-Y');
        $minutes = max(1, (int)round($durationSeconds / 60));
        $hours = intdiv($minutes, 60);
        $minutePart = $minutes % 60;
        $duration = $hours > 0 ? $hours . 'h' : '';
        if ($minutePart > 0 || $hours === 0) {
            $duration .= ($duration !== '' ? ' ' : '') . $minutePart . 'm';
        }
        return $date . ' × ' . $duration;
    }

}
