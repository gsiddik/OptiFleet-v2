<?php

namespace App\Domain\Tire\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderPartRequest;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A planned tire job on one vehicle — Replacement, Rotation or Inspection — carried out through
 * the Work Order created with it. Its status is never stored: TireOperationStatus derives it from
 * the cancellation and the Work Order status, so the two can never disagree.
 */
class TireOperation extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const REPLACEMENT = 'REPLACEMENT';

    public const ROTATION = 'ROTATION';

    public const INSPECTION = 'INSPECTION';

    public const TYPES = [self::REPLACEMENT, self::ROTATION, self::INSPECTION];

    protected $fillable = [
        'tenant_id', 'vehicle_id', 'work_order_id', 'wheel_configuration_version_id', 'config_code', 'operation_type',
        'operated_at', 'odometer', 'applied_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['operated_at' => 'datetime', 'applied_at' => 'datetime', 'cancelled_at' => 'datetime', 'odometer' => 'decimal:2'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TireOperationItem::class)->orderBy('pair_number')->orderBy('position_code');
    }

    public function partRequest(): HasOne
    {
        return $this->hasOne(WorkOrderPartRequest::class);
    }
}
