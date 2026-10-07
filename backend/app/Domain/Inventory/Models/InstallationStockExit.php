<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** How one installed serialized unit left the warehouse ledger (see its migration). Written only by SerializedStockExitService. */
class InstallationStockExit extends Model
{
    use HasUuids;

    public const SOURCE_DIRECT = 'DIRECT_ISSUE';

    public const SOURCE_WORK_ORDER = 'WO_ISSUE';

    public const SOURCE_NOT_LEDGERED = 'NOT_LEDGERED';

    public const TYPE_COMPONENT = 'COMPONENT_ASSET';

    public const TYPE_TIRE = 'TIRE';

    protected $fillable = ['tenant_id', 'asset_type', 'asset_id', 'installation_id', 'product_id', 'warehouse_id', 'source', 'reason', 'stock_movement_id', 'planned_part_id', 'created_by'];
}
