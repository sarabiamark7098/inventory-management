<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deployment details, and the monitor serial for desktop sets.
 *
 * The PAR names the officer *accountable* for an asset, which is often not the
 * person actually sitting in front of it. `actual_user` and `position` record
 * who is really using it; `ad_username` and `anydesk_number` are what a
 * technician needs to reach that machine remotely.
 *
 * All four are nullable: a spare laptop in the storeroom has no user, and a
 * projector has no Active Directory account.
 *
 * A desktop is one asset with two serialised parts, so it carries the system
 * unit serial in `serial_number` and the display in `monitor_serial_number`.
 * Which categories work that way is flagged on the category itself rather than
 * inferred by string-matching the name "desktop".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('requires_monitor_serial')->default(false)->after('default_loan_days');
        });

        Schema::table('equipment', function (Blueprint $table) {
            $table->string('monitor_serial_number', 100)->nullable()->unique()->after('serial_number');
            $table->string('actual_user')->nullable()->after('sub_par_issued_to');
            $table->string('position')->nullable()->after('actual_user');
            $table->string('ad_username', 100)->nullable()->after('position');
            $table->string('anydesk_number', 50)->nullable()->after('ad_username');

            // Support staff look assets up by the person or the remote-access id.
            $table->index('actual_user');
            $table->index('ad_username');
            $table->index('anydesk_number');
        });
    }

    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->dropIndex(['actual_user']);
            $table->dropIndex(['ad_username']);
            $table->dropIndex(['anydesk_number']);
            $table->dropUnique(['monitor_serial_number']);
            $table->dropColumn([
                'monitor_serial_number', 'actual_user', 'position', 'ad_username', 'anydesk_number',
            ]);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('requires_monitor_serial');
        });
    }
};
