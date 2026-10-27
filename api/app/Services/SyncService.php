<?php

namespace App\Services;

use App\Domain\InventoryStatus;
use App\Domain\LoanCalculator;
use App\Models\BorrowingTransaction;
use App\Models\Category;
use App\Models\Concerns\ActivityLogMode;
use App\Models\Equipment;
use App\Models\Office;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The single write path into the inventory.
 *
 * Every mutation — from the REST controllers or from a console command —
 * arrives here as a batch of changes, each applied in its own transaction with
 * a per-change verdict.
 *
 * Nothing here writes the audit log. That is the `RecordsActivity` trait's job,
 * fired by Eloquent's own model events, which is what makes the log impossible
 * to bypass: a write that skipped this service would still be recorded.
 *
 * The conflict rules survived the removal of offline mode, because they were
 * never really about offline: two operators on two browsers can still submit
 * overlapping loans for the same asset within the same second. Last-Write-Wins
 * on `updatedAt` and the Status Lock Rule adjudicate that, and always did.
 */
class SyncService
{
    /**
     * @param  array{changes: array<int, array>}  $request
     */
    public function push(array $request): array
    {
        // Replay in the order the operator made the edits, not the order they
        // happened to arrive in the payload.
        $changes = collect($request['changes'])
            ->sortBy('clientTimestamp')
            ->values();

        $results = [];

        foreach ($changes as $change) {
            try {
                $results[] = $this->applyChange($change);
            } catch (Throwable $e) {
                if ($this->isInfrastructureFailure($e)) {
                    // The database is unreachable — fail the whole request so the
                    // client retries the batch instead of discarding it.
                    throw $e;
                }

                $results[] = [
                    'changeId' => $change['id'],
                    'table' => $change['table'],
                    'docId' => $change['docId'],
                    'status' => 'REJECTED',
                    'message' => $this->describe($e),
                ];
            }
        }

        return [
            'serverTime' => now()->toIso8601ZuluString('millisecond'),
            'results' => $results,
        ];
    }

    /**
     * Brings an archived asset back into the live register (US-2.9).
     *
     * The mirror of a soft delete, and the reason purging is a separate,
     * admin-only action: archiving is meant to be reversible, so an operator
     * who removes the wrong asset has a way back that does not involve
     * re-typing a property record.
     *
     * No identifier collision is possible on the way back in: the unique indexes
     * on asset_tag, property_number and both serials cover archived rows as well
     * as live ones, so an archived asset keeps its identifiers reserved and
     * nothing can have taken them while it was away.
     *
     * @return array{status: string, message?: string, data?: array}
     */
    public function restoreEquipment(string $id): array
    {
        return DB::transaction(function () use ($id) {
            $asset = Equipment::query()->lockForUpdate()->find($id);

            if (! $asset) {
                return ['status' => 'NOT_FOUND', 'message' => 'That asset no longer exists.'];
            }

            if ($asset->deleted_at === null) {
                return [
                    'status' => 'REJECTED',
                    'message' => "{$asset->asset_tag} is not archived.",
                ];
            }

            $now = Carbon::now();

            $asset->forceFill(['deleted_at' => null, 'updated_at' => $now])->save();

            // Archived while on loan, and the loan has since been closed: the
            // stored status would otherwise say BORROWED for a device sitting
            // on the shelf.
            $this->reconcileEquipmentStatus($asset->id, $now);

            $fresh = $asset->fresh();

            return ['status' => 'RESTORED', 'data' => $fresh->toSyncArray()];
        });
    }

