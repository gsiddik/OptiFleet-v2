<?php

use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Inventory → Reservation is retired: Part Requests (REQUESTED → APPROVED → ISSUE, stock checked
 * at Issue) replace it. Owner decision: every reservation still holding stock is released once,
 * through the inventory engine (RELEASE_RESERVATION ledger entry, quantity back to Available),
 * and marked RELEASED. Reservation tables and their history are kept; only the obsolete
 * `inventory.reserve` permission (used solely by the removed cancel endpoint) is dropped.
 * Re-running is safe: released reservations hold nothing.
 */
return new class extends Migration
{
    public const REASON = 'Inventory Reservation retired — replaced by Part Requests';

    public function up(): void
    {
        $this->releaseActive();

        $permissionId = DB::table('permissions')->where('name', 'inventory.reserve')->value('id');
        if ($permissionId) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }

    public function down(): void
    {
        // Released stock is not re-reserved (that would re-block stock); the permission row is
        // restored without role grants so an older release can still reference it.
        if (! DB::table('permissions')->where('name', 'inventory.reserve')->exists()) {
            $template = DB::table('permissions')->where('name', 'inventory.issue')->first();
            if ($template) {
                $row = (array) $template;
                $row['id'] = (string) Str::uuid();
                $row['name'] = 'inventory.reserve';
                $row['description'] = 'Reserve stock (retired)';
                DB::table('permissions')->insert($row);
            }
        }
    }

    /** Releases every reservation item that still holds stock and marks its reservation RELEASED. */
    public function releaseActive(): void
    {
        $inventory = app(InventoryService::class);

        StockReservation::query()->withoutGlobalScopes()
            ->whereIn('status', ['DRAFT', 'RESERVED', 'PARTIALLY_RESERVED'])
            ->orderBy('created_at')
            ->each(function (StockReservation $reservation) use ($inventory) {
                DB::transaction(function () use ($reservation, $inventory) {
                    $locked = StockReservation::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($reservation->id);
                    $warehouse = Warehouse::query()->withoutGlobalScopes()->withTrashed()->findOrFail($locked->warehouse_id);

                    foreach ($locked->items()->get() as $item) {
                        $held = (float) $item->reserved_quantity;
                        if ($held <= 0) {
                            continue;
                        }
                        $product = Product::query()->withoutGlobalScopes()->withTrashed()->findOrFail($item->product_id);
                        $inventory->releaseReservation($warehouse, $product, $held, StockReservation::class, $locked->id, null, self::REASON);
                        $item->update(['reserved_quantity' => 0]);

                        if ($item->work_order_planned_part_id) {
                            $part = DB::table('work_order_planned_parts')->where('id', $item->work_order_planned_part_id)->lockForUpdate()->first();
                            if ($part) {
                                $reserved = max(0, (float) $part->reserved_quantity - $held);
                                $status = ((float) $part->issued_quantity <= 0 && $reserved <= 0 && in_array($part->status, ['RESERVED', 'PARTIALLY_RESERVED'], true)) ? 'PLANNED' : $part->status;
                                DB::table('work_order_planned_parts')->where('id', $part->id)->update(['reserved_quantity' => $reserved, 'status' => $status, 'updated_at' => now()]);
                            }
                        }
                    }

                    $locked->update(['status' => 'RELEASED', 'notes' => trim(($locked->notes ? $locked->notes.' | ' : '').self::REASON)]);
                });
            });
    }
};
