<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToLandlord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Invoice extends Model
{
    use HasFactory, Auditable, BelongsToLandlord;

    protected $fillable = [
        'number',
        'lease_id',
        'invoiceable_type',
        'invoiceable_id',
        'type',
        'title',
        'invoice_date',
        'amount',
        'due_date',
        'status',
        'notes',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'amount' => 'decimal:2',
    ];

    protected $appends = ['display_payer_name'];

    public function invoiceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function getDisplayPayerNameAttribute(): string
    {
        if ($this->invoiceable_type === Customer::class) {
            return $this->invoiceable->name ?? 'Unknown Customer';
        }

        return $this->lease?->tenant?->name ?? 'Unknown Tenant';
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, ['unpaid', 'partial']) && $this->due_date->isPast();
    }

    public function unpaidBalance(): float
    {
        $allocated = (string) $this->allocations()->sum('amount');
        return (float) bcsub((string) $this->amount, $allocated, 2);
    }

    public function syncStatus(): void
    {
        if (in_array($this->status, ['cancelled', 'written_off'])) {
            return;
        }

        $oldStatus = $this->status;
        $paid = (string) $this->allocations()->sum('amount');

        $this->status = match (true) {
            bccomp($paid, (string) $this->amount, 2) >= 0 => 'paid',
            bccomp($paid, '0', 2) > 0 => 'partial',
            default => 'unpaid',
        };

        $this->saveQuietly();

        if ($this->type === 'security_deposit') {
            $this->syncSecurityDepositLedger($oldStatus);
        }
    }

    protected function syncSecurityDepositLedger(string $oldStatus): void
    {
        $paidAmount = (string) $this->allocations()->sum('amount');

        $ledgerEntry = SecurityDepositLedger::where('lease_id', $this->lease_id)
            ->where('type', 'deposit')
            ->first();

        if (bccomp($paidAmount, '0', 2) > 0) {
            if ($ledgerEntry) {
                $ledgerEntry->update(['amount' => $paidAmount]);
            } else {
                SecurityDepositLedger::create([
                    'date' => now()->toDateString(),
                    'lease_id' => $this->lease_id,
                    'type' => 'deposit',
                    'amount' => $paidAmount,
                    'notes' => "Auto-tracked from invoice {$this->number}.",
                ]);
            }
        } elseif ($ledgerEntry) {
            $hasMovements = SecurityDepositLedger::where('lease_id', $this->lease_id)
                ->whereIn('type', ['refund', 'deduction'])
                ->exists();

            if (!$hasMovements) {
                $ledgerEntry->delete();
            }
        }
    }
}
