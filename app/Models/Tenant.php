<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Tenant extends Model
{
    use HasFactory, Auditable;

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
