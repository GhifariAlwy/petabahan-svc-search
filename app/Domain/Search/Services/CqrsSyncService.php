<?php

namespace App\Domain\Search\Services;

use App\Domain\Search\Models\SearchSupplierProjection;
use Illuminate\Support\Carbon;

class CqrsSyncService
{
    /**
     * Upsert supplier projection from external microservices events or sync command.
     *
     * @param  array<string, mixed>  $data
     * @return SearchSupplierProjection
     */
    public function syncSupplier(array $data): SearchSupplierProjection
    {
        $supplierId = (string) $data['supplier_id'];

        /** @var SearchSupplierProjection $projection */
        $projection = SearchSupplierProjection::query()->firstOrNew(['supplier_id' => $supplierId]);

        $projection->store_name = (string) ($data['store_name'] ?? $projection->store_name ?? 'Toko Bangunan');
        $projection->address = isset($data['address']) ? (string) $data['address'] : $projection->address;
        $projection->city = isset($data['city']) ? (string) $data['city'] : $projection->city;
        $projection->province = isset($data['province']) ? (string) $data['province'] : $projection->province;
        $projection->whatsapp_number = isset($data['whatsapp_number']) ? (string) $data['whatsapp_number'] : $projection->whatsapp_number;
        $projection->opening_hours = isset($data['opening_hours']) ? (array) $data['opening_hours'] : $projection->opening_hours;
        
        if (isset($data['is_approved'])) {
            $projection->is_approved = (bool) $data['is_approved'];
        }
        if (isset($data['is_accepting_booking'])) {
            $projection->is_accepting_booking = (bool) $data['is_accepting_booking'];
        }
        if (isset($data['is_deposit_sufficient'])) {
            $projection->is_deposit_sufficient = (bool) $data['is_deposit_sufficient'];
        }
        if (isset($data['products_snapshot'])) {
            $projection->products_snapshot = (array) $data['products_snapshot'];
        }
        if (isset($data['latitude'])) {
            $projection->latitude = (float) $data['latitude'];
        }
        if (isset($data['longitude'])) {
            $projection->longitude = (float) $data['longitude'];
        }

        $projection->synced_at = Carbon::now();
        $projection->save();

        $projection->updatePostGisLocation();

        return $projection;
    }
}