    /**
     * Permanently destroys an archived asset (US-2.9).
     *
     * Soft delete hides a record; this erases it. Refused while the asset is
     * not archived, or while a loan still physically holds it — purging then
     * would leave a borrower holding a device the system has no record of.
     *
     * Deleting the row fires Eloquent's `deleted` event, and the activity log
     * entry that produces carries a full snapshot of it. After this returns
     * that entry is the only remaining evidence the asset ever existed, which
     * is exactly what a property audit will ask for.
     *
     * @return array{status: string, message?: string, assetTag?: string, detachedLoans?: int}
     */
    public function purgeEquipment(string $id): array
    {
        return DB::transaction(function () use ($id) {
            $asset = Equipment::query()->lockForUpdate()->find($id);

            if (! $asset) {
                return ['status' => 'NOT_FOUND', 'message' => 'That asset no longer exists.'];
            }

            if ($asset->deleted_at === null) {
                return [
                    'status' => 'REJECTED',
                    'message' => "{$asset->asset_tag} is not archived. Archive it first.",
                ];
            }

            $active = BorrowingTransaction::query()
                ->where('equipment_id', $id)
                ->whereNull('deleted_at')
                ->active()
                ->count();

            if ($active > 0) {
                return [
                    'status' => 'REJECTED',
                    'message' => "{$asset->asset_tag} is still out on an active loan and cannot be destroyed.",
                ];
            }

            // The FK is ON DELETE SET NULL, so the loan rows survive the purge
            // with a dangling reference. Counting them first is what lets the
            // confirmation dialog say how much history is about to come loose.
            $detached = BorrowingTransaction::query()->where('equipment_id', $id)->count();

            $assetTag = $asset->asset_tag;
            $asset->delete();

            return [
                'status' => 'PURGED',
                'assetTag' => $assetTag,
                'detachedLoans' => $detached,
            ];
        });
    }

    /* ------------------------------------------------------------------ */
    /* Dispatch                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Applies one change in its own transaction.
     *
     * `$bulk` is passed only by `pushBatch()`, which has already opened a
     * transaction around the whole chunk — opening another per row would make
     * every one of them a savepoint for no benefit.
     */
    protected function applyChange(array $change, ?BulkContext $bulk = null): array
    {
        $apply = fn () => match ($change['table']) {
            'offices' => $this->applyOffice($change),
            'categories' => $this->applyCategory($change),
            'equipment' => $this->applyEquipment($change, $bulk),
            'borrowings' => $this->applyBorrowing($change),
        };

        return $bulk ? $apply() : DB::transaction($apply);
    }

    /**
     * Writes a batch the caller has already validated — the import path.
     *
     * Three things make this bearable at thousands of rows where `push()` is
     * not. Offices and categories are resolved once for the batch rather than
     * per row; the uniqueness SELECTs are skipped because the caller has
     * already run them, leaving the unique indexes as the guarantee; and a
     * chunk shares one transaction instead of opening five hundred.
     *
     * When a chunk does fail — a concurrent writer taking an identifier
     * between the caller's check and this insert — the whole chunk rolls back
     * and is replayed one row at a time, so one clash costs one record and the
     * operator is told which. That path is rare by construction, which is why
     * the fast path is allowed to assume it will not happen.
     *
     * @param  list<array>  $changes
     */
    public function pushBatch(array $changes, ?BulkContext $context = null, int $chunkSize = 500): array
    {
        return ActivityLogMode::duringTrustedBatch(
            fn () => $this->applyBatch($changes, $context ?? new BulkContext(preValidated: true), $chunkSize),
        );
    }

    /** @param  list<array>  $changes */
    private function applyBatch(array $changes, BulkContext $context, int $chunkSize): array
    {
        $results = [];

        foreach (array_chunk($changes, $chunkSize) as $chunk) {
            try {
                $applied = DB::transaction(function () use ($chunk, $context) {
                    $verdicts = [];
                    foreach ($chunk as $change) {
                        $verdicts[] = $this->applyChange($change, $context);
                    }

                    return $verdicts;
                });

                array_push($results, ...$applied);
            } catch (Throwable $e) {
                if ($this->isInfrastructureFailure($e)) {
                    throw $e;
                }

                // The chunk is gone; nothing in it was written. Replay it row
                // by row so the failures are attributed and the rest survive.
                foreach ($chunk as $change) {
                    try {
                        $results[] = $this->applyChange($change);
                    } catch (Throwable $rowError) {
                        if ($this->isInfrastructureFailure($rowError)) {
                            throw $rowError;
                        }

                        $results[] = [
                            'changeId' => $change['id'],
                            'table' => $change['table'],
                            'docId' => $change['docId'],
                            'status' => 'REJECTED',
                            'message' => $this->describe($rowError),
                        ];
                    }
                }
            }
        }

        return ['serverTime' => now()->toIso8601ZuluString('millisecond'), 'results' => $results];
    }

