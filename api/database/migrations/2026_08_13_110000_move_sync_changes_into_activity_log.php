<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carries the existing audit trail into the activity log.
 *
 * `sync_changes` has recorded every write since the first migration, including
 * the PURGE snapshots that are the only remaining evidence of assets that were
 * disposed of. Adopting the package without bringing those across would start
 * the History screen at zero and quietly abandon the record a property audit
 * asks for.
 *
 * The old table is left in place, emptied of purpose but not of rows. Dropping
 * it would make this migration unreversible in the only way that matters — the
 * data — and there is no cost to leaving it until someone has checked the
 * migrated history reads correctly.
 */
return new class extends Migration
{
    /** Which model each entity_type belongs to, for the polymorphic subject. */
    private const SUBJECTS = [
        'equipment' => \App\Models\Equipment::class,
        'categories' => \App\Models\Category::class,
        'offices' => \App\Models\Office::class,
        'borrowings' => \App\Models\BorrowingTransaction::class,
        'users' => \App\Models\User::class,
    ];

    public function up(): void
    {
        if (! Schema::hasTable('sync_changes')) {
            return;
        }

        DB::table('sync_changes')->orderBy('client_timestamp')->chunk(500, function ($rows) {
            $insert = [];

            foreach ($rows as $row) {
                $subject = self::SUBJECTS[$row->entity_type] ?? null;

                if ($subject === null) {
                    continue;
                }

                $insert[] = [
                    'log_name' => $row->entity_type,
                    'description' => $row->operation,
                    'subject_type' => $subject,
                    'subject_id' => $row->entity_id,
                    'event' => $row->operation,
                    'causer_type' => $row->user_id ? \App\Models\User::class : null,
                    'causer_id' => $row->user_id,
                    // v5 splits the two apart: tracked model state lives in
                    // `attribute_changes`, anything custom in `properties`.
                    //
                    // The old log stored one snapshot per entry and no previous
                    // state, so migrated rows have `attributes` and no `old`.
                    // The History screen reports "no comparison available" for
                    // them rather than inventing a diff it cannot know.
                    'attribute_changes' => json_encode([
                        'attributes' => json_decode($row->data, true),
                    ]),
                    'properties' => json_encode([
                        'migrated_from' => 'sync_changes',
                        // Preserves attribution when the account behind the
                        // entry is gone and causer_id is therefore null.
                        'actor_label' => $row->client_id,
                    ]),
                    // `client_timestamp`, not `synced_at`. The latter was a
                    // MySQL CURRENT_TIMESTAMP default, so it holds the database
                    // server's local time in a column every other timestamp in
                    // this schema treats as UTC - eight hours out here, and
                    // enough to put an entry in tomorrow. `client_timestamp`
                    // was written by PHP, which runs in UTC.
                    'created_at' => $row->client_timestamp,
                    'updated_at' => $row->client_timestamp,
                ];
            }

            if ($insert !== []) {
                DB::table('activity_log')->insert($insert);
            }
        });
    }

    public function down(): void
    {
        DB::table('activity_log')
            ->whereJsonContains('properties->migrated_from', 'sync_changes')
            ->delete();
    }
};
