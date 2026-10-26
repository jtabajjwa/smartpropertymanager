<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class StaffUser extends Authenticatable
{
    use HasFactory, Notifiable, Auditable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
        'permissions',
        'is_active',
        'must_change_password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'permissions' => 'array',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * May this staff member do the given permission? Staff admins can do everything.
     * See App\Support\StaffPermissions for the list.
     */
    public function hasPermission(string $permission): bool
    {
        return $this->isAdmin() || in_array($permission, $this->permissions ?? [], true);
    }

    /** Permissions in effect (admins: all of them). */
    public function effectivePermissions(): array
    {
        return $this->isAdmin() ? \App\Support\StaffPermissions::all() : \App\Support\StaffPermissions::normalize($this->permissions ?? []);
    }

    /**
     * In a landlord's portal (staff mode), may this staff member use the
     * landlord's admin areas (users, audit log, company profile, subscription)?
     */
    public function isLandlordAdmin(): bool
    {
        return $this->hasPermission('portal.admin');
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }
}
