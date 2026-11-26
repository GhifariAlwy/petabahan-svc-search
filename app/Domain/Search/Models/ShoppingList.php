<?php

namespace App\Domain\Search\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property string $id
 * @property string|null $merchant_id
 * @property int $radius_m
 * @property float|null $latitude
 * @property float|null $longitude
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ShoppingList extends Model
{
    use HasUuids;

    protected $table = 'shopping_lists';

    protected $fillable = [
        'merchant_id',
        'radius_m',
        'latitude',
        'longitude',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'radius_m' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * @return HasMany<ShoppingListItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ShoppingListItem::class, 'shopping_list_id');
    }

    public function updatePostGisLocation(): void
    {
        if ($this->latitude !== null && $this->longitude !== null && DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'UPDATE shopping_lists SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
                [$this->longitude, $this->latitude, $this->id]
            );
        }
    }
}
