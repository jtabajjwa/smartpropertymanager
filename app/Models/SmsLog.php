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
        'channel',
        'wamid',
        'message',
        'segments',
        'credits_charged',
        'tenant_id',
        'notification_type',
        'reference_type',
        'reference_id',
        'status',
        'error',
        'resent_log_id',
        'resent_at',
    ];

    /** Not sent, for a reason a resend can fix. */
    public const NOT_SENT = ['failed', 'no_credit', 'read_only'];

    /** How far back "Resend all failed" reaches: older messages are usually out of date. */
    public const RESEND_DAYS = 30;

    protected function casts(): array
    {
        return ['resent_at' => 'datetime'];
    }

    public function resentAs(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resent_log_id');
    }

    /** Counts as sent: an SMS sent, or a WhatsApp alert on its way, delivered or read. */
    public const SENT = ['sent', 'pending', 'delivered', 'read'];

    /** Only SMS are resent by hand; a WhatsApp alert that fails is resent by SMS automatically. */
    public function canResend(): bool
    {
        return $this->channel !== 'whatsapp' && in_array($this->status, self::NOT_SENT, true) && $this->resent_at === null;
    }

    /** Failed messages still waiting for a resend, from the last RESEND_DAYS days. */
    public function scopeResendable($query)
    {
        return $query->where('channel', 'sms')->whereIn('status', self::NOT_SENT)->whereNull('resent_at')
            ->where('created_at', '>=', now()->subDays(self::RESEND_DAYS));
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public static function alreadySentToday(string $type, string $referenceType, int $referenceId): bool
    {
        return static::where('notification_type', $type)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->whereIn('status', self::SENT)
            ->whereDate('created_at', today())
            ->exists();
    }
}
