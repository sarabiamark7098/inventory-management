<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\BorrowingTransaction;
use App\Models\Category;
use App\Models\Equipment;
use App\Models\Office;
use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Coverage of the inventory write rules against MySQL.
 *
 * These exercise SyncService, the single write path, rather than any one
 * endpoint: the REST controllers are a thin shell over it, and the rules worth
 * protecting - Last-Write-Wins, the Status Lock Rule, the Loan Pool Rule, the
 * accountability requirements - all live in the service.
 */
class InventoryWriteTest extends TestCase
{
    use RefreshDatabase;

    private string $categoryId;

    private string $divisionId;

    private string $sectionId;

    private string $equipmentId;

    protected function setUp(): void
    {
        parent::setUp();

        // Writing inventory is an authenticated operation, and the REST reads
        // asserted here need a token too, so these run as a manager.
        $this->actingAs(User::create([
            'name' => 'Sync Tester',
            'email' => 'sync@ict.test',
            'role' => Role::MANAGER,
            'is_active' => true,
        ]), 'sanctum');

        $this->divisionId = (string) Str::uuid();
        Office::create(['id' => $this->divisionId, 'code' => 'TEST-DIV', 'name' => 'Test Division']);

        $this->sectionId = (string) Str::uuid();
        Office::create([
            'id' => $this->sectionId,
            'code' => 'TEST-SEC',
            'name' => 'Test Section',
            'division_id' => $this->divisionId,
        ]);

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

    /**
     * Applies a batch of changes and wraps the verdict in a TestResponse.
     *
     * The batch used to arrive over HTTP from an offline client's outbox. That
     * endpoint is gone, but batches are still the service's unit of work - a
     * checkout writes the loan and the asset's new status together - so the
     * tests keep driving it that way rather than through single-row REST calls,
     * which could not express an overlap between two loans in one submission.
     */
    private function push(array $changes): TestResponse
    {
        $payload = ['changes' => $changes];

        $validator = Validator::make($payload, [
            'changes' => ['present', 'array', 'max:500'],
            'changes.*.id' => ['required', 'string', 'max:100'],
            'changes.*.table' => ['required', 'string', 'in:offices,categories,equipment,borrowings'],
            'changes.*.action' => ['required', 'string', 'in:INSERT,UPDATE,DELETE'],
            'changes.*.docId' => ['required', 'string', 'uuid'],
            'changes.*.payload' => ['present', 'array'],
            'changes.*.clientTimestamp' => ['required', 'date'],
        ]);

        if ($validator->fails()) {
            return $this->respond(['errors' => $validator->errors()->toArray()], 422);
        }

        return $this->respond(app(SyncService::class)->push($payload), 200);
    }

    private function respond(array $body, int $status): TestResponse
    {
        return TestResponse::fromBaseResponse(new JsonResponse($body, $status));
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

    public function test_the_rest_reads_return_the_current_dataset(): void
    {
        $this->getJson('/api/categories')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/equipment')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/borrowings')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_batch_sync_endpoints_are_gone(): void
    {
        // Removed with offline mode. Asserted so they cannot creep back in as
        // a batch write surface nothing owns any more.
        $this->getJson('/api/sync/pull')->assertNotFound();
        $this->postJson('/api/sync/push', ['clientId' => 'x', 'changes' => []])
            ->assertNotFound();
    }

    public function test_push_validates_the_payload(): void
    {
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
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'categories',
            'subject_id' => $id,
            'event' => 'INSERT',
        ]);
    }

