<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\Category;
use App\Models\Equipment;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The activity history (US-2.10).
 *
 * Two things are being asserted here, and the second matters more than the
 * first: that the screen can read the log, and that the log is written at all
 * for every operation — including the two the package gets wrong for this
 * schema, a restore and a purge.
 */
class HistoryTest extends TestCase
{
    use RefreshDatabase;

    private string $categoryId;

    private string $divisionId;

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
        Office::create(['id' => $this->divisionId, 'code' => 'HIS-DIV', 'name' => 'History Division']);

        $this->categoryId = (string) Str::uuid();
        Category::create([
            'id' => $this->categoryId,
            'name' => 'Laptops',
            'custom_fields' => ['fields' => []],
            'default_loan_days' => 14,
        ]);
    }

    /** Registers an asset through the API, so the write path is the real one. */
    private function register(string $tag, array $overrides = []): string
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/equipment', array_merge([
                'assetTag' => $tag,
                'propertyNumber' => 'PN-'.$tag,
                'dateAcquired' => '2026-02-01',
                'modelName' => 'ThinkPad T14',
                'categoryId' => $this->categoryId,
                'divisionId' => $this->divisionId,
                'province' => 'Davao del Sur',
                'parIssuedTo' => 'Ana R. Cruz',
            ], $overrides))
            ->assertCreated();

        return $response->json('data.id');
    }

    /* ------------------------------ writing ----------------------------- */

    public function test_registering_an_asset_is_recorded_against_the_person_who_did_it(): void
    {
        $id = $this->register('ICT-HIS-0001');

        $entry = Activity::where('subject_id', $id)->where('event', 'INSERT')->firstOrFail();

        $this->assertSame('equipment', $entry->log_name);
        $this->assertSame('manager@ict.test', $entry->causer->email);
        $this->assertSame('ICT-HIS-0001', $entry->attribute_changes['attributes']['asset_tag']);
    }

    public function test_an_edit_records_both_sides_of_every_changed_field(): void
    {
        $id = $this->register('ICT-HIS-0002');

        $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/equipment/{$id}", [
                'assetTag' => 'ICT-HIS-0002',
                'modelName' => 'ThinkPad T14',
                'divisionId' => $this->divisionId,
                'parIssuedTo' => 'Ben D. Santos',
                'location' => 'Room 204',
            ])
            ->assertOk();

        $entry = Activity::where('subject_id', $id)->where('event', 'UPDATE')->firstOrFail();

        $this->assertSame('Ana R. Cruz', $entry->attribute_changes['old']['par_issued_to']);
        $this->assertSame('Ben D. Santos', $entry->attribute_changes['attributes']['par_issued_to']);

        // logOnlyDirty: a field nobody touched has no business in the entry.
        $this->assertArrayNotHasKey('asset_tag', $entry->attribute_changes['attributes']);
    }

    public function test_archiving_and_restoring_are_distinguishable_from_an_edit(): void
    {
        $id = $this->register('ICT-HIS-0003');

        $this->actingAs($this->manager, 'sanctum')->deleteJson("/api/equipment/{$id}")->assertOk();
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/archive/equipment/{$id}/restore")->assertOk();

        $operations = Activity::where('subject_id', $id)->orderBy('id')->pluck('event')->all();

        // Both are plain `updated` events to Eloquent. If the trait ever stops
        // reading the deleted_at transition they collapse into UPDATE, and an
        // asset could leave and re-enter the register with nothing to show it.
        $this->assertSame(['INSERT', 'DELETE', 'RESTORE'], $operations);
    }

    public function test_a_purge_leaves_the_full_record_behind(): void
    {
        $id = $this->register('ICT-HIS-0004');
        $this->actingAs($this->manager, 'sanctum')->deleteJson("/api/equipment/{$id}")->assertOk();
        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/archive/equipment/{$id}")->assertOk();

        $entry = Activity::where('subject_id', $id)->where('event', 'PURGE')->firstOrFail();

        // The row is gone. A destroyed record has no "after", so its final
        // state is filed as `old` - and it is all that is left of the asset.
        $this->assertSame('ICT-HIS-0004', $entry->attribute_changes['old']['asset_tag']);
        $this->assertSame('PN-ICT-HIS-0004', $entry->attribute_changes['old']['property_number']);
        $this->assertDatabaseMissing('equipment', ['id' => $id]);
    }

    public function test_a_write_that_changes_nothing_is_not_an_activity(): void
    {
        $id = $this->register('ICT-HIS-0005');
        $before = Activity::where('subject_id', $id)->count();

        // The same values again, in full. Only `updated_at` moves, and that is
        // excluded - so there is no change worth a line in the history.
        $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/equipment/{$id}", [
                'assetTag' => 'ICT-HIS-0005',
                'propertyNumber' => 'PN-ICT-HIS-0005',
                'dateAcquired' => '2026-02-01',
                'modelName' => 'ThinkPad T14',
                'categoryId' => $this->categoryId,
                'divisionId' => $this->divisionId,
                'province' => 'Davao del Sur',
                'parIssuedTo' => 'Ana R. Cruz',
            ])
            ->assertOk();

        $this->assertSame($before, Activity::where('subject_id', $id)->count());
    }

    /* ------------------------------ reading ----------------------------- */

    public function test_the_history_lists_newest_first_with_the_record_named(): void
    {
        $this->register('ICT-HIS-0010');

        $row = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/history')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('equipment', $row['entityType']);
        $this->assertSame('Equipment', $row['entityLabel']);
        $this->assertSame('ICT-HIS-0010', $row['recordLabel']);
        $this->assertSame('INSERT', $row['operation']);
        $this->assertSame('Inventory Manager', $row['actor']['name']);
        $this->assertFalse($row['actor']['deleted']);
    }

    public function test_a_purged_asset_is_still_named_in_the_history(): void
    {
        $id = $this->register('ICT-HIS-0011');
        $this->actingAs($this->manager, 'sanctum')->deleteJson("/api/equipment/{$id}")->assertOk();
        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/archive/equipment/{$id}")->assertOk();

        $row = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/history?operation=PURGE')
            ->assertOk()
            ->json('data.0');

        // There is no row left to look the name up in - it has to come off the
        // entry itself, which is the whole point of keeping the snapshot.
        $this->assertSame('ICT-HIS-0011', $row['recordLabel']);
    }

    public function test_an_entry_reports_the_fields_it_changed(): void
    {
        $id = $this->register('ICT-HIS-0012');

        $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/equipment/{$id}", [
                'assetTag' => 'ICT-HIS-0012',
                'modelName' => 'ThinkPad T14',
                'divisionId' => $this->divisionId,
                'parIssuedTo' => 'Ben D. Santos',
            ])
            ->assertOk();

        $entryId = Activity::where('subject_id', $id)->where('event', 'UPDATE')->value('id');

        $entry = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/history/{$entryId}")
            ->assertOk()
            ->json('entry');

        $this->assertTrue($entry['comparable']);

        $changed = collect($entry['changes'])->firstWhere('field', 'par_issued_to');
        $this->assertSame('Ana R. Cruz', $changed['from']);
        $this->assertSame('Ben D. Santos', $changed['to']);

        // updated_at moves on every write and would drown the real change.
        $this->assertNotContains('updated_at', array_column($entry['changes'], 'field'));
    }

    public function test_filters_narrow_by_operation_type_and_person(): void
    {
        $id = $this->register('ICT-HIS-0013');
        $this->actingAs($this->manager, 'sanctum')->deleteJson("/api/equipment/{$id}")->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/categories', ['name' => 'Projectors', 'defaultLoanDays' => 3])
            ->assertCreated();

        $only = fn (string $query) => $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/history?{$query}")->assertOk()->json('data');

        $this->assertSame(['DELETE'], array_unique(array_column($only('operation=DELETE'), 'operation')));
        $this->assertSame(['categories'], array_unique(array_column($only('entityType=categories'), 'entityType')));

        $byAdmin = $only('actor=admin@ict.test');
        $this->assertNotEmpty($byAdmin);
        $this->assertSame(['Property Officer'], array_unique(array_column(array_column($byAdmin, 'actor'), 'name')));
    }

    public function test_search_matches_the_snapshot_not_just_the_id(): void
    {
        $this->register('ICT-HIS-0014');

        $rows = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/history?search=ICT-HIS-0014')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($rows);
        $this->assertSame('ICT-HIS-0014', $rows[0]['recordLabel']);
    }

    public function test_searching_an_asset_finds_every_entry_about_it_not_just_the_named_ones(): void
    {
        $id = $this->register('ICT-HIS-0015');

        $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/equipment/{$id}", [
                'assetTag' => 'ICT-HIS-0015',
                'modelName' => 'ThinkPad T14',
                'divisionId' => $this->divisionId,
                'parIssuedTo' => 'Ben D. Santos',
            ])
            ->assertOk();

        $found = array_column(
            $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/history?search=ICT-HIS-0015')->assertOk()->json('data'),
            'operation',
        );

        // The edit's snapshot holds only the fields that changed - no asset tag
        // anywhere in it. Searching the log's contents alone would miss it, and
        // "what happened to this asset" is the question the screen exists for.
        $this->assertContains('UPDATE', $found);
        $this->assertContains('INSERT', $found);
    }

    public function test_a_destroyed_records_earlier_entries_are_still_searchable(): void
    {
        $id = $this->register('ICT-HIS-0016');
        $this->actingAs($this->manager, 'sanctum')->deleteJson("/api/equipment/{$id}")->assertOk();
        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/archive/equipment/{$id}")->assertOk();

        $found = array_column(
            $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/history?search=ICT-HIS-0016')->assertOk()->json('data'),
            'operation',
        );

        // Nothing left in any table to resolve the tag against - the id has to
        // be recovered from the snapshots, or the trail leading up to a
        // disposal disappears exactly when an auditor goes looking for it.
        $this->assertContains('PURGE', $found);
        $this->assertContains('DELETE', $found);
        $this->assertContains('INSERT', $found);
    }

    public function test_the_history_is_paginated(): void
    {
        foreach (range(1, 5) as $n) {
            $this->register(sprintf('ICT-HIS-02%02d', $n));
        }

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/history?perPage=2')
            ->assertOk();

        $response->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.perPage', 2)
            ->assertJsonPath('meta.page', 1);

        $this->assertGreaterThan(1, $response->json('meta.lastPage'));
    }

    /* ---------------------------- who may read -------------------------- */

    public function test_account_activity_is_hidden_from_a_manager(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/users', [
                'name' => 'New Hire',
                'email' => 'new.hire@dswd.gov.ph',
                'role' => Role::MANAGER,
            ])
            ->assertCreated();

        $types = array_column(
            $this->actingAs($this->manager, 'sanctum')->getJson('/api/history')->assertOk()->json('data'),
            'entityType',
        );

        $this->assertNotContains('users', $types);

        // And it is not offered as a filter they could ask for either.
        $offered = array_column(
            $this->actingAs($this->manager, 'sanctum')->getJson('/api/history')->json('filters.entityTypes'),
            'value',
        );
        $this->assertNotContains('users', $offered);
    }

    public function test_an_admin_sees_account_activity(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/users', [
                'name' => 'New Hire',
                'email' => 'new.hire@dswd.gov.ph',
                'role' => Role::MANAGER,
            ])
            ->assertCreated();

        $row = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/history?entityType=users')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('Account', $row['entityLabel']);
        $this->assertSame('New Hire', $row['recordLabel']);
    }

    public function test_a_manager_cannot_reach_an_account_entry_by_its_id(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/users', [
                'name' => 'New Hire',
                'email' => 'new.hire@dswd.gov.ph',
                'role' => Role::MANAGER,
            ])
            ->assertCreated();

        $entryId = Activity::where('log_name', 'users')->value('id');

        // Hiding it from the list and then serving it on request would be a
        // filter, not a permission.
        $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/history/{$entryId}")
            ->assertStatus(404);
    }

    public function test_the_history_needs_a_token(): void
    {
        $this->getJson('/api/history')->assertStatus(401);
    }

    public function test_the_log_cannot_be_edited_or_deleted_over_the_api(): void
    {
        $this->register('ICT-HIS-0030');
        $entryId = Activity::query()->value('id');

        // An audit trail with a delete route is not an audit trail.
        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/history/{$entryId}")->assertStatus(405);
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/history/{$entryId}", ['event' => 'INSERT'])->assertStatus(405);
    }

    /* ------------------------- attribution edge case -------------------- */

    public function test_activity_survives_the_account_that_caused_it(): void
    {
        $temp = User::create([
            'name' => 'Temporary Staff',
            'email' => 'temp@ict.test',
            'role' => Role::MANAGER,
            'is_active' => true,
        ]);

        $this->actingAs($temp, 'sanctum')
            ->postJson('/api/equipment', [
                'assetTag' => 'ICT-HIS-0040',
                'propertyNumber' => 'PN-ICT-HIS-0040',
                'dateAcquired' => '2026-02-01',
                'modelName' => 'ThinkPad T14',
                'divisionId' => $this->divisionId,
                'parIssuedTo' => 'Ana R. Cruz',
            ])
            ->assertCreated();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/users/{$temp->id}")
            ->assertOk();

        $rows = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/history?entityType=equipment')
            ->assertOk()
            ->json('data');

        $row = collect($rows)->firstWhere('recordLabel', 'ICT-HIS-0040');

        // The FK is ON DELETE SET NULL, so the entry outlives the account. What
        // it must not do is lose the record of what happened to the asset.
        $this->assertNotNull($row);
        $this->assertSame('ICT-HIS-0040', $row['recordLabel']);
        $this->assertSame('INSERT', $row['operation']);
    }
}