    /**
     * Last-Write-Wins guard shared by every entity: a mutation whose updatedAt
     * is not newer than the stored row is dropped, and the authoritative server
     * state is echoed back so the client can reconcile.
     */
    protected function isStale(Carbon $incoming, ?Carbon $stored): bool
    {
        return $stored !== null && $incoming->lessThanOrEqualTo($stored);
    }

    protected function payloadUpdatedAt(array $change): Carbon
    {
        return $this->date($change['payload']['updatedAt'] ?? null)
            ?? Carbon::parse($change['clientTimestamp']);
    }

    /* ------------------------------------------------------------------ */
    /* Offices                                                             */
    /* ------------------------------------------------------------------ */

    protected function applyOffice(array $change): array
    {
        $payload = $change['payload'];
        $existing = Office::find($change['docId']);
        $updatedAt = $this->payloadUpdatedAt($change);

        if ($existing && $this->isStale($updatedAt, $existing->updated_at)) {
            return $this->verdict($change, 'IGNORED_STALE', $existing->toSyncArray());
        }

        if ($change['action'] === 'DELETE') {
            if (! $existing) {
                return $this->verdict($change, 'APPLIED', null);
            }

            // An office still in use must not disappear underneath the records
            // that point at it, or a report loses its office silently.
            $sections = Office::where('division_id', $existing->id)->whereNull('deleted_at')->count();
            $assets = Equipment::where('division_id', $existing->id)
                ->orWhere('section_id', $existing->id)
                ->whereNull('deleted_at')
                ->count();

            if ($sections > 0 || $assets > 0) {
                $why = $sections > 0
                    ? "{$sections} section(s) still belong to it"
                    : "{$assets} asset(s) are still assigned to it";

                return [
                    'changeId' => $change['id'],
                    'table' => 'offices',
                    'docId' => $change['docId'],
                    'status' => 'REJECTED',
                    'message' => "{$existing->name} cannot be removed: {$why}.",
                    'document' => $existing->toSyncArray(),
                ];
            }

            $existing->forceFill([
                'deleted_at' => $this->date($payload['deletedAt'] ?? null) ?? now(),
                'updated_at' => $updatedAt,
            ])->save();

            return $this->verdict($change, 'APPLIED', $existing->fresh()->toSyncArray());
        }

        $code = $this->str($payload['code'] ?? null) ?? $existing?->code;
        if ($code === null) {
            return [
                'changeId' => $change['id'],
                'table' => 'offices',
                'docId' => $change['docId'],
                'status' => 'REJECTED',
                'message' => 'An office needs a code.',
            ];
        }

        $codeOwner = Office::where('code', $code)->whereKeyNot($change['docId'])->first();
        if ($codeOwner) {
            return [
                'changeId' => $change['id'],
                'table' => 'offices',
                'docId' => $change['docId'],
                'status' => 'REJECTED',
                'message' => "Office code \"{$code}\" already belongs to {$codeOwner->name}.",
                'document' => $codeOwner->toSyncArray(),
            ];
        }

        $parentId = $this->str($payload['divisionId'] ?? null);

        if ($parentId === $change['docId']) {
            return [
                'changeId' => $change['id'],
                'table' => 'offices',
                'docId' => $change['docId'],
                'status' => 'REJECTED',
                'message' => 'An office cannot be its own division.',
            ];
        }

        // Only a division may be a parent: the register is two levels, and a
        // section of a section has no meaning in the org chart.
        if ($parentId !== null && ! Office::whereKey($parentId)->whereNull('division_id')->exists()) {
            return [
                'changeId' => $change['id'],
                'table' => 'offices',
                'docId' => $change['docId'],
                'status' => 'REJECTED',
                'message' => 'A section must belong to a division, not to another section.',
            ];
        }

        // Demoting a division that still has sections would orphan them.
        if ($existing && $existing->isDivision() && $parentId !== null) {
            $sections = Office::where('division_id', $existing->id)->whereNull('deleted_at')->count();
            if ($sections > 0) {
                return [
                    'changeId' => $change['id'],
                    'table' => 'offices',
                    'docId' => $change['docId'],
                    'status' => 'REJECTED',
                    'message' => "{$existing->name} has {$sections} section(s) and cannot become a section itself.",
                ];
            }
        }

        $attributes = [
            'code' => $code,
            'name' => $this->str($payload['name'] ?? null) ?? $existing?->name ?? 'Untitled office',
            'division_id' => $parentId,
            'deleted_at' => $this->date($payload['deletedAt'] ?? null),
            'updated_at' => $updatedAt,
        ];

        $model = $existing ?? new Office;
        if (! $existing) {
            $model->id = $change['docId'];
            $attributes['created_at'] = $this->date($payload['createdAt'] ?? null) ?? $updatedAt;
        }
        $model->forceFill($attributes)->save();

        return $this->verdict($change, 'APPLIED', $model->fresh()->toSyncArray());
    }

