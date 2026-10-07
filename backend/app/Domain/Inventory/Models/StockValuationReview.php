<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Append-only log of a manual valuation status change. Written only by StockValuationService; never updated. */
class StockValuationReview extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'warehouse_stock_id', 'warehouse_id', 'product_id', 'from_status', 'to_status', 'basis', 'reason',
        'evidence_reference', 'quantity_at_review', 'unit_cost_at_review', 'acknowledged_mixed_sources', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_at_review' => 'decimal:4',
            'unit_cost_at_review' => 'decimal:4',
            'acknowledged_mixed_sources' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }
}
