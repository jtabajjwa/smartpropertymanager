<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A code landlords enter (or follow in a link) at sign-up or when paying:
 * links them to a partner and may carry a promotion. Without a partner it's
 * a Primora campaign code (a discount only).
 */
class PartnerCode extends Model
{
    use Auditable;

    protected $fillable = ['partner_id', 'code', 'label', 'promotion_id', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function landlords(): HasMany
    {
        return $this->hasMany(Landlord::class);
    }

    /** The promotion a landlord gets with this code: its own, else the partner's default. */
    public function effectivePromotion(): ?Promotion
    {
        return $this->promotion ?? $this->partner?->defaultPromotion;
    }

    public function signupUrl(): string
    {
        return route('signup', ['ref' => $this->code]);
    }

    /** Upper case, no spaces: "john 24" → "JOHN24". */
    public static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($code)));
    }
}
