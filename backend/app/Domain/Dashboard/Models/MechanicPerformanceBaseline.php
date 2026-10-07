<?php

namespace App\Domain\Dashboard\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Expected work hours per maintenance type for the Mechanic Performance widget (WS-07). */
class MechanicPerformanceBaseline extends Model
{
    use BelongsToTenant, HasUuids;

    /** Work Order maintenance types (work_orders.maintenance_type). */
    public const MAINTENANCE_TYPES = ['PREVENTIVE', 'CORRECTIVE', 'BREAKDOWN', 'INSPECTION', 'CAMPAIGN'];

    protected $fillable = ['tenant_id', 'maintenance_type', 'baseline_hours', 'updated_by'];

    protected $casts = ['baseline_hours' => 'decimal:2'];
}
