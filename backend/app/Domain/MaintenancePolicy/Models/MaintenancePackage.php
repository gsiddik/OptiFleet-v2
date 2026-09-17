<?php

namespace App\Domain\MaintenancePolicy\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaintenancePackage extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'code', 'name', 'maintenance_type', 'description', 'standard_labor_hours', 'status',
        'period_by', 'threshold_days', 'threshold_month', 'threshold_km', 'threshold_engine_hour', 'schedule_period',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(MaintenancePackageItem::class);
    }

    public function intervals(): HasMany
    {
        return $this->hasMany(MaintenanceInterval::class);
    }

    public function componentGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            ComponentGroup::class,
            'maintenance_package_component_groups'
        )->withTimestamps();
    }

    /**
     * Section 10: a serializable snapshot of everything a schedule created
     * from this package must remember, regardless of later changes to the
     * package itself.
     */
    public function toSnapshot(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'maintenance_type' => $this->maintenance_type,
            'period_by' => $this->period_by,
            'threshold_days' => $this->threshold_days,
            'threshold_month' => $this->threshold_month,
            'threshold_km' => $this->threshold_km,
            'threshold_engine_hour' => $this->threshold_engine_hour,
            'schedule_period' => $this->schedule_period,
            'standard_labor_hours' => $this->standard_labor_hours,
            'description' => $this->description,
            'component_group_ids' => $this->componentGroups()->pluck('component_groups.id')->all(),
        ];
    }
}
