<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One reviewed correction of an old installation that never left the ledger. Written only by StockReconciliationAdjustmentService. */
class StockReconciliationAdjustment extends Model
{
    use HasUuids;

    public const PENDING = 'PENDING_APPROVAL';

    public const APPROVED = 'APPROVED';

    public const APPLIED = 'APPLIED';

    public const REJECTED = 'REJECTED';

    public const SUPERSEDED = 'SUPERSEDED';

    protected $fillable = [
        'tenant_id', 'installation_class', 'installation_id', 'asset_type', 'asset_id', 'product_id', 'warehouse_id', 'serial', 'installed_at', 'quantity',
        'status', 'reason', 'evidence', 'proposed_by', 'proposed_at', 'workflow_configuration_version_id', 'workflow_approval_request_id',
        'decided_by', 'decided_at', 'decision_note', 'applied_by', 'applied_at', 'stock_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array', 'quantity' => 'decimal:4', 'installed_at' => 'datetime', 'proposed_at' => 'datetime',
            'decided_at' => 'datetime', 'applied_at' => 'datetime',
        ];
    }
}
