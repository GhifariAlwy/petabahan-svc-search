<?php

namespace App\Domain\Search\Services;

use App\Domain\Search\Models\SearchSupplierProjection;
use App\Domain\Search\Models\ShoppingList;
use App\Domain\Search\Models\ShoppingListItem;
use App\Support\ErrorCode;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Collection;

class SupplierSearchService
{
    public const DEFAULT_BASE_SHIPPING = 10000;
    public const DEFAULT_SHIPPING_PER_KM = 2500;
    public const DEFAULT_MERCHANT_FEE = 2000;

    /**
     * Execute Multi-Material Geosearch with PostGIS, Pin classification, and Worth It ranking.
     *
     * @param  array{
     *     latitude: float,
     *     longitude: float,
     *     radius_m?: int,
     *     save_as_shopping_list?: bool,
     *     items: array<int, array{material_id: string, qty: float, unit: string}>
     * }  $params
     * @param  string|null  $merchantId
     * @return array<string, mixed>
     */
    public function search(array $params, ?string $merchantId = null): array
    {
        $lat = (float) $params['latitude'];
        $lng = (float) $params['longitude'];
        $radiusMeters = (int) ($params['radius_m'] ?? 5000);
        $items = $params['items'] ?? [];
        $saveShoppingList = (bool) ($params['save_as_shopping_list'] ?? false);

        $shoppingListId = null;
        if ($saveShoppingList && $merchantId) {
            $shoppingList = ShoppingList::create([
                'merchant_id' => $merchantId,
                'radius_m' => $radiusMeters,
                'latitude' => $lat,
                'longitude' => $lng,
            ]);
            $shoppingList->updatePostGisLocation();

            foreach ($items as $item) {
                ShoppingListItem::create([
                    'shopping_list_id' => $shoppingList->id,
                    'material_id' => $item['material_id'],
                    'qty' => $item['qty'],
                    'unit' => $item['unit'],
                ]);
            }
            $shoppingListId = $shoppingList->id;
        }

        // 1. PostGIS Query: Filter active, approved, and sufficient deposit (DEC-005)
        /** @var Collection<int, SearchSupplierProjection> $suppliers */
        $suppliers = SearchSupplierProjection::query()
            ->activeAndSufficient()
            ->withinRadius($lat, $lng, $radiusMeters)
            ->get();

        $greenPins = [];
        $yellowPins = [];

        foreach ($suppliers as $supplier) {
            $evaluation = $this->evaluateSupplierItems($supplier, $items);

            // SRS FR-C3: 0% ketersediaan tidak ditampilkan
            if ($evaluation['fulfillment_percentage'] <= 0) {
                continue;
            }

            $distanceMeters = (int) round($supplier->distance_meters ?? 0);
            $distanceKm = round($distanceMeters / 1000, 2);

            // SRS FR-C4: Kalkulasi Worth It Score
            $shippingCost = $this->estimateShippingCost($distanceMeters);
            $merchantFee = self::DEFAULT_MERCHANT_FEE;
            $worthItScore = $evaluation['subtotal_materials'] + $shippingCost + $merchantFee;

            $supplierResult = [
                'supplier_id' => $supplier->supplier_id,
                'store_name' => $supplier->store_name,
                'address' => $supplier->address,
                'whatsapp_number' => $supplier->whatsapp_number,
                'location' => [
                    'latitude' => (float) $supplier->latitude,
                    'longitude' => (float) $supplier->longitude,
                ],
                'distance_meters' => $distanceMeters,
                'distance_km' => $distanceKm,
                'pin_color' => $evaluation['pin_color'],
                'fulfillment_percentage' => $evaluation['fulfillment_percentage'],
                'worth_it_score' => $worthItScore,
                'price_breakdown' => [
                    'subtotal_materials' => $evaluation['subtotal_materials'],
                    'shipping_cost_estimate' => $shippingCost,
                    'merchant_fee' => $merchantFee,
                ],
                'items_summary' => [
                    'total_requested' => count($items),
                    'available_count' => $evaluation['available_count'],
                    'unavailable_count' => count($items) - $evaluation['available_count'],
                ],
                'items' => $evaluation['evaluated_items'],
            ];

            if ($evaluation['pin_color'] === 'green') {
                $greenPins[] = $supplierResult;
            } else {
                $yellowPins[] = $supplierResult;
            }
        }

        // 2. Ranking Prioritas (SRS FR-C4):
        // Kelompok 1 (Hijau): worth_it_score ASC
        usort($greenPins, fn (array $a, array $b) => $a['worth_it_score'] <=> $b['worth_it_score']);

        // Kelompok 2 (Kuning): fulfillment_percentage DESC, lalu worth_it_score ASC
        usort($yellowPins, function (array $a, array $b) {
            if ($b['fulfillment_percentage'] !== $a['fulfillment_percentage']) {
                return $b['fulfillment_percentage'] <=> $a['fulfillment_percentage'];
            }
            return $a['worth_it_score'] <=> $b['worth_it_score'];
        });

        $rankedSuppliers = array_merge($greenPins, $yellowPins);

        return [
            'search_id' => $shoppingListId,
            'origin' => [
                'latitude' => $lat,
                'longitude' => $lng,
                'radius_m' => $radiusMeters,
            ],
            'summary' => [
                'total_suppliers_found' => count($rankedSuppliers),
                'green_pins_count' => count($greenPins),
                'yellow_pins_count' => count($yellowPins),
            ],
            'suppliers' => $rankedSuppliers,
        ];
    }

