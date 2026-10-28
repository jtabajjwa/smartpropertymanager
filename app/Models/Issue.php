<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A repair, request or complaint raised by a tenant, with a status log both sides can see. */
class Issue extends Model
{
    use Auditable, BelongsToLandlord;

    public const CATEGORIES = [
        'repair' => 'Repair / maintenance',
        'request' => 'Request',
        'complaint' => 'Complaint',
    ];

    /** status => [label, badge colour] */
    public const STATUSES = [
        'open' => ['Open', 'orange'],
        'in_progress' => ['In progress', 'blue'],
        'resolved' => ['Resolved', 'green'],
        'closed' => ['Closed', 'gray'],
    ];

    protected $fillable = ['landlord_id', 'number', 'tenant_id', 'lease_id', 'unit_id', 'category', 'title', 'description', 'photo', 'status', 'resolved_at'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** Job cards booked to fix this issue. */
    public function jobs(): HasMany
    {
        return $this->hasMany(VendorJob::class);
    }

    /** Every job booked for it is done (cancelled ones aside), and at least one was completed. */
    public function jobsAllDone(): bool
    {
        $jobs = $this->jobs->where('status', '!=', 'cancelled');

        return $jobs->isNotEmpty() && $jobs->every(fn (VendorJob $j) => $j->status === 'completed');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(IssueUpdate::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'in_progress'], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusColor(): string
    {
        return self::STATUSES[$this->status][1] ?? 'gray';
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst($this->category);
    }
}
