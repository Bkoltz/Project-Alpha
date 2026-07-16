<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;

/**
 * Resolves worker compensation precedence and performs deterministic previews.
 * Client pricing never enters this service except as an explicitly selected
 * percentage basis.
 */
final class CompensationRuleService
{
    private const METHODS = ['nonpayable', 'hourly', 'fixed', 'base_overage', 'percentage'];
    private const BASES = ['gross_line', 'net_line', 'cash_collected'];
    private const TRIGGERS = ['completed_approved', 'delivered', 'invoice_paid', 'manual_release'];

    public function __construct(private readonly PDO $pdo) {}

    public function resolve(
        int $workerProfileId,
        int $workTypeId,
        ?int $catalogComponentId = null,
        ?int $assignmentId = null,
        ?string $effectiveDate = null
    ): array {
        $effectiveDate ??= gmdate('Y-m-d');

        if ($assignmentId !== null) {
            $stmt = $this->pdo->prepare('SELECT compensation_override FROM work_assignments WHERE id=?');
            $stmt->execute([$assignmentId]);
            $override = $this->decode($stmt->fetchColumn());
            if ($override !== null) {
                return $this->normalize($override + ['source' => 'assignment_override']);
            }
        }

        if ($catalogComponentId !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM worker_compensation_rules
                 WHERE worker_profile_id=? AND catalog_work_component_id=?
                   AND effective_from<=? AND (effective_until IS NULL OR effective_until>=?)
                 ORDER BY effective_from DESC,id DESC LIMIT 1'
            );
            $stmt->execute([$workerProfileId, $catalogComponentId, $effectiveDate, $effectiveDate]);
            if ($rule = $stmt->fetch(PDO::FETCH_ASSOC)) {
                return $this->normalize($this->ruleColumns($rule) + ['source' => 'worker_catalog_component']);
            }

            $stmt = $this->pdo->prepare('SELECT * FROM catalog_work_components WHERE id=?');
            $stmt->execute([$catalogComponentId]);
            if ($component = $stmt->fetch(PDO::FETCH_ASSOC)) {
                return $this->normalize([
                    'method' => $component['compensation_method'],
                    'amount' => $component['compensation_amount'],
                    'included_minutes' => $component['included_minutes'],
                    'overage_rate' => $component['overage_rate'],
                    'percentage' => $component['percentage'],
                    'percentage_basis' => $component['percentage_basis'],
                    'eligibility_trigger' => $component['eligibility_trigger'],
                    'currency' => $component['currency'],
                    'source' => 'catalog_component_default',
                ]);
            }
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM worker_compensation_rules
             WHERE worker_profile_id=? AND work_type_id=?
               AND effective_from<=? AND (effective_until IS NULL OR effective_until>=?)
             ORDER BY effective_from DESC,id DESC LIMIT 1'
        );
        $stmt->execute([$workerProfileId, $workTypeId, $effectiveDate, $effectiveDate]);
        if ($rule = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return $this->normalize($this->ruleColumns($rule) + ['source' => 'worker_work_type']);
        }

        $stmt = $this->pdo->prepare('SELECT * FROM work_types WHERE id=?');
        $stmt->execute([$workTypeId]);
        if ($type = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return $this->normalize([
                'method' => $type['default_compensation_method'],
                'amount' => $type['default_amount'],
                'included_minutes' => $type['default_base_minutes'],
                'overage_rate' => $type['default_overage_rate'],
                'percentage' => $type['default_percentage'],
                'percentage_basis' => $type['default_percentage_basis'],
                'eligibility_trigger' => $type['default_eligibility_trigger'],
                'currency' => $type['currency'],
                'source' => 'work_type_default',
            ]);
        }

