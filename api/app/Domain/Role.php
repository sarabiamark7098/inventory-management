<?php

namespace App\Domain;

/**
 * Maps the personas in the specification onto account roles.
 *
 * ADMIN    — System Admin: manages accounts and categories (Epic 1, US-3.4)
 * MANAGER  — Inventory Manager: registers equipment, issues and receives loans
 *
 * There is deliberately no borrower role. A borrower is recorded on the loan
 * itself by name and email; they never sign in, so giving them an account would
 * only create a login with nothing to do.
 */
final class Role
{
    public const ADMIN = 'ADMIN';

    public const MANAGER = 'MANAGER';

    public const ALL = [self::ADMIN, self::MANAGER];

    /**
     * Roles allowed to push inventory mutations to the server.
     *
     * Every current role qualifies. The distinction is kept explicit so the
     * requirement is stated in one place rather than resting on the fact that
     * the role list happens to be short.
     */
    public const CAN_WRITE_INVENTORY = [self::ADMIN, self::MANAGER];

    public static function label(string $role): string
    {
        return match ($role) {
            self::ADMIN => 'System Admin',
            self::MANAGER => 'Inventory Manager',
            default => $role,
        };
    }
}
