<?php

use App\Domain\Role;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
 * ICT Equipment & Borrowing Management API.
 *
 * Authentication is passwordless: an emailed one-time code is exchanged for a
 * Sanctum bearer token. Everything except /health and the two OTP steps
 * requires that token.
 */

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'service' => 'ict-inventory-api',
    'time' => now()->toIso8601ZuluString('millisecond'),
]));

/* ----------------------------- public ----------------------------- */

Route::prefix('auth')->group(function () {
    Route::post('/request-code', [AuthController::class, 'requestCode']);
    Route::post('/verify-code', [AuthController::class, 'verifyCode']);
});

/* --------------------------- authenticated -------------------------- */

Route::middleware(['auth:sanctum', 'role'])->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/profile', [AuthController::class, 'updateProfile']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/logout-all', [AuthController::class, 'logoutAll']);
    });

    // Readable by every signed-in role, including borrowers browsing the catalogue.
    Route::get('/offices', [InventoryController::class, 'offices']);
    Route::get('/categories', [InventoryController::class, 'categories']);
    Route::get('/equipment', [InventoryController::class, 'equipment']);
    Route::get('/borrowings', [InventoryController::class, 'borrowings']);
    Route::get('/stats', [InventoryController::class, 'stats']);
    Route::get('/sync/pull', [SyncController::class, 'pull']);

    // Writing inventory is limited to staff who physically handle the assets.
    Route::middleware('role:'.Role::ADMIN.','.Role::MANAGER)->group(function () {
        Route::post('/sync/push', [SyncController::class, 'push']);
        Route::post('/{table}', [InventoryController::class, 'store'])
            ->whereIn('table', ['offices', 'categories', 'equipment', 'borrowings']);
        Route::put('/{table}/{id}', [InventoryController::class, 'update'])
            ->whereIn('table', ['offices', 'categories', 'equipment', 'borrowings']);
        Route::delete('/{table}/{id}', [InventoryController::class, 'destroy'])
            ->whereIn('table', ['offices', 'categories', 'equipment', 'borrowings']);
    });

    // Account management is admin-only.
    Route::middleware('role:'.Role::ADMIN)->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
    });
});