    /**
     * Get detailed pricing, price tiers, and stock status for a specific supplier.
     *
     * @param  string  $supplierId
     * @param  array<int, array{material_id: string, qty: float, unit: string}>  $items
     * @param  float|null  $userLat
     * @param  float|null  $userLng
     * @return array<string, mixed>
     */
    public function getSupplierDetail(string $supplierId, array $items = [], ?float $userLat = null, ?float $userLng = null): array
    {
        /** @var SearchSupplierProjection|null $supplier */
        $supplier = SearchSupplierProjection::query()->find($supplierId);

        if (! $supplier) {
            throw new ApiException(
                errorCode: ErrorCode::NOT_FOUND,
                message: 'Toko bahan bangunan tidak ditemukan.',
                httpStatus: 404,
            );
        }

        $distanceMeters = 0;
        if ($userLat !== null && $userLng !== null && $supplier->latitude !== null && $supplier->longitude !== null) {
            $distanceMeters = $this->calculateHaversineDistance(
                $userLat,
                $userLng,
                (float) $supplier->latitude,
                (float) $supplier->longitude
            );
        }

        $evaluation = $this->evaluateSupplierItems($supplier, $items);
        $shippingCost = $this->estimateShippingCost($distanceMeters);
        $merchantFee = self::DEFAULT_MERCHANT_FEE;
        $grandTotalEstimate = $evaluation['subtotal_materials'] + $shippingCost + $merchantFee;

        $availableItems = [];
        $unavailableItems = [];

        foreach ($evaluation['evaluated_items'] as $item) {
            if ($item['is_available']) {
                $availableItems[] = $item;
            } else {
                $unavailableItems[] = $item;
            }
        }

        return [
            'supplier_id' => $supplier->supplier_id,
            'store_name' => $supplier->store_name,
            'address' => $supplier->address,
            'city' => $supplier->city,
            'province' => $supplier->province,
            'whatsapp_number' => $supplier->whatsapp_number,
            'opening_hours' => $supplier->opening_hours,
            'location' => [
                'latitude' => (float) $supplier->latitude,
                'longitude' => (float) $supplier->longitude,
            ],
            'distance_meters' => $distanceMeters,
            'distance_km' => round($distanceMeters / 1000, 2),
            'pin_color' => $evaluation['pin_color'],
            'fulfillment_percentage' => $evaluation['fulfillment_percentage'],
            'price_summary' => [
                'subtotal_materials' => $evaluation['subtotal_materials'],
                'shipping_cost_estimate' => $shippingCost,
                'merchant_fee' => $merchantFee,
                'grand_total_estimate' => $grandTotalEstimate,
            ],
            'available_items' => $availableItems,
            'unavailable_items' => $unavailableItems,
        ];
    }

