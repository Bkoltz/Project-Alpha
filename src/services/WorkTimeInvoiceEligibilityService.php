<?php

declare(strict_types=1);

namespace App\Services;

use App\Modules\Timekeeping\WorkforceSettings;
use DomainException;
use PDO;

/**
 * Provides the canonical invoice-editor view of Workforce time.
 *
 * The selector deliberately applies the same client, Job, invoice-destination,
 * completion, and billing-projection rules to both ready and pending entries.
 * This prevents a historical or concurrently attached billing projection from
 * leaking back into the invoice editor as time that can be added again.
 */
final class WorkTimeInvoiceEligibilityService
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Keeps direct attachment requests aligned with the selector's destination
     * rules so a crafted POST cannot link an entry hidden by the editor.
     *
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $invoice
     */
    public static function assertCompatibleDestination(array $entry, array $invoice): void
    {
        if (!empty($entry['client_id'])
            && (int)$entry['client_id'] !== (int)($invoice['client_id'] ?? 0)) {
            throw new DomainException('The time entry and invoice must belong to the same client.');
        }
        if (empty($invoice['job_id'])) {
            throw new DomainException('Assign the invoice to a Job before adding tracked time.');
        }
        if (!empty($entry['job_id'])
            && (int)$entry['job_id'] !== (int)$invoice['job_id']) {
            throw new DomainException(
                'The time entry and invoice belong to different Jobs. Confirm a context move before linking them.'
            );
        }
        if (!empty($entry['invoice_id'])
            && (int)$entry['invoice_id'] !== (int)($invoice['id'] ?? 0)) {
            throw new DomainException(
                'The time entry is assigned to a different invoice. Confirm a context move before linking it.'
            );
        }
    }

    /**
     * Guards direct attachment requests against billing representations from
     * any approval revision, not only the latest snapshot selected by a caller.
     */
    public function assertUnattached(string $entryId): void
    {
        if ($entryId === '') {
            throw new DomainException('Choose a time entry.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM work_time_entries t'
            . ' WHERE t.id=? AND ' . $this->hasNoAttachedProjectionSql()
        );
        $stmt->execute([$entryId]);
        if (!$stmt->fetchColumn()) {
            throw new DomainException('This time entry is already attached to an invoice.');
        }
    }

    /**
     * Ensure confirmation will leave the entry with a usable hourly rate, or
     * that the destination invoice provides the one unambiguous fallback rate.
     *
     * @param array<string,mixed> $entry
     */
    public function assertResolvableBillingRate(
        array $entry,
        int $invoiceId,
        ?string $requestedRate
    ): void {
        $requestedRate = trim((string)$requestedRate);
        if ($requestedRate !== '') {
            if (!is_numeric($requestedRate)
                || !is_finite((float)$requestedRate)
                || (float)$requestedRate <= 0) {
                throw new DomainException('Enter a positive hourly billing rate.');
            }
            return;
        }

        $projectionRate = $this->pdo->prepare(
            "SELECT COALESCE(NULLIF(s.billing_rate,0),NULLIF(bt.rate,0),NULLIF(ba.rate,0))
             FROM work_time_entries t
             LEFT JOIN work_approval_snapshots s ON s.id=(
               SELECT s2.id FROM work_approval_snapshots s2
               WHERE s2.time_entry_id=t.id AND s2.voided_at IS NULL
               ORDER BY s2.entry_revision DESC LIMIT 1
             )
             LEFT JOIN work_billing_consumptions c ON c.approval_snapshot_id=s.id
               AND c.consumption_type IN ('approved','correction')
             LEFT JOIN time_entries bt ON bt.id=c.billing_time_entry_id
             LEFT JOIN work_time_billing_allocations ba ON ba.id=(
               SELECT ba2.id FROM work_time_billing_allocations ba2
               WHERE ba2.time_entry_id=t.id AND ba2.entry_revision=t.revision
                 AND ba2.status IN ('rate_needed','ready')
               ORDER BY ba2.id DESC LIMIT 1
             )
             WHERE t.id=? LIMIT 1"
        );
        $projectionRate->execute([(string)($entry['id'] ?? '')]);
        if ((float)($projectionRate->fetchColumn() ?: 0) > 0) {
            return;
        }

        foreach (['project_id' => 'project', 'client_id' => 'client'] as $field => $scope) {
            if (empty($entry[$field])) {
                continue;
            }
            $rule = $this->pdo->prepare(
                "SELECT amount FROM billing_rate_rules
                 WHERE scope_type=? AND {$field}=?
                   AND effective_from<=DATE(?)
                   AND (effective_until IS NULL OR effective_until>=DATE(?))
                   AND amount>0
                 ORDER BY effective_from DESC,id DESC LIMIT 1"
            );
            $rule->execute([
                $scope,
                (int)$entry[$field],
                (string)$entry['start_time'],
                (string)$entry['start_time'],
            ]);
            if ((float)($rule->fetchColumn() ?: 0) > 0) {
                return;
            }
        }

        $defaults = $this->pdo->prepare(
            "SELECT COALESCE(NULLIF(jwc.client_billing_rate_snapshot,0),NULLIF(wtb.default_billing_rate,0))
             FROM work_time_entries t
             LEFT JOIN work_assignments wa ON wa.id=t.work_assignment_id
             LEFT JOIN job_work_components jwc ON jwc.id=wa.job_work_component_id
             LEFT JOIN work_type_billing_defaults wtb ON wtb.work_type_id=t.work_type_id
             WHERE t.id=? LIMIT 1"
        );
        $defaults->execute([(string)($entry['id'] ?? '')]);
        if ((float)($defaults->fetchColumn() ?: 0) > 0
            || (float)(WorkforceSettings::load($this->pdo)['default_billing_rate'] ?? 0) > 0) {
            return;
        }

        $invoiceRates = $this->pdo->prepare(
            "SELECT DISTINCT unit_price FROM invoice_items
             WHERE invoice_id=? AND billing_unit='hour' AND unit_price>0
             ORDER BY unit_price"
        );
        $invoiceRates->execute([$invoiceId]);
        if (count($invoiceRates->fetchAll(PDO::FETCH_COLUMN)) === 1) {
            return;
        }

        throw new DomainException('Enter the hourly billing rate to add this time to the invoice.');
    }

    /**
     * @return array{
     *   invoice: array<string,mixed>,
     *   ready: list<array<string,mixed>>,
     *   pending: list<array<string,mixed>>,
     *   attached: list<array<string,mixed>>,
     *   blocking_reason: ?string,
     *   actor_role: string,
     *   actor_can_administratively_self_confirm: bool,
     *   actor_is_verified_owner: bool
     * }
     */
    public function forInvoice(int $invoiceId, int $actorId): array
    {
        if ($invoiceId <= 0) {
            throw new DomainException('Choose an invoice.');
        }

        $invoiceStmt = $this->pdo->prepare('SELECT * FROM invoices WHERE id=?');
        $invoiceStmt->execute([$invoiceId]);
        $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            throw new DomainException('Invoice not found.');
        }
        if ((string)($invoice['status'] ?? '') !== 'draft' || !empty($invoice['finalized_at'])) {
            throw new DomainException('Choose a mutable draft invoice.');
        }

        $actor = $this->actorState($actorId);
        $result = [
            'invoice' => $invoice,
            'ready' => [],
            'pending' => [],
            'attached' => $this->attachedEntries($invoiceId),
            'blocking_reason' => null,
            'actor_role' => $actor['role'],
            'actor_can_administratively_self_confirm' => $actor['administrative'],
            'actor_is_verified_owner' => $actor['verified_owner'],
        ];

        $jobId = (int)($invoice['job_id'] ?? 0);
        if ($jobId <= 0) {
            $result['blocking_reason'] = 'Assign the invoice to a Job before adding tracked time.';
            return $result;
        }

        $clientId = (int)($invoice['client_id'] ?? 0);
        $result['ready'] = $this->readyEntries($invoiceId, $clientId, $jobId);
        $result['pending'] = array_map(
            static function (array $entry) use ($actorId, $actor): array {
                $isOwnEntry = (int)$entry['user_id'] === $actorId;
                $entry['can_confirm_and_add'] = $isOwnEntry
                    && ($actor['administrative'] || $actor['verified_owner']);
                $entry['confirmation_mode'] = !$entry['can_confirm_and_add']
                    ? null
                    : ($actor['administrative'] ? 'administrative' : 'verified_owner');
                $entry['requires_another_reviewer'] = $isOwnEntry
                    && !$entry['can_confirm_and_add']
                    && (string)$entry['workflow_status'] === 'submitted';
                return $entry;
            },
            $this->pendingEntries($invoiceId, $clientId, $jobId)
        );

        return $result;
    }

    /** @return array{role:string,administrative:bool,verified_owner:bool} */
    private function actorState(int $actorId): array
    {
        if ($actorId <= 0) {
            return ['role' => '', 'administrative' => false, 'verified_owner' => false];
        }

        $roleStmt = $this->pdo->prepare(
            'SELECT role FROM users WHERE id=? AND deleted_at IS NULL AND is_disabled=0'
        );
        $roleStmt->execute([$actorId]);
        $role = strtolower((string)($roleStmt->fetchColumn() ?: ''));

        $ownerStmt = $this->pdo->prepare(
            "SELECT 1 FROM worker_profiles
             WHERE user_id=? AND status='active' AND relationship_type='owner'
               AND relationship_review_required=0 LIMIT 1"
        );
        $ownerStmt->execute([$actorId]);

        return [
            'role' => $role,
            'administrative' => in_array($role, ['admin', 'owner'], true),
            'verified_owner' => (bool)$ownerStmt->fetchColumn(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function readyEntries(int $invoiceId, int $clientId, int $jobId): array
    {
        $stmt = $this->pdo->prepare(
            $this->entrySelect(
                "JOIN work_approval_snapshots s ON s.id=(
                   SELECT s2.id FROM work_approval_snapshots s2
                   WHERE s2.time_entry_id=t.id AND s2.entry_revision<=t.revision
                     AND s2.voided_at IS NULL
                   ORDER BY s2.entry_revision DESC LIMIT 1
                 )
                 JOIN work_billing_consumptions wbc ON wbc.id=(
                   SELECT c2.id FROM work_billing_consumptions c2
                   WHERE c2.approval_snapshot_id=s.id
                     AND c2.consumption_type IN ('approved','correction')
                   ORDER BY c2.id DESC LIMIT 1
                 )
                 JOIN time_entries bt ON bt.id=wbc.billing_time_entry_id",
                "t.client_id=? AND t.status='approved' AND t.workflow_status='confirmed'
                 AND t.billable=1 AND t.end_time IS NOT NULL
                 AND COALESCE(bt.billed,0)=0 AND bt.invoice_item_id IS NULL
                 AND NOT EXISTS (
                   SELECT 1 FROM invoice_items ii
                   WHERE ii.time_entry_id=bt.id
                 )
                 AND " . $this->compatibleDestinationSql() . "
                 AND " . $this->hasNoAttachedProjectionSql(),
                'COALESCE(NULLIF(bt.rate,0),NULLIF(s.billing_rate,0)) billing_rate'
            )
        );
        $stmt->execute([
            $clientId,
            $invoiceId,
            $jobId,
        ]);

        return $this->hydrate($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    private function pendingEntries(int $invoiceId, int $clientId, int $jobId): array
    {
        $stmt = $this->pdo->prepare(
            $this->entrySelect(
                '',
                "t.client_id=? AND t.billable=1 AND t.end_time IS NOT NULL
                 AND t.workflow_status IN ('draft','submitted','returned')
                 AND " . $this->compatibleDestinationSql() . "
                 AND " . $this->hasNoAttachedProjectionSql(),
                'NULL billing_rate'
            )
        );
        $stmt->execute([
            $clientId,
            $invoiceId,
            $jobId,
        ]);

        return $this->hydrate($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    private function attachedEntries(int $invoiceId): array
    {
        $stmt = $this->pdo->prepare(
            $this->entrySelect(
                '',
                "EXISTS (
                   SELECT 1
                   FROM work_approval_snapshots sx
                   JOIN work_billing_consumptions cx ON cx.approval_snapshot_id=sx.id
                     AND cx.consumption_type IN ('approved','correction')
                   JOIN time_entries btx ON btx.id=cx.billing_time_entry_id
                   LEFT JOIN invoice_items iix ON iix.id=btx.invoice_item_id
                     OR iix.time_entry_id=btx.id
                   WHERE sx.time_entry_id=t.id
                     AND (btx.invoice_id=? OR iix.invoice_id=?)
                 )
                 OR EXISTS (
                   SELECT 1 FROM work_time_billing_allocations bax
                   WHERE bax.time_entry_id=t.id AND bax.status='invoiced'
                     AND bax.invoice_id=?
                 )",
                'NULL billing_rate'
            )
        );
        $stmt->execute([$invoiceId, $invoiceId, $invoiceId]);

        return $this->hydrate($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function compatibleDestinationSql(): string
    {
        return '(t.invoice_id IS NULL OR t.invoice_id=?)'
            . ' AND (t.job_id IS NULL OR t.job_id=?)';
    }

    /**
     * Excludes any historical or current billing representation. Looking at all
     * approval revisions is intentional: once any projection is on an invoice,
     * the correction workflow owns subsequent billing changes.
     */
    private function hasNoAttachedProjectionSql(): string
    {
        return "NOT EXISTS (
                  SELECT 1
                  FROM work_approval_snapshots sx
                  JOIN work_billing_consumptions cx ON cx.approval_snapshot_id=sx.id
                    AND cx.consumption_type IN ('approved','correction')
                  JOIN time_entries btx ON btx.id=cx.billing_time_entry_id
                  WHERE sx.time_entry_id=t.id
                    AND (
                      COALESCE(btx.billed,0)=1
                      OR btx.invoice_item_id IS NOT NULL
                      OR EXISTS (
                        SELECT 1 FROM invoice_items iix
                        WHERE iix.time_entry_id=btx.id
                      )
                    )
                )
                AND NOT EXISTS (
                  SELECT 1 FROM work_time_billing_allocations bax
                  WHERE bax.time_entry_id=t.id
                    AND (bax.status='invoiced' OR bax.invoice_item_id IS NOT NULL)
                )";
    }

    private function entrySelect(string $joins, string $where, string $billingRateSelect): string
    {
        return "SELECT DISTINCT
                  t.id,t.user_id,t.start_time,t.duration_seconds,t.description,
                  t.job_id,t.project_id,t.status,t.workflow_status,
                  wt.name work_type_name,j.job_code,p.name project_name,
                  ep.first_name,ep.last_name,u.username,u.email,
                  {$billingRateSelect}
                FROM work_time_entries t
                JOIN users u ON u.id=t.user_id
                LEFT JOIN employee_profiles ep ON ep.user_id=t.user_id
                LEFT JOIN work_types wt ON wt.id=t.work_type_id
                LEFT JOIN jobs j ON j.id=t.job_id
                LEFT JOIN projects p ON p.id=t.project_id
                {$joins}
                WHERE {$where}
                ORDER BY t.start_time DESC";
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @return list<array<string,mixed>>
     */
    private function hydrate(array $entries): array
    {
        foreach ($entries as &$entry) {
            $name = trim((string)($entry['first_name'] ?? '') . ' ' . (string)($entry['last_name'] ?? ''));
            $entry['worker_name'] = $name !== ''
                ? $name
                : ((string)($entry['username'] ?? '') !== ''
                    ? (string)$entry['username']
                    : (string)($entry['email'] ?? ''));
            unset(
                $entry['first_name'],
                $entry['last_name'],
                $entry['username'],
                $entry['email']
            );
        }
        unset($entry);

        return array_values($entries);
    }
}
