<?php

namespace App\Domain\MaintenancePolicy\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaintenancePackage extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'code', 'name', 'maintenance_type', 'description', 'standard_labor_hours', 'status',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(MaintenancePackageItem::class);
    }

    public function intervals(): HasMany
    {
        return $this->hasMany(MaintenanceInterval::class);
    }
}
