<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\BorrowingTransaction;
use App\Models\Category;
use App\Models\Equipment;
use App\Models\Office;
use App\Models\SyncChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The archive screen and the one irreversible operation in the system.
 *
 * Purging is guarded in three independent places, and each guard is asserted
 * here: the route is admin-only, the service refuses an asset that is not
 * archived, and it refuses one a borrower is still physically holding.
 */
class ArchiveTest extends TestCase
{
    use RefreshDatabase;

    private string $categoryId;

    private string $divisionId;

    private string $equipmentId;

    private User $admin;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Property Officer',
            'email' => 'admin@ict.test',
            'role' => Role::ADMIN,
            'is_active' => true,
        ]);

        $this->manager = User::create([
            'name' => 'Inventory Manager',
            'email' => 'manager@ict.test',
            'role' => Role::MANAGER,
            'is_active' => true,
        ]);

        $this->divisionId = (string) Str::uuid();
        Office::create(['id' => $this->divisionId, 'code' => 'ARC-DIV', 'name' => 'Archive Division']);

        $this->categoryId = (string) Str::uuid();
        Category::create([
            'id' => $this->categoryId,
            'name' => 'Laptops',
            'custom_fields' => ['fields' => []],
            'default_loan_days' => 14,
        ]);

        $this->equipmentId = $this->asset('ICT-ARC-0001');
    }

    private function asset(string $tag, ?string $deletedAt = null): string
    {
        $id = (string) Str::uuid();
        Equipment::create([
            'id' => $id,
            'asset_tag' => $tag,
            'property_number' => 'PN-'.$tag,
            'model_name' => 'ThinkPad T14',
            'category_id' => $this->categoryId,
            'division_id' => $this->divisionId,
            'par_issued_to' => 'Ana R. Cruz',
            'specifications' => [],
            'deleted_at' => $deletedAt,
        ]);

        return $id;
    }

    private function loan(string $equipmentId, string $status): string
    {
        $id = (string) Str::uuid();
        BorrowingTransaction::create([
            'id' => $id,
            'equipment_id' => $equipmentId,
            'borrower_name' => 'Ada Lovelace',
            'borrower_email' => 'ada@example.org',
            'issue_date' => now()->subDays(3),
            'expected_return_date' => now()->addDays(4),
            'status' => $status,
        ]);

        return $id;
    }

    /* ------------------------------ listing ----------------------------- */

    public function test_the_archive_lists_only_soft_deleted_records(): void
    {
        $archived = $this->asset('ICT-ARC-0002', now()->toDateTimeString());

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/archive')
            ->assertOk();

        $ids = array_column($response->json('equipment'), 'id');

        $this->assertSame([$archived], $ids, 'live assets must not appear in the archive');
    }

    public function test_the_archive_covers_every_table_that_soft_deletes(): void
    {
        $this->asset('ICT-ARC-0003', now()->toDateTimeString());
        Category::where('id', $this->categoryId)->update(['deleted_at' => now()]);
        Office::where('id', $this->divisionId)->update(['deleted_at' => now()]);
        $loan = $this->loan($this->equipmentId, 'RETURNED');
        BorrowingTransaction::where('id', $loan)->update(['deleted_at' => now()]);

        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/archive')
            ->assertOk()
            ->assertJsonCount(1, 'equipment')
            ->assertJsonCount(1, 'categories')
            ->assertJsonCount(1, 'offices')
            ->assertJsonCount(1, 'borrowings');
    }

    public function test_each_archived_asset_reports_the_loan_history_it_would_take_with_it(): void
    {
        $id = $this->asset('ICT-ARC-0004', now()->toDateTimeString());
        $this->loan($id, 'RETURNED');
        $this->loan($id, 'RETURNED');
        $this->loan($id, 'ISSUED');

        $row = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/archive')
            ->assertOk()
            ->json('equipment.0');

        $this->assertSame(3, $row['loanCount']);
        $this->assertSame(1, $row['activeLoanCount']);
    }

    /* ------------------------------ purging ----------------------------- */

    public function test_an_admin_can_destroy_an_archived_asset(): void
    {
        $id = $this->asset('ICT-ARC-0005', now()->toDateTimeString());

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/archive/equipment/{$id}")
            ->assertOk()
            ->assertJsonPath('status', 'PURGED')
            ->assertJsonPath('assetTag', 'ICT-ARC-0005');

        $this->assertDatabaseMissing('equipment', ['id' => $id]);
    }

    public function test_the_purge_leaves_a_snapshot_in_the_audit_log(): void
    {
        $id = $this->asset('ICT-ARC-0006', now()->toDateTimeString());

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/archive/equipment/{$id}")
            ->assertOk();

        $audit = SyncChange::where('entity_id', $id)->where('operation', 'PURGE')->firstOrFail();

        // The row is gone; this snapshot is the only remaining evidence of it.
        $this->assertSame('ICT-ARC-0006', $audit->data['assetTag']);
        $this->assertSame('PN-ICT-ARC-0006', $audit->data['propertyNumber']);
        $this->assertSame('admin@ict.test', $audit->client_id);
    }

    public function test_a_live_asset_cannot_be_destroyed(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/archive/equipment/{$this->equipmentId}")
            ->assertStatus(422)
            ->assertJsonPath('status', 'REJECTED');

        $this->assertDatabaseHas('equipment', ['id' => $this->equipmentId]);
    }

    public function test_an_asset_still_out_on_loan_cannot_be_destroyed(): void
    {
        $id = $this->asset('ICT-ARC-0007', now()->toDateTimeString());
        $this->loan($id, 'ISSUED');

        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/archive/equipment/{$id}")
            ->assertStatus(422)
            ->assertJsonPath('status', 'REJECTED');

        $this->assertStringContainsString('active loan', $response->json('message'));
        $this->assertDatabaseHas('equipment', ['id' => $id]);
    }

    public function test_a_settled_loan_does_not_block_the_purge_but_is_counted(): void
    {
        $id = $this->asset('ICT-ARC-0008', now()->toDateTimeString());
        $loan = $this->loan($id, 'RETURNED');

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/archive/equipment/{$id}")
            ->assertOk()
            ->assertJsonPath('detachedLoans', 1);

        // ON DELETE SET NULL: the loan record survives the asset it referenced,
        // so the borrowing history stays readable even once the asset is gone.
        $this->assertDatabaseHas('borrowing_transactions', [
            'id' => $loan,
            'equipment_id' => null,
        ]);
    }

    public function test_a_manager_cannot_destroy_an_asset(): void
    {
        $id = $this->asset('ICT-ARC-0009', now()->toDateTimeString());

        $this->actingAs($this->manager, 'sanctum')
            ->deleteJson("/api/archive/equipment/{$id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('equipment', ['id' => $id]);
    }

    public function test_purging_something_that_is_already_gone_is_a_404(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson('/api/archive/equipment/'.(string) Str::uuid())
            ->assertStatus(404)
            ->assertJsonPath('status', 'NOT_FOUND');
    }

    public function test_a_malformed_id_is_rejected_before_it_reaches_the_database(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson('/api/archive/equipment/not-a-uuid')
            ->assertStatus(422);
    }

    /* ----------------------------- restoring ---------------------------- */

    public function test_an_archived_asset_can_be_restored(): void
    {
        $id = $this->asset('ICT-ARC-0010', now()->toDateTimeString());

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/archive/equipment/{$id}/restore")
            ->assertOk()
            ->assertJsonPath('status', 'RESTORED')
            ->assertJsonPath('data.assetTag', 'ICT-ARC-0010')
            ->assertJsonPath('data.deletedAt', null);

        $this->assertNull(Equipment::find($id)->deleted_at);

        // And it is back in the live register, not just un-flagged.
        $tags = array_column($this->getJson('/api/equipment')->json('data'), 'assetTag');
        $this->assertContains('ICT-ARC-0010', $tags);
    }

    public function test_restoring_leaves_a_trace_in_the_audit_log(): void
    {
        $id = $this->asset('ICT-ARC-0011', now()->toDateTimeString());

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/archive/equipment/{$id}/restore")
            ->assertOk();

        $audit = SyncChange::where('entity_id', $id)->where('operation', 'RESTORE')->firstOrFail();
        $this->assertSame('manager@ict.test', $audit->client_id);
    }

    public function test_a_restored_asset_leaves_the_archive(): void
    {
        $id = $this->asset('ICT-ARC-0012', now()->toDateTimeString());

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/archive/equipment/{$id}/restore")
            ->assertOk();

        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/archive')
            ->assertOk()
            ->assertJsonCount(0, 'equipment');
    }

    public function test_a_live_asset_cannot_be_restored(): void
    {
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/archive/equipment/{$this->equipmentId}/restore")
            ->assertStatus(422)
            ->assertJsonPath('status', 'REJECTED');
    }

    public function test_restoring_an_asset_archived_mid_loan_frees_it_if_the_loan_closed(): void
    {
        $id = $this->asset('ICT-ARC-0018', now()->toDateTimeString());
        Equipment::where('id', $id)->update(['status' => 'BORROWED']);
        $this->loan($id, 'RETURNED');

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/archive/equipment/{$id}/restore")
            ->assertOk()
            ->assertJsonPath('data.status', 'AVAILABLE');
    }

    public function test_restoring_keeps_the_asset_borrowed_while_a_loan_is_open(): void
    {
        $id = $this->asset('ICT-ARC-0019', now()->toDateTimeString());
        Equipment::where('id', $id)->update(['status' => 'BORROWED']);
        $this->loan($id, 'ISSUED');

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/archive/equipment/{$id}/restore")
            ->assertOk()
            ->assertJsonPath('data.status', 'BORROWED');
    }

    public function test_an_archived_asset_keeps_its_identifiers_reserved(): void
    {
        // Why a restore can never collide: the unique indexes cover archived
        // rows, so nothing can claim the tag or property number while the
        // asset sits in the archive waiting to come back.
        $id = $this->asset('ICT-ARC-0020', now()->toDateTimeString());

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/equipment', [
                'assetTag' => 'ICT-ARC-0020',
                'propertyNumber' => 'PN-NEW-0020',
                'dateAcquired' => '2026-01-05',
                'modelName' => 'Replacement',
                'divisionId' => $this->divisionId,
                'parIssuedTo' => 'Ana R. Cruz',
            ])
            ->assertStatus(422);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/archive/equipment/{$id}/restore")
            ->assertOk()
            ->assertJsonPath('status', 'RESTORED');
    }

    public function test_restoring_something_that_never_existed_is_a_404(): void
    {
        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/archive/equipment/'.(string) Str::uuid().'/restore')
            ->assertStatus(404);
    }

    public function test_restore_needs_a_token(): void
    {
        $this->postJson("/api/archive/equipment/{$this->equipmentId}/restore")
            ->assertStatus(401);
    }

    public function test_the_archive_needs_a_token(): void
    {
        $this->getJson('/api/archive')->assertStatus(401);
        $this->deleteJson('/api/archive/equipment/'.$this->equipmentId)->assertStatus(401);
    }
}
