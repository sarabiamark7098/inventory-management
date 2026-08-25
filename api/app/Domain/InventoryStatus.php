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
        'ISSUED',
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
     * Equipment states that put an asset out of the loan pool entirely.
     *
     * `ISSUED` means permanently handed to a named user on a PAR — a staff
     * member's own workstation, not shared stock — so it can never be booked
     * out, unlike `BORROWED` which is merely busy until the loan ends.
     *
     * Note the collision: `ISSUED` is also a *loan* status meaning "handed over
     * for this loan". They live in separate vocabularies — equipment.status vs
     * borrowing_transactions.status — and must not be compared to each other.
     */
    public const NON_BORROWABLE_STATUSES = [
        'ISSUED',
        'MAINTENANCE',
        'DECOMMISSIONED',
        'LOST',
    ];

    /**
     * `BORROWED` stays borrowable: a future loan on a currently-out asset is
     * legitimate, and the overlap conflict engine adjudicates the dates.
     */
    public static function canBeBorrowed(string $equipmentStatus): bool
    {
        return ! in_array($equipmentStatus, self::NON_BORROWABLE_STATUSES, true);
    }

    public static function explainNotBorrowable(string $equipmentStatus): string
    {
        return match ($equipmentStatus) {
            'ISSUED' => 'is permanently issued to a user and is not part of the loan pool',
            'MAINTENANCE' => 'is under maintenance',
            'DECOMMISSIONED' => 'has been decommissioned',
            'LOST' => 'is recorded as lost',
            default => 'cannot be borrowed',
        };
    }

    /**
     * Legal equipment status transitions (US-2.3). A transition to the same
     * status is always allowed so idempotent syncs never trip validation.
     */
    public const EQUIPMENT_TRANSITIONS = [
        'AVAILABLE' => ['BORROWED', 'ISSUED', 'MAINTENANCE', 'DECOMMISSIONED', 'LOST'],
        // An asset on loan must be returned before it can be permanently issued.
        'BORROWED' => ['AVAILABLE', 'MAINTENANCE', 'LOST'],
        'ISSUED' => ['AVAILABLE', 'MAINTENANCE', 'DECOMMISSIONED', 'LOST'],
        'MAINTENANCE' => ['AVAILABLE', 'ISSUED', 'DECOMMISSIONED', 'LOST'],
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
