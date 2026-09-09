<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'number',
        'payable_type',
        'payable_id',
        'amount',
        'payment_date',
        'payment_method',
        'reference',
        'notes',
        'receipt_photo',
        'status',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
    ];

    protected $appends = ['display_payer_name'];

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function tenant()
    {
        return $this->payable_type === Tenant::class ? $this->payable() : null;
    }

    public function getDisplayPayerNameAttribute(): string
    {
        if ($this->payable_type === Customer::class) {
            return $this->payable->name ?? 'Unknown Customer';
        }

        return $this->payable_type === Tenant::class && $this->payable ? $this->payable->name : 'Unknown Tenant';
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function unallocatedAmount(): float
    {
        $allocated = (string) $this->allocations()->sum('amount');
        return (float) bcsub((string) $this->amount, $allocated, 2);
    }

    public function updateAllocationStatus(): void
    {
        if ($this->status === 'cancelled') {
            return;
        }

        $unallocated = $this->unallocatedAmount();
        
        if (bccomp((string) $unallocated, (string) $this->amount, 2) === 0) {
            $status = 'unallocated';
        } elseif ($unallocated > 0) {
            $status = 'partially_allocated';
        } else {
            $status = 'fully_allocated';
        }

        $this->update(['status' => $status]);
    }

}
