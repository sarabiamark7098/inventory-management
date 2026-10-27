<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which categories record an AnyDesk number.
 *
 * AnyDesk is how support reaches a machine remotely, so it belongs on a
 * desktop or a laptop and on nothing else — a projector has no remote session,
 * and offering the field on every category invites someone to fill it in.
 *
 * A flag on the category rather than a hard-coded list of names, for the same
 * reason `requires_monitor_serial` is one: a field office that adds its own
 * "Workstations" category can tick the box, and renaming a category does not
 * silently change which fields its assets record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('tracks_anydesk')->default(false)->after('requires_monitor_serial');
        });

        /*
         * Anything already holding an AnyDesk number was recorded under a
         * category that evidently does track one, so the flag is switched on
         * for those rather than stranding values nothing can edit. A fresh
         * install has no equipment and takes the seeder's settings instead.
         */
        DB::statement('
            UPDATE categories SET tracks_anydesk = 1
            WHERE id IN (
                SELECT category_id FROM (
                    SELECT DISTINCT category_id FROM equipment
                    WHERE anydesk_number IS NOT NULL AND anydesk_number <> ""
                ) AS holders
            )
        ');
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('tracks_anydesk');
        });
    }
};
