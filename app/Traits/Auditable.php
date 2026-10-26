<?php

namespace App\Traits;

use App\Models\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

trait Auditable
{
    /** Reason attached to the next audit entry this model writes (see withAuditNote). */
    protected ?string $auditNote = null;

    /** Attach a reason to the next change, e.g. $landlord->withAuditNote('Goodwill extension')->update([...]). */
    public function withAuditNote(?string $note): static
    {
        $this->auditNote = $note;

        return $this;
    }

    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            $attrs = $model->getAttributes();
            if (method_exists($model, 'getHidden')) {
                foreach ($model->getHidden() as $hiddenField) {
                    unset($attrs[$hiddenField]);
                }
            }
            $model->logAudit('created', [], $attrs);
        });

        static::updated(function ($model) {
            $old = $model->getOriginal();
            $new = $model->getChanges();

            // Remove timestamp fields from audit diff
            unset($new['updated_at'], $old['updated_at']);

            // Remove hidden fields (like password, remember_token)
            if (method_exists($model, 'getHidden')) {
                foreach ($model->getHidden() as $hiddenField) {
                    unset($new[$hiddenField], $old[$hiddenField]);
                }
            }

            if (!empty($new)) {
                $oldFiltered = array_intersect_key($old, $new);
                $model->logAudit('updated', $oldFiltered, $new);
            }
        });

        static::deleted(function ($model) {
            $attrs = $model->getOriginal();
            if (method_exists($model, 'getHidden')) {
                foreach ($model->getHidden() as $hiddenField) {
                    unset($attrs[$hiddenField]);
                }
            }
            $model->logAudit('deleted', $attrs, []);
        });
    }

    public function audits()
    {
        return $this->morphMany(Audit::class, 'auditable');
    }

    protected function logAudit(string $action, array $oldValues, array $newValues): void
    {
        Audit::create([
            'user_id' => Auth::guard('web')->id(),
            'staff_user_id' => Auth::guard('staff')->id(),
            // A landlord's own record is filed under that landlord.
            'landlord_id' => $this instanceof \App\Models\Landlord ? $this->getKey() : ($this->landlord_id ?? null),
            'auditable_type' => get_class($this),
            'auditable_id' => $this->getKey(),
            'action' => $action,
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'ip_address' => Request::ip(),
        ] + ($this->auditNote !== null ? ['note' => $this->auditNote] : []));
        // (note only when set: data migrations that run before the note column exists still work)

        $this->auditNote = null;
    }
}
