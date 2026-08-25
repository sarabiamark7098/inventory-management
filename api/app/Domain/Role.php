<?php

namespace App\Domain;

/**
 * Maps the personas in the specification onto three account roles.
 *
 * ADMIN    — System Admin: manages accounts and categories (Epic 1, US-3.4)
 * MANAGER  — Inventory Manager: registers equipment, issues and receives loans
 * STAFF    — Borrower: requests loans and views the catalogue
 */
final class Role
{
    public const ADMIN = 'ADMIN';

    public const MANAGER = 'MANAGER';

    public const STAFF = 'STAFF';

    public const ALL = [self::ADMIN, self::MANAGER, self::STAFF];

    /** Roles allowed to push inventory mutations to the server. */
    public const CAN_WRITE_INVENTORY = [self::ADMIN, self::MANAGER];

    public static function label(string $role): string
    {
        return match ($role) {
            self::ADMIN => 'System Admin',
            self::MANAGER => 'Inventory Manager',
            self::STAFF => 'Staff / Borrower',
            default => $role,
        };
    }
}
