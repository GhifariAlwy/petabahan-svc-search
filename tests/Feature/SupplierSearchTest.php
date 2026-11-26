<?php

use App\Domain\Auth\Models\User;
use App\Domain\Search\Models\SearchSupplierProjection;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->merchantId = (string) Str::uuid();
    $this->userId = (string) Str::uuid();

    $this->merchantHeaders = [
        'X-User-Id' => $this->userId,
        'X-User-Role' => 'merchant',
        'X-Merchant-Id' => $this->merchantId,
    ];

    $this->materialSemen = (string) Str::uuid();
    $this->materialPasir = (string) Str::uuid();
    $this->materialBatu = (string) Str::uuid();
});

test('health check returns healthy status', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(200)
        ->assertJson([
            'status' => 'healthy',
            'service' => 'svc-search',
            'version' => '2.0.0',
        ]);
});

test('search requires authentication', function () {
    $response = $this->postJson('/api/v1/search', [
        'latitude' => -6.2088,
        'longitude' => 106.8456,
        'items' => [
            ['material_id' => $this->materialSemen, 'qty' => 10, 'unit' => 'sak'],
        ],
    ]);

    $response->assertStatus(401);
});

test('search validates required parameters', function () {
    $response = $this->withHeaders($this->merchantHeaders)
        ->postJson('/api/v1/search', []);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_ERROR');

    $fields = $response->json('error.details.fields');
    expect($fields)->toHaveKeys(['latitude', 'longitude', 'items']);
});

