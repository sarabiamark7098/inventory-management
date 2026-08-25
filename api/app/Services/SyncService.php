<?php

namespace App\Services;

use App\Domain\InventoryStatus;
use App\Domain\LoanCalculator;
use App\Models\BorrowingTransaction;
use App\Models\Category;
use App\Models\Equipment;
use App\Models\SyncChange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bidirectional sync between offline clients and the central database.
 *
 * Push applies queued client mutations one at a time, each in its own
 * transaction, and returns a per-change verdict. Pull streams back everything
 * changed since the client's last checkpoint.
 */
class SyncService
{
    /**
     * @param  array{clientId: string, changes: array<int, array>}  $request
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
                $results[] = $this->applyChange($change, $request['clientId']);
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

    public function pull(?CarbonImmutable $since): array
    {
        // Captured before the reads so a row written mid-query is re-sent on the
        // next pull rather than silently skipped.
        $serverTime = now()->toIso8601ZuluString('millisecond');

        $scope = fn ($query) => $since
            ? $query->where('updated_at', '>', $since)
            : $query;

        return [
            'serverTime' => $serverTime,
            'categories' => $scope(Category::query()->orderBy('updated_at'))
                ->get()->map->toSyncArray()->all(),
            'equipment' => $scope(Equipment::query()->orderBy('updated_at'))
                ->get()->map->toSyncArray()->all(),
            'borrowings' => $scope(BorrowingTransaction::query()->orderBy('updated_at'))
                ->get()->map->toSyncArray()->all(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Dispatch                                                            */
    /* ------------------------------------------------------------------ */

    protected function applyChange(array $change, string $clientId): array
    {
        return DB::transaction(function () use ($change, $clientId) {
            $result = match ($change['table']) {
                'categories' => $this->applyCategory($change),
                'equipment' => $this->applyEquipment($change),
                'borrowings' => $this->applyBorrowing($change),
            };

            if (in_array($result['status'], ['APPLIED', 'CONFLICT'], true)) {
                SyncChange::create([
                    'entity_type' => $change['table'],
                    'entity_id' => $change['docId'],
                    'operation' => $change['action'],
                    'data' => $change['payload'],
                    'client_id' => $clientId,
                    'client_timestamp' => Carbon::parse($change['clientTimestamp']),
                ]);
            }

            return $result;
        });
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

    protected function applyEquipment(array $change): array
    {
        $payload = $change['payload'];
        $existing = Equipment::find($change['docId']);
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
        $tagOwner = Equipment::where('asset_tag', $assetTag)->first();
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
        $parNumber = $this->str($payload['parNumber'] ?? null);
        $parIssuedTo = $this->str($payload['parIssuedTo'] ?? null);
        $propertyNumber = $this->str($payload['propertyNumber'] ?? null);
        $dateAcquired = $this->date($payload['dateAcquired'] ?? null);

        if (! $existing) {
            $missing = match (true) {
                $propertyNumber === null => 'a property number',
                $dateAcquired === null => 'an acquisition date',
                $parNumber === null => 'a PAR number',
                $parIssuedTo === null => 'the name the PAR is issued to',
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
        if ($propertyNumber !== null) {
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

        $categoryId = $this->str($payload['categoryId'] ?? null);
        $category = $categoryId ? Category::find($categoryId) : null;

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
            if ($value === null) {
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
            'section' => $this->str($payload['section'] ?? null) ?? $existing?->section,
            'division' => $this->str($payload['division'] ?? null) ?? $existing?->division,
            'province' => $this->str($payload['province'] ?? null) ?? $existing?->province,
            'par_number' => $parNumber ?? $existing?->par_number,
            'par_issued_to' => $parIssuedTo ?? $existing?->par_issued_to,
            'sub_par_number' => $this->str($payload['subParNumber'] ?? null),
            'sub_par_issued_to' => $this->str($payload['subParIssuedTo'] ?? null),
            'actual_user' => $this->str($payload['actualUser'] ?? null),
            'position' => $this->str($payload['position'] ?? null),
            'ad_username' => $this->str($payload['adUsername'] ?? null),
            'anydesk_number' => $this->str($payload['anydeskNumber'] ?? null),
            'specifications' => is_array($payload['specifications'] ?? null) ? $payload['specifications'] : [],
            'deleted_at' => $this->date($payload['deletedAt'] ?? null),
            'updated_at' => $updatedAt,
        ];

        $model = $existing ?? new Equipment;
        if (! $existing) {
            $model->id = $change['docId'];
            $attributes['created_at'] = $this->date($payload['createdAt'] ?? null) ?? $updatedAt;
        }
        $model->forceFill($attributes)->save();

        return $this->verdict($change, 'APPLIED', $model->fresh()->toSyncArray());
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
