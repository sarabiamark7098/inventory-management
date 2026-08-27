<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The activity log, from spatie/laravel-activitylog.
 *
 * Published from the package and then edited in one respect: `subject_id` is a
 * string, not the `nullableMorphs()` bigint the stub ships. Four of the five
 * logged models key on `CHAR(36)` UUIDs, and a bigint column would silently
 * coerce every one of them to 0 — every equipment row in the log would point at
 * the same non-existent record.
 *
 * `causer_id` keeps the bigint the stub gives it, because the causer is always a
 * `users` row and that table does key on a bigint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');

            $table->string('subject_type')->nullable();
            $table->string('subject_id', 36)->nullable();
            $table->index(['subject_type', 'subject_id'], 'subject');

            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');

            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();

            // The History screen's default read: newest first, and its filters
            // narrow by event or by who did it before ordering.
            $table->index('created_at');
            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
