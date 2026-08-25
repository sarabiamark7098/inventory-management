<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Government property accountability fields.
 *
 * Public-sector inventory is tracked against a Property Acknowledgement Receipt
 * (PAR) — the document naming the accountable officer for an item — and against
 * the office unit holding it. A Sub-PAR covers items reassigned onward from the
 * original accountable officer, so it is optional.
 *
 * Columns are nullable at the database level on purpose: equipment registered
 * before this policy must keep syncing. The PAR requirement is enforced where it
 * belongs — on new registrations, in SyncService::applyEquipment() and in the
 * registration form — so legacy rows can still be corrected rather than locked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->string('section')->nullable()->after('location');
            $table->string('division')->nullable()->after('section');
            $table->string('province', 100)->nullable()->after('division');
            $table->string('par_number', 100)->nullable()->after('province');
            $table->string('sub_par_number', 100)->nullable()->after('par_number');

            // Auditors pull inventory by accountable document and by office unit.
            $table->index('par_number');
            $table->index('sub_par_number');
            $table->index('province');
            $table->index(['division', 'section']);
        });
    }

    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->dropIndex(['par_number']);
            $table->dropIndex(['sub_par_number']);
            $table->dropIndex(['province']);
            $table->dropIndex(['division', 'section']);
            $table->dropColumn(['section', 'division', 'province', 'par_number', 'sub_par_number']);
        });
    }
};
