<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\BorrowingTransaction;
use App\Models\Category;
use App\Models\Equipment;
use App\Models\SyncChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** End-to-end coverage of /api/sync/push and /api/sync/pull against MySQL. */
class SyncTest extends TestCase
{
    use RefreshDatabase;

    private string $categoryId;

    private string $equipmentId;

    protected function setUp(): void
    {
        parent::setUp();

        // Sync is an authenticated, inventory-writing operation; these tests
        // exercise the engine, so they run as a manager.
        $this->actingAs(User::create([
            'name' => 'Sync Tester',
            'email' => 'sync@ict.test',
            'role' => Role::MANAGER,
            'is_active' => true,
        ]), 'sanctum');

        $this->categoryId = (string) Str::uuid();
        Category::create([
            'id' => $this->categoryId,
            'name' => 'Laptops',
            'custom_fields' => ['fields' => []],
            'default_loan_days' => 14,
        ]);

        $this->equipmentId = (string) Str::uuid();
        Equipment::create([
            'id' => $this->equipmentId,
            'asset_tag' => 'ICT-TEST-0001',
            'model_name' => 'ThinkPad T14',
            'category_id' => $this->categoryId,
            'specifications' => [],
        ]);
    }

    /**
     * Timestamps are always expressed relative to "now".
     *
     * Anything the sync engine compares against the wall clock (Last-Write-Wins
     * against a row written during setUp) silently rots if the test hardcodes a
     * calendar date, so it never does.
     */
    private function at(string $modifier): string
    {
        return now()->modify($modifier)->toIso8601ZuluString('millisecond');
    }

    private function change(string $table, string $action, string $docId, array $payload, ?string $ts = null): array
    {
        return [
            'id' => (string) Str::uuid(),
            'table' => $table,
            'action' => $action,
            'docId' => $docId,
            'payload' => $payload,
            'clientTimestamp' => $ts ?? now()->toIso8601ZuluString('millisecond'),
        ];
    }

    private function push(array $changes, string $clientId = 'test-client')
    {
        return $this->postJson('/api/sync/push', [
            'clientId' => $clientId,
            'changes' => $changes,
        ]);
    }

    private function loan(string $id, string $createdAt, string $issue, string $due, string $status = 'ISSUED'): array
    {
        return $this->change('borrowings', 'INSERT', $id, [
            'id' => $id,
            'equipmentId' => $this->equipmentId,
            'borrowerName' => 'Ada Lovelace',
            'borrowerEmail' => 'ada@example.org',
            'issueDate' => $issue,
            'expectedReturnDate' => $due,
            'actualReturnDate' => null,
            'status' => $status,
            'notes' => null,
            'conflictReason' => null,
            'createdAt' => $createdAt,
            'updatedAt' => now()->toIso8601ZuluString('millisecond'),
            'deletedAt' => null,
        ]);
    }

    /* ----------------------------- basics ---------------------------- */

    public function test_pull_returns_the_full_dataset_when_no_checkpoint_is_given(): void
    {
        $response = $this->getJson('/api/sync/pull')->assertOk();

        $this->assertCount(1, $response->json('categories'));
        $this->assertCount(1, $response->json('equipment'));
        $this->assertSame([], $response->json('borrowings'));
        $this->assertNotNull($response->json('serverTime'));
    }

    public function test_pull_only_returns_rows_changed_since_the_checkpoint(): void
    {
        $checkpoint = now()->addSecond()->toIso8601ZuluString('millisecond');

        $this->getJson('/api/sync/pull?last_synced_at='.urlencode($checkpoint))
            ->assertOk()
            ->assertJsonCount(0, 'categories')
            ->assertJsonCount(0, 'equipment');
    }

    public function test_pull_rejects_a_malformed_checkpoint(): void
    {
        $this->getJson('/api/sync/pull?last_synced_at=not-a-date')
            ->assertStatus(400)
            ->assertJsonStructure(['error']);
    }

    public function test_push_validates_the_payload(): void
    {
        $this->postJson('/api/sync/push', ['clientId' => 'x'])
            ->assertStatus(422);

        $this->push([$this->change('nope', 'INSERT', (string) Str::uuid(), [])])
            ->assertStatus(422);

        $this->push([$this->change('equipment', 'INSERT', 'not-a-uuid', [])])
            ->assertStatus(422);
    }

