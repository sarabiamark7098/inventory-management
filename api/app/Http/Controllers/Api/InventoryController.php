<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BorrowingTransaction;
use App\Models\Category;
use App\Models\Equipment;
use App\Services\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Conventional REST surface for API consumers that are not the offline client.
 *
 * Writes are deliberately funnelled through SyncService so the Status Lock Rule
 * and Last-Write-Wins live in exactly one place — a direct Eloquent write here
 * would be a second, divergent code path.
 */
class InventoryController extends Controller
{
    public function __construct(protected SyncService $sync) {}

    /* ----------------------------- reads ----------------------------- */

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

    /** Dashboard counters, computed server-side for non-offline consumers. */
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

    public function destroy(string $table, string $id): JsonResponse
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
        abort_unless(in_array($table, ['categories', 'equipment', 'borrowings'], true), 404);

        if (! Str::isUuid($docId)) {
            return response()->json(['error' => 'Resource id must be a UUID.'], 422);
        }

        $now = now()->toIso8601ZuluString('millisecond');
        $payload['updatedAt'] ??= $now;

        $response = $this->sync->push([
            'clientId' => 'rest-api',
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

        return response()->json($result, $httpStatus);
    }

    protected function applySoftDeleteFilter($query, Request $request): void
    {
        if (! $request->boolean('with_deleted')) {
            $query->whereNull('deleted_at');
        }
    }
}
