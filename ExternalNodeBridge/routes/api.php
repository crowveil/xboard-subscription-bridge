<?php

use Illuminate\Support\Facades\Route;
use Plugin\ExternalNodeBridge\Controllers\AdminController;

// Admin middleware resolves the existing Sanctum administrator token.
// No public diagnostics, alternate user tokens or unauthenticated refresh route.
Route::prefix('api/v1/external-node-bridge/admin')->middleware('admin')->group(function () {
    Route::get('session', [AdminController::class, 'session']);
    Route::post('renew', [AdminController::class, 'renew']);
    Route::post('close', [AdminController::class, 'close']);
    Route::get('settings', [AdminController::class, 'settings']);
    Route::post('settings', [AdminController::class, 'save']);
    Route::post('debug', [AdminController::class, 'debug']);
    Route::get('status', [AdminController::class, 'status']);
    Route::get('export', [AdminController::class, 'export']);
    Route::post('health', [AdminController::class, 'health']);
    Route::post('refresh', [AdminController::class, 'refresh']);
});
