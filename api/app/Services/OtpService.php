<?php

namespace App\Services;

use App\Mail\LoginCodeMail;
use App\Models\LoginCode;
use App\Models\User;
use App\Rules\AllowedEmailDomain;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Passwordless sign-in with an emailed one-time code.
 *
 * Design decisions worth knowing:
 *  - Only a hash of the code is stored, so a database leak cannot be replayed.
 *  - Requesting a code invalidates any earlier outstanding code for that email,
 *    so a user who clicks "resend" cannot be confused by two valid codes.
 *  - Verification is constant in its response shape whether or not the account
 *    exists, so this endpoint cannot be used to enumerate staff addresses.
 */
class OtpService
{
    public const CODE_TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    /**
     * Issues a code for the address if an active account exists.
     *
     * Returns the plain code only when the caller is allowed to see it (local
     * development convenience); production callers ignore the return value.
     */
    public function issue(string $email, ?string $ip = null): ?string
    {
        // Defence in depth: an account created before the domain policy (or by a
        // direct database edit) must still not be able to receive a code.
        if (! AllowedEmailDomain::passes($email)) {
            return null;
        }

        $user = User::where('email', $email)->first();

        // Unknown or deactivated accounts are silently ignored: the controller
        // still returns success so the response cannot be used as an oracle.
        if (! $user || ! $user->is_active) {
            return null;
        }

        LoginCode::where('email', $email)->whereNull('consumed_at')->delete();

        $code = $this->generateCode();

        LoginCode::create([
            'email' => $email,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'request_ip' => $ip,
        ]);

        Mail::to($email)->send(new LoginCodeMail($code, $user->name, self::CODE_TTL_MINUTES));

        return $code;
    }

    /**
     * Redeems a code. Returns the user on success, or null on any failure —
     * callers must not distinguish "wrong code" from "unknown email".
     */
    public function verify(string $email, string $code): ?User
    {
        $record = LoginCode::where('email', $email)
            ->usable()
            ->latest('id')
            ->first();

        if (! $record) {
            return null;
        }

        if ($record->attempts >= self::MAX_ATTEMPTS) {
            // Burn the code rather than leaving it to be brute-forced further.
            $record->update(['consumed_at' => now()]);

            return null;
        }

        if (! Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');

            return null;
        }

        $user = User::where('email', $email)->first();
        if (! $user || ! $user->is_active) {
            return null;
        }

        // Single use: consume before issuing a token.
        $record->update(['consumed_at' => now()]);
        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }

    /** Six digits, zero-padded, from a cryptographically secure source. */
    protected function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /** Housekeeping for expired/consumed rows. */
    public function prune(): int
    {
        return LoginCode::where('expires_at', '<', now()->subDay())->delete();
    }
}
