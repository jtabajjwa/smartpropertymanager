<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Audit extends Model
{
    // Tier B: audits can be generated with no landlord at all (staff actions
    // on Landlord/Plan/StaffUser records), so this does NOT use
    // BelongsToLandlord — landlord-side reads must filter explicitly.

    protected $fillable = [
        'user_id',
        'staff_user_id',
        'landlord_id',
        'auditable_type',
        'auditable_id',
        'action',
        'old_values',
        'new_values',
        'ip_address',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class);
    }

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(Landlord::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getActorNameAttribute(): string
    {
        return $this->user?->name ?? $this->staffUser?->name ?? 'System';
    }

    public function getRecordNameAttribute(): string
    {
        $model = $this->auditable;

        if ($model) {
            return $model->name ?? $model->title ?? $model->number ?? $model->reference ?? "ID: {$model->id}";
        }

        // Fallback if model was deleted or not found
        $data = $this->new_values ?? $this->old_values ?? [];
        return $data['name'] ?? $data['title'] ?? $data['number'] ?? $data['reference'] ?? "ID: {$this->auditable_id}";
    }
}
