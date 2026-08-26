<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The organisational chart: divisions and the sections inside them.
 *
 * One self-referencing table rather than two, because a division and a section
 * are the same kind of thing — an office — differing only in whether they sit
 * under another. `division_id` null means this office IS a division.
 *
 * `code` is the stable business key from the office register; `id` is a UUID so
 * offices can sync to offline clients like every other entity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->uuid('division_id')->nullable();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent();
            $table->dateTime('deleted_at', 3)->nullable();

            $table->index('division_id');
            $table->index('updated_at');

            // Restrict, not cascade: deleting a division that still has sections
            // would silently orphan every asset assigned to them.
            $table->foreign('division_id')->references('id')->on('offices')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offices');
    }
};
