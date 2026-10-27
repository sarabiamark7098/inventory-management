<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Office;

/**
 * Lookups resolved once for a whole batch, instead of once per row.
 *
 * A single-row write can afford to ask the database what office `X` is. Five
 * thousand of them cannot: the offices and categories are the same on every
 * row, and asking again for each one turned a bulk import into tens of
 * thousands of identical queries.
 *
 * `$preValidated` is the sharper claim, and it is only ever true for the
 * importer. It says the caller has already checked every uniqueness rule
 * against a snapshot of the register, so the per-row SELECTs that look for a
 * clashing asset tag, property number or serial can be skipped. The unique
 * indexes remain the actual guarantee — a row that races another writer still
 * fails, and `SyncService::pushBatch()` replays that chunk one row at a time so
 * the clash costs one record rather than the whole chunk.
 */
final class BulkContext
{
    /** @var array<string, Office> */
    private array $offices;

    /** @var array<string, Category> */
    private array $categories;

    public function __construct(public readonly bool $preValidated = false)
    {
        $this->offices = Office::query()
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('id')
            ->all();

        $this->categories = Category::query()
            ->get()
            ->keyBy('id')
            ->all();
    }

    /** A division: an office with no parent. */
    public function division(?string $id): ?Office
    {
        $office = $id === null ? null : ($this->offices[$id] ?? null);

        return $office && $office->division_id === null ? $office : null;
    }

    /** A section: an office that belongs to a division. */
    public function section(?string $id): ?Office
    {
        $office = $id === null ? null : ($this->offices[$id] ?? null);

        return $office && $office->division_id !== null ? $office : null;
    }

    public function category(?string $id): ?Category
    {
        return $id === null ? null : ($this->categories[$id] ?? null);
    }
}
