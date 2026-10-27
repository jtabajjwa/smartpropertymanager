<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A tax or levy a landlord has switched on (one per estate for per-estate taxes), with their overrides. */
class LandlordTax extends Model
{
    use Auditable, BelongsToLandlord;

    protected $fillable = ['landlord_id', 'tax_template_id', 'estate_id', 'is_enabled', 'settings', 'notes'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'is_enabled' => 'boolean'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(TaxTemplate::class, 'tax_template_id');
    }

    public function estate(): BelongsTo
    {
        return $this->belongsTo(Estate::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(TaxPayment::class);
    }

    /** The landlord's value for a setting, else the template default. */
    public function setting(string $key, mixed $default = null): mixed
    {
        $own = $this->settings[$key] ?? null;

        return ($own !== null && $own !== '') ? $own : $default;
    }

    public function label(): string
    {
        return $this->template->name.($this->estate ? ' · '.$this->estate->name : '');
    }
}
