<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Organization\Models\Warehouse;
use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Domain\WorkOrder\Models\WorkOrder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockReservation extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'warehouse_id', 'work_order_id', 'status', 'created_by', 'notes'];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockReservationItem::class);
    }
}
