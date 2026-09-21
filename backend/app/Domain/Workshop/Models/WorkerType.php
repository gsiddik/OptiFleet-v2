<?php

namespace App\Domain\Workshop\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenantOrPlatform;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * "Next Improvement Tenant Portal - Products" (Mechanic): Worker Type as
 * real, tenant-manageable master data. `workers.worker_type` (the legacy
 * 5-value enum) is kept for backward compatibility; this table is the
 * new, extensible source going forward, seeded with one is_system row
 * per legacy enum value.
 */
class WorkerType extends Model
{
    use Auditable, BelongsToTenantOrPlatform, HasUuids, SoftDeletes;

    protected $fillable = ['tenant_id', 'code', 'name', 'description', 'is_system', 'status'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function workers(): HasMany
    {
        return $this->hasMany(Worker::class);
    }
}
