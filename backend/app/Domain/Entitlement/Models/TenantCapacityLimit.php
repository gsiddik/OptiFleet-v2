<?php

namespace App\Domain\Entitlement\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantCapacityLimit extends Model
{
    use Auditable, HasUuids;

    protected $fillable = [
        'tenant_id',
        'resource_type',
        'max_count',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
