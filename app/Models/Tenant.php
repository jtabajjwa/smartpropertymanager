<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToLandlord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Tenants sign in to the portal and the app with a one-time code (no
 * password); being Authenticatable lets an app token stand for one.
 */
class Tenant extends Model implements \Illuminate\Contracts\Auth\Authenticatable
{
    use HasFactory, Auditable, BelongsToLandlord, \Laravel\Sanctum\HasApiTokens, \Illuminate\Auth\Authenticatable;

    protected $fillable = [
        'number',
        'type',
        'name',
        'email',
        'primary_phone',
        'secondary_phone',
        'photo',
        'lc_letter',
        'id_type',
        'id_number',
        'id_scan',
        'emergency_contact_name',
        'emergency_contact_relationship',
        'emergency_contact_phone',
        'emergency_contact_email',
        'emergency_contact_address',
        'notes',
    ];

    protected function casts(): array
    {
        return ['whatsapp_opted_in_at' => 'datetime', 'whatsapp_opted_out_at' => 'datetime', 'whatsapp_failed_at' => 'datetime'];
    }

    /** Agreed to WhatsApp alerts, hasn't stopped them, and WhatsApp hasn't recently refused their number. */
    public function whatsAppAlertsOn(): bool
    {
        return $this->whatsapp_opted_in_at !== null && $this->whatsapp_opted_out_at === null
            && ($this->whatsapp_failed_at === null || $this->whatsapp_failed_at->lt(now()->subDays(\App\Services\WhatsAppAlerts::RETRY_AFTER_DAYS)));
    }

    /** The landlord confirms (or withdraws) that the tenant agreed. A tenant who stopped alerts stays stopped. */
    public function setWhatsAppConsent(bool $agreed): void
    {
        if ($agreed && !$this->whatsapp_opted_in_at && !$this->whatsapp_opted_out_at) {
            $this->forceFill(['whatsapp_opted_in_at' => now()])->save();
        } elseif (!$agreed && $this->whatsapp_opted_in_at) {
            $this->forceFill(['whatsapp_opted_in_at' => null])->save();
        }
    }

    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function activeLease(): ?Lease
    {
        return $this->leases()->where('status', 'active')->latest('start_date')->first();
    }

    public function hasOverdueInvoices(): bool
    {
        return Invoice::whereIn('lease_id', $this->leases()->pluck('id'))
            ->whereIn('status', ['unpaid', 'partial'])
            ->where('due_date', '<', now())
            ->exists();
    }

    public function pendingInvoiceBalance(): float
    {
        $leaseIds = $this->leases()->pluck('id');

        return (float) Invoice::whereIn('lease_id', $leaseIds)
            ->whereIn('status', ['unpaid', 'partial'])
            ->get()
            ->sum(fn (Invoice $invoice) => $invoice->amount - $invoice->allocations()->sum('amount'));
    }

    public function securityDepositBalance(): float
    {
        $leaseIds = $this->leases()->pluck('id');

        return (float) SecurityDepositLedger::whereIn('lease_id', $leaseIds)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE -amount END), 0) as balance")
            ->value('balance');
    }
}
