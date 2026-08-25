<?php

use App\Services\OtpService;
use Illuminate\Support\Facades\Schedule;

/*
 * Housekeeping.
 *
 * Sign-in codes are single-use and short-lived, but the rows outlive them.
 * Without this the `login_codes` table grows forever — slowly, but a table that
 * only ever grows is a table nobody notices until it matters.
 *
 * Requires the scheduler to be running; see DEPLOYMENT.md.
 */
Schedule::call(fn (OtpService $otp) => $otp->prune())
    ->daily()
    ->name('prune-expired-login-codes')
    ->withoutOverlapping();

/*
 * Sanctum tokens do not expire by default. Revoking anything unused for 90 days
 * limits how long a forgotten laptop stays a valid key to the inventory.
 */
Schedule::command('sanctum:prune-expired --hours=2160')
    ->daily()
    ->name('prune-stale-tokens');
