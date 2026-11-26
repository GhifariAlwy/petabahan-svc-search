<?php

namespace App\Domain\Search\Controllers;

use App\Domain\Search\Models\ShoppingList;
use App\Domain\Search\Models\ShoppingListItem;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShoppingListController extends Controller
{
    /**
     * GET /api/v1/shopping-lists
     */
    public function index(Request $request): JsonResponse
    {
        $merchantId = $request->attributes->get('merchant_id') 
            ?? $request->user()?->merchant_id 
            ?? $request->header('X-Merchant-Id');

        $query = ShoppingList::with('items')->orderByDesc('created_at');
        if ($merchantId) {
            $query->where('merchant_id', $merchantId);
        }

        $lists = $query->paginate(20);

        return ApiResponse::paginated($lists);
    }

    /**
     * POST /api/v1/shopping-lists
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'radius_m' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.material_id' => ['required', 'string'],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit' => ['required', 'string'],
        ]);

        $merchantId = $request->attributes->get('merchant_id') 
            ?? $request->user()?->merchant_id 
            ?? $request->header('X-Merchant-Id');

        $shoppingList = ShoppingList::create([
            'merchant_id' => $merchantId,
            'radius_m' => (int) ($request->input('radius_m') ?? 5000),
            'latitude' => $request->input('latitude'),
            'longitude' => $request->input('longitude'),
        ]);
        $shoppingList->updatePostGisLocation();

        foreach ($request->input('items') as $item) {
            ShoppingListItem::create([
                'shopping_list_id' => $shoppingList->id,
                'material_id' => $item['material_id'],
                'qty' => $item['qty'],
                'unit' => $item['unit'],
            ]);
        }

        return ApiResponse::success($shoppingList->load('items'), 201);
    }
}
