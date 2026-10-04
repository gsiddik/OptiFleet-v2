<?php

namespace App\Domain\MaintenancePolicy\Models;

use App\Domain\MasterData\Models\ComponentGroup;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenancePackageItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'maintenance_package_id', 'component_group_id', 'service_item',
        'recommended_part_reference', 'standard_labor_hours', 'checklist_template_id',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(MaintenancePackage::class, 'maintenance_package_id');
    }

    /** maintenance_package_items.component_group_id → component_groups (nullable FK). */
    public function componentGroup(): BelongsTo
    {
        return $this->belongsTo(ComponentGroup::class);
    }
}
