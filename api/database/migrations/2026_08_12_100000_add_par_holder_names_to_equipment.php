<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who each accountability document is issued to.
 *
 * A PAR is only meaningful with a named accountable officer — the number alone
 * does not tell an auditor who holds the asset. The Sub-PAR holder is the person
 * an item was reassigned onward to, so it is optional in the same way the
 * Sub-PAR number is.
 *
 * Nullable for the same reason as the numbers themselves: equipment registered
 * before this policy must stay editable rather than becoming unsyncable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->string('par_issued_to')->nullable()->after('par_number');
            $table->string('sub_par_issued_to')->nullable()->after('sub_par_number');

            // Auditors routinely pull "everything accountable to this officer".
            $table->index('par_issued_to');
            $table->index('sub_par_issued_to');
        });
    }

    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->dropIndex(['par_issued_to']);
            $table->dropIndex(['sub_par_issued_to']);
            $table->dropColumn(['par_issued_to', 'sub_par_issued_to']);
        });
    }
};
