<?php

use App\Domain\Search\Controllers\InternalSyncController;
use App\Domain\Search\Controllers\SearchController;
use App\Domain\Search\Controllers\ShoppingListController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Health Check
    Route::get('/health', fn () => response()->json([
        'status' => 'healthy',
        'service' => 'svc-search',
        'version' => '2.0.0',
    ]))->name('api.v1.health');

    // Internal Service-to-Service CQRS Synchronization (No public access via Nginx)
    Route::prefix('internal/search')->group(function (): void {
        Route::post('/sync-supplier', [InternalSyncController::class, 'syncSupplier'])
            ->name('api.v1.internal.search.sync');
    });

    // Protected Routes (Forward-Auth via Nginx Gateway or Sanctum Bearer)
    Route::middleware(['auth'])->group(function (): void {
        // Geosearch & Map Engine
        Route::post('/search', [SearchController::class, 'search'])
            ->name('api.v1.search');

        Route::get('/search/{supplierId}/detail', [SearchController::class, 'detail'])
            ->name('api.v1.search.detail');

        // Shopping Lists
        Route::get('/shopping-lists', [ShoppingListController::class, 'index'])
            ->name('api.v1.shopping-lists.index');

        Route::post('/shopping-lists', [ShoppingListController::class, 'store'])
            ->name('api.v1.shopping-lists.store');
    });
});