    public function test_soft_delete_keeps_the_row_and_marks_it_archived(): void
    {
        $now = now()->toIso8601ZuluString('millisecond');

        $this->push([$this->change('categories', 'DELETE', $this->categoryId, [
            'id' => $this->categoryId,
            'deletedAt' => $now,
            'updatedAt' => $now,
        ])])->assertOk()->assertJsonPath('results.0.status', 'APPLIED');

        $categories = $this->getJson('/api/categories?with_deleted=1')->json('data');
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

    /* ------------------- property accountability (PAR) ------------------- */

    private function equipmentPayload(string $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'assetTag' => 'ICT-NEW-0001',
            'propertyNumber' => '2026-11-NEW-0001',
            'dateAcquired' => '2026-03-15',
            'modelName' => 'ThinkPad T14',
            'specifications' => [],
            'divisionId' => $this->divisionId,
            'sectionId' => $this->sectionId,
            'province' => 'Davao del Sur',
            'parIssuedTo' => 'Mark Rezzel T. Sarabia',
            'subParIssuedTo' => null,
            'updatedAt' => $this->at('+1 hour'),
        ], $overrides);
    }

    public function test_accountability_fields_round_trip_through_a_write(): void
    {
        $id = (string) Str::uuid();

        $this->push([$this->change('equipment', 'INSERT', $id, $this->equipmentPayload($id, [
            'subParIssuedTo' => 'Ed Michael O. Flete',
        ]))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'APPLIED')
            ->assertJsonPath('results.0.document.divisionId', $this->divisionId)
            ->assertJsonPath('results.0.document.sectionId', $this->sectionId)
            ->assertJsonPath('results.0.document.province', 'Davao del Sur')
            ->assertJsonPath('results.0.document.propertyNumber', '2026-11-NEW-0001')
            ->assertJsonPath('results.0.document.dateAcquired', '2026-03-15')
            ->assertJsonPath('results.0.document.parIssuedTo', 'Mark Rezzel T. Sarabia')
            ->assertJsonPath('results.0.document.subParIssuedTo', 'Ed Michael O. Flete');

        $this->assertDatabaseHas('equipment', [
            'id' => $id,
            'province' => 'Davao del Sur',
            'par_issued_to' => 'Mark Rezzel T. Sarabia',
            'sub_par_issued_to' => 'Ed Michael O. Flete',
        ]);

        // And it must come back down on the next pull.
        $pulled = collect($this->getJson('/api/equipment?with_deleted=1')->json('data'))
            ->firstWhere('id', $id);
        $this->assertSame($this->divisionId, $pulled['divisionId']);
        $this->assertSame($this->sectionId, $pulled['sectionId']);
    }

    public function test_new_equipment_without_a_par_holder_name_is_rejected(): void
    {
        $id = (string) Str::uuid();

        // A PAR number with nobody named on it does not establish accountability.
        $this->push([$this->change('equipment', 'INSERT', $id,
            $this->equipmentPayload($id, ['parIssuedTo' => null]))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'REJECTED');

        $this->assertDatabaseMissing('equipment', ['id' => $id]);
    }

    public function test_an_update_cannot_blank_out_the_par_holder_name(): void
    {
        $id = (string) Str::uuid();
        $this->push([$this->change('equipment', 'INSERT', $id, $this->equipmentPayload($id))]);

        $this->push([$this->change('equipment', 'UPDATE', $id, $this->equipmentPayload($id, [
            'parIssuedTo' => null,
            'updatedAt' => $this->at('+2 hours'),
        ]))])->assertOk()->assertJsonPath('results.0.status', 'APPLIED');

        $this->assertSame('Mark Rezzel T. Sarabia', Equipment::find($id)->par_issued_to);
    }

    public function test_new_equipment_without_a_property_number_is_rejected(): void
    {
        $id = (string) Str::uuid();

        $this->push([$this->change('equipment', 'INSERT', $id,
            $this->equipmentPayload($id, ['propertyNumber' => null]))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'REJECTED');

        $this->assertDatabaseMissing('equipment', ['id' => $id]);
    }

    public function test_new_equipment_without_an_acquisition_date_is_rejected(): void
    {
        $id = (string) Str::uuid();

        $this->push([$this->change('equipment', 'INSERT', $id,
            $this->equipmentPayload($id, ['dateAcquired' => null]))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'REJECTED');
    }

    public function test_a_duplicate_property_number_is_rejected(): void
    {
        $first = (string) Str::uuid();
        $second = (string) Str::uuid();

        $this->push([$this->change('equipment', 'INSERT', $first, $this->equipmentPayload($first))])
            ->assertJsonPath('results.0.status', 'APPLIED');

        // Same property number on a different asset tag: one number, one item.
        $this->push([$this->change('equipment', 'INSERT', $second, $this->equipmentPayload($second, [
            'assetTag' => 'ICT-NEW-0002',
        ]))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'REJECTED');

        $this->assertDatabaseMissing('equipment', ['id' => $second]);
    }

    public function test_the_acquisition_date_is_stored_as_a_calendar_date(): void
    {
        $id = (string) Str::uuid();

        $this->push([$this->change('equipment', 'INSERT', $id,
            $this->equipmentPayload($id, ['dateAcquired' => '2026-03-15']))])->assertOk();

        // No time component invented, and no timezone shift off the given day.
        $this->assertSame('2026-03-15', Equipment::find($id)->date_acquired->toDateString());
        $this->assertSame(
            '2026-03-15',
            collect($this->getJson('/api/equipment?with_deleted=1')->json('data'))
                ->firstWhere('id', $id)['dateAcquired'],
        );
    }

    public function test_an_update_cannot_blank_the_property_number_or_acquisition_date(): void
    {
        $id = (string) Str::uuid();
        $this->push([$this->change('equipment', 'INSERT', $id, $this->equipmentPayload($id))]);

        $this->push([$this->change('equipment', 'UPDATE', $id, $this->equipmentPayload($id, [
            'propertyNumber' => null,
            'dateAcquired' => null,
            'updatedAt' => $this->at('+2 hours'),
        ]))])->assertOk()->assertJsonPath('results.0.status', 'APPLIED');

        $row = Equipment::find($id);
        $this->assertSame('2026-11-NEW-0001', $row->property_number);
        $this->assertSame('2026-03-15', $row->date_acquired->toDateString());
    }

    public function test_equipment_registered_before_the_policy_stays_editable(): void
    {
        // A legacy row with no PAR must not become unsyncable, or the number
        // could never be corrected from the app.
        $legacyId = (string) Str::uuid();
        Equipment::create([
            'id' => $legacyId,
            'asset_tag' => 'ICT-LEGACY-0001',
            'model_name' => 'Old Asset',
            'specifications' => [],
        ]);

        $this->push([$this->change('equipment', 'UPDATE', $legacyId, [
            'id' => $legacyId,
            'assetTag' => 'ICT-LEGACY-0001',
            'modelName' => 'Old Asset',
            'specifications' => [],
            'parIssuedTo' => 'Juan D. Dela Cruz',
            'province' => 'Davao de Oro',
            'updatedAt' => $this->at('+1 hour'),
        ])])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'APPLIED')
            ->assertJsonPath('results.0.document.parIssuedTo', 'Juan D. Dela Cruz');
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

    /* ------------------------------ offices ------------------------------ */

    public function test_an_office_without_a_division_is_a_division(): void
    {
        $id = (string) Str::uuid();

        $this->push([$this->change('offices', 'INSERT', $id, [
            'id' => $id,
            'code' => 'XI-FO-AD',
            'name' => 'FO Administrative Division',
            'divisionId' => null,
            'updatedAt' => $this->at('+1 hour'),
        ])])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'APPLIED')
            ->assertJsonPath('results.0.document.isDivision', true);

        $this->assertTrue(Office::find($id)->isDivision());
    }

    public function test_an_office_with_a_division_is_a_section(): void
    {
        $id = (string) Str::uuid();

        $this->push([$this->change('offices', 'INSERT', $id, [
            'id' => $id,
            'code' => 'XI-FO-AD-GSS',
            'name' => 'FO General Service Section',
            'divisionId' => $this->divisionId,
            'updatedAt' => $this->at('+1 hour'),
        ])])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'APPLIED')
            ->assertJsonPath('results.0.document.isDivision', false)
            ->assertJsonPath('results.0.document.divisionId', $this->divisionId);
    }

    public function test_a_section_cannot_belong_to_another_section(): void
    {
        $id = (string) Str::uuid();

        // The register is two levels; a section of a section has no meaning.
        $this->push([$this->change('offices', 'INSERT', $id, [
            'id' => $id,
            'code' => 'XI-DEEP',
            'name' => 'Too deep',
            'divisionId' => $this->sectionId,
            'updatedAt' => $this->at('+1 hour'),
        ])])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'REJECTED');

        $this->assertDatabaseMissing('offices', ['id' => $id]);
    }

    public function test_an_office_cannot_be_its_own_division(): void
    {
        $this->push([$this->change('offices', 'UPDATE', $this->divisionId, [
            'id' => $this->divisionId,
            'code' => 'TEST-DIV',
            'name' => 'Test Division',
            'divisionId' => $this->divisionId,
            'updatedAt' => $this->at('+1 hour'),
        ])])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'REJECTED');
    }

    public function test_office_codes_are_unique(): void
    {
        $id = (string) Str::uuid();

        $this->push([$this->change('offices', 'INSERT', $id, [
            'id' => $id,
            'code' => 'TEST-DIV',
            'name' => 'Impostor',
            'divisionId' => null,
            'updatedAt' => $this->at('+1 hour'),
        ])])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'REJECTED');

        $this->assertDatabaseMissing('offices', ['id' => $id]);
    }

    public function test_a_division_with_sections_cannot_become_a_section(): void
    {
        // Demoting it would orphan every section beneath it.
        $this->push([$this->change('offices', 'UPDATE', $this->divisionId, [
            'id' => $this->divisionId,
            'code' => 'TEST-DIV',
            'name' => 'Test Division',
            'divisionId' => (string) Str::uuid(),
            'updatedAt' => $this->at('+1 hour'),
        ])])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'REJECTED');
    }

    public function test_an_office_still_holding_assets_cannot_be_deleted(): void
    {
        $id = (string) Str::uuid();
        $this->push([$this->change('equipment', 'INSERT', $id, $this->equipmentPayload($id))])
            ->assertJsonPath('results.0.status', 'APPLIED');

        $now = $this->at('+2 hours');
        $this->push([$this->change('offices', 'DELETE', $this->sectionId, [
            'id' => $this->sectionId, 'deletedAt' => $now, 'updatedAt' => $now,
        ])])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'REJECTED');

        $this->assertNull(Office::find($this->sectionId)->deleted_at);
    }

    public function test_a_division_with_sections_cannot_be_deleted(): void
    {
        $now = $this->at('+1 hour');

        $this->push([$this->change('offices', 'DELETE', $this->divisionId, [
            'id' => $this->divisionId, 'deletedAt' => $now, 'updatedAt' => $now,
        ])])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'REJECTED');
    }

    public function test_offices_are_readable_through_the_rest_endpoint(): void
    {
        $offices = $this->getJson('/api/offices')->assertOk()->json('data');

        $this->assertCount(2, $offices);
        $this->assertContains(true, array_column($offices, 'isDivision'));
        $this->assertContains(false, array_column($offices, 'isDivision'));
    }

    /* ------------------------- office on equipment ------------------------ */

    public function test_new_equipment_without_a_division_is_rejected(): void
    {
        $id = (string) Str::uuid();

        $this->push([$this->change('equipment', 'INSERT', $id,
            $this->equipmentPayload($id, ['divisionId' => null]))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'REJECTED');

        $this->assertDatabaseMissing('equipment', ['id' => $id]);
    }

    public function test_the_section_is_optional(): void
    {
        $id = (string) Str::uuid();

        $this->push([$this->change('equipment', 'INSERT', $id,
            $this->equipmentPayload($id, ['sectionId' => null]))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'APPLIED')
            ->assertJsonPath('results.0.document.sectionId', null)
            ->assertJsonPath('results.0.document.divisionId', $this->divisionId);
    }

    public function test_a_section_from_a_different_division_is_rejected(): void
    {
        $otherDivision = Office::create([
            'id' => (string) Str::uuid(), 'code' => 'OTHER-DIV', 'name' => 'Other Division',
        ]);
        $id = (string) Str::uuid();

        // The section belongs to Test Division, not Other Division.
        $this->push([$this->change('equipment', 'INSERT', $id, $this->equipmentPayload($id, [
            'divisionId' => $otherDivision->id,
        ]))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'REJECTED');

        $this->assertDatabaseMissing('equipment', ['id' => $id]);
    }

    public function test_a_division_cannot_be_passed_as_a_section(): void
    {
        $id = (string) Str::uuid();

        $this->push([$this->change('equipment', 'INSERT', $id, $this->equipmentPayload($id, [
            'sectionId' => $this->divisionId,
        ]))])
            ->assertOk()
            // The division-level office is not a valid section, so it resolves
            // to null rather than being filed as one.
            ->assertJsonPath('results.0.status', 'APPLIED')
            ->assertJsonPath('results.0.document.sectionId', null);
    }

    public function test_an_update_cannot_blank_out_the_division(): void
    {
        $id = (string) Str::uuid();
        $this->push([$this->change('equipment', 'INSERT', $id, $this->equipmentPayload($id))]);

        $this->push([$this->change('equipment', 'UPDATE', $id, $this->equipmentPayload($id, [
            'divisionId' => null,
            'updatedAt' => $this->at('+2 hours'),
        ]))])->assertOk()->assertJsonPath('results.0.status', 'APPLIED');

        $this->assertSame($this->divisionId, Equipment::find($id)->division_id);
    }

    /* ------------------------------ remarks ------------------------------ */

    public function test_remarks_round_trip_through_a_write(): void
    {
        $id = (string) Str::uuid();
        $note = "Screen flickers under load. Swapped the cable 2026-03-02; monitor still suspect.";

        $this->push([$this->change('equipment', 'INSERT', $id,
            $this->equipmentPayload($id, ['remarks' => $note]))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'APPLIED')
            ->assertJsonPath('results.0.document.remarks', $note);

        $this->assertSame($note, Equipment::find($id)->remarks);

        // And it must come back down on the next pull.
        $pulled = collect($this->getJson('/api/equipment?with_deleted=1')->json('data'))
            ->firstWhere('id', $id);
        $this->assertSame($note, $pulled['remarks']);
    }

    public function test_remarks_are_optional(): void
    {
        $id = (string) Str::uuid();

        $this->push([$this->change('equipment', 'INSERT', $id, $this->equipmentPayload($id))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'APPLIED')
            ->assertJsonPath('results.0.document.remarks', null);
    }

    public function test_remarks_survive_text_longer_than_a_varchar(): void
    {
        $id = (string) Str::uuid();
        // A TEXT column, not VARCHAR(255): a maintenance history outgrows 255
        // characters quickly, and truncation would lose the newest entry.
        $long = str_repeat('Returned from repair; battery replaced. ', 30);

        $this->push([$this->change('equipment', 'INSERT', $id,
            $this->equipmentPayload($id, ['remarks' => $long]))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'APPLIED');

        $this->assertSame(trim($long), Equipment::find($id)->remarks);
        $this->assertGreaterThan(255, strlen(Equipment::find($id)->remarks));
    }

    public function test_remarks_can_be_edited_and_cleared_deliberately(): void
    {
        $id = (string) Str::uuid();
        $this->push([$this->change('equipment', 'INSERT', $id,
            $this->equipmentPayload($id, ['remarks' => 'First note']))]);

        $this->push([$this->change('equipment', 'UPDATE', $id, $this->equipmentPayload($id, [
            'remarks' => 'Replaces the first note',
            'updatedAt' => $this->at('+2 hours'),
        ]))])->assertOk()->assertJsonPath('results.0.document.remarks', 'Replaces the first note');

        // Blanking the field in the form is a real edit, so it must stick —
        // unlike the PAR fields, which an incomplete payload must never erase.
        $this->push([$this->change('equipment', 'UPDATE', $id, $this->equipmentPayload($id, [
            'remarks' => '',
            'updatedAt' => $this->at('+3 hours'),
        ]))])->assertOk();

        $this->assertNull(Equipment::find($id)->remarks);
    }

    /* ------------------ deployment + multi-part serials ------------------ */

    public function test_deployment_details_round_trip_through_a_write(): void
    {
        $id = (string) Str::uuid();

        $this->push([$this->change('equipment', 'INSERT', $id, $this->equipmentPayload($id, [
            'actualUser' => 'Ed Michael O. Flete',
            'position' => 'Information Technology Officer I',
            'adUsername' => 'eoflete',
            'anydeskNumber' => '1 234 567 890',
        ]))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'APPLIED')
            ->assertJsonPath('results.0.document.actualUser', 'Ed Michael O. Flete')
            ->assertJsonPath('results.0.document.position', 'Information Technology Officer I')
            ->assertJsonPath('results.0.document.adUsername', 'eoflete')
            ->assertJsonPath('results.0.document.anydeskNumber', '1 234 567 890');
    }

    public function test_deployment_details_stay_optional_for_stock_in_the_storeroom(): void
    {
        $id = (string) Str::uuid();

        // A spare laptop has no user, and a projector has no AD account.
        $this->push([$this->change('equipment', 'INSERT', $id, $this->equipmentPayload($id))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'APPLIED')
            ->assertJsonPath('results.0.document.actualUser', null)
            ->assertJsonPath('results.0.document.adUsername', null);
    }

    private function desktopCategory(): string
    {
        $id = (string) Str::uuid();
        Category::create([
            'id' => $id,
            'name' => 'Desktops',
            'custom_fields' => ['fields' => []],
            'default_loan_days' => 30,
            'requires_monitor_serial' => true,
        ]);

        return $id;
    }

    public function test_a_desktop_needs_both_the_system_unit_and_monitor_serial(): void
    {
        $categoryId = $this->desktopCategory();
        $id = (string) Str::uuid();

        $this->push([$this->change('equipment', 'INSERT', $id, $this->equipmentPayload($id, [
            'categoryId' => $categoryId,
            'serialNumber' => 'MXL3421ABC',
            'monitorSerialNumber' => null,
        ]))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'REJECTED');

        $this->assertDatabaseMissing('equipment', ['id' => $id]);
    }

    public function test_a_desktop_without_a_system_unit_serial_is_rejected(): void
    {
        $categoryId = $this->desktopCategory();
        $id = (string) Str::uuid();

        $this->push([$this->change('equipment', 'INSERT', $id, $this->equipmentPayload($id, [
            'categoryId' => $categoryId,
            'serialNumber' => null,
            'monitorSerialNumber' => 'CNC4210XYZ',
        ]))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'REJECTED');
    }

    public function test_a_desktop_with_both_serials_is_accepted(): void
    {
        $categoryId = $this->desktopCategory();
        $id = (string) Str::uuid();

        $this->push([$this->change('equipment', 'INSERT', $id, $this->equipmentPayload($id, [
            'categoryId' => $categoryId,
            'serialNumber' => 'MXL3421ABC',
            'monitorSerialNumber' => 'CNC4210XYZ',
        ]))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'APPLIED')
            ->assertJsonPath('results.0.document.serialNumber', 'MXL3421ABC')
            ->assertJsonPath('results.0.document.monitorSerialNumber', 'CNC4210XYZ');
    }

    public function test_categories_without_the_flag_do_not_demand_a_monitor_serial(): void
    {
        $id = (string) Str::uuid();

        // $this->categoryId is a plain Laptops category created in setUp.
        $this->push([$this->change('equipment', 'INSERT', $id, $this->equipmentPayload($id, [
            'categoryId' => $this->categoryId,
            'serialNumber' => 'PF3ZK9QA',
            'monitorSerialNumber' => null,
        ]))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'APPLIED');
    }

    public function test_a_desktop_registered_before_the_flag_stays_editable(): void
    {
        $categoryId = $this->desktopCategory();

        // Registered while the category still had the flag off.
        $legacyId = (string) Str::uuid();
        Equipment::create([
            'id' => $legacyId,
            'asset_tag' => 'ICT-OLD-DT-0001',
            'model_name' => 'Old Desktop',
            'category_id' => $categoryId,
            'specifications' => [],
        ]);

        $this->push([$this->change('equipment', 'UPDATE', $legacyId, [
            'id' => $legacyId,
            'assetTag' => 'ICT-OLD-DT-0001',
            'modelName' => 'Old Desktop',
            'categoryId' => $categoryId,
            'specifications' => [],
            'location' => 'Room 2',
            'updatedAt' => $this->at('+1 hour'),
        ])])->assertOk()->assertJsonPath('results.0.status', 'APPLIED');
    }

    public function test_a_serial_cannot_collide_with_another_records_monitor_serial(): void
    {
        $categoryId = $this->desktopCategory();
        $first = (string) Str::uuid();

        $this->push([$this->change('equipment', 'INSERT', $first, $this->equipmentPayload($first, [
            'categoryId' => $categoryId,
            'serialNumber' => 'MXL3421ABC',
            'monitorSerialNumber' => 'CNC4210XYZ',
        ]))])->assertJsonPath('results.0.status', 'APPLIED');

        // Re-using the monitor serial as another machine's system unit serial
        // would make one physical device resolve to two records.
        $second = (string) Str::uuid();
        $this->push([$this->change('equipment', 'INSERT', $second, $this->equipmentPayload($second, [
            'assetTag' => 'ICT-NEW-0002',
            'propertyNumber' => '2026-11-NEW-0002',
            'serialNumber' => 'CNC4210XYZ',
        ]))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'REJECTED');

        $this->assertDatabaseMissing('equipment', ['id' => $second]);
    }

    public function test_the_system_unit_and_monitor_cannot_share_a_serial(): void
    {
        $categoryId = $this->desktopCategory();
        $id = (string) Str::uuid();

        $this->push([$this->change('equipment', 'INSERT', $id, $this->equipmentPayload($id, [
            'categoryId' => $categoryId,
            'serialNumber' => 'SAME-SERIAL',
            'monitorSerialNumber' => 'SAME-SERIAL',
        ]))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'REJECTED');
    }

    public function test_the_monitor_serial_flag_persists_on_the_category(): void
    {
        $id = (string) Str::uuid();

        $this->push([$this->change('categories', 'INSERT', $id, [
            'id' => $id,
            'name' => 'Desktops',
            'customFields' => ['fields' => []],
            'defaultLoanDays' => 30,
            'requiresMonitorSerial' => true,
            'updatedAt' => $this->at('+1 hour'),
        ])])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'APPLIED')
            ->assertJsonPath('results.0.document.requiresMonitorSerial', true);

        $this->assertTrue(Category::find($id)->requires_monitor_serial);
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
        // Book the loan first, while the asset is still borrowable...
        $id = (string) Str::uuid();
        $this->push([$this->loan($id, $this->at('+1 hour'), $this->at('+30 days'), $this->at('+39 days'))])
            ->assertJsonPath('results.0.status', 'APPLIED');

        // ...then an operator pulls it for repair.
        Equipment::find($this->equipmentId)->forceFill(['status' => 'MAINTENANCE'])->save();

        // Syncing a later edit to that loan must not reconcile the status back.
        $this->push([$this->change('borrowings', 'UPDATE', $id, [
            'id' => $id,
            'equipmentId' => $this->equipmentId,
            'borrowerName' => 'Ada Lovelace',
            'borrowerEmail' => 'ada@example.org',
            'issueDate' => $this->at('+30 days'),
            'expectedReturnDate' => $this->at('+39 days'),
            'status' => 'ISSUED',
            'notes' => 'Handover note',
            'updatedAt' => $this->at('+2 hours'),
        ])])->assertOk()->assertJsonPath('results.0.status', 'APPLIED');

        // MAINTENANCE is a human decision; the sync engine must not overwrite it.
        $this->assertSame('MAINTENANCE', Equipment::find($this->equipmentId)->status);
    }

    /* --------------------- out of the loan pool ----------------------- */

    /** @return array<int, array{0: string}> */
    public static function nonBorrowableStatuses(): array
    {
        return [['ISSUED'], ['MAINTENANCE'], ['DECOMMISSIONED'], ['LOST']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonBorrowableStatuses')]
    public function test_equipment_out_of_the_loan_pool_cannot_be_booked(string $status): void
    {
        Equipment::find($this->equipmentId)->forceFill(['status' => $status])->save();

        $id = (string) Str::uuid();
        $this->push([$this->loan($id, $this->at('+1 hour'), $this->at('+30 days'), $this->at('+39 days'))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'REJECTED');

        $this->assertDatabaseMissing('borrowing_transactions', ['id' => $id]);
        $this->assertSame($status, Equipment::find($this->equipmentId)->status);
    }

    public function test_the_rejection_explains_that_an_issued_asset_is_not_shared_stock(): void
    {
        Equipment::find($this->equipmentId)->forceFill(['status' => 'ISSUED'])->save();

        $id = (string) Str::uuid();
        $message = $this->push([
            $this->loan($id, $this->at('+1 hour'), $this->at('+30 days'), $this->at('+39 days')),
        ])->json('results.0.message');

        $this->assertStringContainsString('ICT-TEST-0001', $message);
        $this->assertStringContainsString('not part of the loan pool', $message);
    }

    public function test_an_available_asset_can_still_be_booked(): void
    {
        $id = (string) Str::uuid();

        $this->push([$this->loan($id, $this->at('+1 hour'), $this->at('+30 days'), $this->at('+39 days'))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'APPLIED');
    }

    public function test_a_borrowed_asset_can_still_take_a_future_loan(): void
    {
        // BORROWED is busy, not withdrawn: the overlap engine judges the dates.
        $first = (string) Str::uuid();
        $this->push([$this->loan($first, $this->at('+1 hour'), $this->at('+30 days'), $this->at('+39 days'))])
            ->assertJsonPath('results.0.status', 'APPLIED');
        $this->assertSame('BORROWED', Equipment::find($this->equipmentId)->status);

        $later = (string) Str::uuid();
        $this->push([$this->loan($later, $this->at('+2 hours'), $this->at('+90 days'), $this->at('+94 days'))])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'APPLIED');
    }

    public function test_returning_a_loan_still_applies_after_the_asset_is_withdrawn(): void
    {
        $id = (string) Str::uuid();
        $this->push([$this->loan($id, $this->at('+1 hour'), $this->at('+30 days'), $this->at('+39 days'))]);

        // The asset is written off while still out on loan.
        Equipment::find($this->equipmentId)->forceFill(['status' => 'LOST'])->save();

        // The return must not be blocked — the guard applies to new loans only.
        $this->push([$this->change('borrowings', 'UPDATE', $id, [
            'id' => $id,
            'equipmentId' => $this->equipmentId,
            'borrowerName' => 'Ada Lovelace',
            'borrowerEmail' => 'ada@example.org',
            'issueDate' => $this->at('+30 days'),
            'expectedReturnDate' => $this->at('+39 days'),
            'actualReturnDate' => $this->at('+31 days'),
            'status' => 'RETURNED',
            'updatedAt' => $this->at('+2 hours'),
        ])])->assertOk()->assertJsonPath('results.0.status', 'APPLIED');

        $this->assertSame('RETURNED', BorrowingTransaction::find($id)->status);
    }

    public function test_a_request_can_be_filed_against_an_issued_asset(): void
    {
        // REQUESTED is not an active loan; it holds nothing, so a manager may
        // still record the ask and reject it themselves.
        Equipment::find($this->equipmentId)->forceFill(['status' => 'ISSUED'])->save();

        $id = (string) Str::uuid();
        $this->push([
            $this->loan($id, $this->at('+1 hour'), $this->at('+30 days'), $this->at('+39 days'), 'REQUESTED'),
        ])->assertOk()->assertJsonPath('results.0.status', 'APPLIED');
    }

    public function test_issued_is_a_legal_equipment_status(): void
    {
        $this->push([$this->change('equipment', 'UPDATE', $this->equipmentId, [
            'id' => $this->equipmentId,
            'assetTag' => 'ICT-TEST-0001',
            'modelName' => 'ThinkPad T14',
            'specifications' => [],
            'status' => 'ISSUED',
            'updatedAt' => $this->at('+1 hour'),
        ])])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'APPLIED')
            ->assertJsonPath('results.0.document.status', 'ISSUED');
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

        // A dropped stale write is not an activity: nothing changed.
        $this->assertSame(0, Activity::where('subject_id', $id)->count());
    }

    public function test_rest_endpoints_expose_the_same_data(): void
    {
        $this->getJson('/api/equipment')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/categories')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/stats')->assertOk()->assertJsonPath('equipment.total', 1);
        $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'ok');
    }
}
