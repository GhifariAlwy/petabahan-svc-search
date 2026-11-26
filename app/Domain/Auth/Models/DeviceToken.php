<?php

namespace App\Domain\Auth\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $user_id
 * @property string $fcm_token
 * @property string $platform
 */
class DeviceToken extends Model
{
    use HasUuids;

    protected $table = 'device_tokens';

    protected $fillable = [
        'user_id',
        'fcm_token',
        'platform',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
