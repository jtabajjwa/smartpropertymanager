<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry in an issue's log: a status change and/or a note, by the tenant or the landlord's side. */
class IssueUpdate extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['issue_id', 'author', 'user_id', 'staff_user_id', 'status', 'note'];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class);
    }
}
