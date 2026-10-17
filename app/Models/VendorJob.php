<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToLandlord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorJob extends Model
{
    use HasFactory, Auditable, BelongsToLandlord;

    protected $fillable = [
        'vendor_id',
        'estate_id',
        'unit_id',
        'expense_id',
        'number',
        'title',
        'description',
        'job_date',
        'status',
        'cost',
    ];

    protected function casts(): array
    {
        return [
            'job_date' => 'date',
            'cost' => 'decimal:2',
        ];
    }

    public const STATUSES = [
        'scheduled' => 'Scheduled',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function estate(): BelongsTo
    {
        return $this->belongsTo(Estate::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }
}
