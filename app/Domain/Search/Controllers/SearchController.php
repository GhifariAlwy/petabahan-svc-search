<?php

namespace App\Domain\Search\Controllers;

use App\Domain\Search\Requests\SearchRequest;
use App\Domain\Search\Services\SupplierSearchService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(
        protected SupplierSearchService $searchService,
    ) {}

    /**
     * POST /api/v1/search
     * Multi-material PostGIS geosearch with Green/Yellow pin classification & Worth It ranking.
     */
    public function search(SearchRequest $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id') 
            ?? $request->user()?->merchant_id 
            ?? $request->header('X-Merchant-Id');

        $result = $this->searchService->search($request->validated(), $merchantId);

        return ApiResponse::success($result);
    }

    /**
     * GET /api/v1/search/{supplierId}/detail
     * Detailed store profile, opening hours, transparent tiered pricing, and missing items.
     */
    public function detail(Request $request, string $supplierId): JsonResponse
    {
        $lat = $request->query('lat') !== null ? (float) $request->query('lat') : null;
        $lng = $request->query('lng') !== null ? (float) $request->query('lng') : null;

        $items = [];
        if ($request->has('items')) {
            $rawItems = $request->input('items');
            $items = is_string($rawItems) ? json_decode($rawItems, true) : (array) $rawItems;
        }

        $result = $this->searchService->getSupplierDetail($supplierId, $items ?: [], $lat, $lng);

        return ApiResponse::success($result);
    }
}