        return $this->normalize(['method' => 'nonpayable', 'source' => 'nonpayable_fallback']);
    }

    /**
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $context duration_seconds, quantity,
     * line_gross, line_net, or cash_collected.
     * @return array<string,mixed>
     */
    public function calculate(array $rule, array $context): array
    {
        $rule = $this->normalize($rule);
        $method = $rule['method'];
        $durationSeconds = max(0, (int)($context['duration_seconds'] ?? 0));
        $quantity = max(0.0, (float)($context['quantity'] ?? 1));
        $basisAmount = null;

        $amount = match ($method) {
            'nonpayable' => 0.0,
            'hourly' => ($durationSeconds / 3600) * (float)$rule['amount'],
            'fixed' => $quantity * (float)$rule['amount'],
            'base_overage' => $this->baseOverage($rule, $durationSeconds, $quantity),
            'percentage' => $this->percentage($rule, $context, $basisAmount),
            default => throw new DomainException('Unsupported compensation method.'),
        };

        return [
            'method' => $method,
            'amount' => number_format(round($amount + 1e-9, 2), 2, '.', ''),
            'currency' => $rule['currency'],
            'duration_seconds' => $durationSeconds,
            'quantity' => number_format($quantity, 4, '.', ''),
            'basis' => $method === 'percentage' ? $rule['percentage_basis'] : null,
            'basis_amount' => $basisAmount === null ? null : number_format($basisAmount, 2, '.', ''),
            'eligibility_trigger' => $rule['eligibility_trigger'],
            'rule_snapshot' => $rule,
        ];
    }

    private function baseOverage(array $rule, int $durationSeconds, float $quantity): float
    {
        $includedSeconds = max(0, (int)$rule['included_minutes']) * 60 * $quantity;
        $overageSeconds = max(0, $durationSeconds - $includedSeconds);
        return ((float)$rule['amount'] * $quantity)
            + (($overageSeconds / 3600) * (float)$rule['overage_rate']);
    }

    private function percentage(array $rule, array $context, ?float &$basisAmount): float
    {
        if ($rule['percentage_basis'] === 'cash_collected' && $rule['eligibility_trigger'] !== 'invoice_paid') {
            throw new DomainException('Cash-collected compensation requires the invoice-paid trigger.');
        }
        $key = match ($rule['percentage_basis']) {
            'gross_line' => 'line_gross',
            'net_line' => 'line_net',
            'cash_collected' => 'cash_collected',
        };
        if (!array_key_exists($key, $context)) {
            throw new DomainException('The selected percentage basis is unavailable.');
        }
        $basisAmount = max(0.0, (float)$context[$key]);
        return $basisAmount * ((float)$rule['percentage'] / 100);
    }

    private function ruleColumns(array $row): array
    {
        return [
            'method' => $row['compensation_method'],
            'amount' => $row['compensation_amount'],
            'included_minutes' => $row['included_minutes'],
            'overage_rate' => $row['overage_rate'],
            'percentage' => $row['percentage'],
            'percentage_basis' => $row['percentage_basis'],
            'eligibility_trigger' => $row['eligibility_trigger'],
            'currency' => $row['currency'],
        ];
    }

    private function decode(mixed $value): ?array
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function normalize(array $rule): array
    {
        $method = (string)($rule['method'] ?? $rule['compensation_method'] ?? 'nonpayable');
        if (!in_array($method, self::METHODS, true)) {
            throw new DomainException('Invalid compensation method.');
        }
        $basis = (string)($rule['percentage_basis'] ?? 'net_line');
        $trigger = (string)($rule['eligibility_trigger'] ?? 'completed_approved');
        if (!in_array($basis, self::BASES, true) || !in_array($trigger, self::TRIGGERS, true)) {
            throw new DomainException('Invalid compensation basis or trigger.');
        }
        if ($method !== 'nonpayable' && $method !== 'percentage' && (float)($rule['amount'] ?? 0) < 0) {
            throw new DomainException('Compensation amounts cannot be negative.');
        }
        if ($method === 'percentage' && ((float)($rule['percentage'] ?? 0) < 0 || (float)($rule['percentage'] ?? 0) > 100)) {
            throw new DomainException('Compensation percentage must be between 0 and 100.');
        }
        return [
            'method' => $method,
            'amount' => number_format((float)($rule['amount'] ?? 0), 4, '.', ''),
            'included_minutes' => max(0, (int)($rule['included_minutes'] ?? 0)),
            'overage_rate' => number_format((float)($rule['overage_rate'] ?? 0), 4, '.', ''),
            'percentage' => number_format((float)($rule['percentage'] ?? 0), 4, '.', ''),
            'percentage_basis' => $basis,
            'eligibility_trigger' => $trigger,
            'currency' => strtoupper((string)($rule['currency'] ?? 'USD')),
            'source' => (string)($rule['source'] ?? 'provided'),
        ];
    }
}
