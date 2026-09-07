<?php

namespace App\Domain\Warranty\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\ComponentAsset\Models\ComponentAsset;
use App\Domain\Partner\Models\Partner;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Domain\Tire\Models\Tire;
use App\Domain\WorkOrder\Models\WorkOrder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Warranty extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'coverage_basis', 'duration_months', 'duration_km', 'duration_engine_hours',
        'tolerance_days', 'tolerance_km', 'tolerance_engine_hours', 'starts_at', 'start_odometer', 'start_engine_hour',
        'partner_id', 'product_id', 'component_asset_id', 'tire_id', 'work_order_id', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return ['starts_at' => 'date', 'start_odometer' => 'decimal:2', 'start_engine_hour' => 'decimal:2'];
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

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
