<?php

declare(strict_types=1);

namespace App\Modules\Timekeeping;

use PDO;

/**
 * Append-only adapter from immutable approval snapshots into PA's billing-time
 * table. It never updates work_time_entries or an existing billing projection.
 */
final class BillingTimeConsumer implements ApprovedTimeConsumer
{
    public function __construct(private readonly PDO $pdo) {}

    public function consume(array $snapshot): void
    {
        $replacesApproval = false;
        if ((int) $snapshot['entry_revision'] > 1) {
            $previous = $this->pdo->prepare(
                'SELECT s.*,c.billing_time_entry_id
                 FROM work_approval_snapshots s
                 JOIN work_billing_consumptions c ON c.approval_snapshot_id=s.id AND c.consumption_type IN (\'approved\',\'correction\')
                 WHERE s.time_entry_id=? AND s.entry_revision<? AND s.billable=1
                 ORDER BY s.entry_revision DESC LIMIT 1'
            );
            $previous->execute([$snapshot['time_entry_id'], $snapshot['entry_revision']]);
            if ($old = $previous->fetch(PDO::FETCH_ASSOC)) {
                $replacesApproval = true;
                $alreadyReversed = $this->pdo->prepare(
                    "SELECT 1 FROM work_billing_consumptions WHERE approval_snapshot_id=? AND consumption_type='reversal'"
                );
                $alreadyReversed->execute([$old['id']]);
                if (!$alreadyReversed->fetchColumn()) {
                    $reversalId = $this->insertBillingRow($old, true, 'Correction reversal');
                    $this->recordConsumption((string) $old['id'], $reversalId, 'reversal');
                }
            }
        }

        if ((int) $snapshot['billable'] !== 1) {
            return;
        }

        $exists = $this->pdo->prepare(
            "SELECT 1 FROM work_billing_consumptions WHERE approval_snapshot_id=? AND consumption_type IN ('approved','correction') LIMIT 1"
        );
        $exists->execute([$snapshot['id']]);
        if ($exists->fetchColumn()) {
            return;
        }

        $billingId = $this->insertBillingRow($snapshot, false, 'Approved time');
        $type = $replacesApproval ? 'correction' : 'approved';
        $this->recordConsumption((string) $snapshot['id'], $billingId, $type);
    }

    public function void(array $snapshot): void
    {
        if ((int) $snapshot['billable'] !== 1) {
            return;
        }
        $exists = $this->pdo->prepare(
            "SELECT 1 FROM work_billing_consumptions WHERE approval_snapshot_id=? AND consumption_type='void'"
        );
        $exists->execute([$snapshot['id']]);
        if ($exists->fetchColumn()) {
            return;
        }
        $billingId = $this->insertBillingRow($snapshot, true, 'Void reversal');
        $this->recordConsumption((string) $snapshot['id'], $billingId, 'void');
    }

    private function insertBillingRow(array $snapshot, bool $reversal, string $label): int
    {
        $project = null;
        if (!empty($snapshot['project_id'])) {
            $stmt = $this->pdo->prepare('SELECT id,client_id,name FROM projects WHERE id=?');
            $stmt->execute([(int) $snapshot['project_id']]);
            $project = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $member = $this->pdo->prepare('SELECT id FROM team_members WHERE user_id=? LIMIT 1');
        $member->execute([(int) $snapshot['employee_user_id']]);
        $teamMemberId = $member->fetchColumn();
        $hours = number_format(((int) $snapshot['duration_seconds']) / 3600, 2, '.', '');
        if ($reversal) {
            $hours = '-' . ltrim($hours, '-');
        }
        $description = sprintf('%s: %s [approval %s rev %d]',
            $label,
            trim((string) $snapshot['description']),
            (string) $snapshot['id'],
            (int) $snapshot['entry_revision']
        );
        $stmt = $this->pdo->prepare(
            'INSERT INTO time_entries
             (user_id,team_member_id,client_id,project_id,description,started_at,ended_at,hours,billable,billed,rate,currency,rate_snapshot_source)
             VALUES (?,?,?,?,?,?,?,?,1,0,?,?,?)'
        );
        $stmt->execute([
            (int) $snapshot['employee_user_id'],
            $teamMemberId === false ? null : (int) $teamMemberId,
            $project['client_id'] ?? null,
            $project['id'] ?? null,
            $description,
            $snapshot['start_time'],
            $snapshot['end_time'],
            $hours,
            $snapshot['billing_rate'] ?? '0.0000',
            $snapshot['currency'],
            'work_approval_snapshot',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function recordConsumption(string $snapshotId, int $billingId, string $type): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO work_billing_consumptions (approval_snapshot_id,billing_time_entry_id,consumption_type) VALUES (?,?,?)'
        );
        $stmt->execute([$snapshotId, $billingId, $type]);
    }
}
