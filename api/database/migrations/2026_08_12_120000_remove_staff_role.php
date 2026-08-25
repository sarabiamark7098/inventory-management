<?php

use App\Domain\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the STAFF (borrower) role.
 *
 * Borrowers are recorded on the loan itself by name and email — they never
 * needed an account — so the role only ever created a read-only login with
 * nothing useful to do. Everyone who signs in now handles inventory.
 *
 * Any account still on STAFF is moved to MANAGER rather than left holding a
 * role the application no longer recognises, which would lock it out at
 * EnsureRole with no way back except a database edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('role', 'STAFF')->update(['role' => Role::MANAGER]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default(Role::MANAGER)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('STAFF')->change();
        });
    }
};
