<?php

namespace App\Domain\Warranty\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\ComponentAsset\Models\ComponentAsset;
use App\Domain\Partner\Models\Partner;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Domain\Tire\Models\Tire;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\WorkOrder\Models\WorkOrder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarrantyClaim extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'claim_number', 'numbering_configuration_version_id', 'workflow_configuration_version_id', 'warranty_id', 'partner_id', 'product_id', 'component_asset_id', 'tire_id',
        'vehicle_id', 'work_order_id', 'failure_date', 'failure_odometer', 'claim_amount', 'evidence', 'reason',
        'status', 'reviewed_by', 'reviewed_at', 'review_note', 'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'failure_date' => 'date', 'failure_odometer' => 'decimal:2', 'claim_amount' => 'decimal:4',
            'reviewed_at' => 'datetime', 'settled_at' => 'datetime',
        ];
    }

    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function componentAsset(): BelongsTo
    {
        return $this->belongsTo(ComponentAsset::class);
    }

    public function tire(): BelongsTo
    {
        return $this->belongsTo(Tire::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