test('geosearch returns green pin when 100% materials are available', function () {
    // Toko A (Jarak ~500m dari -6.2088, 106.8456)
    SearchSupplierProjection::create([
        'supplier_id' => (string) Str::uuid(),
        'store_name' => 'TB Berkah Makmur',
        'latitude' => -6.2050,
        'longitude' => 106.8450,
        'is_approved' => true,
        'is_accepting_booking' => true,
        'is_deposit_sufficient' => true,
        'products_snapshot' => [
            [
                'product_id' => (string) Str::uuid(),
                'material_id' => $this->materialSemen,
                'material_name' => 'Semen Tiga Roda 40kg',
                'brand' => 'Tiga Roda',
                'unit' => 'sak',
                'stock_mode' => 'status',
                'stock_status' => 'banyak',
                'is_available' => true,
                'price_tiers' => [
                    ['min_qty' => 1, 'max_qty' => 10, 'unit_price' => 65000],
                    ['min_qty' => 11, 'max_qty' => null, 'unit_price' => 63000],
                ],
            ],
            [
                'product_id' => (string) Str::uuid(),
                'material_id' => $this->materialPasir,
                'material_name' => 'Pasir Bangka',
                'brand' => 'Bangka',
                'unit' => 'truk',
                'stock_mode' => 'numeric',
                'qty_on_hand' => 10,
                'qty_reserved' => 2,
                'is_available' => true,
                'price_tiers' => [
                    ['min_qty' => 1, 'max_qty' => null, 'unit_price' => 1200000],
                ],
            ],
        ],
    ]);

    $response = $this->withHeaders($this->merchantHeaders)
        ->postJson('/api/v1/search', [
            'latitude' => -6.2088,
            'longitude' => 106.8456,
            'radius_m' => 5000,
            'items' => [
                ['material_id' => $this->materialSemen, 'qty' => 5, 'unit' => 'sak'],
                ['material_id' => $this->materialPasir, 'qty' => 2, 'unit' => 'truk'],
            ],
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.summary.total_suppliers_found', 1)
        ->assertJsonPath('data.summary.green_pins_count', 1)
        ->assertJsonPath('data.summary.yellow_pins_count', 0)
        ->assertJsonPath('data.suppliers.0.pin_color', 'green')
        ->assertJsonPath('data.suppliers.0.items.0.unit_price_applied', 65000)
        ->assertJsonPath('data.suppliers.0.items.0.line_total', 325000)
        ->assertJsonPath('data.suppliers.0.items.1.unit_price_applied', 1200000)
        ->assertJsonPath('data.suppliers.0.items.1.line_total', 2400000);

    expect((float) $response->json('data.suppliers.0.fulfillment_percentage'))->toEqual(100.0);
});

test('geosearch returns yellow pin when partial materials are available', function () {
    // Toko B (Hanya punya Semen, tidak punya Pasir)
    SearchSupplierProjection::create([
        'supplier_id' => (string) Str::uuid(),
        'store_name' => 'TB Sederhana Jaya',
        'latitude' => -6.2060,
        'longitude' => 106.8460,
        'is_approved' => true,
        'is_accepting_booking' => true,
        'is_deposit_sufficient' => true,
        'products_snapshot' => [
            [
                'product_id' => (string) Str::uuid(),
                'material_id' => $this->materialSemen,
                'material_name' => 'Semen Gresik 40kg',
                'brand' => 'Gresik',
                'unit' => 'sak',
                'stock_mode' => 'status',
                'stock_status' => 'banyak',
                'is_available' => true,
                'price_tiers' => [
                    ['min_qty' => 1, 'max_qty' => null, 'unit_price' => 64000],
                ],
            ],
        ],
    ]);

    $response = $this->withHeaders($this->merchantHeaders)
        ->postJson('/api/v1/search', [
            'latitude' => -6.2088,
            'longitude' => 106.8456,
            'radius_m' => 5000,
            'items' => [
                ['material_id' => $this->materialSemen, 'qty' => 5, 'unit' => 'sak'],
                ['material_id' => $this->materialPasir, 'qty' => 2, 'unit' => 'truk'],
            ],
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.summary.total_suppliers_found', 1)
        ->assertJsonPath('data.summary.green_pins_count', 0)
        ->assertJsonPath('data.summary.yellow_pins_count', 1)
        ->assertJsonPath('data.suppliers.0.pin_color', 'yellow');

    expect((float) $response->json('data.suppliers.0.fulfillment_percentage'))->toEqual(50.0);
});

test('geosearch excludes supplier with 0 percent available materials according to FR-C3', function () {
    // Toko C (Hanya punya Batu Split, bukan Semen / Pasir)
    SearchSupplierProjection::create([
        'supplier_id' => (string) Str::uuid(),
        'store_name' => 'TB Batu Alam',
        'latitude' => -6.2060,
        'longitude' => 106.8460,
        'is_approved' => true,
        'is_accepting_booking' => true,
        'is_deposit_sufficient' => true,
        'products_snapshot' => [
            [
                'product_id' => (string) Str::uuid(),
                'material_id' => $this->materialBatu,
                'material_name' => 'Batu Split',
                'brand' => 'Merak',
                'unit' => 'truk',
                'stock_mode' => 'status',
                'stock_status' => 'banyak',
                'is_available' => true,
                'price_tiers' => [
                    ['min_qty' => 1, 'max_qty' => null, 'unit_price' => 1500000],
                ],
            ],
        ],
    ]);

    $response = $this->withHeaders($this->merchantHeaders)
        ->postJson('/api/v1/search', [
            'latitude' => -6.2088,
            'longitude' => 106.8456,
            'radius_m' => 5000,
            'items' => [
                ['material_id' => $this->materialSemen, 'qty' => 5, 'unit' => 'sak'],
            ],
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.summary.total_suppliers_found', 0);
});

test('geosearch excludes supplier when deposit balance is below minimum according to DEC-005', function () {
    // Toko D (Punya stok lengkap, tetapi saldo deposit habis / is_deposit_sufficient = false)
    SearchSupplierProjection::create([
        'supplier_id' => (string) Str::uuid(),
        'store_name' => 'TB Saldo Kurang',
        'latitude' => -6.2050,
        'longitude' => 106.8450,
        'is_approved' => true,
        'is_accepting_booking' => true,
        'is_deposit_sufficient' => false, // Aturan DEC-005!
        'products_snapshot' => [
            [
                'product_id' => (string) Str::uuid(),
                'material_id' => $this->materialSemen,
                'material_name' => 'Semen Tiga Roda 40kg',
                'brand' => 'Tiga Roda',
                'unit' => 'sak',
                'stock_mode' => 'status',
                'stock_status' => 'banyak',
                'is_available' => true,
                'price_tiers' => [
                    ['min_qty' => 1, 'max_qty' => null, 'unit_price' => 60000],
                ],
            ],
        ],
    ]);

    $response = $this->withHeaders($this->merchantHeaders)
        ->postJson('/api/v1/search', [
            'latitude' => -6.2088,
            'longitude' => 106.8456,
            'radius_m' => 5000,
            'items' => [
                ['material_id' => $this->materialSemen, 'qty' => 5, 'unit' => 'sak'],
            ],
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.summary.total_suppliers_found', 0);
});

test('geosearch ranks green pins on top ordered by worth it score asc', function () {
    // Toko Dekat & Murah
    $tok1 = SearchSupplierProjection::create([
        'supplier_id' => (string) Str::uuid(),
        'store_name' => 'TB Dekat Murah',
        'latitude' => -6.2080,
        'longitude' => 106.8450,
        'is_approved' => true,
        'is_accepting_booking' => true,
        'is_deposit_sufficient' => true,
        'products_snapshot' => [
            [
                'product_id' => (string) Str::uuid(),
                'material_id' => $this->materialSemen,
                'material_name' => 'Semen',
                'unit' => 'sak',
                'stock_mode' => 'status',
                'stock_status' => 'banyak',
                'is_available' => true,
                'price_tiers' => [
                    ['min_qty' => 1, 'max_qty' => null, 'unit_price' => 60000],
                ],
            ],
        ],
    ]);

    // Toko Agak Jauh & Mahal
    $tok2 = SearchSupplierProjection::create([
        'supplier_id' => (string) Str::uuid(),
        'store_name' => 'TB Jauh Mahal',
        'latitude' => -6.2300,
        'longitude' => 106.8450,
        'is_approved' => true,
        'is_accepting_booking' => true,
        'is_deposit_sufficient' => true,
        'products_snapshot' => [
            [
                'product_id' => (string) Str::uuid(),
                'material_id' => $this->materialSemen,
                'material_name' => 'Semen',
                'unit' => 'sak',
                'stock_mode' => 'status',
                'stock_status' => 'banyak',
                'is_available' => true,
                'price_tiers' => [
                    ['min_qty' => 1, 'max_qty' => null, 'unit_price' => 70000],
                ],
            ],
        ],
    ]);

    $response = $this->withHeaders($this->merchantHeaders)
        ->postJson('/api/v1/search', [
            'latitude' => -6.2088,
            'longitude' => 106.8456,
            'radius_m' => 5000,
            'items' => [
                ['material_id' => $this->materialSemen, 'qty' => 10, 'unit' => 'sak'],
            ],
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.summary.total_suppliers_found', 2)
        ->assertJsonPath('data.suppliers.0.store_name', 'TB Dekat Murah')
        ->assertJsonPath('data.suppliers.1.store_name', 'TB Jauh Mahal');

    $score1 = $response->json('data.suppliers.0.worth_it_score');
    $score2 = $response->json('data.suppliers.1.worth_it_score');
    expect($score1)->toBeLessThan($score2);
});

test('supplier detail endpoint returns complete information', function () {
    $supplierId = (string) Str::uuid();

    SearchSupplierProjection::create([
        'supplier_id' => $supplierId,
        'store_name' => 'TB Maju Lancar',
        'address' => 'Jl. Tebet Barat Dalam Raya No. 12',
        'city' => 'Jakarta Selatan',
        'province' => 'DKI Jakarta',
        'whatsapp_number' => '081234567890',
        'opening_hours' => ['monday' => ['open' => '08:00', 'close' => '17:00']],
        'latitude' => -6.2200,
        'longitude' => 106.8500,
        'is_approved' => true,
        'is_accepting_booking' => true,
        'is_deposit_sufficient' => true,
        'products_snapshot' => [
            [
                'product_id' => (string) Str::uuid(),
                'material_id' => $this->materialSemen,
                'material_name' => 'Semen Tiga Roda',
                'brand' => 'Tiga Roda',
                'unit' => 'sak',
                'stock_mode' => 'status',
                'stock_status' => 'banyak',
                'is_available' => true,
                'price_tiers' => [
                    ['min_qty' => 1, 'max_qty' => null, 'unit_price' => 65000],
                ],
            ],
        ],
    ]);

    $response = $this->withHeaders($this->merchantHeaders)
        ->getJson("/api/v1/search/{$supplierId}/detail?lat=-6.2088&lng=106.8456&items=" . urlencode(json_encode([
            ['material_id' => $this->materialSemen, 'qty' => 10, 'unit' => 'sak'],
            ['material_id' => $this->materialPasir, 'qty' => 1, 'unit' => 'truk'],
        ])));

    $response->assertStatus(200)
        ->assertJsonPath('data.store_name', 'TB Maju Lancar')
        ->assertJsonPath('data.pin_color', 'yellow')
        ->assertJsonPath('data.available_items.0.material_id', $this->materialSemen)
        ->assertJsonPath('data.unavailable_items.0.material_id', $this->materialPasir);

    expect((float) $response->json('data.fulfillment_percentage'))->toEqual(50.0);
});

test('internal sync endpoint upserts supplier projection', function () {
    $supplierId = (string) Str::uuid();

    $response = $this->postJson('/api/v1/internal/search/sync-supplier', [
        'supplier_id' => $supplierId,
        'store_name' => 'TB Sinar Terang Baru',
        'latitude' => -6.2100,
        'longitude' => 106.8400,
        'is_approved' => true,
        'is_accepting_booking' => true,
        'is_deposit_sufficient' => true,
        'products_snapshot' => [],
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('search_supplier_projections', [
        'supplier_id' => $supplierId,
        'store_name' => 'TB Sinar Terang Baru',
    ]);
});
