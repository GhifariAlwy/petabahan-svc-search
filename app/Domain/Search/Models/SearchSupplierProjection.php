<?php

namespace App\Domain\Search\Models;

use App\Support\Geo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property string $supplier_id
 * @property string $store_name
 * @property string|null $address
 * @property string|null $city
 * @property string|null $province
 * @property string|null $whatsapp_number
 * @property array<string, mixed>|null $opening_hours
 * @property bool $is_approved
 * @property bool $is_accepting_booking
 * @property bool $is_deposit_sufficient
 * @property array<int, array<string, mixed>> $products_snapshot
 * @property float|null $latitude
 * @property float|null $longitude
 * @property Carbon|null $synced_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SearchSupplierProjection extends Model
{
    protected $table = 'search_supplier_projections';

    protected $primaryKey = 'supplier_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'supplier_id',
        'store_name',
        'address',
        'city',
        'province',
        'whatsapp_number',
        'opening_hours',
        'is_approved',
        'is_accepting_booking',
        'is_deposit_sufficient',
        'products_snapshot',
        'latitude',
        'longitude',
        'synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_approved' => 'boolean',
            'is_accepting_booking' => 'boolean',
            'is_deposit_sufficient' => 'boolean',
            'opening_hours' => 'array',
            'products_snapshot' => 'array',
            'latitude' => 'float',
            'longitude' => 'float',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * Scope: Filter active suppliers that satisfy KYC, accepting bookings,
     * and have sufficient deposit balance above minimum threshold (DEC-005 rule).
     *
     * @param  Builder<SearchSupplierProjection>  $query
     * @return Builder<SearchSupplierProjection>
     */
    public function scopeActiveAndSufficient(Builder $query): Builder
    {
        return $query->where('is_approved', true)
            ->where('is_accepting_booking', true)
            ->where('is_deposit_sufficient', true);
    }

    /**
     * Scope: Geosearch within radius (in meters) and calculate distance.
     *
     * @param  Builder<SearchSupplierProjection>  $query
     * @return Builder<SearchSupplierProjection>
     */
    public function scopeWithinRadius(Builder $query, float $lat, float $lng, int $radiusMeters): Builder
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            return $query->selectRaw(
                'search_supplier_projections.*, ' .
                'ST_Distance(search_supplier_projections.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS distance_meters',
                [$lng, $lat]
            )->whereRaw(
                'ST_DWithin(search_supplier_projections.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
                [$lng, $lat, $radiusMeters]
            );
        }

        // SQLite Haversine Fallback for testing environments
        $haversine = '(6371000 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude))))';

        return $query->selectRaw("search_supplier_projections.*, {$haversine} AS distance_meters", [$lat, $lng, $lat])
            ->whereRaw("{$haversine} <= ?", [$lat, $lng, $lat, $radiusMeters]);
    }

    /**
     * Synchronize PostGIS geography point from latitude and longitude.
     */
    public function updatePostGisLocation(): void
    {
        if ($this->latitude !== null && $this->longitude !== null && DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'UPDATE search_supplier_projections SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE supplier_id = ?',
                [$this->longitude, $this->latitude, $this->supplier_id]
            );
        }
    }
}
