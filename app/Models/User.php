<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmailContract
{
    use HasFactory, Notifiable, Auditable, MustVerifyEmail;

    protected $fillable = [
        'landlord_id',
        'name',
        'email',
        'email_verified_at',
        'phone',
        'password',
        'role',
        'is_active',
        'must_change_password',
        'dismissed_tips',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'dismissed_tips' => 'array',
        ];
    }

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(Landlord::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** Whether this user has closed an in-app tip (or the getting-started guide). */
    public function hasDismissedTip(string $key): bool
    {
        return in_array($key, $this->dismissed_tips ?? [], true);
    }

    /** Saved quietly: closing a tip isn't worth an audit log entry. */
    public function dismissTip(string $key): void
    {
        if (!$this->hasDismissedTip($key)) {
            $this->forceFill(['dismissed_tips' => [...($this->dismissed_tips ?? []), $key]])->saveQuietly();
        }
    }

    public function restoreTips(): void
    {
        $this->forceFill(['dismissed_tips' => null])->saveQuietly();
    }

    /** May manage this landlord's admin areas (users, audit log, company profile). */
    public function isLandlordAdmin(): bool
    {
        return $this->isAdmin();
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }
}
