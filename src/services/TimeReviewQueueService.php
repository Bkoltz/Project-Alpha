<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/** Shared count/list source for every submitted-time review surface. */
final class TimeReviewQueueService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly TimeApprovalPolicy $policy
    ) {}

    /** @return list<array<string,mixed>> */
    public function pendingFor(int $reviewerId): array
    {
        if (!$this->policy->canAccessQueue($reviewerId)) {
            return [];
        }
        $stmt = $this->pdo->query(
            "SELECT t.*,p.name project_name,c.name client_name,i.doc_number invoice_number,i.invoice_type,
                    wp.id worker_profile_id,wp.relationship_type,
                    COALESCE(NULLIF(TRIM(CONCAT(ep.first_name,' ',ep.last_name)),''),u.username,u.email) employee_name
             FROM work_time_entries t
             JOIN users u ON u.id=t.user_id
             LEFT JOIN employee_profiles ep ON ep.user_id=t.user_id
             LEFT JOIN worker_profiles wp ON wp.user_id=t.user_id AND wp.status='active'
             LEFT JOIN projects p ON p.id=t.project_id
             LEFT JOIN clients c ON c.id=t.client_id
             LEFT JOIN invoices i ON i.id=t.invoice_id
             WHERE t.status='review' AND t.workflow_status='submitted'
             ORDER BY t.start_time"
        );
        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($this->policy->canReviewRecord($reviewerId, $row, 'approve')) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    public function pendingCountFor(int $reviewerId): int
    {
        return count($this->pendingFor($reviewerId));
    }

    /** @return array<int,int> */
    public function pendingCountsByUser(int $reviewerId): array
    {
        $counts = [];
        foreach ($this->pendingFor($reviewerId) as $entry) {
            $userId = (int)$entry['user_id'];
            $counts[$userId] = ($counts[$userId] ?? 0) + 1;
        }
        return $counts;
    }

    /** @return list<array<string,mixed>> */
    public function recentlyApprovedFor(int $reviewerId, int $limit = 50): array
    {
        if (!$this->policy->canAccessQueue($reviewerId)) {
            return [];
        }
        $stmt = $this->pdo->query(
            "SELECT t.*,s.id snapshot_id,s.client_name,s.invoice_number,
                    wp.id worker_profile_id,wp.relationship_type,
                    COALESCE(NULLIF(TRIM(CONCAT(ep.first_name,' ',ep.last_name)),''),u.username,u.email) employee_name,
                    p.name project_name
             FROM work_time_entries t
             JOIN users u ON u.id=t.user_id
             LEFT JOIN employee_profiles ep ON ep.user_id=t.user_id
             LEFT JOIN worker_profiles wp ON wp.user_id=t.user_id AND wp.status='active'
             LEFT JOIN projects p ON p.id=t.project_id
             LEFT JOIN work_approval_snapshots s ON s.id=(
                 SELECT s2.id FROM work_approval_snapshots s2
                 WHERE s2.time_entry_id=t.id AND s2.entry_revision<=t.revision AND s2.voided_at IS NULL
                 ORDER BY s2.entry_revision DESC LIMIT 1
             )
             WHERE t.status='approved'
             ORDER BY t.reviewed_at DESC"
        );
        $rows = [];
        $limit = max(1, min(250, $limit));
        while (count($rows) < $limit && ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
            if ($this->policy->canReviewRecord($reviewerId, $row, 'history')) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}
