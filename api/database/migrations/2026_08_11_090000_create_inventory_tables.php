<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central MySQL schema for the ICT Equipment & Borrowing Management System.
 *
 * Ids are CHAR(36) UUIDs so offline clients can mint primary keys without
 * coordinating with the server. Timestamps use DATETIME(3) rather than
 * TIMESTAMP, whose range ends in 2038 — loan due dates must outlive that.
 * Everything is stored in UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('custom_fields');
            $table->integer('default_loan_days')->default(7);
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent();
            $table->dateTime('deleted_at', 3)->nullable();

            $table->index('updated_at');
        });

        Schema::create('equipment', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('asset_tag', 100)->unique();
            $table->string('serial_number', 100)->nullable()->unique();
            $table->uuid('category_id')->nullable();
            $table->string('model_name');
            $table->string('brand', 100)->nullable();
            $table->string('status', 50)->default('AVAILABLE');
            $table->string('condition', 50)->default('GOOD');
            $table->string('location')->nullable();
            $table->json('specifications');
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent();
            $table->dateTime('deleted_at', 3)->nullable();

            $table->index('category_id');
            $table->index('status');
            $table->index('updated_at');

            $table->foreign('category_id')->references('id')->on('categories')->nullOnDelete();
        });

        Schema::create('borrowing_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('equipment_id')->nullable();
            $table->string('borrower_name');
            $table->string('borrower_email');
            $table->dateTime('issue_date', 3);
            $table->dateTime('expected_return_date', 3);
            $table->dateTime('actual_return_date', 3)->nullable();
            $table->string('status', 50)->default('ISSUED');
            $table->text('notes')->nullable();
            // Written by the sync conflict engine when a dual checkout is detected.
            $table->text('conflict_reason')->nullable();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent();
            $table->dateTime('deleted_at', 3)->nullable();

            $table->index('equipment_id');
            $table->index('status');
            $table->index('updated_at');
            // Drives the overlap lookup in the conflict engine.
            $table->index(['equipment_id', 'status', 'issue_date']);

            $table->foreign('equipment_id')->references('id')->on('equipment')->nullOnDelete();
        });

        // Append-only audit log of every mutation accepted by /api/sync/push.
        Schema::create('sync_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entity_type', 50);
            $table->uuid('entity_id');
            $table->string('operation', 20);
            $table->json('data');
            $table->string('client_id', 100)->nullable();
            $table->dateTime('client_timestamp', 3);
            $table->dateTime('synced_at', 3)->useCurrent();

            $table->index(['entity_type', 'entity_id']);
            $table->index('synced_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_changes');
        Schema::dropIfExists('borrowing_transactions');
        Schema::dropIfExists('equipment');
        Schema::dropIfExists('categories');
    }
};
