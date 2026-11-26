<?php

namespace App\Domain\Search\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $shopping_list_id
 * @property string $material_id
 * @property float $qty
 * @property string $unit
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ShoppingListItem extends Model
{
    use HasUuids;

    protected $table = 'shopping_list_items';

    protected $fillable = [
        'shopping_list_id',
        'material_id',
        'qty',
        'unit',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'float',
        ];
    }

    /**
     * @return BelongsTo<ShoppingList, $this>
     */
    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class, 'shopping_list_id');
    }
}
