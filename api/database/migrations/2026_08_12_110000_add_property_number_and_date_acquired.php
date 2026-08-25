<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Property number and acquisition date.
 *
 * Both are standard columns on a PAR: the property number is the official
 * government identifier for the physical item (distinct from the internal asset
 * tag), and the acquisition date drives useful-life and depreciation reporting.
 *
 * `property_number` is UNIQUE because it names exactly one item. MySQL permits
 * repeated NULLs in a unique index, which is what lets legacy rows without one
 * continue to exist.
 *
 * `date_acquired` is a DATE, not a DATETIME: acquisition is recorded on a
 * calendar day, and storing a time would invent precision that no PAR carries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->string('property_number', 100)->nullable()->unique()->after('asset_tag');
            $table->date('date_acquired')->nullable()->after('property_number');

            $table->index('date_acquired');
        });
    }

    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->dropIndex(['date_acquired']);
            $table->dropUnique(['property_number']);
            $table->dropColumn(['property_number', 'date_acquired']);
        });
    }
};
