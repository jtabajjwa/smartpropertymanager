<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToLandlord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Expense extends Model
{
    use HasFactory, Auditable, BelongsToLandlord;

    protected $fillable = [
        'number',
        'date',
        'expense_item_id',
        'estate_id',
        'unit_id',
        'amount',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
    ];

    /** A job card's expense and its cost stay in step: changing the amount here updates the job. */
    protected static function booted(): void
    {
        static::updated(function (Expense $expense) {
            if ($expense->wasChanged('amount') && ($job = $expense->vendorJob) && (float) $job->cost !== (float) $expense->amount) {
                $job->update(['cost' => $expense->amount]);
            }
        });
    }

    public function expenseItem(): BelongsTo
    {
        return $this->belongsTo(ExpenseItems::class, 'expense_item_id');
    }

    public function estate(): BelongsTo
    {
        return $this->belongsTo(Estate::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function vendorJob(): HasOne
    {
        return $this->hasOne(VendorJob::class);
    }
}
