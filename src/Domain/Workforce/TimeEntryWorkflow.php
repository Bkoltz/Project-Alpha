<?php

declare(strict_types=1);

namespace App\Domain\Workforce;

use DomainException;

/**
 * Canonical time-entry lifecycle used by the redesigned Workforce services.
 *
 * The legacy work_time_entries.status column remains available while callers
 * migrate. legacyStatus() makes dual writes explicit instead of allowing the
 * two representations to drift accidentally.
 */
final class TimeEntryWorkflow
{
    public const RUNNING = 'running';
    public const DRAFT = 'draft';
    public const SUBMITTED = 'submitted';
    public const RETURNED = 'returned';
    public const CONFIRMED = 'confirmed';
    public const VOIDED = 'voided';

    /** @var array<string,array<int,string>> */
    private const TRANSITIONS = [
        self::RUNNING => [self::DRAFT, self::VOIDED],
        self::DRAFT => [self::SUBMITTED, self::VOIDED],
        self::SUBMITTED => [self::RETURNED, self::CONFIRMED, self::VOIDED],
        self::RETURNED => [self::DRAFT, self::SUBMITTED, self::VOIDED],
        self::CONFIRMED => [self::VOIDED],
        self::VOIDED => [],
    ];

    public static function fromLegacyStatus(string $status): string
    {
        return match ($status) {
            'running' => self::RUNNING,
            'review' => self::SUBMITTED,
            'rejected' => self::RETURNED,
            'approved' => self::CONFIRMED,
            'voided', 'cancelled' => self::VOIDED,
            default => throw new DomainException('Unknown legacy time-entry status.'),
        };
    }

    public static function legacyStatus(string $status): string
    {
        return match ($status) {
            self::RUNNING => 'running',
            self::DRAFT, self::SUBMITTED => 'review',
            self::RETURNED => 'rejected',
            self::CONFIRMED => 'approved',
            self::VOIDED => 'voided',
            default => throw new DomainException('Unknown time-entry workflow status.'),
        };
    }

    public static function assertTransition(string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }
        if (!array_key_exists($from, self::TRANSITIONS)
            || !in_array($to, self::TRANSITIONS[$from], true)) {
            throw new DomainException("Time entry cannot move from {$from} to {$to}.");
        }
    }

    /** @return array<int,string> */
    public static function states(): array
    {
        return array_keys(self::TRANSITIONS);
    }
}
