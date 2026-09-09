<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Lease extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'number',
        'tenant_id',
        'unit_id',
        'status',
        'start_date',
        'end_date',
        'rent_amount',
        'billing_cycle',
        'security_deposit',
        'required_upfront_periods',
        'agreement_file',
        'next_invoice_date',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'next_invoice_date' => 'date',
        'rent_amount' => 'decimal:2',
        'security_deposit' => 'decimal:2',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function invoices(): MorphMany
    {
        return $this->morphMany(Invoice::class, 'invoiceable');
    }

    public function securityDepositLedgers(): HasMany
    {
        return $this->hasMany(SecurityDepositLedger::class);
    }

    public function isExpired(): bool
    {
        return $this->end_date !== null && $this->end_date->isPast();
    }

    public function daysRemaining(): ?int
    {
        if ($this->end_date === null) {
            return null;
        }

        return (int) max(0, now()->diffInDays($this->end_date, false));
    }

    public function securityDepositBalance(): float
    {
        return (float) $this->securityDepositLedgers()
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE -amount END), 0) as balance")
            ->value('balance');
    }

    public function pendingInvoiceBalance(): float
    {
        return (float) $this->invoices()
            ->whereIn('status', ['unpaid', 'partial'])
            ->get()
            ->sum(fn (Invoice $invoice) => $invoice->unpaidBalance());
    }
}