    /* ------------------------------------------------------------------ */
    /* Categories                                                          */
    /* ------------------------------------------------------------------ */

    protected function applyCategory(array $change): array
    {
        $payload = $change['payload'];
        $existing = Category::find($change['docId']);
        $updatedAt = $this->payloadUpdatedAt($change);

        if ($existing && $this->isStale($updatedAt, $existing->updated_at)) {
            return $this->verdict($change, 'IGNORED_STALE', $existing->toSyncArray());
        }

        if ($change['action'] === 'DELETE') {
            if (! $existing) {
                return $this->verdict($change, 'APPLIED', null);
            }
            $existing->forceFill([
                'deleted_at' => $this->date($payload['deletedAt'] ?? null) ?? now(),
                'updated_at' => $updatedAt,
            ])->save();

            return $this->verdict($change, 'APPLIED', $existing->fresh()->toSyncArray());
        }

        $attributes = [
            'name' => $this->str($payload['name'] ?? null) ?? $existing?->name ?? 'Untitled',
            'description' => $this->str($payload['description'] ?? null),
            'custom_fields' => Category::normalizeCustomFields($payload['customFields'] ?? null),
            'default_loan_days' => (int) ($payload['defaultLoanDays'] ?? $existing?->default_loan_days ?? 7),
            'requires_monitor_serial' => (bool) ($payload['requiresMonitorSerial']
                ?? $existing?->requires_monitor_serial
                ?? false),
            'tracks_anydesk' => (bool) ($payload['tracksAnydesk']
                ?? $existing?->tracks_anydesk
                ?? false),
            'deleted_at' => $this->date($payload['deletedAt'] ?? null),
            'updated_at' => $updatedAt,
        ];

        $model = $existing ?? new Category(['id' => $change['docId']]);
        if (! $existing) {
            $model->id = $change['docId'];
            $attributes['created_at'] = $this->date($payload['createdAt'] ?? null) ?? $updatedAt;
        }
        $model->forceFill($attributes)->save();

        return $this->verdict($change, 'APPLIED', $model->fresh()->toSyncArray());
    }

    /* ------------------------------------------------------------------ */
    /* Equipment                                                           */
    /* ------------------------------------------------------------------ */

