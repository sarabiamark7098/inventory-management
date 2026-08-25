<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account management + passwordless email OTP sign-in.
 *
 * Accounts are admin-provisioned: there is no public sign-up, because an ICT
 * inventory is not something strangers should be able to enrol themselves into.
 * Passwords stay nullable — the OTP flow never sets one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('STAFF')->after('email');
            $table->boolean('is_active')->default(true)->after('role');
            $table->timestamp('last_login_at')->nullable()->after('is_active');

            $table->index('role');
        });

        // Existing installs have a NOT NULL password; OTP users never get one.
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });

        Schema::create('login_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            // Only the hash is stored — a leaked database must not hand out
            // working sign-in codes.
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('request_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['email', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_codes');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn(['role', 'is_active', 'last_login_at']);
        });
    }
};
