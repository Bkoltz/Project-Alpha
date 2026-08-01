<?php

declare(strict_types=1);

namespace App\Services;

use App\Modules\Timekeeping\WorkforceSettings;
use DomainException;
use PDO;

/**
 * One authorization policy for every time-review surface and mutation.
 *
 * PA's role ACL answers whether a user may be a reviewer. The worker capability
 * scope answers which workers' entries that reviewer may see. Keeping both
 * checks here prevents a count, list, or POST action from silently widening the
 * reviewer's access.
 */
final class TimeApprovalPolicy
{
    /** @var array<int,array<string,mixed>> */
    private array $actors = [];

    public function __construct(private readonly PDO $pdo) {}

    public function canAccessQueue(int $actorId): bool
    {
        $actor = $this->actor($actorId);
        if ($actor === []) {
            return false;
        }
        if ($this->isAdministrator($actor)) {
            return true;
        }

        return (int)WorkforceSettings::load($this->pdo)['allow_non_admin_time_approval'] === 1
            && function_exists('user_can')
            && \user_can($this->pdo, $actorId, 'approvals.review', 0)
            && $this->reviewScope($actor) !== null;
    }

    public function canReviewEntry(int $actorId, string $entryId, string $action = 'approve'): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id,t.user_id,t.client_id,t.project_id,t.job_id,t.work_assignment_id,t.status,
                    wp.id worker_profile_id,wp.relationship_type
             FROM work_time_entries t
             LEFT JOIN worker_profiles wp ON wp.user_id=t.user_id AND wp.status="active"
             WHERE t.id=? LIMIT 1'
        );
        $stmt->execute([$entryId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($entry) && $this->canReviewRecord($actorId, $entry, $action);
    }

    /** @param array<string,mixed> $entry */
    public function canReviewRecord(int $actorId, array $entry, string $action = 'approve'): bool
    {
        if (!$this->canAccessQueue($actorId)) {
            return false;
        }

        $actor = $this->actor($actorId);
        $entryUserId = (int)($entry['user_id'] ?? 0);
        if ($entryUserId <= 0) {
            return false;
        }
        if ($action === 'administrative_self_confirm') {
            return $entryUserId === $actorId
                && ($this->isAdministrator($actor)
                    || WorkforceSettings::canManageAllTime($this->pdo, $actorId));
        }
        if ($entryUserId === $actorId) {
            if ($this->isAdministrator($actor)) {
                return in_array($action, ['approve', 'correct', 'void', 'history'], true);
            }
            return in_array($action, ['correct', 'void', 'history'], true)
                && $this->isOwnerIdentity($actor);
        }

        if ($this->isAdministrator($actor)) {
            return true;
        }

        return match ($this->reviewScope($actor)) {
            'all' => true,
            'business_unit' => $this->sharesBusinessUnit($actor, $entry, false),
            'assigned' => $this->isAssignedReviewer($actor, $entry),
            default => false,
        };
    }

    public function assertCanReviewEntry(int $actorId, string $entryId, string $action): void
    {
        if (!$this->canReviewEntry($actorId, $entryId, $action)) {
            throw new DomainException('You are not allowed to perform this review action for that worker.');
        }
    }

    public function hasGlobalReviewScope(int $actorId): bool
    {
        $actor = $this->actor($actorId);
        return $this->canAccessQueue($actorId)
            && ($this->isAdministrator($actor) || $this->reviewScope($actor) === 'all');
    }

    public function canReviewWorker(int $actorId, int $workerUserId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id user_id,wp.id worker_profile_id,wp.relationship_type
             FROM users u LEFT JOIN worker_profiles wp ON wp.user_id=u.id AND wp.status="active"
             WHERE u.id=? AND u.deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([$workerUserId]);
        $worker = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($worker) && $this->canReviewRecord($actorId, $worker, 'approve');
    }

    public function isOwnerSelfAction(int $actorId, string $entryId): bool
    {
        if (!$this->isOwnerIdentity($this->actor($actorId))) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT 1 FROM work_time_entries WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$entryId, $actorId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Built-in account roles may confirm their own time without turning the
     * worker into a nonpayable owner. Users who reach time management through
     * the explicit permission path (allow_non_admin_time_management + the
     * timekeeping.manage capability) are treated equivalently so that their
     * own entries behave consistently with how they manage others' time.
     */
    public function canAdministrativelySelfConfirm(int $actorId): bool
    {
        return $this->isAdministrator($this->actor($actorId))
            || WorkforceSettings::canManageAllTime($this->pdo, $actorId);
    }

    public function canAdministrativelySelfConfirmEntry(int $actorId, string $entryId): bool
    {
        // Administrative self-confirmation is distinct from queue review. Bypass
        // canAccessQueue so time managers without approvals.review can still
        // confirm their own entries.
        if (!$this->canAdministrativelySelfConfirm($actorId)) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT 1 FROM work_time_entries WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$entryId, $actorId]);
        return (bool)$stmt->fetchColumn();
    }

    public function assertCanAdministrativelySelfConfirmEntry(int $actorId, string $entryId): void
    {
        if (!$this->canAdministrativelySelfConfirmEntry($actorId, $entryId)) {
            throw new DomainException('Only an administrator can self-confirm their own time entry.');
        }
    }

    /** @return array<string,mixed> */
    private function actor(int $actorId): array
    {
        if (array_key_exists($actorId, $this->actors)) {
            return $this->actors[$actorId];
        }
        $stmt = $this->pdo->prepare(
            'SELECT u.id user_id,u.role,wp.id worker_profile_id,wp.relationship_type,
                    COALESCE(wp.relationship_review_required,0) relationship_review_required,
                    wcs.access_scope review_scope
             FROM users u
             LEFT JOIN worker_profiles wp ON wp.user_id=u.id AND wp.status="active"
             LEFT JOIN worker_capability_scopes wcs ON wcs.worker_profile_id=wp.id
                AND wcs.capability="approvals.review" AND wcs.allowed=1
             WHERE u.id=? AND u.deleted_at IS NULL AND u.is_disabled=0
             LIMIT 1'
        );
        $stmt->execute([$actorId]);
        $actor = $stmt->fetch(PDO::FETCH_ASSOC);
        return $this->actors[$actorId] = is_array($actor) ? $actor : [];
    }

    /** @param array<string,mixed> $actor */
    private function isAdministrator(array $actor): bool
    {
        return in_array((string)($actor['role'] ?? ''), ['admin', 'owner'], true);
    }

    /** @param array<string,mixed> $actor */
    private function isOwnerIdentity(array $actor): bool
    {
        return ($actor['relationship_type'] ?? '') === 'owner'
            && empty($actor['relationship_review_required']);
    }

    /** @param array<string,mixed> $actor */
    private function reviewScope(array $actor): ?string
    {
        $scope = (string)($actor['review_scope'] ?? '');
        return in_array($scope, ['own', 'assigned', 'business_unit', 'all'], true) ? $scope : null;
    }

    /**
     * Business-unit scope follows the worker first. Client-unit membership is a
     * fallback for legacy time rows whose user has no worker profile yet.
     *
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $entry
     */
    private function sharesBusinessUnit(array $actor, array $entry, bool $leadOnly): bool
    {
        $actorWorkerId = (int)($actor['worker_profile_id'] ?? 0);
        if ($actorWorkerId <= 0) {
            return false;
        }

        $targetWorkerId = (int)($entry['worker_profile_id'] ?? 0);
        if ($targetWorkerId > 0) {
            $leadClause = $leadOnly ? ' AND reviewer.is_lead=1' : '';
            $stmt = $this->pdo->prepare(
                'SELECT 1
                 FROM worker_business_units reviewer
                 JOIN worker_business_units subject ON subject.business_unit_id=reviewer.business_unit_id
                 WHERE reviewer.worker_profile_id=? AND subject.worker_profile_id=?
                   ' . $leadClause . '
                   AND (reviewer.ends_at IS NULL OR reviewer.ends_at>CURRENT_TIMESTAMP)
                   AND (subject.ends_at IS NULL OR subject.ends_at>CURRENT_TIMESTAMP)
                 LIMIT 1'
            );
            $stmt->execute([$actorWorkerId, $targetWorkerId]);
            if ($stmt->fetchColumn()) {
                return true;
            }
        }

        $clientId = (int)($entry['client_id'] ?? 0);
        if ($clientId <= 0 && (int)($entry['job_id'] ?? 0) > 0) {
            $stmt = $this->pdo->prepare('SELECT client_id FROM jobs WHERE id=? LIMIT 1');
            $stmt->execute([(int)$entry['job_id']]);
            $clientId = (int)($stmt->fetchColumn() ?: 0);
        }
        if ($clientId <= 0) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM worker_business_units reviewer
             JOIN client_business_units client_unit ON client_unit.business_unit_id=reviewer.business_unit_id
             WHERE reviewer.worker_profile_id=? AND client_unit.client_id=?
               ' . ($leadOnly ? ' AND reviewer.is_lead=1' : '') . '
               AND (reviewer.ends_at IS NULL OR reviewer.ends_at>CURRENT_TIMESTAMP)
             LIMIT 1'
        );
        $stmt->execute([$actorWorkerId, $clientId]);
        return (bool)$stmt->fetchColumn();
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $entry */
    private function isAssignedReviewer(array $actor, array $entry): bool
    {
        $projectId = (int)($entry['project_id'] ?? 0);
        if ($projectId > 0) {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM project_assignments
                 WHERE project_id=? AND user_id=?
                   AND (ends_at IS NULL OR ends_at>CURRENT_TIMESTAMP)
                 LIMIT 1'
            );
            $stmt->execute([$projectId, (int)$actor['user_id']]);
            if ($stmt->fetchColumn()) {
                return true;
            }
        }
        return $this->sharesBusinessUnit($actor, $entry, true);
    }
}
