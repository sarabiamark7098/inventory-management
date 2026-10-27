<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\Category;
use App\Models\Equipment;
use App\Models\Office;
use App\Models\User;
use App\Services\SyncService;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Bulk CSV import, one profile per kind of ICT equipment.
 *
 * The cases that matter here are the ones that come from a real spreadsheet
 * rather than a tidy fixture: the source workbook's misspelt `Date Aquired`
 * header, its habit of packing a property number and a serial into one cell,
 * and the `N/A` placeholders that would otherwise take a unique index and
 * reject every row after the first.
 */
class ImportTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $admin;

    private Office $division;

    private Office $section;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::create([
            'name' => 'Inventory Manager',
            'email' => 'manager@ict.test',
            'role' => Role::MANAGER,
            'is_active' => true,
        ]);

        $this->admin = User::create([
            'name' => 'Property Officer',
            'email' => 'admin@ict.test',
            'role' => Role::ADMIN,
            'is_active' => true,
        ]);

        $this->division = Office::create([
            'id' => (string) Str::uuid(),
            'code' => 'XI-FO-PPD',
            'name' => 'FO Policy and Plans Division',
        ]);

        $this->section = Office::create([
            'id' => (string) Str::uuid(),
            'code' => 'XI-FO-PPD-ICT',
            'name' => 'FO Information & Planning Communication Technology Section',
            'division_id' => $this->division->id,
        ]);

        // The importer resolves categories by the seeder's fixed ids.
        $this->seed(CategorySeeder::class);
    }

    private function csv(string $contents, string $name = 'import.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    private function preview(string $contents, string $profile = 'laptop', array $defaults = [])
    {
        return $this->actingAs($this->manager, 'sanctum')
            ->post("/api/import/{$profile}/preview", ['file' => $this->csv($contents)] + $defaults);
    }

    private function commit(string $contents, string $profile = 'laptop', array $defaults = [])
    {
        return $this->actingAs($this->manager, 'sanctum')
            ->post("/api/import/{$profile}", ['file' => $this->csv($contents)] + $defaults);
    }

    /** A minimal laptop row: every required column and nothing else. */
    private function laptopCsv(array $overrides = []): string
    {
        $row = array_merge([
            'Property Number' => 'PN-0001',
            'Date Acquired' => '2026-02-14',
            'Model' => 'TravelMate P216',
            'Division' => 'XI-FO-PPD',
            'PAR Issued To' => 'Ana R. Cruz',
        ], $overrides);

        return implode(',', array_keys($row))."\n".implode(',', array_values($row))."\n";
    }

    /* ------------------------------ profiles ----------------------------- */

    public function test_the_profiles_are_listed_with_their_columns(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/import/profiles')
            ->assertOk();

        $names = array_column($response->json('data'), 'name');
        $this->assertSame(['desktop', 'laptop', 'tablet'], $names);

        $desktop = collect($response->json('data'))->firstWhere('name', 'desktop');
        $labels = array_column($desktop['columns'], 'label');

        $this->assertContains('Property Number', $labels);
        $this->assertContains('Monitor Serial Number', $labels);
        // Tablets record a charger; desktops do not.
        $this->assertNotContains('Charger Serial Number', $labels);
    }

    public function test_each_profile_targets_a_category_that_exists(): void
    {
        foreach ($this->actingAs($this->manager, 'sanctum')->getJson('/api/import/profiles')->json('data') as $profile) {
            $this->assertNotNull(
                Category::find($profile['categoryId']),
                "the {$profile['name']} profile points at a category that is not seeded",
            );
        }
    }

    public function test_an_unknown_profile_is_a_404(): void
    {
        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/import/printer/template')
            ->assertStatus(404)
            ->assertJsonPath('available', ['desktop', 'laptop', 'tablet']);
    }

    /* ------------------------------ templates ---------------------------- */

    public function test_the_template_carries_the_headers_the_importer_accepts(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->get('/api/import/laptop/template')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        // Excel reads a UTF-8 CSV in the system code page without this.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("\r\n", $csv);

        $header = explode("\r\n", substr($csv, 3))[0];
        $this->assertStringContainsString('Property Number', $header);
        $this->assertStringContainsString('Date Acquired', $header);

        // The ambiguous combined column is accepted but never suggested.
        $this->assertStringNotContainsString('PROPERTY NO / SN No.', $header);
    }

    public function test_the_template_round_trips_through_its_own_importer(): void
    {
        $csv = $this->actingAs($this->manager, 'sanctum')
            ->get('/api/import/laptop/template')->streamedContent();

        // The sample rows leave Division blank, so supply it the way the form does.
        $response = $this->preview($csv, 'laptop', ['division' => 'XI-FO-PPD'])->assertOk();

        $this->assertSame(2, $response->json('summary.total'));
        $this->assertSame(2, $response->json('summary.ready'), json_encode($response->json('rows')));
        $this->assertSame([], $response->json('headers.unrecognised'));
    }

    public function test_headers_only_leaves_out_the_examples(): void
    {
        $csv = $this->actingAs($this->manager, 'sanctum')
            ->get('/api/import/laptop/template?headersOnly=1')->streamedContent();

        $this->assertSame(1, count(array_filter(explode("\r\n", trim(substr($csv, 3))))));
    }

    /* --------------------------- header matching ------------------------- */

    public function test_columns_are_matched_by_name_not_position(): void
    {
        $csv = "PAR Issued To,Model,Division,Date Acquired,Property Number\n"
            ."Ana R. Cruz,TravelMate P216,XI-FO-PPD,2026-02-14,PN-0001\n";

        $this->preview($csv)->assertOk()->assertJsonPath('summary.ready', 1);
    }

    public function test_a_missing_required_column_fails_the_whole_file_once(): void
    {
        $csv = "Property Number,Model,Division\nPN-0001,TravelMate,XI-FO-PPD\n";

        $response = $this->preview($csv)->assertStatus(422);

        $this->assertStringContainsString('Date Acquired', $response->json('fatal'));
        $this->assertStringContainsString('PAR Issued To', $response->json('fatal'));
        // One message about the header, not one per row.
        $this->assertSame([], $response->json('rows'));
    }

    public function test_unrecognised_columns_are_reported_rather_than_ignored_silently(): void
    {
        $csv = $this->laptopCsv(['Blood Type' => 'O+']);

        $response = $this->preview($csv)->assertOk();

        $this->assertSame(1, $response->json('summary.ready'));
        $this->assertSame('Blood Type', $response->json('headers.unrecognised.0.header'));
    }

    public function test_a_repeated_header_keeps_the_first_and_reports_the_second(): void
    {
        // The workbook has both `Remarks` and a later `REMARKS` for peripherals.
        $csv = "Property Number,Date Acquired,Model,Division,PAR Issued To,Remarks,REMARKS\n"
            ."PN-0001,2026-02-14,TravelMate,XI-FO-PPD,Ana R. Cruz,kept,dropped\n";

        $response = $this->preview($csv)->assertOk();

        $this->assertSame('Remarks', $response->json('headers.duplicate.0.column'));
        $this->assertSame('kept', $response->json('rows.0.payload.remarks'));
    }

    /* ----------------------- the real workbook's shape ------------------- */

    public function test_the_source_workbooks_own_header_row_imports(): void
    {
        // Verbatim from "DAVAO ORIENTAL LAPTOP", including the misspelt date
        // header and the combined property/serial column.
        $csv = "Site Name,PAR / Assigned to,Sub-PAR / Actual User,ID Number,AD USER,Position,"
            ."Program/ Section,Computer/Host Name,New Host Name,Brand,Model,PROPERTY NO / SN No.,"
            ."Date Aquired,Operating System,Service Status,Remarks\n"
            .'"POO ORIENTAL","Ana R. Cruz","Norberto S. Cajes II","6789-11","ncajes",'
            .'"Cluster Beneficiary Data Officer","4Ps","114PSmabasa","11-PPP-LT021","ACER",'
            ."\"TravelMate P216-51\",\"FO11-CO2-24-0021 / NXB13SP001\",\"2024-05-18\",\"Windows\","
            ."\"Serviceable\",\"With extra monitor\"\n";

        $response = $this->preview($csv, 'laptop', ['division' => 'XI-FO-PPD'])->assertOk();

        $this->assertSame(1, $response->json('summary.ready'), json_encode($response->json('rows.0.errors')));

        $payload = $response->json('rows.0.payload');
        $this->assertSame('FO11-CO2-24-0021', $payload['propertyNumber']);
        $this->assertSame('NXB13SP001', $payload['serialNumber']);
        $this->assertSame('2024-05-18', $payload['dateAcquired']);
        $this->assertSame('Ana R. Cruz', $payload['parIssuedTo']);
        $this->assertSame('Norberto S. Cajes II', $payload['actualUser']);
        $this->assertSame('POO ORIENTAL', $payload['location']);
        $this->assertSame('ncajes', $payload['adUsername']);
        // "Serviceable" is the register's word for GOOD.
        $this->assertSame('GOOD', $payload['condition']);
        $this->assertSame('4Ps', $payload['specifications']['program']);
        $this->assertSame('11-PPP-LT021', $payload['specifications']['newHostName']);
    }

    public function test_a_combined_column_with_no_separator_is_flagged_for_review(): void
    {
        $csv = "PROPERTY NO / SN No.,Date Aquired,Model,PAR / Assigned to\n"
            ."DTVMASP002649010923000,2024-01-02,Veriton X4110G,Ana R. Cruz\n";

        $response = $this->preview($csv, 'laptop', ['division' => 'XI-FO-PPD'])->assertOk();

        $this->assertSame('DTVMASP002649010923000', $response->json('rows.0.payload.propertyNumber'));
        $this->assertNull($response->json('rows.0.payload.serialNumber'));
        $this->assertStringContainsString(
            'no "/" separator',
            $response->json('rows.0.warnings.0'),
        );
    }

    public function test_a_dedicated_column_wins_over_the_combined_one(): void
    {
        $csv = "Property Number,Serial Number,PROPERTY NO / SN No.,Date Acquired,Model,PAR Issued To\n"
            ."PN-REAL,SN-REAL,PN-COMBINED / SN-COMBINED,2026-02-14,TravelMate,Ana R. Cruz\n";

        $response = $this->preview($csv, 'laptop', ['division' => 'XI-FO-PPD'])->assertOk();

        $this->assertSame('PN-REAL', $response->json('rows.0.payload.propertyNumber'));
        $this->assertSame('SN-REAL', $response->json('rows.0.payload.serialNumber'));
    }

    /* ---------------------------- placeholders --------------------------- */

    public function test_na_in_an_identifier_becomes_empty_rather_than_a_literal(): void
    {
        // Two rows with a literal "N/A" serial would collide on the unique index.
        $csv = "Property Number,Serial Number,Date Acquired,Model,PAR Issued To\n"
            ."PN-0001,N/A,2026-02-14,TravelMate,Ana R. Cruz\n"
            ."PN-0002,NONE,2026-02-14,TravelMate,Ana R. Cruz\n";

        $response = $this->commit($csv, 'laptop', ['division' => 'XI-FO-PPD'])->assertOk();

        $this->assertSame(2, $response->json('summary.applied'), json_encode($response->json('rows')));
        $this->assertNull(Equipment::where('asset_tag', $response->json('rows.0.assetTag'))->value('serial_number'));
    }

    public function test_a_placeholder_is_not_stripped_from_a_descriptive_column(): void
    {
        // The older sheets answer XDR with "YES - 8.7", agent and version in
        // one cell. That column is free text so the version is not lost.
        $csv = "Property Number,Date Acquired,Model,PAR Issued To,With XDR\n"
            ."PN-0001,2026-02-14,TravelMate,Ana R. Cruz,YES - 8.7\n";

        $response = $this->preview($csv, 'laptop', ['division' => 'XI-FO-PPD'])->assertOk();

        $this->assertSame('YES - 8.7', $response->json('rows.0.payload.specifications.xdr'));
    }

    /* ---------------------------- yes/no columns -------------------------- */

    public function test_yes_no_columns_settle_on_one_spelling(): void
    {
        // The sheets use every case and abbreviation; the equipment form shows
        // a Yes/No dropdown, and a value outside it renders blank there.
        $csv = "Property Number,Date Acquired,Model,PAR Issued To,XDR,Sentinel,Keyboard,Mouse,Working Charger,Laptop Warranty\n"
            ."PN-0001,2026-02-14,TravelMate,Ana R. Cruz,YES,no,Y,TRUE,yes,N\n";

        $specs = $this->preview($csv, 'laptop', ['division' => 'XI-FO-PPD'])
            ->assertOk()
            ->json('rows.0.payload.specifications');

        $this->assertSame('Yes', $specs['xdr']);
        $this->assertSame('No', $specs['sentinel']);
        $this->assertSame('Yes', $specs['keyboard']);
        $this->assertSame('Yes', $specs['mouse']);
        $this->assertSame('Yes', $specs['workingCharger']);
        $this->assertSame('No', $specs['warranty']);
    }

    public function test_a_yes_no_column_keeps_a_value_it_does_not_recognise(): void
    {
        $csv = "Property Number,Date Acquired,Model,PAR Issued To,XDR\n"
            ."PN-0001,2026-02-14,TravelMate,Ana R. Cruz,YES - 8.7\n";

        $response = $this->preview($csv, 'laptop', ['division' => 'XI-FO-PPD'])->assertOk();

        $this->assertSame('YES - 8.7', $response->json('rows.0.payload.specifications.xdr'));
    }

    public function test_the_split_peripheral_columns_reach_the_records_they_describe(): void
    {
        $csv = 'Property Number,Date Acquired,Model,PAR Issued To,Serial Number,Monitor Serial Number,'
            ."Printer,Printer Property,Printer Serial,Scanner,Scanner Property,Scanner Serial,XDR,XDR Version\n"
            .'PN-0001,2026-02-14,Veriton,Ana R. Cruz,SN-1,MON-1,'
            ."EPSON M1170,PR-PROP-1,XC6R003098,BROTHER ADS-4300N,SC-PROP-1,E81467C3X115282,Yes,8.7\n";

        $rows = collect(
            $this->preview($csv, 'desktop', ['division' => 'XI-FO-PPD'])->assertOk()->json('rows')
        );

        // The machine keeps what describes the machine...
        $this->assertSame(
            '8.7',
            $rows->firstWhere('derivedFrom', null)['payload']['specifications']['xdrVersion'],
        );

        // ...and each peripheral column reaches the record it describes.
        $printer = $rows->firstWhere(fn ($r) => ($r['payload']['propertyNumber'] ?? null) === 'PR-PROP-1');
        $this->assertSame('EPSON M1170', $printer['payload']['modelName']);
        $this->assertSame('XC6R003098', $printer['payload']['serialNumber']);

        $scanner = $rows->firstWhere(fn ($r) => ($r['payload']['propertyNumber'] ?? null) === 'SC-PROP-1');
        $this->assertSame('BROTHER ADS-4300N', $scanner['payload']['modelName']);
        $this->assertSame('E81467C3X115282', $scanner['payload']['serialNumber']);
    }

    public function test_every_specification_the_import_writes_is_shown_by_its_category(): void
    {
        /*
         * The equipment form renders only the fields its category declares, so
         * a spec written under a key no category names would be stored and
         * searchable but unreadable on the equipment screen. This is the check
         * that the two lists have not drifted apart.
         */
        foreach ($this->actingAs($this->manager, 'sanctum')->getJson('/api/import/profiles')->json('data') as $profile) {
            $declared = array_column(
                Category::findOrFail($profile['categoryId'])->custom_fields['fields'] ?? [],
                'key',
            );

            // A `printer:` or `scanner:` target belongs to the record derived
            // from the row, so it is checked against that category instead.
            $elsewhere = [
                'printer:spec:' => CategorySeeder::PRINTERS,
                'scanner:spec:' => CategorySeeder::SCANNERS,
            ];

            foreach ($profile['columns'] as $column) {
                foreach ($column['targets'] as $target) {
                    [$key, $owner] = match (true) {
                        str_starts_with($target, 'spec:') => [substr($target, 5), $profile['categoryId']],
                        str_starts_with($target, 'printer:spec:') => [substr($target, 13), $elsewhere['printer:spec:']],
                        str_starts_with($target, 'scanner:spec:') => [substr($target, 13), $elsewhere['scanner:spec:']],
                        default => [null, null],
                    };

                    if ($key === null) {
                        continue;
                    }

                    $keys = $owner === $profile['categoryId']
                        ? $declared
                        : array_column(Category::findOrFail($owner)->custom_fields['fields'] ?? [], 'key');

                    $this->assertContains(
                        $key,
                        $keys,
                        "{$profile['label']} imports \"{$column['label']}\" into specifications.{$key}, ".
                        'but the category that holds it does not declare that field, so the equipment form cannot show it.',
                    );
                }
            }
        }
    }

    /* -------------------------------- dates ------------------------------ */

    public function test_an_ambiguous_slash_date_is_refused_rather_than_guessed(): void
    {
        $response = $this->preview($this->laptopCsv(['Date Acquired' => '03/04/2026']))->assertOk();

        $this->assertSame('ERROR', $response->json('rows.0.status'));
        $this->assertStringContainsString('YYYY-MM-DD', $response->json('rows.0.errors.0'));
    }

    public function test_an_unambiguous_slash_date_is_accepted(): void
    {
        $response = $this->preview($this->laptopCsv(['Date Acquired' => '25/12/2025']))->assertOk();

        $this->assertSame('2025-12-25', $response->json('rows.0.payload.dateAcquired'));
    }

    public function test_an_excel_day_count_is_read_as_a_date(): void
    {
        // 45678 is what Excel stores when the cell was formatted as a number.
        $response = $this->preview($this->laptopCsv(['Date Acquired' => '45678']))->assertOk();

        $this->assertSame('2025-01-21', $response->json('rows.0.payload.dateAcquired'));
    }

    /* ----------------------------- uniqueness ---------------------------- */

    public function test_a_property_number_already_in_the_register_is_reported_not_written(): void
    {
        Equipment::create([
            'id' => (string) Str::uuid(),
            'asset_tag' => 'ICT-LT-9000',
            'property_number' => 'PN-0001',
            'model_name' => 'Existing',
            'division_id' => $this->division->id,
            'par_issued_to' => 'Someone',
            'specifications' => [],
        ]);

        $response = $this->commit($this->laptopCsv())->assertOk();

        $this->assertSame('DUPLICATE', $response->json('rows.0.status'));
        $this->assertSame(0, $response->json('summary.applied'));
        $this->assertStringContainsString('ICT-LT-9000', $response->json('rows.0.errors.0'));
    }

    public function test_an_archived_assets_identifiers_still_block_an_import(): void
    {
        Equipment::create([
            'id' => (string) Str::uuid(),
            'asset_tag' => 'ICT-LT-9001',
            'property_number' => 'PN-0001',
            'model_name' => 'Archived',
            'division_id' => $this->division->id,
            'par_issued_to' => 'Someone',
            'specifications' => [],
            'deleted_at' => now(),
        ]);

        $response = $this->preview($this->laptopCsv())->assertOk();

        $this->assertSame('DUPLICATE', $response->json('rows.0.status'));
        $this->assertStringContainsString('archived', $response->json('rows.0.errors.0'));
    }

    public function test_two_rows_in_one_file_cannot_claim_the_same_property_number(): void
    {
        $csv = "Property Number,Date Acquired,Model,PAR Issued To\n"
            ."PN-DUP,2026-02-14,TravelMate,Ana R. Cruz\n"
            ."PN-DUP,2026-02-14,Latitude,Ana R. Cruz\n";

        $response = $this->commit($csv, 'laptop', ['division' => 'XI-FO-PPD'])->assertOk();

        $this->assertSame('IMPORTED', $response->json('rows.0.status'));
        $this->assertSame('DUPLICATE', $response->json('rows.1.status'));
        $this->assertStringContainsString('line 2 of this file', $response->json('rows.1.errors.0'));
        $this->assertSame(1, $response->json('summary.applied'));
    }

    public function test_a_rejected_row_does_not_reserve_its_identifiers(): void
    {
        // Row 1 fails on a bad date. Row 2 uses the same serial legitimately and
        // must not be told it duplicates a row that was never written.
        $csv = "Property Number,Serial Number,Date Acquired,Model,PAR Issued To\n"
            ."PN-0001,SN-SHARED,03/04/2026,TravelMate,Ana R. Cruz\n"
            ."PN-0002,SN-SHARED,2026-02-14,Latitude,Ana R. Cruz\n";

        $response = $this->preview($csv, 'laptop', ['division' => 'XI-FO-PPD'])->assertOk();

        $this->assertSame('ERROR', $response->json('rows.0.status'));
        $this->assertSame('READY', $response->json('rows.1.status'), json_encode($response->json('rows.1.errors')));
    }

    /* ---------------------------- asset tagging -------------------------- */

    public function test_a_blank_asset_tag_is_generated_from_the_profile_prefix(): void
    {
        $csv = "Property Number,Date Acquired,Model,PAR Issued To\n"
            ."PN-0001,2026-02-14,TravelMate,Ana R. Cruz\n"
            ."PN-0002,2026-02-14,Latitude,Ana R. Cruz\n";

        $response = $this->commit($csv, 'laptop', ['division' => 'XI-FO-PPD'])->assertOk();

        $this->assertSame('ICT-LT-0001', $response->json('rows.0.assetTag'));
        $this->assertSame('ICT-LT-0002', $response->json('rows.1.assetTag'));
        $this->assertTrue($response->json('rows.0.assetTagGenerated'));
    }

    public function test_generated_tags_continue_from_the_highest_already_issued(): void
    {
        Equipment::create([
            'id' => (string) Str::uuid(),
            'asset_tag' => 'ICT-LT-0041',
            'property_number' => 'PN-EXISTING',
            'model_name' => 'Existing',
            'division_id' => $this->division->id,
            'par_issued_to' => 'Someone',
            'specifications' => [],
        ]);

        $response = $this->commit($this->laptopCsv())->assertOk();

        $this->assertSame('ICT-LT-0042', $response->json('rows.0.assetTag'));
    }

    public function test_a_supplied_asset_tag_is_kept(): void
    {
        $response = $this->commit($this->laptopCsv(['Asset Tag' => 'ICT-LT-7777']))->assertOk();

        $this->assertSame('ICT-LT-7777', $response->json('rows.0.assetTag'));
        $this->assertFalse($response->json('rows.0.assetTagGenerated'));
    }

    /* ------------------------------- offices ----------------------------- */

    public function test_a_division_resolves_by_code_or_by_name(): void
    {
        foreach (['XI-FO-PPD', 'FO Policy and Plans Division', 'policy and plans division'] as $i => $value) {
            $response = $this->preview($this->laptopCsv([
                'Property Number' => "PN-100{$i}",
                'Division' => $value,
            ]))->assertOk();

            $this->assertSame(
                'FO Policy and Plans Division',
                $response->json('rows.0.office.division'),
                "\"{$value}\" did not resolve",
            );
        }
    }

    public function test_an_unknown_division_is_an_error_on_that_row(): void
    {
        $response = $this->preview($this->laptopCsv(['Division' => 'Department of Mysteries']))->assertOk();

        $this->assertSame('ERROR', $response->json('rows.0.status'));
        $this->assertStringContainsString('not in the office register', $response->json('rows.0.errors.0'));
    }

    public function test_a_section_from_another_division_is_refused(): void
    {
        $other = Office::create([
            'id' => (string) Str::uuid(),
            'code' => 'XI-FO-ORD',
            'name' => 'FO Office of the Regional Director',
        ]);
        Office::create([
            'id' => (string) Str::uuid(),
            'code' => 'XI-FO-SMU',
            'name' => 'FO Social Marketing Unit',
            'division_id' => $other->id,
        ]);

        $response = $this->preview($this->laptopCsv(['Section' => 'XI-FO-SMU']))->assertOk();

        $this->assertSame('ERROR', $response->json('rows.0.status'));
        $this->assertStringContainsString('is not a section of', $response->json('rows.0.errors.0'));
    }

    public function test_no_division_column_and_no_default_fails_the_file(): void
    {
        $csv = "Property Number,Date Acquired,Model,PAR Issued To\nPN-0001,2026-02-14,TravelMate,Ana R. Cruz\n";

        $this->preview($csv)
            ->assertStatus(422)
            ->assertJsonPath('headers.missingRequired', ['Division']);
    }

    public function test_a_blank_division_cell_with_no_default_is_a_row_error(): void
    {
        $csv = "Property Number,Date Acquired,Model,PAR Issued To,Division\n"
            ."PN-0001,2026-02-14,TravelMate,Ana R. Cruz,\n";

        $response = $this->preview($csv)->assertOk();

        $this->assertSame('ERROR', $response->json('rows.0.status'));
        $this->assertStringContainsString('A division is required', $response->json('rows.0.errors.0'));
    }

    public function test_the_form_default_fills_only_the_rows_that_left_it_blank(): void
    {
        $other = Office::create([
            'id' => (string) Str::uuid(),
            'code' => 'XI-FO-ORD',
            'name' => 'FO Office of the Regional Director',
        ]);

        $csv = "Property Number,Date Acquired,Model,PAR Issued To,Division\n"
            ."PN-0001,2026-02-14,TravelMate,Ana R. Cruz,\n"
            ."PN-0002,2026-02-14,Latitude,Ana R. Cruz,XI-FO-ORD\n";

        $response = $this->preview($csv, 'laptop', ['division' => 'XI-FO-PPD'])->assertOk();

        $this->assertSame('FO Policy and Plans Division', $response->json('rows.0.office.division'));
        $this->assertSame($other->name, $response->json('rows.1.office.division'));
    }

    /* ------------------------- per-profile validation -------------------- */

    public function test_a_desktop_needs_both_serial_numbers(): void
    {
        $csv = "Property Number,Date Acquired,Model,PAR Issued To,Serial Number\n"
            ."PN-0001,2026-02-14,Veriton X4710G,Ana R. Cruz,SN-UNIT\n";

        $response = $this->preview($csv, 'desktop', ['division' => 'XI-FO-PPD'])->assertOk();

        $this->assertSame('ERROR', $response->json('rows.0.status'));
        $this->assertStringContainsString('monitor serial number', $response->json('rows.0.errors.0'));
    }

    public function test_a_desktop_with_both_serials_imports_under_the_desktop_category(): void
    {
        $csv = "Property Number,Date Acquired,Model,PAR Issued To,Serial Number,Monitor Serial Number\n"
            ."PN-0001,2026-02-14,Veriton X4710G,Ana R. Cruz,SN-UNIT,SN-MONITOR\n";

        $response = $this->commit($csv, 'desktop', ['division' => 'XI-FO-PPD'])->assertOk();

        $this->assertSame(1, $response->json('summary.applied'), json_encode($response->json('rows')));
        $asset = Equipment::where('property_number', 'PN-0001')->firstOrFail();
        $this->assertSame(CategorySeeder::DESKTOPS, $asset->category_id);
        $this->assertSame('SN-MONITOR', $asset->monitor_serial_number);
    }

    public function test_a_tablet_does_not_accept_the_desktop_only_columns(): void
    {
        $csv = "Property Number,Date Acquired,Model,PAR Issued To,Host Name,Charger Serial Number\n"
            ."PN-0001,2026-05-14,Galaxy Tab S10,Ana R. Cruz,SHOULD-NOT-MATCH,CHG-1\n";

        $response = $this->preview($csv, 'tablet', ['division' => 'XI-FO-PPD'])->assertOk();

        $this->assertSame(['Host Name'], array_column($response->json('headers.unrecognised'), 'header'));
        $this->assertSame('CHG-1', $response->json('rows.0.payload.specifications.chargerSerial'));
    }

    /* --------------------- spreadsheet padding and hints ------------------ */

    public function test_excel_padding_rows_are_reported_as_blank_not_as_failures(): void
    {
        // A trailing row with nothing but a leftover FALSE in a Printer column
        // is what Excel leaves behind; the source sheets carry ~900 of them.
        $csv = "Property Number,Date Acquired,Model,PAR Issued To,Printer,Scanner\n"
            ."PN-0001,2026-02-14,TravelMate,Ana R. Cruz,,\n"
            .",,,,FALSE,FALSE\n"
            .",,,,FALSE,FALSE\n";

        $response = $this->preview($csv, 'laptop', ['division' => 'XI-FO-PPD'])->assertOk();

        $this->assertSame(1, $response->json('summary.ready'));
        $this->assertSame(2, $response->json('summary.blank'));
        $this->assertSame(0, $response->json('summary.errors'));

        // Counted, but never listed: there is nothing to do about a blank row,
        // and a real sheet carries hundreds of them.
        $this->assertSame([2], array_column($response->json('rows'), 'line'));
    }

    public function test_a_file_whose_par_column_is_empty_throughout_gets_one_actionable_hint(): void
    {
        $csv = "Property Number,Date Acquired,Model,PAR / Assigned to,Sub-PAR / Actual User\n"
            ."PN-0001,2026-02-14,TravelMate,,Novem Grace L. Tagalog\n"
            ."PN-0002,2026-02-14,Latitude,,Shenette E. Esperat\n";

        $response = $this->preview($csv, 'laptop', ['division' => 'XI-FO-PPD'])->assertOk();

        $this->assertSame(0, $response->json('summary.ready'));
        $this->assertStringContainsString('Sub-PAR / Actual User', $response->json('hint'));
        $this->assertStringContainsString('Use Actual User', $response->json('hint'));
    }

    public function test_the_par_fallback_is_opt_in_and_says_so_on_every_row_it_touches(): void
    {
        // The real sheets carry the PAR column and leave every cell in it empty.
        $csv = "Property Number,Date Acquired,Model,PAR / Assigned to,Sub-PAR / Actual User\n"
            ."PN-0001,2026-02-14,TravelMate,,Novem Grace L. Tagalog\n";

        $without = $this->preview($csv, 'laptop', ['division' => 'XI-FO-PPD'])->assertOk();
        $this->assertSame('ERROR', $without->json('rows.0.status'));

        $with = $this->preview($csv, 'laptop', [
            'division' => 'XI-FO-PPD',
            'parFallback' => '1',
        ])->assertOk();

        $this->assertSame('READY', $with->json('rows.0.status'));
        $this->assertSame('Novem Grace L. Tagalog', $with->json('rows.0.payload.parIssuedTo'));
        $this->assertSame('Novem Grace L. Tagalog', $with->json('rows.0.payload.actualUser'));
        $this->assertStringContainsString('Correct it if someone else holds the PAR', $with->json('rows.0.warnings.0'));
        // The hint has been acted on, so it stops being offered.
        $this->assertNull($with->json('hint'));
    }

    public function test_the_fallback_also_covers_a_file_with_no_par_column_at_all(): void
    {
        $csv = "Property Number,Date Acquired,Model,Actual User\n"
            ."PN-0001,2026-02-14,TravelMate,Novem Grace L. Tagalog\n";

        // Without it, a missing required column is fatal for the file.
        $this->preview($csv, 'laptop', ['division' => 'XI-FO-PPD'])
            ->assertStatus(422)
            ->assertJsonPath('headers.missingRequired', ['PAR Issued To']);

        $this->preview($csv, 'laptop', ['division' => 'XI-FO-PPD', 'parFallback' => '1'])
            ->assertOk()
            ->assertJsonPath('rows.0.status', 'READY')
            ->assertJsonPath('rows.0.payload.parIssuedTo', 'Novem Grace L. Tagalog');
    }

    public function test_the_fallback_never_overwrites_a_par_holder_that_is_present(): void
    {
        $csv = "Property Number,Date Acquired,Model,PAR Issued To,Actual User\n"
            ."PN-0001,2026-02-14,TravelMate,Ana R. Cruz,Someone Else\n";

        $response = $this->preview($csv, 'laptop', [
            'division' => 'XI-FO-PPD',
            'parFallback' => '1',
        ])->assertOk();

        $this->assertSame('Ana R. Cruz', $response->json('rows.0.payload.parIssuedTo'));
        $this->assertSame([], $response->json('rows.0.warnings'));
    }

    public function test_a_lone_monitor_value_is_read_as_a_serial_not_a_property_number(): void
    {
        // The workbook's monitor column is headed "property / serial" but holds
        // a bare serial, so a desktop would otherwise fail for want of one.
        $csv = "PROPERTY NO / SN No.,Monitor Property No. / SN No.,Date Aquired,Model,PAR Issued To\n"
            ."FO11-CO1-24-0012 / DTL9X8,MMLY7SS02264908B0D85BA,2024-01-02,Veriton X4710G,Ana R. Cruz\n";

        $response = $this->preview($csv, 'desktop', ['division' => 'XI-FO-PPD'])->assertOk();

        $this->assertSame('READY', $response->json('rows.0.status'), json_encode($response->json('rows.0.errors')));
        $this->assertSame('MMLY7SS02264908B0D85BA', $response->json('rows.0.payload.monitorSerialNumber'));
        $this->assertNull($response->json('rows.0.payload.specifications.monitorPropertyNumber'));
    }

    public function test_a_shared_serial_between_unit_and_monitor_is_caught(): void
    {
        // Four rows of the real desktop sheet do this.
        $csv = "Property Number,Serial Number,Monitor Serial Number,Date Acquired,Model,PAR Issued To\n"
            ."PN-0001,SN-SAME,SN-SAME,2026-02-14,Veriton,Ana R. Cruz\n";

        $response = $this->preview($csv, 'desktop', ['division' => 'XI-FO-PPD'])->assertOk();

        $this->assertSame('ERROR', $response->json('rows.0.status'));
        $this->assertContains(
            'The system unit and monitor cannot share a serial number.',
            $response->json('rows.0.errors'),
        );
    }

    /* -------------------------- preview vs commit ------------------------ */

    public function test_a_preview_writes_nothing(): void
    {
        $this->preview($this->laptopCsv())->assertOk()->assertJsonPath('summary.ready', 1);

        $this->assertDatabaseCount('equipment', 0);
        $this->assertFalse(Activity::query()->where('log_name', 'equipment')->exists());
    }

    public function test_a_commit_writes_and_is_recorded_in_the_activity_log(): void
    {
        $response = $this->commit($this->laptopCsv())->assertOk();

        $this->assertTrue($response->json('committed'));
        $this->assertSame(1, $response->json('summary.applied'));

        $asset = Equipment::where('property_number', 'PN-0001')->firstOrFail();
        $this->assertSame($this->division->id, $asset->division_id);
        $this->assertSame(CategorySeeder::LAPTOPS, $asset->category_id);

        $activity = Activity::query()
            ->where('log_name', 'equipment')
            ->where('subject_id', $asset->id)
            ->firstOrFail();

        $this->assertSame('INSERT', $activity->event);
        $this->assertSame($this->manager->id, $activity->causer_id);
    }

    public function test_the_default_status_from_the_form_is_applied(): void
    {
        $response = $this->commit($this->laptopCsv(), 'laptop', ['status' => 'ISSUED'])->assertOk();

        $this->assertSame('ISSUED', Equipment::where('property_number', 'PN-0001')->value('status'));
        $this->assertSame('ISSUED', $response->json('rows.0.payload.status'));
    }

    public function test_a_row_level_status_overrides_the_form_default(): void
    {
        $csv = $this->laptopCsv(['Status' => 'MAINTENANCE']);

        $this->commit($csv, 'laptop', ['status' => 'ISSUED'])->assertOk();

        $this->assertSame('MAINTENANCE', Equipment::where('property_number', 'PN-0001')->value('status'));
    }

    /* ------------------------------ file shapes -------------------------- */

    public function test_a_semicolon_delimited_file_is_read(): void
    {
        $csv = "Property Number;Date Acquired;Model;PAR Issued To\nPN-0001;2026-02-14;TravelMate;Ana R. Cruz\n";

        $response = $this->preview($csv, 'laptop', ['division' => 'XI-FO-PPD'])->assertOk();

        $this->assertSame(';', $response->json('delimiter'));
        $this->assertSame(1, $response->json('summary.ready'));
    }

    public function test_a_byte_order_mark_does_not_break_the_first_column(): void
    {
        $response = $this->preview("\xEF\xBB\xBF".$this->laptopCsv())->assertOk();

        $this->assertSame(1, $response->json('summary.ready'));
        $this->assertSame([], $response->json('headers.unrecognised'));
    }

    public function test_a_windows_1252_file_keeps_its_accented_names(): void
    {
        $csv = mb_convert_encoding(
            $this->laptopCsv(['PAR Issued To' => 'José Peñaranda']),
            'Windows-1252',
            'UTF-8',
        );

        $response = $this->preview($csv)->assertOk();

        $this->assertSame('José Peñaranda', $response->json('rows.0.payload.parIssuedTo'));
    }

    public function test_a_quoted_cell_containing_a_line_break_stays_one_row(): void
    {
        $csv = "Property Number,Date Acquired,Model,PAR Issued To,Remarks\n"
            ."PN-0001,2026-02-14,TravelMate,Ana R. Cruz,\"first line\nsecond line\"\n";

        $response = $this->preview($csv, 'laptop', ['division' => 'XI-FO-PPD'])->assertOk();

        $this->assertSame(1, $response->json('summary.total'));
        $this->assertStringContainsString("\n", $response->json('rows.0.payload.remarks'));
    }

    public function test_trailing_blank_rows_are_not_counted(): void
    {
        $response = $this->preview($this->laptopCsv()."\n,,,,\n\n")->assertOk();

        $this->assertSame(1, $response->json('summary.total'));
    }

    public function test_an_empty_file_says_so(): void
    {
        $this->preview("\n")
            ->assertStatus(422)
            ->assertJsonPath('message', 'That file is empty.');
    }

    public function test_an_xlsx_upload_is_refused_with_an_instruction(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->post('/api/import/laptop/preview', [
                'file' => UploadedFile::fake()->createWithContent('register.xlsx', 'PK binary'),
            ])
            ->assertStatus(422);

        $this->assertStringContainsString('CSV UTF-8', $response->json('message'));
    }

    /* ----------------------------- corrections --------------------------- */

    public function test_a_row_names_the_columns_that_need_correcting(): void
    {
        $csv = "Property Number,Date Acquired,Model,PAR Issued To,Division\n"
            .",03/04/2026,TravelMate,Ana R. Cruz,Department of Mysteries\n";

        $response = $this->preview($csv)->assertOk();

        $this->assertEqualsCanonicalizing(
            ['Division', 'Date Acquired', 'Property Number'],
            $response->json('rows.0.fix'),
        );
    }

    public function test_a_row_comes_back_with_its_cells_for_editing(): void
    {
        $response = $this->preview($this->laptopCsv())->assertOk();

        $cells = $response->json('rows.0.cells');

        $this->assertSame('PN-0001', $cells['Property Number']);
        $this->assertSame('TravelMate P216', $cells['Model']);
        // Columns the file does not have are offered as empty, not missing.
        $this->assertSame('', $cells['Province']);
    }

    public function test_a_correction_fixes_the_row_it_names(): void
    {
        $csv = "Property Number,Date Acquired,Model,PAR Issued To,Division\n"
            ."PN-0001,03/04/2026,TravelMate,Ana R. Cruz,XI-FO-PPD\n";

        $this->preview($csv)->assertOk()->assertJsonPath('rows.0.status', 'ERROR');

        $response = $this->preview($csv, 'laptop', [
            'corrections' => json_encode([2 => ['Date Acquired' => '2026-04-03']]),
        ])->assertOk();

        $this->assertSame('READY', $response->json('rows.0.status'));
        $this->assertSame('2026-04-03', $response->json('rows.0.payload.dateAcquired'));
        $this->assertSame(['Date Acquired' => '2026-04-03'], $response->json('rows.0.corrected'));
        // `cells` stays the file's own values, so the editor can tell a real
        // edit from a cell typed back to what it already said.
        $this->assertSame('03/04/2026', $response->json('rows.0.cells.Date Acquired'));
    }

    public function test_a_correction_is_revalidated_and_can_still_fail(): void
    {
        // The whole point: a correction replaces the cell, not the verdict.
        $csv = "Property Number,Date Acquired,Model,PAR Issued To,Division\n"
            ."PN-0001,03/04/2026,TravelMate,Ana R. Cruz,XI-FO-PPD\n";

        $response = $this->preview($csv, 'laptop', [
            'corrections' => json_encode([2 => ['Date Acquired' => 'tomorrow']]),
        ])->assertOk();

        $this->assertSame('ERROR', $response->json('rows.0.status'));
        $this->assertStringContainsString('YYYY-MM-DD', $response->json('rows.0.errors.0'));
    }

    public function test_a_correction_cannot_smuggle_past_a_uniqueness_rule(): void
    {
        Equipment::create([
            'id' => (string) Str::uuid(),
            'asset_tag' => 'ICT-LT-9000',
            'property_number' => 'PN-TAKEN',
            'model_name' => 'Existing',
            'division_id' => $this->division->id,
            'par_issued_to' => 'Someone',
            'specifications' => [],
        ]);

        $csv = "Property Number,Date Acquired,Model,PAR Issued To,Division\n"
            ."PN-0001,,TravelMate,Ana R. Cruz,XI-FO-PPD\n";

        $response = $this->commit($csv, 'laptop', [
            'corrections' => json_encode([2 => ['Date Acquired' => '2026-02-14', 'Property Number' => 'PN-TAKEN']]),
        ])->assertOk();

        $this->assertSame('DUPLICATE', $response->json('rows.0.status'));
        $this->assertSame(0, $response->json('summary.applied'));
    }

    public function test_a_correction_can_supply_a_column_the_file_does_not_have(): void
    {
        // The file has no Division column at all, which is otherwise fatal.
        $csv = "Property Number,Date Acquired,Model,PAR Issued To\n"
            ."PN-0001,2026-02-14,TravelMate,Ana R. Cruz\n";

        $this->preview($csv)->assertStatus(422);

        $response = $this->preview($csv, 'laptop', [
            'corrections' => json_encode([2 => ['Division' => 'XI-FO-PPD']]),
        ])->assertOk();

        $this->assertSame('READY', $response->json('rows.0.status'));
        $this->assertSame('FO Policy and Plans Division', $response->json('rows.0.office.division'));
    }

    public function test_corrections_survive_into_the_commit(): void
    {
        $csv = "Property Number,Date Acquired,Model,PAR Issued To,Division\n"
            ."PN-0001,not a date,Latitude,Ana R. Cruz,XI-FO-PPD\n";

        $response = $this->commit($csv, 'laptop', [
            'corrections' => json_encode([2 => ['Date Acquired' => '2025-12-25', 'Model' => 'Latitude 3540']]),
        ])->assertOk();

        $this->assertSame(1, $response->json('summary.applied'), json_encode($response->json('rows.0.errors')));

        $asset = Equipment::where('property_number', 'PN-0001')->firstOrFail();
        $this->assertSame('Latitude 3540', $asset->model_name);
        $this->assertSame('2025-12-25', $asset->date_acquired->toDateString());
    }

    public function test_a_correction_to_a_combined_column_is_split_the_same_way(): void
    {
        $csv = "PROPERTY NO / SN No.,Date Aquired,Model,PAR Issued To,Division\n"
            ."N/A,2024-01-02,Veriton,Ana R. Cruz,XI-FO-PPD\n";

        $response = $this->preview($csv, 'laptop', [
            'corrections' => json_encode([2 => ['PROPERTY NO / SN No.' => 'FO11-CO1-24-0012 / DTL9X8']]),
        ])->assertOk();

        $this->assertSame('FO11-CO1-24-0012', $response->json('rows.0.payload.propertyNumber'));
        $this->assertSame('DTL9X8', $response->json('rows.0.payload.serialNumber'));
    }

    public function test_corrections_that_are_not_line_keyed_cells_are_ignored(): void
    {
        $response = $this->preview($this->laptopCsv(), 'laptop', [
            'corrections' => json_encode(['nonsense', ['also' => 'nonsense']]),
        ])->assertOk();

        $this->assertSame(1, $response->json('summary.ready'));
    }

    public function test_unreadable_corrections_are_refused_rather_than_ignored(): void
    {
        $this->preview($this->laptopCsv(), 'laptop', ['corrections' => 'not json at all'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The corrections could not be read. Reload the page and preview the file again.');
    }

    /* -------------------------- how the form shows it --------------------- */

    public function test_only_computers_record_an_anydesk_number(): void
    {
        // AnyDesk is a remote support session. A projector has none, and
        // offering the field invites someone to fill it in.
        $tracks = Category::query()->pluck('tracks_anydesk', 'name')->map(fn ($v) => (bool) $v)->all();

        $this->assertTrue($tracks['Desktops']);
        $this->assertTrue($tracks['Laptops']);
        $this->assertFalse($tracks['Tablets']);
        $this->assertFalse($tracks['Networking']);
        $this->assertFalse($tracks['Peripherals']);
    }

    public function test_the_fields_placed_beside_another_are_the_ones_that_belong_there(): void
    {
        /*
         * A `slot` lifts a specification out of the block at the bottom of the
         * equipment form and draws it beside the field it identifies. Asserted
         * here because the placement is category data, not markup, and a typo
         * in a key would quietly send a field back to the bottom.
         */
        $slots = function (string $category): array {
            $fields = Category::query()->where('name', $category)->firstOrFail()->custom_fields['fields'];

            $placed = [];
            foreach ($fields as $field) {
                if (isset($field['slot'])) {
                    $placed[$field['slot']][] = $field['key'];
                }
            }

            return $placed;
        };

        $this->assertSame(
            ['monitor' => ['monitorPropertyNumber'], 'identity' => ['hostName', 'newHostName'], 'deployment' => ['idNumber']],
            $slots('Desktops'),
        );

        // A laptop has host names but no monitor of its own.
        $this->assertSame(
            ['identity' => ['hostName', 'newHostName'], 'deployment' => ['idNumber']],
            $slots('Laptops'),
        );

        // A tablet has neither.
        $this->assertSame(['deployment' => ['idNumber']], $slots('Tablets'));
    }

    public function test_the_retired_fields_are_gone_from_every_category(): void
    {
        foreach (Category::all() as $category) {
            $keys = array_column($category->custom_fields['fields'] ?? [], 'key');

            foreach (['description', 'keyboardMouse'] as $retired) {
                $this->assertNotContains($retired, $keys, "{$category->name} still declares {$retired}");
            }
        }

        // The monitor's property number survives only where there is a monitor.
        $withMonitor = Category::query()
            ->get()
            ->filter(fn (Category $c) => in_array('monitorPropertyNumber', array_column($c->custom_fields['fields'] ?? [], 'key'), true))
            ->pluck('name')
            ->all();

        $this->assertSame(['Desktops'], $withMonitor);
    }

    public function test_a_retired_column_is_reported_rather_than_written_nowhere(): void
    {
        $csv = "Property Number,Date Acquired,Model,PAR Issued To,Division,Description\n"
            ."PN-0001,2026-02-14,TravelMate,Ana R. Cruz,XI-FO-PPD,Windows 11 Pro\n";

        $response = $this->preview($csv)->assertOk();

        $this->assertSame(['Description'], array_column($response->json('headers.unrecognised'), 'header'));
        $this->assertArrayNotHasKey('description', $response->json('rows.0.payload.specifications'));
    }

    /* ------------------------ printers and scanners ----------------------- */

    /** A workstation row that also names a printer and a scanner. */
    private function workstationCsv(array $peripheral = []): string
    {
        $row = array_merge([
            'Printer' => 'EPSON M1170',
            'Printer Property' => 'PR-PROP-1',
            'Printer Serial' => 'XC6R003098',
            'Printer Warranty' => 'Yes',
            'Scanner' => 'BROTHER ADS-4300N',
            'Scanner Property' => 'SC-PROP-1',
            'Scanner Serial' => 'E81467C3',
            'Scanner Warranty' => 'No',
            'Peripheral Remarks' => 'Delivered with the unit',
        ], $peripheral);

        $head = 'Property Number,Date Acquired,Model,PAR Issued To,Division,Province,Actual User,'
            .'Serial Number,Monitor Serial Number';
        $body = 'PN-WS-1,2026-01-20,Veriton X4710G,Ana R. Cruz,XI-FO-PPD,Davao del Sur,Maria Santos,'
            .'SN-WS-1,MON-WS-1';

        return $head.','.implode(',', array_keys($row))."\n"
            .$body.','.implode(',', array_values($row))."\n";
    }

    public function test_a_workstation_row_registers_its_printer_and_scanner_too(): void
    {
        $response = $this->commit($this->workstationCsv(), 'desktop')->assertOk();

        // One line in, three assets out.
        $this->assertSame(1, $response->json('summary.total'));
        $this->assertSame(2, $response->json('summary.derived'));
        $this->assertSame(3, $response->json('summary.applied'));

        $printer = Equipment::where('property_number', 'PR-PROP-1')->firstOrFail();
        $this->assertSame(CategorySeeder::PRINTERS, $printer->category_id);
        $this->assertSame('EPSON M1170', $printer->model_name);
        $this->assertSame('XC6R003098', $printer->serial_number);
        $this->assertSame('ICT-PRN-0001', $printer->asset_tag);

        $scanner = Equipment::where('property_number', 'SC-PROP-1')->firstOrFail();
        $this->assertSame(CategorySeeder::SCANNERS, $scanner->category_id);
        $this->assertSame('ICT-SCN-0001', $scanner->asset_tag);
    }

    public function test_a_derived_peripheral_inherits_the_accountability_of_its_workstation(): void
    {
        /*
         * A printer delivered with a machine shares its office, its accountable
         * officer and its purchase date — that is what makes it a property
         * record rather than a note. What it must bring itself is what
         * identifies it.
         */
        $this->commit($this->workstationCsv(), 'desktop')->assertOk();

        $workstation = Equipment::where('property_number', 'PN-WS-1')->firstOrFail();
        $printer = Equipment::where('property_number', 'PR-PROP-1')->firstOrFail();

        $this->assertSame($workstation->division_id, $printer->division_id);
        $this->assertSame($workstation->province, $printer->province);
        $this->assertSame($workstation->par_issued_to, $printer->par_issued_to);
        $this->assertSame($workstation->actual_user, $printer->actual_user);
        $this->assertEquals($workstation->date_acquired, $printer->date_acquired);

        // And it stays findable from the machine it sits next to.
        $this->assertSame($workstation->asset_tag, $printer->specifications['attachedTo']);
        $this->assertSame('Delivered with the unit', $printer->remarks);
        $this->assertSame('Yes', $printer->specifications['warranty']);
        $this->assertSame('No', Equipment::where('property_number', 'SC-PROP-1')->value('specifications')['warranty']);
    }

    public function test_a_printer_without_a_property_number_is_reported_not_invented(): void
    {
        // Common in the real sheets: "PRINTER NO PROPERTY STICKER".
        $csv = $this->workstationCsv(['Printer Property' => '', 'Scanner' => '', 'Scanner Property' => '', 'Scanner Serial' => '']);

        $response = $this->preview($csv, 'desktop')->assertOk();

        $this->assertSame(0, $response->json('summary.derived'));
        $this->assertStringContainsString(
            'is missing a property number',
            implode(' ', $response->json('rows.0.warnings')),
        );
        // The workstation itself is unaffected.
        $this->assertSame('READY', $response->json('rows.0.status'));
    }

    public function test_a_tick_in_the_printer_column_is_not_taken_for_a_model(): void
    {
        // The older sheets answer PRINTER with TRUE, meaning "there is one".
        $csv = $this->workstationCsv(['Printer' => 'True', 'Scanner' => '', 'Scanner Property' => '', 'Scanner Serial' => '']);

        $response = $this->preview($csv, 'desktop')->assertOk();

        $this->assertSame(0, $response->json('summary.derived'));
        $this->assertStringContainsString(
            'yes/no answer rather than a name',
            implode(' ', $response->json('rows.0.warnings')),
        );
        $this->assertNull(Equipment::where('model_name', 'True')->first());
    }

    public function test_a_row_naming_no_peripherals_derives_nothing(): void
    {
        $csv = $this->workstationCsv(array_fill_keys(
            ['Printer', 'Printer Property', 'Printer Serial', 'Printer Warranty',
                'Scanner', 'Scanner Property', 'Scanner Serial', 'Scanner Warranty', 'Peripheral Remarks'],
            '',
        ));

        $response = $this->commit($csv, 'desktop')->assertOk();

        $this->assertSame(0, $response->json('summary.derived'));
        $this->assertSame(1, $response->json('summary.applied'));
    }

    public function test_a_derived_peripheral_is_checked_for_clashes_like_any_other_record(): void
    {
        Equipment::create([
            'id' => (string) Str::uuid(),
            'asset_tag' => 'ICT-PRN-9000',
            'property_number' => 'PR-PROP-1',
            'model_name' => 'The printer already registered',
            'division_id' => $this->division->id,
            'par_issued_to' => 'Someone',
            'specifications' => [],
        ]);

        $response = $this->commit($this->workstationCsv(), 'desktop')->assertOk();

        // The workstation and the scanner still import; only the printer clashes.
        $this->assertSame(2, $response->json('summary.applied'));
        $this->assertSame(1, $response->json('summary.duplicates'));

        $clash = collect($response->json('rows'))
            ->firstWhere(fn ($r) => ($r['payload']['propertyNumber'] ?? null) === 'PR-PROP-1');

        $this->assertSame('DUPLICATE', $clash['status']);
        $this->assertStringContainsString('ICT-PRN-9000', $clash['errors'][0]);
    }

    public function test_a_derived_row_is_corrected_through_the_row_it_came_from(): void
    {
        $csv = $this->workstationCsv(['Printer Property' => '']);

        $this->preview($csv, 'desktop')->assertOk()->assertJsonPath('summary.derived', 1);

        // Correcting the workstation's Printer Property column creates it.
        $response = $this->preview($csv, 'desktop', [
            'corrections' => json_encode([2 => ['Printer Property' => 'PR-PROP-FIXED']]),
        ])->assertOk();

        $this->assertSame(2, $response->json('summary.derived'));

        $printer = collect($response->json('rows'))->firstWhere('assetTag', 'ICT-PRN-0001');
        $this->assertSame('PR-PROP-FIXED', $printer['payload']['propertyNumber']);
        // A derived row offers no editor of its own; its source row does.
        $this->assertSame(2, $printer['derivedFrom']);
        $this->assertSame([], $printer['fix']);
    }

    public function test_printers_and_scanners_register_by_hand_like_a_peripheral(): void
    {
        foreach ([CategorySeeder::PRINTERS, CategorySeeder::SCANNERS] as $id) {
            $category = Category::findOrFail($id);

            $this->assertFalse((bool) $category->requires_monitor_serial);
            $this->assertFalse((bool) $category->tracks_anydesk);

            $keys = array_column($category->custom_fields['fields'], 'key');
            $this->assertContains('attachedTo', $keys);
            $this->assertContains('warranty', $keys);
            // None of a computer's remote-support detail belongs here.
            $this->assertNotContains('hostName', $keys);
            $this->assertNotContains('xdr', $keys);
        }
    }

    public function test_a_workstation_no_longer_stores_its_printer_as_a_specification(): void
    {
        $this->commit($this->workstationCsv(), 'desktop')->assertOk();

        $specs = Equipment::where('property_number', 'PN-WS-1')->value('specifications');

        foreach (['printer', 'printerProperty', 'printerSerial', 'scanner', 'scannerSerial'] as $gone) {
            $this->assertArrayNotHasKey($gone, $specs);
        }
    }

    /* ------------------------------ at volume ----------------------------- */

    /** A file of $count rows, every one of them valid. */
    private function bulkCsv(int $count, int $from = 1): string
    {
        $csv = "Property Number,Serial Number,Date Acquired,Model,PAR Issued To,Division\n";

        for ($i = $from; $i < $from + $count; $i++) {
            $csv .= sprintf("BULK-PN-%05d,BULK-SN-%05d,2026-02-14,TravelMate,Ana R. Cruz,XI-FO-PPD\n", $i, $i);
        }

        return $csv;
    }

    public function test_a_large_import_costs_two_queries_a_row(): void
    {
        /*
         * The insert and its activity log entry, and nothing else. This started
         * at eleven per row — a lookup of the offices, the category and every
         * uniqueness rule, repeated for each record — which made a five
         * thousand row file take longer than PHP's execution limit allows.
         */
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $response = $this->commit($this->bulkCsv(200))->assertOk();

        $this->assertSame(200, $response->json('summary.applied'));
        $this->assertLessThan(
            200 * 3,
            $queries,
            'a bulk import is issuing more queries per row than the insert and its log entry',
        );
    }

    public function test_a_large_import_lists_a_sample_rather_than_every_row(): void
    {
        $response = $this->commit($this->bulkCsv(200))->assertOk();

        // The counts describe the whole file...
        $this->assertSame(200, $response->json('summary.total'));
        $this->assertSame(200, $response->json('summary.applied'));

        // ...while the listing stays small enough to render and to send.
        $this->assertLessThanOrEqual(50, count($response->json('rows')));
        $this->assertGreaterThan(0, $response->json('rowsOmitted'));
        $this->assertSame(
            200,
            count($response->json('rows')) + $response->json('rowsOmitted'),
            'every record should be either listed or counted as omitted',
        );
    }

    public function test_rows_needing_attention_are_listed_before_clean_ones(): void
    {
        // One bad row buried in the middle must still reach the operator.
        $rows = explode("\n", trim($this->bulkCsv(200)));
        $rows[120] = 'BULK-PN-BAD,BULK-SN-BAD,not a date,TravelMate,Ana R. Cruz,XI-FO-PPD';

        $response = $this->preview(implode("\n", $rows)."\n")->assertOk();

        $lines = array_column($response->json('rows'), 'line');
        $this->assertContains(121, $lines, 'the failing row was dropped from the listing');

        $failing = collect($response->json('rows'))->firstWhere('line', 121);
        $this->assertSame('ERROR', $failing['status']);
    }

    public function test_a_commit_revalidates_rather_than_trusting_the_preview(): void
    {
        /*
         * The preview is not a promise. Something can take an identifier in
         * between — here, another operator registering the asset while the
         * file was being reviewed — and the commit has to notice rather than
         * write what the preview said.
         */
        $csv = $this->bulkCsv(20);

        $this->preview($csv)->assertOk()->assertJsonPath('summary.ready', 20);

        Equipment::create([
            'id' => (string) Str::uuid(),
            'asset_tag' => 'ICT-LT-9999',
            'property_number' => 'BULK-PN-00007',
            'model_name' => 'Registered while the file was being reviewed',
            'division_id' => $this->division->id,
            'par_issued_to' => 'Someone',
            'specifications' => [],
        ]);

        $response = $this->commit($csv)->assertOk();

        $this->assertSame(19, $response->json('summary.applied'));
        $this->assertSame(1, $response->json('summary.duplicates'));
        // The 19 imported, plus the one that got there first.
        $this->assertSame(20, Equipment::count());
    }

    public function test_a_chunk_that_hits_the_unique_index_loses_only_that_row(): void
    {
        /*
         * The batch path skips the per-row uniqueness SELECTs and leaves the
         * unique indexes as the guarantee, so a row that races another writer
         * fails at the insert and takes its whole chunk's transaction down
         * with it. SyncService replays that chunk one row at a time, so the
         * cost is one record rather than five hundred.
         *
         * Driven through SyncService directly: that window is between the
         * importer's own check and its insert, and nothing above this layer
         * can open it on purpose.
         */
        Equipment::create([
            'id' => (string) Str::uuid(),
            'asset_tag' => 'ICT-LT-8000',
            'property_number' => 'PN-ALREADY-THERE',
            'model_name' => 'Incumbent',
            'division_id' => $this->division->id,
            'par_issued_to' => 'Someone',
            'specifications' => [],
        ]);

        $change = fn (string $tag, string $property) => [
            'id' => (string) Str::uuid(),
            'table' => 'equipment',
            'action' => 'INSERT',
            'docId' => (string) Str::uuid(),
            'payload' => [
                'assetTag' => $tag,
                'propertyNumber' => $property,
                'dateAcquired' => '2026-02-14',
                'modelName' => 'TravelMate',
                'categoryId' => CategorySeeder::LAPTOPS,
                'divisionId' => $this->division->id,
                'parIssuedTo' => 'Ana R. Cruz',
                'specifications' => [],
                'updatedAt' => now()->toIso8601ZuluString('millisecond'),
            ],
            'clientTimestamp' => now()->toIso8601ZuluString('millisecond'),
        ];

        $results = app(SyncService::class)->pushBatch([
            $change('ICT-LT-8001', 'PN-FINE-1'),
            $change('ICT-LT-8002', 'PN-ALREADY-THERE'),   // violates the index
            $change('ICT-LT-8003', 'PN-FINE-2'),
        ])['results'];

        $statuses = array_column($results, 'status');
        $this->assertSame(['APPLIED', 'REJECTED', 'APPLIED'], $statuses);

        // The two good rows survived the rollback and replay.
        $this->assertNotNull(Equipment::where('property_number', 'PN-FINE-1')->first());
        $this->assertNotNull(Equipment::where('property_number', 'PN-FINE-2')->first());
        $this->assertSame(3, Equipment::count());
    }

    public function test_a_bulk_insert_logs_what_it_actually_stored(): void
    {
        /*
         * The batch path stops the activity logger re-reading each row, on the
         * grounds that every column was set explicitly a moment earlier. If
         * that ever stops being true the log would quietly record something
         * other than the record, so it is checked rather than assumed.
         */
        $this->commit($this->bulkCsv(5))->assertOk();

        $asset = Equipment::where('property_number', 'BULK-PN-00003')->firstOrFail();
        $logged = Activity::query()
            ->where('subject_id', $asset->id)
            ->where('event', 'INSERT')
            ->firstOrFail()
            ->attribute_changes['attributes'] ?? [];

        foreach (['asset_tag', 'property_number', 'serial_number', 'model_name', 'status', 'condition', 'division_id'] as $column) {
            $this->assertSame(
                $asset->getAttribute($column),
                $logged[$column] ?? null,
                "the activity log's {$column} does not match the stored row",
            );
        }
    }

    public function test_the_activity_logger_still_re_reads_outside_a_batch(): void
    {
        // The optimisation is scoped to the import. An ordinary write must keep
        // the package's safeguard, and must not be left switched off after one.
        $this->commit($this->bulkCsv(2))->assertOk();

        $queries = 0;
        DB::listen(function ($q) use (&$queries) {
            if (str_contains($q->sql, 'select * from `equipment` where `id`')) {
                $queries++;
            }
        });

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/equipment', [
                'assetTag' => 'ICT-LT-5000',
                'propertyNumber' => 'PN-SINGLE',
                'dateAcquired' => '2026-02-14',
                'modelName' => 'Single write',
                'divisionId' => $this->division->id,
                'parIssuedTo' => 'Ana R. Cruz',
            ])
            ->assertSuccessful();

        $this->assertGreaterThan(0, $queries, 'the single-write path should still re-read what it stored');
    }

    public function test_generated_tags_keep_counting_past_the_fourth_digit(): void
    {
        // Picking the highest tag as text makes ICT-LT-9999 beat ICT-LT-10000,
        // and the next import hands out tags that already exist.
        Equipment::create([
            'id' => (string) Str::uuid(),
            'asset_tag' => 'ICT-LT-10000',
            'property_number' => 'PN-EXISTING',
            'model_name' => 'Existing',
            'division_id' => $this->division->id,
            'par_issued_to' => 'Someone',
            'specifications' => [],
        ]);

        $response = $this->commit($this->laptopCsv())->assertOk();

        $this->assertSame('ICT-LT-10001', $response->json('rows.0.assetTag'));
    }

    /* ---------------------------- authorisation -------------------------- */

    public function test_import_needs_a_token(): void
    {
        $this->postJson('/api/import/laptop/preview')->assertStatus(401);
        $this->getJson('/api/import/profiles')->assertStatus(401);
        $this->getJson('/api/import/laptop/template')->assertStatus(401);
    }

    public function test_an_admin_may_also_import(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->post('/api/import/laptop/preview', ['file' => $this->csv($this->laptopCsv())])
            ->assertOk()
            ->assertJsonPath('summary.ready', 1);
    }
}
