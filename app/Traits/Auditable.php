<?php

namespace App\Traits;

use App\Models\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

trait Auditable
{
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
            'user_id' => Auth::id(),
            'auditable_type' => get_class($this),
            'auditable_id' => $this->getKey(),
            'action' => $action,
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'ip_address' => Request::ip(),
        ]);
    }
}