    /**
     * Evaluate requested items against supplier's products snapshot.
     *
     * @param  SearchSupplierProjection  $supplier
     * @param  array<int, array{material_id: string, qty: float, unit: string}>  $requestedItems
     * @return array{
     *     evaluated_items: array<int, array<string, mixed>>,
     *     available_count: int,
     *     subtotal_materials: int,
     *     fulfillment_percentage: float,
     *     pin_color: string
     * }
     */
    public function evaluateSupplierItems(SearchSupplierProjection $supplier, array $requestedItems): array
    {
        $productsSnapshot = $supplier->products_snapshot ?? [];
        $evaluatedItems = [];
        $availableCount = 0;
        $subtotalMaterials = 0;

        foreach ($requestedItems as $req) {
            $materialId = $req['material_id'];
            $reqQty = (float) $req['qty'];
            $reqUnit = $req['unit'] ?? 'unit';

            // Find matching product in snapshot
            $matchedProduct = null;
            foreach ($productsSnapshot as $prod) {
                if (($prod['material_id'] ?? null) === $materialId && ($prod['is_available'] ?? true)) {
                    $matchedProduct = $prod;
                    break;
                }
            }

            if ($matchedProduct === null) {
                $evaluatedItems[] = [
                    'material_id' => $materialId,
                    'material_name' => 'Bahan Baku',
                    'product_id' => null,
                    'brand' => null,
                    'unit' => $reqUnit,
                    'qty_requested' => $reqQty,
                    'unit_price_applied' => 0,
                    'line_total' => 0,
                    'is_available' => false,
                    'stock_status' => 'habis',
                    'reason' => 'Tidak disediakan oleh toko ini',
                ];
                continue;
            }

            // Determine stock availability
            $stockMode = $matchedProduct['stock_mode'] ?? 'status';
            $isAvailable = false;
            $stockStatus = $matchedProduct['stock_status'] ?? 'tersedia';

            if ($stockMode === 'numeric') {
                $qtyOnHand = (float) ($matchedProduct['qty_on_hand'] ?? 0);
                $qtyReserved = (float) ($matchedProduct['qty_reserved'] ?? 0);
                $qtyAvailable = max(0, $qtyOnHand - $qtyReserved);
                $isAvailable = $qtyAvailable >= $reqQty;
                $stockStatus = $isAvailable ? 'banyak' : 'menipis';
            } else {
                // Quick Toggle status mode: 'banyak' / 'menipis' = available, 'habis' = unavailable
                $isAvailable = in_array($stockStatus, ['banyak', 'menipis', 'tersedia'], true);
            }

            // Determine price tier
            $unitPrice = $this->determineApplicableUnitPrice($matchedProduct, $reqQty);
            $lineTotal = $isAvailable ? (int) round($reqQty * $unitPrice) : 0;

            if ($isAvailable) {
                $availableCount++;
                $subtotalMaterials += $lineTotal;
            }

            $evaluatedItems[] = [
                'material_id' => $materialId,
                'material_name' => $matchedProduct['material_name'] ?? 'Bahan Baku',
                'product_id' => $matchedProduct['product_id'] ?? null,
                'brand' => $matchedProduct['brand'] ?? 'Standar',
                'unit' => $matchedProduct['unit'] ?? $reqUnit,
                'qty_requested' => $reqQty,
                'unit_price_applied' => $unitPrice,
                'line_total' => $lineTotal,
                'is_available' => $isAvailable,
                'stock_status' => $stockStatus,
            ];
        }

        $totalRequested = count($requestedItems);
        $fulfillmentPercentage = $totalRequested > 0 ? round(($availableCount / $totalRequested) * 100, 2) : 0.0;

        $pinColor = 'none';
        if ($fulfillmentPercentage >= 100.0) {
            $pinColor = 'green';
        } elseif ($fulfillmentPercentage > 0.0) {
            $pinColor = 'yellow';
        }

        return [
            'evaluated_items' => $evaluatedItems,
            'available_count' => $availableCount,
            'subtotal_materials' => $subtotalMaterials,
            'fulfillment_percentage' => $fulfillmentPercentage,
            'pin_color' => $pinColor,
        ];
    }

    /**
     * Determine the tiered unit price for a product based on requested quantity.
     *
     * @param  array<string, mixed>  $product
     * @param  float  $qty
     * @return int
     */
    public function determineApplicableUnitPrice(array $product, float $qty): int
    {
        $tiers = $product['price_tiers'] ?? [];
        if (empty($tiers)) {
            return (int) ($product['base_price'] ?? 0);
        }

        // Tiers should be ordered by min_qty ASC
        usort($tiers, fn ($a, $b) => ($a['min_qty'] ?? 0) <=> ($b['min_qty'] ?? 0));

        foreach ($tiers as $tier) {
            $minQty = (float) ($tier['min_qty'] ?? 0);
            $maxQty = isset($tier['max_qty']) && $tier['max_qty'] !== null ? (float) $tier['max_qty'] : null;

            if ($qty >= $minQty && ($maxQty === null || $qty <= $maxQty)) {
                return (int) ($tier['unit_price'] ?? 0);
            }
        }

        // Fallback to the first tier or highest tier
        return (int) ($tiers[0]['unit_price'] ?? 0);
    }

    /**
     * Estimate shipping cost based on distance.
     * Base Rp 10.000 + Rp 2.500 per km.
     */
    public function estimateShippingCost(int $distanceMeters): int
    {
        $km = $distanceMeters / 1000;
        return self::DEFAULT_BASE_SHIPPING + (int) round(self::DEFAULT_SHIPPING_PER_KM * $km);
    }

    /**
     * Haversine formula calculation in meters.
     */
    public function calculateHaversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): int
    {
        $earthRadius = 6371000; // in meters

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($lonDelta / 2) * sin($lonDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return (int) round($earthRadius * $c);
    }
}
