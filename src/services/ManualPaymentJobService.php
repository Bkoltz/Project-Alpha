<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;

/**
 * Resolves service jobs that can receive standalone payments and calculates
 * their expected client charge from immutable job-component billing snapshots.
 */
final class ManualPaymentJobService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function availableJobs(int $userId, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $where = ['j.archived=0', 'j.status<>"cancelled"'];
        $params = [];
        if (!acl_user_has_org_wide_scope($this->pdo, $userId, 0)) {
            $where[] = 'j.created_by=?';
            $params[] = $userId;
        }

        $jobDateOrder = acl_table_has_column($this->pdo, 'jobs', 'completed_at')
            ? 'COALESCE(j.completed_at,j.updated_at,j.created_at)'
            : 'COALESCE(j.updated_at,j.created_at)';
        $stmt = $this->pdo->prepare(
            'SELECT j.id,j.job_code,j.client_id,j.organization_id,j.status,j.created_at,
                    c.name AS client_name,c.email AS client_email,
                    (SELECT GROUP_CONCAT(DISTINCT jwc.name ORDER BY jwc.id SEPARATOR ", ")
                     FROM job_work_components jwc
                     WHERE jwc.job_id=j.id AND jwc.status<>"cancelled") AS service_names
             FROM jobs j
             LEFT JOIN clients c ON c.id=j.client_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY ' . $jobDateOrder . ' DESC,j.id DESC
             LIMIT ' . $limit
        );
        $stmt->execute($params);
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $previews = $this->expectedCharges(array_map(static fn(array $job): int => (int)$job['id'], $jobs));
        foreach ($jobs as &$job) {
            $preview = $previews[(int)$job['id']] ?? ['amount' => 0.0, 'currency' => 'USD', 'known' => false];
            $job['expected_charge'] = $preview['amount'];
            $job['expected_currency'] = $preview['currency'];
            $job['expected_charge_known'] = $preview['known'];
        }
        unset($job);

        return $jobs;
    }

    /** @return array<string,mixed> */
    public function accessibleJob(int $jobId, int $userId): array
    {
        if ($jobId <= 0 || !can_access_record($this->pdo, 'jobs', $jobId, $userId)) {
            throw new DomainException('The selected service job is not available.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT j.id,j.job_code,j.client_id,j.organization_id,j.status,
                    c.name AS client_name,c.email AS client_email
             FROM jobs j
             LEFT JOIN clients c ON c.id=j.client_id
             WHERE j.id=? AND j.archived=0 AND j.status<>"cancelled"
             LIMIT 1'
        );
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            throw new DomainException('The selected service job is not available.');
        }

        $preview = $this->expectedCharge($jobId);
        return $job + [
            'expected_charge' => $preview['amount'],
            'expected_currency' => $preview['currency'],
            'expected_charge_known' => $preview['known'],
        ];
    }

    /** @return array{amount:float,currency:string,known:bool} */
    public function expectedCharge(int $jobId): array
    {
        return $this->expectedCharges([$jobId])[$jobId] ?? ['amount' => 0.0, 'currency' => 'USD', 'known' => false];
    }

    /**
     * @param array<int,int> $jobIds
     * @return array<int,array{amount:float,currency:string,known:bool}>
     */
    private function expectedCharges(array $jobIds): array
    {
        $jobIds = array_values(array_unique(array_filter(array_map('intval', $jobIds), static fn(int $id): bool => $id > 0)));
        if (!$jobIds) {
            return [];
        }
        $hasSnapshots = acl_table_has_column($this->pdo, 'job_work_components', 'client_billing_treatment_snapshot');
        $hasCatalogBilling = acl_table_has_column($this->pdo, 'catalog_work_components', 'client_billing_treatment');
        $snapshotSelect = $hasSnapshots
            ? 'jwc.client_billing_treatment_snapshot,jwc.client_billing_rate_snapshot,
               jwc.client_included_minutes_snapshot,jwc.client_overage_rate_snapshot,
               jwc.client_billing_currency_snapshot'
            : 'NULL AS client_billing_treatment_snapshot,NULL AS client_billing_rate_snapshot,
               NULL AS client_included_minutes_snapshot,NULL AS client_overage_rate_snapshot,
               NULL AS client_billing_currency_snapshot';
        $catalogSelect = $hasCatalogBilling
            ? 'c.client_billing_treatment,c.client_billing_rate,c.client_included_minutes,
               c.client_overage_rate,c.client_billing_currency'
            : 'NULL AS client_billing_treatment,NULL AS client_billing_rate,
               NULL AS client_included_minutes,NULL AS client_overage_rate,
               NULL AS client_billing_currency';

        $stmt = $this->pdo->prepare(
            'SELECT jwc.id,jwc.job_id,jwc.item_library_id,jwc.work_type_id,jwc.planned_quantity,i.unit_price,
                    ' . $catalogSelect . ',' . $snapshotSelect . ',
                    COALESCE((
                      SELECT SUM(e.duration_seconds)
                      FROM work_time_entries e
                      WHERE e.job_id=jwc.job_id
                        AND e.status="approved"
                        AND e.workflow_status="confirmed"
                        AND (
                          EXISTS (
                            SELECT 1 FROM work_assignments wa
                            WHERE wa.id=e.work_assignment_id AND wa.job_work_component_id=jwc.id
                          )
                          OR (
                            e.work_assignment_id IS NULL
                            AND e.work_type_id=jwc.work_type_id
                            AND jwc.id=(
                              SELECT MIN(first_component.id)
                              FROM job_work_components first_component
                              WHERE first_component.job_id=jwc.job_id
                                AND first_component.work_type_id=jwc.work_type_id
                                AND first_component.status<>"cancelled"
                            )
                          )
                        )
                    ),0) AS confirmed_seconds
             FROM job_work_components jwc
             LEFT JOIN catalog_work_components c ON c.id=jwc.catalog_work_component_id
             LEFT JOIN item_library i ON i.id=jwc.item_library_id
             WHERE jwc.job_id IN (' . implode(',', array_fill(0, count($jobIds), '?')) . ') AND jwc.status<>"cancelled"
             ORDER BY jwc.job_id,jwc.id'
        );
        $stmt->execute($jobIds);
        $components = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $previews = [];
        foreach ($jobIds as $jobId) {
            $previews[$jobId] = ['amount' => 0.0, 'currency' => 'USD', 'known' => false];
        }
        $baseApplied = [];
        foreach ($components as $component) {
            $jobId = (int)$component['job_id'];
            $treatment = (string)($component['client_billing_treatment_snapshot']
                ?? $component['client_billing_treatment']
                ?? '');
            if ($treatment === '') {
                continue;
            }

            $component['treatment'] = $treatment;
            $component['rate'] = $component['client_billing_rate_snapshot']
                ?? $component['client_billing_rate']
                ?? $component['unit_price']
                ?? 0;
            $component['included_minutes'] = $component['client_included_minutes_snapshot']
                ?? $component['client_included_minutes']
                ?? 0;
            $component['overage_rate'] = $component['client_overage_rate_snapshot']
                ?? $component['client_overage_rate']
                ?? 0;
            $component['base_price'] = $component['client_billing_rate_snapshot']
                ?? $component['unit_price']
                ?? $component['client_billing_rate']
                ?? 0;
            if (in_array($treatment, ['fixed_price_included', 'base_overage'], true)) {
                $baseKey = !empty($component['item_library_id'])
                    ? $jobId . ':service:' . (int)$component['item_library_id']
                    : $jobId . ':component:' . (int)$component['id'];
                if (isset($baseApplied[$baseKey])) {
                    $component['base_price'] = 0;
                } else {
                    $baseApplied[$baseKey] = true;
                }
            }
            $previews[$jobId]['amount'] += self::calculateComponentCharge($component, (int)$component['confirmed_seconds']);
            $currency = strtoupper((string)($component['client_billing_currency_snapshot']
                ?? $component['client_billing_currency']
                ?? $previews[$jobId]['currency']));
            $previews[$jobId]['currency'] = preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'USD';
            $previews[$jobId]['known'] = true;
        }

        foreach ($previews as &$preview) {
            $preview['amount'] = round((float)$preview['amount'], 2);
        }
        unset($preview);
        return $previews;
    }

    /**
     * Pure pricing calculation used by the UI preview and focused tests.
     *
     * @param array<string,mixed> $component
     */
    public static function calculateComponentCharge(array $component, int $confirmedSeconds): float
    {
        $treatment = (string)($component['treatment'] ?? 'internal');
        $quantity = max(0.0, (float)($component['planned_quantity'] ?? 1));
        $hours = max(0, $confirmedSeconds) / 3600;
        $rate = max(0.0, (float)($component['rate'] ?? 0));

        $amount = match ($treatment) {
            'hourly' => $hours * $rate,
            'fixed_price_included' => max(0.0, (float)($component['base_price'] ?? $rate)) * $quantity,
            'base_overage' => self::baseOverageCharge($component, $confirmedSeconds, $quantity),
            default => 0.0,
        };

        return round($amount, 2);
    }

    /** @param array<string,mixed> $component */
    private static function baseOverageCharge(array $component, int $confirmedSeconds, float $quantity): float
    {
        $base = max(0.0, (float)($component['base_price'] ?? $component['rate'] ?? 0)) * $quantity;
        $includedSeconds = max(0, (int)($component['included_minutes'] ?? 0)) * 60 * $quantity;
        $overageHours = max(0, $confirmedSeconds - $includedSeconds) / 3600;
        return $base + ($overageHours * max(0.0, (float)($component['overage_rate'] ?? 0)));
    }
}