    public function test_push_inserts_a_category_and_records_an_audit_row(): void
    {
        $id = (string) Str::uuid();

        $this->push([$this->change('categories', 'INSERT', $id, [
            'id' => $id,
            'name' => 'Networking',
            'description' => 'Switches',
            'customFields' => ['fields' => [
                ['key' => 'ports', 'label' => 'Ports', 'type' => 'number', 'required' => false],
            ]],
            'defaultLoanDays' => 30,
            'updatedAt' => now()->toIso8601ZuluString('millisecond'),
        ])])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'APPLIED')
            ->assertJsonPath('results.0.document.name', 'Networking')
            ->assertJsonPath('results.0.document.customFields.fields.0.key', 'ports');

        $this->assertDatabaseHas('categories', ['id' => $id, 'default_loan_days' => 30]);
        $this->assertDatabaseHas('sync_changes', ['entity_id' => $id, 'operation' => 'INSERT']);
    }

    public function test_soft_delete_syncs_and_is_included_in_pull(): void
    {
        $now = now()->toIso8601ZuluString('millisecond');

        $this->push([$this->change('categories', 'DELETE', $this->categoryId, [
            'id' => $this->categoryId,
            'deletedAt' => $now,
            'updatedAt' => $now,
        ])])->assertOk()->assertJsonPath('results.0.status', 'APPLIED');

        $categories = $this->getJson('/api/sync/pull')->json('categories');
        $this->assertNotNull($categories[0]['deletedAt']);
    }

    /* ------------------------- last-write-wins ------------------------ */

    public function test_newer_write_wins_and_older_write_is_ignored_as_stale(): void
    {
        $base = [
            'id' => $this->equipmentId,
            'assetTag' => 'ICT-TEST-0001',
            'modelName' => 'ThinkPad T14',
            'specifications' => [],
        ];

        $this->push([$this->change('equipment', 'UPDATE', $this->equipmentId,
            $base + ['location' => 'NEWER WINS', 'updatedAt' => $this->at('+1 day')])])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'APPLIED');

        $this->push([$this->change('equipment', 'UPDATE', $this->equipmentId,
            $base + ['location' => 'OLDER IGNORED', 'updatedAt' => $this->at('-1 day')])])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'IGNORED_STALE')
            // The server echoes its own state back so the client can reconcile.
            ->assertJsonPath('results.0.document.location', 'NEWER WINS');

        $this->assertSame('NEWER WINS', Equipment::find($this->equipmentId)->location);
    }

    public function test_duplicate_asset_tag_is_rejected_rather_than_silently_dropped(): void
    {
        $other = (string) Str::uuid();

        $this->push([$this->change('equipment', 'INSERT', $other, [
            'id' => $other,
            'assetTag' => 'ICT-TEST-0001',
            'modelName' => 'Impostor',
            'specifications' => [],
            'updatedAt' => now()->toIso8601ZuluString('millisecond'),
        ])])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'REJECTED');

        $this->assertDatabaseMissing('equipment', ['id' => $other]);
    }

    /* --------------------------- conflicts ---------------------------- */

    public function test_later_checkout_arriving_second_is_flagged_for_review(): void
    {
        $first = (string) Str::uuid();
        $second = (string) Str::uuid();

        $this->push([$this->loan($first, $this->at('+1 hour'), $this->at('+30 days'), $this->at('+39 days'))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'APPLIED');

        $this->push([$this->loan($second, $this->at('+2 hours'), $this->at('+34 days'), $this->at('+41 days'))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'CONFLICT')
            ->assertJsonPath('results.0.document.status', 'CONFLICT_PENDING_REVIEW');

        $this->assertSame('ISSUED', BorrowingTransaction::find($first)->status);
    }

    public function test_earlier_checkout_arriving_second_wins_and_demotes_the_stored_one(): void
    {
        $lateCreated = (string) Str::uuid();
        $earlyCreated = (string) Str::uuid();

        // The later checkout reaches the server first...
        $this->push([$this->loan($lateCreated, $this->at('+2 hours'), $this->at('+34 days'), $this->at('+41 days'))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'APPLIED');

        // ...but the one that physically happened first must keep the asset.
        $this->push([$this->loan($earlyCreated, $this->at('+1 hour'), $this->at('+30 days'), $this->at('+39 days'))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'CONFLICT');

        $this->assertSame('ISSUED', BorrowingTransaction::find($earlyCreated)->status);
        $this->assertSame('CONFLICT_PENDING_REVIEW', BorrowingTransaction::find($lateCreated)->status);
    }

    public function test_disjoint_windows_on_the_same_asset_do_not_conflict(): void
    {
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();

        $this->push([$this->loan($a, $this->at('+1 hour'), $this->at('+30 days'), $this->at('+39 days'))])
            ->assertJsonPath('results.0.status', 'APPLIED');

        $this->push([$this->loan($b, $this->at('+2 hours'), $this->at('+90 days'), $this->at('+94 days'))])
            ->assertJsonPath('results.0.status', 'APPLIED');
    }

    public function test_a_returned_loan_does_not_block_a_new_checkout(): void
    {
        $old = (string) Str::uuid();
        $new = (string) Str::uuid();

        $this->push([$this->loan($old, $this->at('+1 hour'), $this->at('+30 days'), $this->at('+39 days'), 'RETURNED')])
            ->assertJsonPath('results.0.status', 'APPLIED');

        $this->push([$this->loan($new, $this->at('+2 hours'), $this->at('+31 days'), $this->at('+38 days'))])
            ->assertJsonPath('results.0.status', 'APPLIED');
    }

    /* --------------------- equipment reconciliation -------------------- */

    public function test_issuing_a_loan_marks_the_asset_borrowed(): void
    {
        $id = (string) Str::uuid();

        $this->push([$this->loan($id, $this->at('+1 hour'), $this->at('+30 days'), $this->at('+39 days'))])
            ->assertOk();

        $this->assertSame('BORROWED', Equipment::find($this->equipmentId)->status);
    }

    public function test_returning_the_only_loan_frees_the_asset(): void
    {
        $id = (string) Str::uuid();
        $this->push([$this->loan($id, $this->at('+1 hour'), $this->at('+30 days'), $this->at('+39 days'))]);
        $this->assertSame('BORROWED', Equipment::find($this->equipmentId)->status);

        $this->push([$this->change('borrowings', 'UPDATE', $id, [
            'id' => $id,
            'equipmentId' => $this->equipmentId,
            'borrowerName' => 'Ada Lovelace',
            'borrowerEmail' => 'ada@example.org',
            'issueDate' => $this->at('+30 days'),
            'expectedReturnDate' => $this->at('+39 days'),
            'actualReturnDate' => now()->toIso8601ZuluString('millisecond'),
            'status' => 'RETURNED',
            'updatedAt' => now()->addMinute()->toIso8601ZuluString('millisecond'),
        ])])->assertOk();

        $this->assertSame('AVAILABLE', Equipment::find($this->equipmentId)->status);
    }

    public function test_operator_set_statuses_survive_loan_reconciliation(): void
    {
        Equipment::find($this->equipmentId)->forceFill(['status' => 'MAINTENANCE'])->save();

        $id = (string) Str::uuid();
        $this->push([$this->loan($id, $this->at('+1 hour'), $this->at('+30 days'), $this->at('+39 days'))]);

        // MAINTENANCE is a human decision; the sync engine must not overwrite it.
        $this->assertSame('MAINTENANCE', Equipment::find($this->equipmentId)->status);
    }

    /* ---------------------------- ordering ---------------------------- */

    public function test_changes_are_replayed_in_client_timestamp_order(): void
    {
        $id = (string) Str::uuid();

        // Deliberately out of order in the payload; the later edit must win.
        $this->push([
            $this->change('categories', 'UPDATE', $this->categoryId, [
                'id' => $this->categoryId, 'name' => 'Second',
                'customFields' => ['fields' => []],
                'updatedAt' => $this->at('+2 hours'),
            ], $this->at('+2 hours')),
            $this->change('categories', 'UPDATE', $this->categoryId, [
                'id' => $this->categoryId, 'name' => 'First',
                'customFields' => ['fields' => []],
                'updatedAt' => $this->at('+1 hour'),
            ], $this->at('+1 hour')),
        ])->assertOk();

        $this->assertSame('Second', Category::find($this->categoryId)->name);
        $this->assertSame(0, SyncChange::where('entity_id', $id)->count());
    }

    public function test_rest_endpoints_expose_the_same_data(): void
    {
        $this->getJson('/api/equipment')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/categories')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/stats')->assertOk()->assertJsonPath('equipment.total', 1);
        $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'ok');
    }
}
