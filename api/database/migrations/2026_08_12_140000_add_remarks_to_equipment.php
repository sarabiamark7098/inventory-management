<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-text remarks for an asset.
 *
 * TEXT rather than VARCHAR: remarks are where an operator writes the thing the
 * structured fields have no column for — a defect history, a transfer note, why
 * this unit is set aside — and a length cap would silently truncate exactly the
 * detail that made it worth writing down.
 *
 * Deliberately not indexed. It is prose, searched by scanning, and a MySQL index
 * on TEXT needs a prefix length that would only match the opening characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->text('remarks')->nullable()->after('specifications');
        });
    }

    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->dropColumn('remarks');
        });
    }
};
