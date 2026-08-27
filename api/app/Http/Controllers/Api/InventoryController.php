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
 * The REST surface the client reads and writes through.
 *
 * Writes are deliberately funnelled through SyncService so the Status Lock Rule
 * and Last-Write-Wins live in exactly one place — a direct Eloquent write here
 * would be a second, divergent code path.
 */
class InventoryController extends Controller
{
    public function __construct(protected SyncService $sync) {}

    /* ----------------------------- reads ----------------------------- */

    /**
     * The office register: divisions, each with its sections.
     *
     * Returned as a tree because that is how every consumer uses it — a
     * cascading pair of pickers — and flattening it only to re-nest on the
     * client is work nobody needs.
     */
    public function offices(Request $request): JsonResponse
    {
        $query = Office::query();
        $this->applySoftDeleteFilter($query, $request);

        if ($search = $request->query('search')) {
            $like = '%'.$search.'%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like));
        }

        $offices = $query->orderBy('name')->get();
        $sections = $offices->whereNotNull('division_id')->groupBy('division_id');

        return response()->json([
            'data' => $offices->map->toSyncArray()->values()->all(),
            'tree' => $offices->whereNull('division_id')->values()->map(fn ($division) => [
                'id' => $division->id,
                'code' => $division->code,
                'name' => $division->name,
                'sections' => ($sections[$division->id] ?? collect())
                    ->map->toSyncArray()->values()->all(),
            ])->all(),
        ]);
    }

    public function categories(Request $request): JsonResponse
    {
        $query = Category::query();
        $this->applySoftDeleteFilter($query, $request);

        return response()->json([
            'data' => $query->orderBy('name')->get()->map->toSyncArray()->all(),
        ]);
    }

    public function equipment(Request $request): JsonResponse
    {
        $query = Equipment::query();
        $this->applySoftDeleteFilter($query, $request);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($categoryId = $request->query('category_id')) {
            $query->where('category_id', $categoryId);
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where('asset_tag', 'like', $like)
                    ->orWhere('serial_number', 'like', $like)
                    ->orWhere('model_name', 'like', $like)
                    ->orWhere('brand', 'like', $like);
            });
        }

        return response()->json([
            'data' => $query->orderByDesc('updated_at')->get()->map->toSyncArray()->all(),
        ]);
    }

    public function borrowings(Request $request): JsonResponse
    {
        $query = BorrowingTransaction::query();
        $this->applySoftDeleteFilter($query, $request);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($request->boolean('active')) {
            $query->active();
        }

        return response()->json([
            'data' => $query->orderByDesc('updated_at')->get()->map->toSyncArray()->all(),
        ]);
    }

    /** Dashboard counters, computed server-side rather than summed on the client. */
    public function stats(): JsonResponse
    {
        $byStatus = Equipment::query()
            ->whereNull('deleted_at')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $active = BorrowingTransaction::query()->active();

        return response()->json([
            'equipment' => [
                'total' => (int) $byStatus->sum(),
                'byStatus' => $byStatus->map(fn ($n) => (int) $n),
            ],
            'loans' => [
                'active' => (clone $active)->count(),
                'overdue' => (clone $active)->where('expected_return_date', '<', now())->count(),
                'requested' => BorrowingTransaction::where('status', 'REQUESTED')->whereNull('deleted_at')->count(),
                'conflicts' => BorrowingTransaction::where('status', 'CONFLICT_PENDING_REVIEW')->whereNull('deleted_at')->count(),
            ],
        ]);
    }

    /* ----------------------------- writes ---------------------------- */

    public function store(Request $request, string $table): JsonResponse
    {
        $payload = $request->all();
        $docId = $payload['id'] ?? (string) Str::uuid();
        $payload['id'] = $docId;

        return $this->delegate($table, 'INSERT', $docId, $payload);
    }

    public function update(Request $request, string $table, string $id): JsonResponse
    {
        $payload = $request->all();
        $payload['id'] = $id;

        return $this->delegate($table, 'UPDATE', $id, $payload);
    }

    /** A soft delete: the row moves to the archive, it is not destroyed. */
    public function destroy(Request $request, string $table, string $id): JsonResponse
    {
        $now = now()->toIso8601ZuluString('millisecond');

        return $this->delegate($table, 'DELETE', $id, [
            'id' => $id,
            'deletedAt' => $now,
            'updatedAt' => $now,
        ]);
    }

    protected function delegate(string $table, string $action, string $docId, array $payload): JsonResponse
    {
        abort_unless(in_array($table, ['offices', 'categories', 'equipment', 'borrowings'], true), 404);

        if (! Str::isUuid($docId)) {
            return response()->json(['error' => 'Resource id must be a UUID.'], 422);
        }

        $now = now()->toIso8601ZuluString('millisecond');
        $payload['updatedAt'] ??= $now;

        $response = $this->sync->push([
            // No author is passed: the activity log resolves the causer from
            // the authenticated user itself, so there is nothing here to keep
            // in step with it.
            'changes' => [[
                'id' => (string) Str::uuid(),
                'table' => $table,
                'action' => $action,
                'docId' => $docId,
                'payload' => $payload,
                'clientTimestamp' => $now,
            ]],
        ]);

        $result = $response['results'][0];
        $httpStatus = match ($result['status']) {
            'APPLIED' => $action === 'INSERT' ? 201 : 200,
            'CONFLICT' => 409,
            'IGNORED_STALE' => 200,
            default => 422,
        };

        // The saved row travels back with the verdict — including on CONFLICT,
        // which is not a failure: the loan was stored, flagged for review. The server normalises
        // values on the way in (trimming, defaults, the loan-date calculation),
        // so echoing the request payload would show the client something subtly
        // different from what was actually stored.
        if (in_array($result['status'], ['APPLIED', 'IGNORED_STALE', 'CONFLICT'], true)) {
            $result['data'] = $this->fetch($table, $docId);
        }

        return response()->json($result, $httpStatus);
    }

    /** The stored row for `$table`, as the client sees it. */
    protected function fetch(string $table, string $id): ?array
    {
        $model = match ($table) {
            'offices' => Office::find($id),
            'categories' => Category::find($id),
            'equipment' => Equipment::find($id),
            'borrowings' => BorrowingTransaction::find($id),
        };

        return $model?->toSyncArray();
    }

    protected function applySoftDeleteFilter($query, Request $request): void
    {
        if (! $request->boolean('with_deleted')) {
            $query->whereNull('deleted_at');
        }
    }
}
