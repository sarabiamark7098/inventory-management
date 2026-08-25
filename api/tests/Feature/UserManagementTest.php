<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Root',
            'email' => 'root@ict.test',
            'role' => Role::ADMIN,
            'is_active' => true,
        ]);
        $this->actingAs($this->admin, 'sanctum');
    }

    public function test_admin_can_provision_an_account(): void
    {
        $this->postJson('/api/users', [
            'name' => 'New Manager',
            'email' => 'Manager@ICT.test',
            'role' => Role::MANAGER,
        ])
            ->assertCreated()
            // Addresses are normalised so sign-in is not case sensitive.
            ->assertJsonPath('user.email', 'manager@ict.test')
            ->assertJsonPath('user.role', Role::MANAGER)
            ->assertJsonPath('user.isActive', true);
    }

    public function test_accounts_have_no_password_because_sign_in_is_otp_only(): void
    {
        $this->postJson('/api/users', [
            'name' => 'New Manager',
            'email' => 'manager@ict.test',
            'role' => Role::MANAGER,
        ])->assertCreated();

        $this->assertNull(User::where('email', 'manager@ict.test')->first()->password);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $this->postJson('/api/users', [
            'name' => 'Clone',
            'email' => 'root@ict.test',
            'role' => Role::STAFF,
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_accounts_outside_the_organisation_domain_are_rejected(): void
    {
        config(['accounts.allowed_email_domains' => ['dswd.gov.ph']]);

        $this->postJson('/api/users', [
            'name' => 'Outsider',
            'email' => 'outsider@gmail.com',
            'role' => Role::STAFF,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->postJson('/api/users', [
            'name' => 'Officer',
            'email' => 'officer@dswd.gov.ph',
            'role' => Role::STAFF,
        ])->assertCreated();
    }

    public function test_an_existing_account_cannot_be_moved_off_the_allowed_domain(): void
    {
        $user = User::create([
            'name' => 'Officer', 'email' => 'officer@dswd.gov.ph', 'role' => Role::STAFF, 'is_active' => true,
        ]);
        config(['accounts.allowed_email_domains' => ['dswd.gov.ph']]);

        $this->putJson("/api/users/{$user->id}", ['email' => 'officer@gmail.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertSame('officer@dswd.gov.ph', $user->fresh()->email);
    }

    public function test_invalid_role_is_rejected(): void
    {
        $this->postJson('/api/users', [
            'name' => 'Nobody',
            'email' => 'nobody@ict.test',
            'role' => 'SUPERUSER',
        ])->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_admin_can_change_a_role(): void
    {
        $user = User::create([
            'name' => 'Staff', 'email' => 's@ict.test', 'role' => Role::STAFF, 'is_active' => true,
        ]);

        $this->putJson("/api/users/{$user->id}", ['role' => Role::MANAGER])
            ->assertOk()
            ->assertJsonPath('user.role', Role::MANAGER);
    }

    public function test_deactivating_an_account_revokes_its_tokens(): void
    {
        $user = User::create([
            'name' => 'Staff', 'email' => 's@ict.test', 'role' => Role::STAFF, 'is_active' => true,
        ]);
        $user->createToken('web');
        $this->assertSame(1, $user->tokens()->count());

        $this->putJson("/api/users/{$user->id}", ['isActive' => false])->assertOk();

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_the_last_active_admin_cannot_be_demoted(): void
    {
        $this->putJson("/api/users/{$this->admin->id}", ['role' => Role::STAFF])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertSame(Role::ADMIN, $this->admin->fresh()->role);
    }

    public function test_the_last_active_admin_cannot_be_deactivated(): void
    {
        $this->putJson("/api/users/{$this->admin->id}", ['isActive' => false])
            ->assertStatus(422);

        $this->assertTrue($this->admin->fresh()->is_active);
    }

    public function test_an_admin_can_be_demoted_once_another_admin_exists(): void
    {
        User::create([
            'name' => 'Second', 'email' => 'second@ict.test', 'role' => Role::ADMIN, 'is_active' => true,
        ]);

        $this->putJson("/api/users/{$this->admin->id}", ['role' => Role::STAFF])->assertOk();
        $this->assertSame(Role::STAFF, $this->admin->fresh()->role);
    }

    public function test_you_cannot_delete_your_own_account(): void
    {
        $this->deleteJson("/api/users/{$this->admin->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('user');
    }

    public function test_admin_can_delete_another_account(): void
    {
        $user = User::create([
            'name' => 'Staff', 'email' => 's@ict.test', 'role' => Role::STAFF, 'is_active' => true,
        ]);

        $this->deleteJson("/api/users/{$user->id}")->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_listing_supports_search_and_role_filters(): void
    {
        User::create(['name' => 'Ada Lovelace', 'email' => 'ada@ict.test', 'role' => Role::STAFF, 'is_active' => true]);
        User::create(['name' => 'Grace Hopper', 'email' => 'grace@ict.test', 'role' => Role::MANAGER, 'is_active' => true]);

        $this->getJson('/api/users?search=Ada')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/users?role='.Role::MANAGER)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/users')->assertOk()->assertJsonCount(3, 'data');
    }
}
