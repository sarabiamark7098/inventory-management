<?php

namespace Database\Seeders;

use App\Domain\Role;
use App\Models\User;
use App\Rules\AllowedEmailDomain;
use Illuminate\Database\Seeder;

/**
 * Bootstrap accounts. There is no public sign-up, so a fresh install needs at
 * least one admin who can then provision everyone else.
 *
 * The admin address is configurable so a real deployment does not ship with a
 * example.com account that nobody can receive mail for.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $adminEmail = strtolower((string) config('accounts.seed_admin.email'));

        if (! AllowedEmailDomain::passes($adminEmail)) {
            $this->command?->error(
                "SEED_ADMIN_EMAIL ({$adminEmail}) is not on an allowed domain ".
                '('.AllowedEmailDomain::describe().'). No admin was created.',
            );

            return;
        }

        User::updateOrCreate(
            ['email' => $adminEmail],
            [
                'name' => config('accounts.seed_admin.name'),
                'role' => Role::ADMIN,
                'is_active' => true,
            ],
        );

        // No demo accounts: every address must be a real mailbox on an allowed
        // domain, otherwise nobody can receive the sign-in code. Provision the
        // rest from the Accounts screen.
        $this->command?->info("Admin account: {$adminEmail}");
    }
}
