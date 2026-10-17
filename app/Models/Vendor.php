<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToLandlord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vendor extends Model
{
    use HasFactory, Auditable, BelongsToLandlord;

    protected $fillable = [
        'name',
        'category',
        'contact_person',
        'phone',
        'email',
        'address',
        'notes',
    ];

    public const CATEGORIES = [
        'electrician' => 'Electrician',
        'plumber' => 'Plumber',
        'garbage_collector' => 'Garbage Collector',
        'security' => 'Security',
        'landscaper' => 'Landscaper',
        'cleaner' => 'Cleaner',
        'painter' => 'Painter',
        'carpenter' => 'Carpenter',
        'other' => 'Other',
    ];

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst($this->category);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(VendorJob::class);
    }
}
