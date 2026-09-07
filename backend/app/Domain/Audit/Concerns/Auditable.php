<?php

namespace App\Domain\Audit\Concerns;

use App\Domain\Audit\Services\AuditService;

/**
 * Attach to any Eloquent model whose changes must be recorded in audit_logs.
 * Hooks into the model lifecycle so create/update/delete are captured
 * automatically without controllers having to remember to call the service.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            $model->recordAudit('created', null, $model->auditableAttributes());
        });

        static::updated(function ($model) {
            $changes = collect($model->getChanges())
                ->except(['updated_at'])
                ->toArray();

            if (empty($changes)) {
                return;
            }

            $original = collect($model->getOriginal())
                ->only(array_keys($changes))
                ->toArray();

            $model->recordAudit('updated', $original, $changes);
        });

        static::deleted(function ($model) {
            $action = method_exists($model, 'trashed') && $model->trashed() ? 'deactivated' : 'deleted';
            $model->recordAudit($action, $model->auditableAttributes(), null);
        });
    }

    protected function auditableAttributes(): array
    {
        return collect($this->getAttributes())
            ->except(['created_at', 'updated_at', 'deleted_at', 'password'])
            ->toArray();
    }

    protected function recordAudit(string $action, ?array $old, ?array $new): void
    {
        app(AuditService::class)->log(
            resourceType: class_basename($this),
            resourceId: (string) $this->getKey(),
            action: $action,
            oldValues: $old,
            newValues: $new,
            tenantId: $this->getAttribute('tenant_id'),
        );
    }
}
