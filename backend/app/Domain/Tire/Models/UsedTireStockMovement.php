<?php

namespace App\Domain\Tire\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Append-only ledger row of the used tire quantity: one serial in (+1) or out (−1) of a warehouse. */
class UsedTireStockMovement extends Model
{
    use BelongsToTenant, HasUuids;

    public const TYPES = ['OPENING_BALANCE', 'INSPECTION_RECEIPT', 'ISSUE', 'RETURN', 'INSTALL', 'SCRAP', 'SALE'];

    protected $fillable = [
        'tenant_id', 'warehouse_id', 'product_id', 'tire_id', 'movement_type', 'quantity', 'balance_after',
        'reference_type', 'reference_id', 'reason', 'performed_by', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'balance_after' => 'integer', 'occurred_at' => 'datetime'];
    }
}
