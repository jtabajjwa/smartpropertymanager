<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A marketing partner or agent who signs landlords up for a share of their subscriptions. */
class Partner extends Model
{
    use Auditable;

    public const TYPES = ['agent' => 'Agent', 'marketing' => 'Marketing partner'];

    public const STATUSES = [
        'pending' => ['Awaiting approval', 'orange'],
        'active' => ['Active', 'green'],
        'suspended' => ['Suspended', 'red'],
        'rejected' => ['Not approved', 'gray'],
    ];

    protected $fillable = [
        'number', 'type', 'name', 'contact_name', 'email', 'phone', 'id_type', 'id_number', 'tin', 'address', 'about',
        'status', 'status_note', 'default_promotion_id', 'commission_plan_id', 'agreement_accepted_at', 'approved_at', 'approved_by_staff_user_id',
    ];

    protected function casts(): array
    {
        return ['agreement_accepted_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(PartnerUser::class);
    }

    public function codes(): HasMany
    {
        return $this->hasMany(PartnerCode::class);
    }

    public function landlords(): HasMany
    {
        return $this->hasMany(Landlord::class);
    }

    public function defaultPromotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class, 'default_promotion_id');
    }

    /** Revenue-share terms; none = the default plan (see PartnerCommissions). */
    public function commissionPlan(): BelongsTo
    {
        return $this->belongsTo(CommissionPlan::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(PartnerCommission::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'approved_by_staff_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function statusLabel(): array
    {
        return self::STATUSES[$this->status] ?? [ucfirst($this->status), 'gray'];
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }

    /** The code the agent number stands for (created with the partner). */
    public function defaultCode(): ?PartnerCode
    {
        return $this->codes()->where('is_default', true)->first();
    }

    /** Make sure the partner has a default code (the one their agent number stands for). */
    public function ensureDefaultCode(): PartnerCode
    {
        return $this->defaultCode() ?? $this->codes()->create(['code' => self::suggestCode($this->contact_name ?: $this->name), 'is_default' => true, 'is_active' => true]);
    }

    /** A short, unused agent number: 4 digits (5 once those run out). */
    public static function newNumber(): string
    {
        for ($digits = 4; ; $digits++) {
            for ($try = 0; $try < 40; $try++) {
                $number = (string) random_int(10 ** ($digits - 1), 10 ** $digits - 1);
                if (!static::where('number', $number)->exists()) {
                    return $number;
                }
            }
        }
    }

    /** An unused code from a name: "John Okello" → "JOHN24". */
    public static function suggestCode(string $name): string
    {
        $base = strtoupper(Str::limit(preg_replace('/[^A-Za-z]/', '', Str::ascii(Str::before(trim($name), ' '))), 8, '')) ?: 'AGENT';
        for ($try = 0; ; $try++) {
            $code = $base.($try < 20 ? random_int(10, 99) : random_int(100, 9999));
            if (!PartnerCode::where('code', $code)->exists()) {
                return $code;
            }
        }
    }
}
