<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncPushRequest;
use App\Services\SyncService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncController extends Controller
{
    public function __construct(protected SyncService $sync) {}

    /** Uploads queued offline mutations from a client's outbox. */
    public function push(SyncPushRequest $request): JsonResponse
    {
        try {
            return response()->json($this->sync->push($request->toPushPayload()));
        } catch (Throwable $e) {
            Log::error('[sync/push] '.$e->getMessage(), ['exception' => $e]);

            return response()->json(
                ['error' => 'Sync push failed. The client will retry.'],
                500,
            );
        }
    }

    /**
     * Downloads everything changed since `last_synced_at` (omit for a full
     * bootstrap). Soft-deleted rows are included so clients learn about removals.
     */
    public function pull(Request $request): JsonResponse
    {
        $param = $request->query('last_synced_at');
        $since = null;

        if (is_string($param) && trim($param) !== '') {
            try {
                $since = CarbonImmutable::parse($param);
            } catch (Throwable) {
                return response()->json(
                    ['error' => '`last_synced_at` must be an ISO-8601 timestamp.'],
                    400,
                );
            }
        }

        try {
            return response()->json($this->sync->pull($since));
        } catch (Throwable $e) {
            Log::error('[sync/pull] '.$e->getMessage(), ['exception' => $e]);

            return response()->json(
                ['error' => 'Sync pull failed. The client will retry.'],
                500,
            );
        }
    }
}
