<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BorrowingTransaction;
use App\Models\Category;
use App\Models\Equipment;
use App\Models\Office;
use App\Services\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The archive: records that were removed but not destroyed.
 *
 * Deleting anywhere else in the system is a soft delete — the row stays, with
 * `deleted_at` set, so the loan history and property trail it belongs to stay
 * readable. That makes it invisible, not gone, and without this screen there
 * was no way to see what had accumulated or to finally dispose of it.
 */
class ArchiveController extends Controller
{
    public function __construct(protected SyncService $sync) {}

    /**
     * Everything currently archived, in one payload.
     *
     * All four tables together rather than four endpoints: the screen is a set
     * of tabs over a single "what has been removed" question, and the volume is
     * small by definition — anything large enough to paginate should have been
     * purged long ago.
     */
    public function index(Request $request): JsonResponse
    {
        $archived = fn ($query) => $query->whereNotNull('deleted_at')
            ->orderByDesc('deleted_at')
            ->get()
            ->map
            ->toSyncArray()
            ->values()
            ->all();

        $equipment = $archived(Equipment::query());

        // How much loan history each asset would take with it if purged. One
        // grouped query rather than one per row.
        $loanCounts = BorrowingTransaction::query()
            ->whereIn('equipment_id', array_column($equipment, 'id'))
            ->selectRaw('equipment_id, COUNT(*) as total')
            ->groupBy('equipment_id')
            ->pluck('total', 'equipment_id');

        $activeCounts = BorrowingTransaction::query()
            ->whereIn('equipment_id', array_column($equipment, 'id'))
            ->whereNull('deleted_at')
            ->active()
            ->selectRaw('equipment_id, COUNT(*) as total')
            ->groupBy('equipment_id')
            ->pluck('total', 'equipment_id');

        $equipment = array_map(fn ($row) => $row + [
            'loanCount' => (int) ($loanCounts[$row['id']] ?? 0),
            'activeLoanCount' => (int) ($activeCounts[$row['id']] ?? 0),
        ], $equipment);

        return response()->json([
            'equipment' => $equipment,
            'categories' => $archived(Category::query()),
            'offices' => $archived(Office::query()),
            'borrowings' => $archived(BorrowingTransaction::query()),
        ]);
    }

    /**
     * Puts an archived asset back into the live register.
     *
     * Open to anyone who may write inventory, unlike purging: this undoes a
     * mistake rather than making a new one, and refusing it would leave the
     * only remedy in the hands of an admin.
     */
    public function restoreEquipment(Request $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return response()->json(['error' => 'Resource id must be a UUID.'], 422);
        }

        $result = $this->sync->restoreEquipment($id, $request->user()?->email ?? 'unknown');

        return response()->json($result, match ($result['status']) {
            'RESTORED' => 200,
            'NOT_FOUND' => 404,
            default => 422,
        });
    }

    /**
     * Destroys an archived asset for good.
     *
     * Admin-only at the route, because this is the one operation in the system
     * with no undo: a soft delete can be reversed by clearing a column, this
     * cannot be reversed at all.
     */
    public function purgeEquipment(Request $request, string $id): JsonResponse
    {
        if (! Str::isUuid($id)) {
            return response()->json(['error' => 'Resource id must be a UUID.'], 422);
        }

        $result = $this->sync->purgeEquipment($id, $request->user()?->email ?? 'unknown');

        return response()->json($result, match ($result['status']) {
            'PURGED' => 200,
            'NOT_FOUND' => 404,
            default => 422,
        });
    }
}
