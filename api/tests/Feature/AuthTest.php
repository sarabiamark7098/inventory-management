<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Mail\LoginCodeMail;
use App\Models\LoginCode;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        RateLimiter::clear('otp-email:staff@ict.test');
        RateLimiter::clear('otp-verify:staff@ict.test');

        $this->user = User::create([
            'name' => 'Ada Lovelace',
            'email' => 'staff@ict.test',
            'role' => Role::MANAGER,
            'is_active' => true,
        ]);
    }

    /** Pulls the plain code out of the mailable that was queued for sending. */
    private function sentCode(): string
    {
        $code = null;
        Mail::assertSent(LoginCodeMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return $code;
    }

    public function test_requesting_a_code_emails_one_and_stores_only_its_hash(): void
    {
        $this->postJson('/api/auth/request-code', ['email' => 'staff@ict.test'])
            ->assertOk()
            ->assertJsonPath('expiresInMinutes', OtpService::CODE_TTL_MINUTES);

        Mail::assertSent(LoginCodeMail::class);

        $record = LoginCode::where('email', 'staff@ict.test')->firstOrFail();
        $code = $this->sentCode();

        $this->assertNotSame($code, $record->code_hash, 'the raw code must never be stored');
        $this->assertTrue(Hash::check($code, $record->code_hash));
    }

    public function test_sign_in_codes_are_sent_from_the_ict_section_mailbox(): void
    {
        $this->postJson('/api/auth/request-code', ['email' => 'staff@ict.test'])->assertOk();

        Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) {
            $from = $mail->envelope()->from;

            return $from->address === 'icts.fo11@dswd.gov.ph';
        });
    }

    public function test_the_otp_sender_is_configurable_without_touching_code(): void
    {
        config(['mail.otp_from.address' => 'noreply@example.gov']);

        $this->postJson('/api/auth/request-code', ['email' => 'staff@ict.test'])->assertOk();

        Mail::assertSent(
            LoginCodeMail::class,
            fn (LoginCodeMail $mail) => $mail->envelope()->from->address === 'noreply@example.gov',
        );
    }

    /* --------------------------- domain policy --------------------------- */

    public function test_only_the_organisation_domain_may_request_a_code(): void
    {
        config(['accounts.allowed_email_domains' => ['dswd.gov.ph']]);

        $this->postJson('/api/auth/request-code', ['email' => 'someone@gmail.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        Mail::assertNothingSent();
    }

    public function test_the_domain_check_is_case_insensitive(): void
    {
        config(['accounts.allowed_email_domains' => ['dswd.gov.ph']]);

        User::create([
            'name' => 'Officer',
            'email' => 'officer@dswd.gov.ph',
            'role' => Role::STAFF,
            'is_active' => true,
        ]);

        $this->postJson('/api/auth/request-code', ['email' => 'Officer@DSWD.GOV.PH'])
            ->assertOk();

        Mail::assertSent(LoginCodeMail::class);
    }

    public function test_a_lookalike_domain_is_rejected(): void
    {
        config(['accounts.allowed_email_domains' => ['dswd.gov.ph']]);

        foreach (['a@dswd.gov.ph.evil.com', 'a@notdswd.gov.ph', 'a@sub.dswd.gov.ph'] as $email) {
            $this->postJson('/api/auth/request-code', ['email' => $email])
                ->assertStatus(422);
        }

        Mail::assertNothingSent();
    }

    public function test_an_account_on_a_disallowed_domain_cannot_receive_a_code(): void
    {
        // Created before the policy existed, or by a direct database edit.
        User::create([
            'name' => 'Legacy',
            'email' => 'legacy@ict.local',
            'role' => Role::ADMIN,
            'is_active' => true,
        ]);
        config(['accounts.allowed_email_domains' => ['dswd.gov.ph']]);

        $this->postJson('/api/auth/request-code', ['email' => 'legacy@ict.local'])
            ->assertStatus(422);

        Mail::assertNothingSent();
        $this->assertDatabaseCount('login_codes', 0);
    }

    public function test_the_allowed_domains_are_configurable(): void
    {
        config(['accounts.allowed_email_domains' => ['example.gov']]);

        User::create([
            'name' => 'Other Org',
            'email' => 'person@example.gov',
            'role' => Role::STAFF,
            'is_active' => true,
        ]);

        $this->postJson('/api/auth/request-code', ['email' => 'person@example.gov'])->assertOk();
        Mail::assertSent(LoginCodeMail::class);

        $this->postJson('/api/auth/request-code', ['email' => 'someone@dswd.gov.ph'])
            ->assertStatus(422);
    }

    public function test_an_empty_domain_list_allows_any_address(): void
    {
        config(['accounts.allowed_email_domains' => []]);

        // staff@ict.test exists from setUp.
        $this->postJson('/api/auth/request-code', ['email' => 'staff@ict.test'])->assertOk();
        Mail::assertSent(LoginCodeMail::class);
    }

    public function test_unknown_address_gets_the_same_response_but_no_email(): void
    {
        $this->postJson('/api/auth/request-code', ['email' => 'nobody@ict.test'])
            ->assertOk()
            ->assertJsonPath('expiresInMinutes', OtpService::CODE_TTL_MINUTES);

        Mail::assertNothingSent();
        $this->assertDatabaseCount('login_codes', 0);
    }

    public function test_deactivated_account_cannot_receive_a_code(): void
    {
        $this->user->update(['is_active' => false]);

        $this->postJson('/api/auth/request-code', ['email' => 'staff@ict.test'])->assertOk();

        Mail::assertNothingSent();
    }

    public function test_valid_code_returns_a_token_and_the_user(): void
    {
        $this->postJson('/api/auth/request-code', ['email' => 'staff@ict.test']);

        $response = $this->postJson('/api/auth/verify-code', [
            'email' => 'staff@ict.test',
            'code' => $this->sentCode(),
        ])->assertOk();

        $this->assertNotEmpty($response->json('token'));
        $response->assertJsonPath('user.email', 'staff@ict.test')
            ->assertJsonPath('user.role', Role::MANAGER)
            ->assertJsonPath('user.canWriteInventory', true);

        $this->assertNotNull($this->user->fresh()->last_login_at);
    }

    public function test_a_code_can_only_be_used_once(): void
    {
        $this->postJson('/api/auth/request-code', ['email' => 'staff@ict.test']);
        $code = $this->sentCode();

        $this->postJson('/api/auth/verify-code', ['email' => 'staff@ict.test', 'code' => $code])
            ->assertOk();

        $this->postJson('/api/auth/verify-code', ['email' => 'staff@ict.test', 'code' => $code])
            ->assertStatus(422);
    }

    public function test_wrong_code_is_rejected(): void
    {
        $this->postJson('/api/auth/request-code', ['email' => 'staff@ict.test']);

        $this->postJson('/api/auth/verify-code', ['email' => 'staff@ict.test', 'code' => '000000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_expired_code_is_rejected(): void
    {
        $this->postJson('/api/auth/request-code', ['email' => 'staff@ict.test']);
        $code = $this->sentCode();

        LoginCode::query()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/auth/verify-code', ['email' => 'staff@ict.test', 'code' => $code])
            ->assertStatus(422);
    }

    public function test_requesting_a_new_code_invalidates_the_previous_one(): void
    {
        $this->postJson('/api/auth/request-code', ['email' => 'staff@ict.test']);
        $firstCode = $this->sentCode();

        Mail::fake();
        $this->postJson('/api/auth/request-code', ['email' => 'staff@ict.test']);

        $this->postJson('/api/auth/verify-code', ['email' => 'staff@ict.test', 'code' => $firstCode])
            ->assertStatus(422);
    }

    public function test_brute_force_burns_the_code_after_max_attempts(): void
    {
        $this->postJson('/api/auth/request-code', ['email' => 'staff@ict.test']);
        $code = $this->sentCode();

        for ($i = 0; $i < OtpService::MAX_ATTEMPTS; $i++) {
            $this->postJson('/api/auth/verify-code', ['email' => 'staff@ict.test', 'code' => '111111']);
        }

        // Even the correct code must now fail; the user has to request a new one.
        $this->postJson('/api/auth/verify-code', ['email' => 'staff@ict.test', 'code' => $code])
            ->assertStatus(422);
    }

    public function test_repeated_requests_for_one_address_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/request-code', ['email' => 'staff@ict.test'])->assertOk();
        }

        $this->postJson('/api/auth/request-code', ['email' => 'staff@ict.test'])
            ->assertStatus(429);
    }

    /* --------------------------- protected routes -------------------------- */

    public function test_protected_routes_require_a_token(): void
    {
        $this->getJson('/api/sync/pull')->assertStatus(401);
        $this->getJson('/api/equipment')->assertStatus(401);
        $this->postJson('/api/sync/push', ['clientId' => 'x', 'changes' => []])->assertStatus(401);
        $this->getJson('/api/users')->assertStatus(401);
    }

    public function test_health_endpoint_stays_public(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_signed_in_user_can_read_and_manage_their_profile(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.name', 'Ada Lovelace');

        $this->putJson('/api/auth/profile', ['name' => 'Ada L.'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Ada L.');
    }

    public function test_profile_endpoint_cannot_be_used_to_escalate_role(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $this->putJson('/api/auth/profile', ['name' => 'Ada', 'role' => Role::ADMIN])->assertOk();

        $this->assertSame(Role::MANAGER, $this->user->fresh()->role);
    }

    public function test_deactivated_user_is_locked_out_even_with_a_valid_token(): void
    {
        $this->actingAs($this->user, 'sanctum');
        $this->user->update(['is_active' => false]);

        $this->getJson('/api/equipment')->assertStatus(403);
    }

    /* ------------------------------- roles -------------------------------- */

    public function test_staff_can_read_inventory_but_not_push_changes(): void
    {
        $staff = User::create([
            'name' => 'Borrower',
            'email' => 'borrower@ict.test',
            'role' => Role::STAFF,
            'is_active' => true,
        ]);
        $this->actingAs($staff, 'sanctum');

        $this->getJson('/api/equipment')->assertOk();
        $this->getJson('/api/sync/pull')->assertOk();

        $this->postJson('/api/sync/push', ['clientId' => 'x', 'changes' => []])->assertStatus(403);
    }

    public function test_only_admins_reach_account_management(): void
    {
        $this->actingAs($this->user, 'sanctum');
        $this->getJson('/api/users')->assertStatus(403);

        $admin = User::create([
            'name' => 'Root',
            'email' => 'root@ict.test',
            'role' => Role::ADMIN,
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'sanctum');
        $this->getJson('/api/users')->assertOk()->assertJsonStructure(['data', 'roles']);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $this->postJson('/api/auth/request-code', ['email' => 'staff@ict.test']);
        $token = $this->postJson('/api/auth/verify-code', [
            'email' => 'staff@ict.test',
            'code' => $this->sentCode(),
        ])->json('token');

        // A second device stays signed in.
        $otherToken = $this->user->createToken('phone')->plainTextToken;
        $this->assertSame(2, $this->user->fresh()->tokens()->count());

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout')->assertOk();

        $this->assertSame(1, $this->user->fresh()->tokens()->count());
        $this->assertSame('phone', $this->user->fresh()->tokens()->first()->name);

        // The guard caches the resolved user for the lifetime of the test app,
        // so it has to be reset before the revoked token is re-checked.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')->assertStatus(401);

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->getJson('/api/auth/me')->assertOk();
    }

    public function test_logout_all_revokes_every_device(): void
    {
        $this->user->createToken('web');
        $this->user->createToken('phone');
        $this->actingAs($this->user, 'sanctum');

        $this->postJson('/api/auth/logout-all')->assertOk();

        $this->assertSame(0, $this->user->fresh()->tokens()->count());
    }
}
