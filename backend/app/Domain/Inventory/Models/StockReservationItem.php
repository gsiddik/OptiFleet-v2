<?php

namespace App\Domain\Inventory\Models;

use App\Domain\ProductMaster\Models\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReservationItem extends Model
{
    use HasUuids;

    protected $fillable = ['stock_reservation_id', 'product_id', 'work_order_planned_part_id', 'requested_quantity', 'reserved_quantity'];

    protected function casts(): array
    {
        return ['requested_quantity' => 'decimal:4', 'reserved_quantity' => 'decimal:4'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(StockReservation::class, 'stock_reservation_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function plannedPart(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\WorkOrder\Models\WorkOrderPlannedPart::class, 'work_order_planned_part_id');
    }
}
