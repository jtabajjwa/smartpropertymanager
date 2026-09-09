<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToLandlord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseItems extends Model
{
    use HasFactory, Auditable, BelongsToLandlord;

    protected $fillable = [
        'name',
        'description',
    ];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'expense_item_id');
    }
}
