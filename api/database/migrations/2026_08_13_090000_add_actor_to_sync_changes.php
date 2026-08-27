<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives the audit log a real author (US-2.10).
 *
 * `client_id` was named for the offline client that used to send batches, and it
 * held whatever that client called itself. Since the offline client went away it
 * has held the signed-in user's email — which is enough to read a log entry, but
 * not enough to build a history screen on:
 *
 *   - an admin renaming someone's email orphans every entry they ever wrote;
 *   - a deleted account leaves entries pointing at an address nobody can resolve;
 *   - grouping "everything Ana did" means grouping on a mutable string.
 *
 * So the actor is now recorded twice, deliberately. `user_id` is the join that
 * resolves to a current name, and it goes NULL if the account is deleted.
 * `client_id` keeps the address as it read at the moment of the write, which is
 * what an audit answers with when the account behind it no longer exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_changes', function (Blueprint $table) {
            // 100 was sized for a client name. An email is allowed 255, and a
            // long one would have been truncated - or, in strict mode, thrown
            // and rolled back the inventory write it was supposed to record.
            $table->string('client_id', 255)->nullable()->change();

            // users.id is a bigint, not a UUID like everything else here.
            $table->foreignId('user_id')->nullable()->after('client_id')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('sync_changes', function (Blueprint $table) {
            $table->index(['user_id', 'synced_at']);
            $table->index(['operation', 'synced_at']);
        });

        // Entries written before this migration only know an email. Resolve the
        // ones that still match a live account; the rest keep the string alone,
        // which is the same state a deleted account leaves behind.
        DB::statement('
            UPDATE sync_changes sc
            JOIN users u ON u.email = sc.client_id
            SET sc.user_id = u.id
            WHERE sc.user_id IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('sync_changes', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'synced_at']);
            $table->dropIndex(['operation', 'synced_at']);
            $table->dropConstrainedForeignId('user_id');
            $table->string('client_id', 100)->nullable()->change();
        });
    }
};
