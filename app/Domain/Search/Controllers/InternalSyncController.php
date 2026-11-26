<?php

namespace App\Domain\Search\Controllers;

use App\Domain\Search\Services\CqrsSyncService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InternalSyncController extends Controller
{
    public function __construct(
        protected CqrsSyncService $syncService,
    ) {}

    /**
     * POST /api/v1/internal/search/sync-supplier
     * Internal CQRS sync endpoint called by events or artisan commands.
     */
    public function syncSupplier(Request $request): JsonResponse
    {
        $request->validate([
            'supplier_id' => ['required', 'string'],
            'store_name' => ['nullable', 'string'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
        ]);

        $projection = $this->syncService->syncSupplier($request->all());

        return ApiResponse::success($projection);
    }
}
