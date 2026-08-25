<?php

namespace App\Domain;

/**
 * Shared vocabulary for equipment and loan states.
 *
 * These constants are the contract between the Vue client and this API; the
 * client mirrors them in `web/src/domain/constants.js`.
 */
final class InventoryStatus
{
    public const EQUIPMENT_STATUSES = [
        'AVAILABLE',
        'BORROWED',
        'MAINTENANCE',
        'DECOMMISSIONED',
        'LOST',
    ];

    public const EQUIPMENT_CONDITIONS = ['NEW', 'GOOD', 'FAIR', 'POOR', 'DAMAGED'];

    public const BORROWING_STATUSES = [
        'REQUESTED',
        'APPROVED',
        'ISSUED',
        'RETURNED',
        'OVERDUE',
        'REJECTED',
        'CANCELLED',
        'CONFLICT_PENDING_REVIEW',
    ];

    /** Loan states that physically hold a device, so they need server validation. */
    public const ACTIVE_LOAN_STATUSES = ['APPROVED', 'ISSUED', 'OVERDUE'];

    /**
     * Legal equipment status transitions (US-2.3). A transition to the same
     * status is always allowed so idempotent syncs never trip validation.
     */
    public const EQUIPMENT_TRANSITIONS = [
        'AVAILABLE' => ['BORROWED', 'MAINTENANCE', 'DECOMMISSIONED', 'LOST'],
        'BORROWED' => ['AVAILABLE', 'MAINTENANCE', 'LOST'],
        'MAINTENANCE' => ['AVAILABLE', 'DECOMMISSIONED', 'LOST'],
        'DECOMMISSIONED' => ['AVAILABLE'],
        'LOST' => ['AVAILABLE', 'MAINTENANCE', 'DECOMMISSIONED'],
    ];

    public static function canTransitionEquipment(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, self::EQUIPMENT_TRANSITIONS[$from] ?? [], true);
    }

    public static function isActiveLoan(string $status): bool
    {
        return in_array($status, self::ACTIVE_LOAN_STATUSES, true);
    }

    /** Falls back to $default when the value is not a recognised member. */
    public static function oneOf(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }
}
