<?php

namespace App\Models;

use App\Traits\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bulk SMS from a landlord to their tenants. The message may contain
 * placeholders (see App\Services\SmsBroadcasts::PLACEHOLDERS), filled in per
 * tenant. Audience: ['estates' => [ids] (empty = all), 'owing_only' => bool].
 */
class SmsBroadcast extends Model
{
    use BelongsToLandlord;

    /** status => [label, badge colour] */
    public const STATUSES = [
        'queued' => ['Queued', 'gray'],
        'sending' => ['Sending', 'blue'],
        'completed' => ['Sent', 'green'],
    ];

    protected $fillable = ['landlord_id', 'created_by_user_id', 'message', 'audience', 'status', 'recipients_count', 'sent_count', 'failed_count', 'credits_used', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['audience' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SmsLog::class, 'reference_id')->where('reference_type', 'SmsBroadcast');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusColor(): string
    {
        return self::STATUSES[$this->status][1] ?? 'gray';
    }

    /** "All current tenants" / "Kisaasi Court, Ntinda Flats · owing only" */
    public function audienceLabel(): string
    {
        $ids = $this->audience['estates'] ?? [];
        $where = $ids ? Estate::whereIn('id', $ids)->orderBy('name')->pluck('name')->implode(', ') : 'All current tenants';

        return $where.(($this->audience['owing_only'] ?? false) ? ' · only tenants who owe' : '');
    }
}
