<?php

namespace App\Models;

use App\Models\Scopes\LandlordScope;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Landlord extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'name',
        'trading_name',
        'contact_email',
        'contact_phone',
        'address',
        'logo',
        'brand_color',
        'invoice_footer_note',
        'status',
        'subscription_status',
        'plan_id',
        'billing_cycle',
        'max_units',
        'collect_payments',
        'pass_collection_fee',
        'books_basis',
        'fiscal_year_start_month',
        'taxpayer_type',
        'tin',
        'extra_features',
        'current_period_end',
        'grace_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'current_period_end' => 'date',
            'grace_ends_at' => 'date',
            'max_units' => 'integer',
            'collect_payments' => 'boolean',
            'pass_collection_fee' => 'boolean',
            'fiscal_year_start_month' => 'integer',
            'extra_features' => 'array',
        ];
    }

    /** Whether this account has a plan feature: their plan includes it, staff granted it, or they have no plan yet. */
    public function hasFeature(string $feature): bool
    {
        if (!array_key_exists($feature, \App\Support\PlanFeatures::FEATURES) || in_array($feature, $this->extra_features ?? [], true)) {
            return true;
        }

        return !$this->plan || $this->plan->includes($feature);
    }

    /** The basis the books are actually kept on: accrual needs the plan feature. */
    public function booksBasis(): string
    {
        return $this->books_basis === 'accrual' && $this->hasFeature('accrual_basis') ? 'accrual' : 'cash';
    }

    public function plan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(LandlordSettlement::class);
    }

    public function smsCreditTransactions(): HasMany
    {
        return $this->hasMany(SmsCreditTransaction::class);
    }

    /** Days counted as "expiring soon" for an active subscription. */
    public const EXPIRING_WITHIN_DAYS = 7;

    /**
     * Where the subscription stands right now, combining the stored status
     * with the paid-until date: trial, trial_ended, active, expiring,
     * expired, past_due or cancelled.
     */
    public function subscriptionState(): string
    {
        $end = $this->current_period_end;

        return match ($this->subscription_status) {
            'cancelled' => 'cancelled',
            'past_due' => 'past_due',
            'trialing' => $end && $end->lt(today()) ? 'trial_ended' : 'trial',
            default => match (true) {
                $end === null => 'active',
                $end->lt(today()) => 'expired',
                $end->lte(today()->addDays(self::EXPIRING_WITHIN_DAYS)) => 'expiring',
                default => 'active',
            },
        };
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /*
     * Landlord-owned records, for staff-side counts across organisations.
     * The landlord_id foreign key already restricts each relation to this
     * landlord, so the "current landlord" scope is lifted (it would throw
     * outside a landlord context, e.g. on staff list pages).
     */

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class)->withoutGlobalScope(LandlordScope::class);
    }

    public function estates(): HasMany
    {
        return $this->hasMany(Estate::class)->withoutGlobalScope(LandlordScope::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class)->withoutGlobalScope(LandlordScope::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function logoUrl(): ?string
    {
        return $this->logo ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->logo) : null;
    }

    /**
     * DomPDF renders offline and doesn't reliably fetch images by URL, so the
     * logo is embedded directly as a base64 data URI when building a PDF.
     */
    public function logoDataUri(): ?string
    {
        if (!$this->logo || !\Illuminate\Support\Facades\Storage::disk('public')->exists($this->logo)) {
            return null;
        }

        $contents = \Illuminate\Support\Facades\Storage::disk('public')->get($this->logo);
        $mime = \Illuminate\Support\Facades\Storage::disk('public')->mimeType($this->logo);

        return "data:{$mime};base64," . base64_encode($contents);
    }
}
