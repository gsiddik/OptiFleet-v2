<?php

namespace App\Domain\Inventory\Services;

use App\Domain\ComponentAsset\Models\ComponentAsset;
use App\Domain\ComponentAsset\Models\ComponentInstallation;
use App\Domain\Inventory\Models\InstallationStockExit;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireInstallation;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The single place where installing a serialized unit (component asset / rim / tire) settles with the
 * inventory ledger, so every entry point (API, Work Order, seeders) follows the same rule and a unit
 * leaves the warehouse exactly once:
 *
 *  - Ledger-backed = new stock (component IN_STOCK; tire IN_STOCK / RESERVED) that sits in a warehouse
 *    and was never installed before. A unit that came back through repair / used-stock flows is not
 *    in the ledger again (owner decision: removed components never silently become available stock).
 *  - Ledger-backed and the installing Work Order has an issued (non-USED) Part Request line for the
 *    same product and warehouse with uncovered capacity → the issue already took it out: WO_ISSUE,
 *    no movement. Capacity = issued − returned − installations already covering that line.
 *  - Ledger-backed otherwise → DIRECT_ISSUE through InventoryService::issue (insufficient stock is
 *    rejected and the caller's transaction rolls back).
 *  - Anything else → NOT_LEDGERED, no movement, with the reason recorded.
 *
 * Must be called inside the installation's transaction, with the serial row already locked, after the
 * installation row exists. One exit row per installation (unique) makes a repeat a no-op.
 */
class SerializedStockExitService
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function recordInstallation(Model $asset, Model $installation, ?string $workOrderId, ?string $userId): InstallationStockExit
    {
        [$type, $newStatuses] = match (true) {
            $asset instanceof ComponentAsset => [InstallationStockExit::TYPE_COMPONENT, ['IN_STOCK']],
            $asset instanceof Tire => [InstallationStockExit::TYPE_TIRE, ['IN_STOCK', 'RESERVED']],
            default => throw new InventoryException('Unsupported serialized unit.'),
        };

        $existing = InstallationStockExit::query()->where('installation_id', $installation->id)->first();
        if ($existing) {
            return $existing;
        }

        $reason = match (true) {
            $asset instanceof Tire && $asset->current_status === 'REUSE' => 'USED_STOCK',
            ! in_array($asset->current_status, $newStatuses, true) => 'NOT_NEW_STOCK',
            $asset->current_warehouse_id === null => 'NO_WAREHOUSE',
            $this->installedBefore($asset, $installation) => 'PREVIOUSLY_INSTALLED',
            default => null,
        };
        $base = ['tenant_id' => $asset->tenant_id, 'asset_type' => $type, 'asset_id' => $asset->id, 'installation_id' => $installation->id,
            'product_id' => $asset->product_id, 'warehouse_id' => $asset->current_warehouse_id, 'created_by' => $userId];

        if ($reason !== null) {
            return InstallationStockExit::query()->create($base + ['source' => InstallationStockExit::SOURCE_NOT_LEDGERED, 'reason' => $reason]);
        }

        $line = $workOrderId ? $this->coveringIssuedLine($asset, $workOrderId) : null;
        if ($line) {
            return InstallationStockExit::query()->create($base + ['source' => InstallationStockExit::SOURCE_WORK_ORDER, 'planned_part_id' => $line->id]);
        }

        $warehouse = Warehouse::query()->findOrFail($asset->current_warehouse_id);
        $product = Product::query()->findOrFail($asset->product_id);
        $this->inventory->issue($warehouse, $product, 1, $installation::class, $installation->id, $userId, 'Installed on a vehicle directly from the warehouse.');
        $movementId = StockMovement::query()->where('reference_type', $installation::class)->where('reference_id', $installation->id)->where('movement_type', 'ISSUE')->value('id');

        return InstallationStockExit::query()->create($base + ['source' => InstallationStockExit::SOURCE_DIRECT, 'stock_movement_id' => $movementId]);
    }

    /** A Work Order given to an installation must be of the same company and for the same vehicle. */
    public function assertWorkOrderMatches(string $tenantId, ?string $workOrderId, string $vehicleId): void
    {
        if ($workOrderId === null) {
            return;
        }
        $workOrder = DB::table('work_orders')->where('id', $workOrderId)->first(['tenant_id', 'vehicle_id']);
        if (! $workOrder || $workOrder->tenant_id !== $tenantId) {
            throw new InventoryException('The Work Order does not belong to this company.');
        }
        if ($workOrder->vehicle_id !== $vehicleId) {
            throw new InventoryException('The Work Order is for another vehicle.');
        }
    }

    private function installedBefore(Model $asset, Model $installation): bool
    {
        return $asset instanceof Tire
            ? TireInstallation::query()->withoutGlobalScopes()->where('tire_id', $asset->id)->where('id', '!=', $installation->id)->exists()
            : ComponentInstallation::query()->withoutGlobalScopes()->where('component_asset_id', $asset->id)->where('id', '!=', $installation->id)->exists();
    }

    /** The issued Part Request line of that Work Order that still has an issued unit not yet matched to a serial. */
    private function coveringIssuedLine(Model $asset, string $workOrderId): ?WorkOrderPlannedPart
    {
        $lines = WorkOrderPlannedPart::query()->withoutGlobalScopes()->lockForUpdate()
            ->where('tenant_id', $asset->tenant_id)->where('work_order_id', $workOrderId)
            ->where('product_id', $asset->product_id)->where('warehouse_id', $asset->current_warehouse_id)
            ->where(fn ($q) => $q->whereNull('stock_condition')->orWhere('stock_condition', '!=', 'USED'))
            ->where('issued_quantity', '>', 0)->orderBy('created_at')->orderBy('id')->get();

        foreach ($lines as $line) {
            $covered = (int) DB::table('installation_stock_exits')->where('planned_part_id', $line->id)->count();
            if (floor((float) $line->issued_quantity - (float) $line->returned_quantity) - $covered >= 1) {
                return $line;
            }
        }

        return null;
    }
}