    protected function applyEquipment(array $change, ?BulkContext $bulk = null): array
    {
        $payload = $change['payload'];

        // A pre-validated batch is all new records with freshly minted ids, so
        // looking each one up to discover it does not exist is a wasted query.
        $existing = $bulk?->preValidated ? null : Equipment::find($change['docId']);
        $updatedAt = $this->payloadUpdatedAt($change);

        if ($existing && $this->isStale($updatedAt, $existing->updated_at)) {
            return $this->verdict($change, 'IGNORED_STALE', $existing->toSyncArray());
        }

        if ($change['action'] === 'DELETE') {
            if (! $existing) {
                return $this->verdict($change, 'APPLIED', null);
            }
            $existing->forceFill([
                'deleted_at' => $this->date($payload['deletedAt'] ?? null) ?? now(),
                'updated_at' => $updatedAt,
            ])->save();

            return $this->verdict($change, 'APPLIED', $existing->fresh()->toSyncArray());
        }

        $assetTag = $this->str($payload['assetTag'] ?? null) ?? $existing?->asset_tag ?? $change['docId'];

        // asset_tag is UNIQUE. A different row already holding it is a hard
        // reject so the operator sees the clash instead of losing the record.
        $tagOwner = $bulk?->preValidated ? null : Equipment::where('asset_tag', $assetTag)->first();
        if ($tagOwner && $tagOwner->id !== $change['docId']) {
            return [
                'changeId' => $change['id'],
                'table' => 'equipment',
                'docId' => $change['docId'],
                'status' => 'REJECTED',
                'message' => "Asset tag \"{$assetTag}\" already belongs to another record.",
                'document' => $tagOwner->toSyncArray(),
            ];
        }

        /*
         * Government property accountability: every newly registered asset must
         * carry a PAR (Property Acknowledgement Receipt) naming the officer it
         * is issued to. Enforced on INSERT only — equipment registered before
         * this policy has to stay editable so the number can be filled in
         * rather than the record becoming unsyncable.
         */
        $parIssuedTo = $this->str($payload['parIssuedTo'] ?? null);
        $propertyNumber = $this->str($payload['propertyNumber'] ?? null);
        $dateAcquired = $this->date($payload['dateAcquired'] ?? null);

        // Division comes from the office register and is required; section is
        // optional, because plenty of offices assign at division level.
        $divisionId = $this->str($payload['divisionId'] ?? null);
        $sectionId = $this->str($payload['sectionId'] ?? null);
        $division = $bulk
            ? $bulk->division($divisionId)
            : ($divisionId ? Office::whereKey($divisionId)->whereNull('division_id')->first() : null);
        $section = $bulk
            ? $bulk->section($sectionId)
            : ($sectionId ? Office::whereKey($sectionId)->whereNotNull('division_id')->first() : null);

        if (! $existing) {
            $missing = match (true) {
                $propertyNumber === null => 'a property number',
                $dateAcquired === null => 'an acquisition date',
                $parIssuedTo === null => 'the name the PAR is issued to',
                $division === null => 'a division',
                default => null,
            };

            if ($missing !== null) {
                return [
                    'changeId' => $change['id'],
                    'table' => 'equipment',
                    'docId' => $change['docId'],
                    'status' => 'REJECTED',
                    'message' => "Asset \"{$assetTag}\" needs {$missing} before it can be registered.",
                ];
            }
        }

        // property_number is UNIQUE and names one physical item, so a clash is
        // surfaced the same way an asset tag clash is rather than silently lost.
        if ($propertyNumber !== null && ! $bulk?->preValidated) {
            $owner = Equipment::where('property_number', $propertyNumber)->first();
            if ($owner && $owner->id !== $change['docId']) {
                return [
                    'changeId' => $change['id'],
                    'table' => 'equipment',
                    'docId' => $change['docId'],
                    'status' => 'REJECTED',
                    'message' => "Property number \"{$propertyNumber}\" already belongs to another record.",
                    'document' => $owner->toSyncArray(),
                ];
            }
        }

        if ($section && $division && $section->division_id !== $division->id) {
            return [
                'changeId' => $change['id'],
                'table' => 'equipment',
                'docId' => $change['docId'],
                'status' => 'REJECTED',
                'message' => "{$section->name} is not a section of {$division->name}.",
            ];
        }

        $categoryId = $this->str($payload['categoryId'] ?? null);
        $category = $bulk
            ? $bulk->category($categoryId)
            : ($categoryId ? Category::find($categoryId) : null);

        $serialNumber = $this->str($payload['serialNumber'] ?? null);
        $monitorSerial = $this->str($payload['monitorSerialNumber'] ?? null);

        /*
         * A desktop set is one asset with two serialised parts, so categories
         * flagged `requires_monitor_serial` need both the system unit and the
         * display recorded. Enforced on INSERT only, like the PAR fields, so
         * assets registered before the flag was set stay editable.
         */
        if (! $existing && $category?->requires_monitor_serial) {
            $missingSerial = match (true) {
                $serialNumber === null => 'a system unit serial number',
                $monitorSerial === null => 'a monitor serial number',
                default => null,
            };

            if ($missingSerial !== null) {
                return [
                    'changeId' => $change['id'],
                    'table' => 'equipment',
                    'docId' => $change['docId'],
                    'status' => 'REJECTED',
                    'message' => "Asset \"{$assetTag}\" needs {$missingSerial}; {$category->name} records both.",
                ];
            }
        }

        /*
         * Serials are checked across BOTH columns: a monitor's serial must not
         * collide with some other machine's system unit serial either, or the
         * scanner would resolve one physical device to two records.
         */
        foreach ([[$serialNumber, 'Serial number'], [$monitorSerial, 'Monitor serial number']] as [$value, $label]) {
            if ($value === null || $bulk?->preValidated) {
                continue;
            }
            $owner = Equipment::whereKeyNot($change['docId'])
                ->where(fn ($q) => $q->where('serial_number', $value)
                    ->orWhere('monitor_serial_number', $value))
                ->first();

            if ($owner) {
                return [
                    'changeId' => $change['id'],
                    'table' => 'equipment',
                    'docId' => $change['docId'],
                    'status' => 'REJECTED',
                    'message' => "{$label} \"{$value}\" already belongs to another record.",
                    'document' => $owner->toSyncArray(),
                ];
            }
        }

        if ($serialNumber !== null && $serialNumber === $monitorSerial) {
            return [
                'changeId' => $change['id'],
                'table' => 'equipment',
                'docId' => $change['docId'],
                'status' => 'REJECTED',
                'message' => 'The system unit and monitor cannot share a serial number.',
            ];
        }

        $attributes = [
            'asset_tag' => $assetTag,
            'property_number' => $propertyNumber ?? $existing?->property_number,
            'date_acquired' => $dateAcquired ?? $existing?->date_acquired,
            'serial_number' => $serialNumber,
            'monitor_serial_number' => $monitorSerial,
            'category_id' => $category?->id,
            'model_name' => $this->str($payload['modelName'] ?? null) ?? $existing?->model_name ?? 'Unknown',
            'brand' => $this->str($payload['brand'] ?? null),
            'status' => InventoryStatus::oneOf(
                $payload['status'] ?? null,
                InventoryStatus::EQUIPMENT_STATUSES,
                $existing?->status ?? 'AVAILABLE',
            ),
            'condition' => InventoryStatus::oneOf(
                $payload['condition'] ?? null,
                InventoryStatus::EQUIPMENT_CONDITIONS,
                $existing?->condition ?? 'GOOD',
            ),
            'location' => $this->str($payload['location'] ?? null),
            'division_id' => $division?->id ?? $existing?->division_id,
            'section_id' => $section?->id,
            'province' => $this->str($payload['province'] ?? null) ?? $existing?->province,
            'par_issued_to' => $parIssuedTo ?? $existing?->par_issued_to,
            'sub_par_issued_to' => $this->str($payload['subParIssuedTo'] ?? null),
            'actual_user' => $this->str($payload['actualUser'] ?? null),
            'position' => $this->str($payload['position'] ?? null),
            'ad_username' => $this->str($payload['adUsername'] ?? null),
            'anydesk_number' => $this->str($payload['anydeskNumber'] ?? null),
            'specifications' => is_array($payload['specifications'] ?? null) ? $payload['specifications'] : [],
            'remarks' => $this->str($payload['remarks'] ?? null),
            'deleted_at' => $this->date($payload['deletedAt'] ?? null),
            'updated_at' => $updatedAt,
        ];

        $model = $existing ?? new Equipment;
        if (! $existing) {
            $model->id = $change['docId'];
            $attributes['created_at'] = $this->date($payload['createdAt'] ?? null) ?? $updatedAt;
        }
        $model->forceFill($attributes)->save();

        /*
         * `fresh()` is a second SELECT for every row. It earns that on the
         * single-write path, where another writer may have landed between the
         * save and the response. In a pre-validated batch every attribute was
         * set explicitly moments ago and nothing else is touching these
         * brand-new rows, so the in-memory model is already the stored record.
         */
        return $this->verdict(
            $change,
            'APPLIED',
            ($bulk?->preValidated ? $model : $model->fresh())->toSyncArray(),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Borrowings + conflict engine                                        */
    /* ------------------------------------------------------------------ */

    protected function applyBorrowing(array $change): array
    {
        $payload = $change['payload'];
        $existing = BorrowingTransaction::find($change['docId']);
        $updatedAt = $this->payloadUpdatedAt($change);

        if ($existing && $this->isStale($updatedAt, $existing->updated_at)) {
            return $this->verdict($change, 'IGNORED_STALE', $existing->toSyncArray());
        }

        if ($change['action'] === 'DELETE') {
            if (! $existing) {
                return $this->verdict($change, 'APPLIED', null);
            }
            $existing->forceFill([
                'deleted_at' => $this->date($payload['deletedAt'] ?? null) ?? now(),
                'updated_at' => $updatedAt,
            ])->save();
            $this->reconcileEquipmentStatus($existing->equipment_id, $updatedAt);

            return $this->verdict($change, 'APPLIED', $existing->fresh()->toSyncArray());
        }

        $equipmentId = $this->str($payload['equipmentId'] ?? null);
        $issueDate = $this->date($payload['issueDate'] ?? null) ?? $existing?->issue_date ?? now();
        $expectedReturn = $this->date($payload['expectedReturnDate'] ?? null)
            ?? $existing?->expected_return_date
            ?? $issueDate;
        $createdAt = $this->date($payload['createdAt'] ?? null) ?? $existing?->created_at ?? $updatedAt;

        $status = InventoryStatus::oneOf(
            $payload['status'] ?? null,
            InventoryStatus::BORROWING_STATUSES,
            $existing?->status ?? 'REQUESTED',
        );
        $conflictReason = $this->str($payload['conflictReason'] ?? null);
        $conflicted = false;

        /*
         * An asset that is out of the loan pool cannot be booked at all.
         *
         * The client blocks this too, but an offline client may not have seen
         * the status change yet, so the server is the one that decides. Only
         * new loans are checked: an existing loan being returned or corrected
         * must still be able to sync.
         */
        if ($equipmentId && ! $existing && InventoryStatus::isActiveLoan($status)) {
            $asset = Equipment::find($equipmentId);

            if ($asset && ! InventoryStatus::canBeBorrowed($asset->status)) {
                $reason = InventoryStatus::explainNotBorrowable($asset->status);

                return [
                    'changeId' => $change['id'],
                    'table' => 'borrowings',
                    'docId' => $change['docId'],
                    'status' => 'REJECTED',
                    'message' => "{$asset->asset_tag} {$reason}.",
                    'document' => $asset->toSyncArray(),
                ];
            }
        }

        /*
         * Status Lock Rule: a loan that physically holds a device must not
         * overlap another active loan on the same asset. Offline clients cannot
         * see each other, so the server adjudicates here.
         */
        if ($equipmentId && InventoryStatus::isActiveLoan($status)) {
            $rival = BorrowingTransaction::query()
                ->active()
                ->where('equipment_id', $equipmentId)
                ->whereKeyNot($change['docId'])
                ->where('issue_date', '<=', $expectedReturn)
                ->where('expected_return_date', '>=', $issueDate)
                ->orderBy('created_at')
                ->orderBy('id')
                ->first();

            if ($rival) {
                $conflicted = true;

                if (LoanCalculator::incomingCheckoutWins(
                    $change['docId'],
                    CarbonImmutable::instance($createdAt),
                    $rival->id,
                    CarbonImmutable::instance($rival->created_at),
                )) {
                    // The incoming loan happened first: it keeps the asset and
                    // the already-stored rival is the one flagged for review.
                    $rival->forceFill([
                        'status' => 'CONFLICT_PENDING_REVIEW',
                        'conflict_reason' => "Overlapping checkout: transaction {$change['docId']} claimed this asset first.",
                        'updated_at' => $updatedAt,
                    ])->save();
                } else {
                    $status = 'CONFLICT_PENDING_REVIEW';
                    $conflictReason = sprintf(
                        'Overlapping checkout: transaction %s already holds this asset from %s to %s.',
                        $rival->id,
                        $rival->issue_date->toIso8601ZuluString('millisecond'),
                        $rival->expected_return_date->toIso8601ZuluString('millisecond'),
                    );
                }
            }
        }

        $attributes = [
            'equipment_id' => $equipmentId && Equipment::whereKey($equipmentId)->exists() ? $equipmentId : null,
            'borrower_name' => $this->str($payload['borrowerName'] ?? null) ?? $existing?->borrower_name ?? 'Unknown',
            'borrower_email' => $this->str($payload['borrowerEmail'] ?? null) ?? $existing?->borrower_email ?? 'unknown@example.invalid',
            'issue_date' => $issueDate,
            'expected_return_date' => $expectedReturn,
            'actual_return_date' => $this->date($payload['actualReturnDate'] ?? null),
            'status' => $status,
            'notes' => $this->str($payload['notes'] ?? null),
            'conflict_reason' => $conflictReason,
            'deleted_at' => $this->date($payload['deletedAt'] ?? null),
            'updated_at' => $updatedAt,
        ];

        $model = $existing ?? new BorrowingTransaction;
        if (! $existing) {
            $model->id = $change['docId'];
            $attributes['created_at'] = $createdAt;
        }
        $model->forceFill($attributes)->save();
        $model = $model->fresh();

        $this->reconcileEquipmentStatus($model->equipment_id, $updatedAt);

        return [
            'changeId' => $change['id'],
            'table' => 'borrowings',
            'docId' => $change['docId'],
            'status' => $conflicted ? 'CONFLICT' : 'APPLIED',
            'message' => $conflicted ? ($conflictReason ?? 'Overlapping checkout') : null,
            'document' => $model->fresh()->toSyncArray(),
        ];
    }

    /**
     * Derives an asset's physical availability from the authoritative loan
     * rows. MAINTENANCE / DECOMMISSIONED / LOST are operator decisions and are
     * never overwritten by this reconciliation.
     */
    protected function reconcileEquipmentStatus(?string $equipmentId, Carbon $updatedAt): void
    {
        if (! $equipmentId) {
            return;
        }

        $equipment = Equipment::find($equipmentId);
        if (! $equipment || ! in_array($equipment->status, ['AVAILABLE', 'BORROWED'], true)) {
            return;
        }

        $hasActiveLoan = BorrowingTransaction::query()
            ->active()
            ->where('equipment_id', $equipmentId)
            ->exists();

        $desired = $hasActiveLoan ? 'BORROWED' : 'AVAILABLE';
        if ($desired === $equipment->status) {
            return;
        }

        $equipment->forceFill(['status' => $desired, 'updated_at' => $updatedAt])->save();
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    protected function verdict(array $change, string $status, ?array $document): array
    {
        return [
            'changeId' => $change['id'],
            'table' => $change['table'],
            'docId' => $change['docId'],
            'status' => $status,
            'document' => $document,
        ];
    }

    protected function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    protected function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** Connection-level failures must not be reported as per-change rejections. */
    protected function isInfrastructureFailure(Throwable $e): bool
    {
        if ($e instanceof \Illuminate\Database\QueryException) {
            // 2002 refused, 2006 gone away, 1045 auth, 1049 unknown database.
            return in_array((string) $e->getCode(), ['2002', '2006', 'HY000', '08S01', '1045', '1049'], true)
                && ! Str::contains($e->getMessage(), 'Duplicate entry');
        }

        return $e instanceof \PDOException;
    }

    protected function describe(Throwable $e): string
    {
        $message = trim($e->getMessage());

        return $message !== '' ? Str::limit($message, 500) : class_basename($e);
    }
}
