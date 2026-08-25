<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Rules\AllowedEmailDomain;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(protected OtpService $otp) {}

    /**
     * Step 1 — email a one-time code.
     *
     * Always answers 200 with the same body regardless of whether the address
     * belongs to an account, so this endpoint cannot enumerate staff emails.
     */
    public function requestCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', new AllowedEmailDomain],
        ]);

        $email = strtolower(trim($data['email']));

        // Two independent limits: per address (stops mailbox flooding) and per
        // IP (stops one host walking a list of addresses).
        foreach ([
            'otp-email:'.$email => [5, 600],
            'otp-ip:'.$request->ip() => [20, 600],
        ] as $key => [$max, $decay]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages([
                    'email' => 'Too many code requests. Try again in '.
                        ceil(RateLimiter::availableIn($key) / 60).' minute(s).',
                ])->status(429);
            }
            RateLimiter::hit($key, $decay);
        }

        $code = $this->otp->issue($email, $request->ip());

        // In local development the mailer writes to the log; surfacing the code
        // saves digging through storage/logs while testing.
        if ($code && app()->environment('local') && config('mail.default') === 'log') {
            Log::info("[auth] Sign-in code for {$email}: {$code}");
        }

        return response()->json([
            'message' => 'If that address belongs to an account, a sign-in code is on its way.',
            'expiresInMinutes' => OtpService::CODE_TTL_MINUTES,
        ]);
    }

    /** Step 2 — redeem the code for an API token. */
    public function verifyCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', new AllowedEmailDomain],
            'code' => ['required', 'string', 'digits:6'],
            'deviceName' => ['nullable', 'string', 'max:100'],
        ]);

        $email = strtolower(trim($data['email']));
        $key = 'otp-verify:'.$email;

        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages([
                'code' => 'Too many attempts. Request a new code shortly.',
            ])->status(429);
        }
        RateLimiter::hit($key, 600);

        $user = $this->otp->verify($email, $data['code']);

        if (! $user) {
            throw ValidationException::withMessages([
                'code' => 'That code is invalid or has expired.',
            ]);
        }

        RateLimiter::clear($key);
        RateLimiter::clear('otp-email:'.$email);

        $token = $user->createToken($data['deviceName'] ?? 'web')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->toClientArray(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()->toClientArray()]);
    }

    /** Updates the signed-in user's own profile. Role is deliberately not editable here. */
    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $user->update(['name' => $data['name']]);

        return response()->json(['user' => $user->fresh()->toClientArray()]);
    }

    /** Revokes only the token used for this request, leaving other devices alone. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Signed out of all devices.']);
    }
}
