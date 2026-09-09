<?php

namespace App\Models;

use App\Traits\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsLog extends Model
{
    use BelongsToLandlord;

    protected $fillable = [
        'phone',
        'message',
        'tenant_id',
        'notification_type',
        'reference_type',
        'reference_id',
        'status',
        'error',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public static function alreadySentToday(string $type, string $referenceType, int $referenceId): bool
    {
        return static::where('notification_type', $type)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('status', 'sent')
            ->whereDate('created_at', today())
            ->exists();
    }
}
