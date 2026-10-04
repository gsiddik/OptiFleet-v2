<?php

namespace App\Domain\Tire\Services;

use App\Domain\Inventory\Services\InventoryException;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\UsedTireStock;
use App\Domain\Tire\Models\UsedTireStockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Used tire warehouse quantity (REUSE tires), kept apart from new-stock warehouse_stocks. Every
 * movement is one serial (+1 / −1) on the append-only ledger, so a tire is counted at most once
 * and its own movements say where it is counted:
 *
 *   in   OPENING_BALANCE (migration), INSPECTION_RECEIPT (inspection approved as REUSE into a
 *        warehouse), RETURN (an issued used tire returned unused and restocked)
 *   out  ISSUE (Part Request issue of a USED line), INSTALL / SCRAP / SALE (the tire leaves the
 *        warehouse without being issued)
 *
 * The stock row is locked for every movement; its quantity can never go below zero.
 */
class UsedTireStockService
{
    /** +1 into the warehouse; no-op when the tire is already counted. */
    public function receive(Tire $tire, string $warehouseId, string $type, ?string $referenceType, ?string $referenceId, ?string $userId, ?string $reason = null): void
    {
        if ($this->isCounted($tire->id)) {
            return;
        }
        $this->move($tire, $warehouseId, 1, $type, $referenceType, $referenceId, $userId, $reason);
    }

    /** −1 for a tire leaving the warehouse other than by issue (install, scrap, sale); no-op when not counted. */
    public function release(Tire $tire, string $type, ?string $referenceType, ?string $referenceId, ?string $userId, ?string $reason = null): void
    {
        if (! $this->isCounted($tire->id)) {
            return;
        }
        $this->move($tire, $this->countedWarehouseId($tire->id), -1, $type, $referenceType, $referenceId, $userId, $reason);
    }

    /** −1 for a Part Request issue: the tire must be counted in that warehouse. */
    public function issue(Tire $tire, string $warehouseId, string $referenceType, string $referenceId, ?string $userId): void
    {
        if (! $this->isCounted($tire->id) || $this->countedWarehouseId($tire->id) !== $warehouseId) {
            throw new InventoryException("Used tire {$tire->serial_number} is not in this warehouse's used stock.");
        }
        $this->move($tire, $warehouseId, -1, 'ISSUE', $referenceType, $referenceId, $userId, null);
    }

    public function isCounted(string $tireId): bool
    {
        return (int) UsedTireStockMovement::query()->withoutGlobalScopes()->where('tire_id', $tireId)->sum('quantity') > 0;
    }

    /** Warehouse of the tire's latest movement (where it is, or was last, counted). */
    public function countedWarehouseId(string $tireId): ?string
    {
        return $this->latest($tireId)?->warehouse_id;
    }

    /** Tires issued against a reference (e.g. a planned part) that are not back in stock. */
    public function issuedTireIds(string $referenceType, string $referenceId): array
    {
        $ids = UsedTireStockMovement::query()->withoutGlobalScopes()->where('movement_type', 'ISSUE')
            ->where('reference_type', $referenceType)->where('reference_id', $referenceId)->orderBy('sequence')->pluck('tire_id')->unique();

        return $ids->filter(fn (string $id) => $this->latest($id)?->movement_type === 'ISSUE')->values()->all();
    }

    /** @return list<array{id: string, serial_number: string}> serials counted in a warehouse's used tire quantity */
    public function countedSerials(string $warehouseId, string $productId): array
    {
        $ids = UsedTireStockMovement::query()->withoutGlobalScopes()->where('warehouse_id', $warehouseId)->where('product_id', $productId)
            ->groupBy('tire_id')->havingRaw('SUM(quantity) > 0')->pluck('tire_id');

        return Tire::query()->withoutGlobalScopes()->whereIn('id', $ids)->orderBy('serial_number')
            ->get(['id', 'serial_number'])->map(fn (Tire $t) => ['id' => $t->id, 'serial_number' => $t->serial_number])->all();
    }

    private function latest(string $tireId): ?UsedTireStockMovement
    {
        return UsedTireStockMovement::query()->withoutGlobalScopes()->where('tire_id', $tireId)
            ->orderByDesc('sequence')->first();
    }

    private function move(Tire $tire, string $warehouseId, int $quantity, string $type, ?string $referenceType, ?string $referenceId, ?string $userId, ?string $reason): void
    {
        DB::transaction(function () use ($tire, $warehouseId, $quantity, $type, $referenceType, $referenceId, $userId, $reason) {
            UsedTireStock::query()->withoutGlobalScopes()->insertOrIgnore([
                'id' => (string) Str::uuid(), 'tenant_id' => $tire->tenant_id, 'warehouse_id' => $warehouseId,
                'product_id' => $tire->product_id, 'quantity_on_hand' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $stock = UsedTireStock::query()->withoutGlobalScopes()->where('warehouse_id', $warehouseId)
                ->where('product_id', $tire->product_id)->lockForUpdate()->firstOrFail();
            $balance = $stock->quantity_on_hand + $quantity;
            if ($balance < 0) {
                throw new InventoryException("Insufficient used tire stock for tire {$tire->serial_number}.");
            }
            $stock->update(['quantity_on_hand' => $balance]);

            UsedTireStockMovement::query()->create([
                'tenant_id' => $tire->tenant_id, 'warehouse_id' => $warehouseId, 'product_id' => $tire->product_id,
                'tire_id' => $tire->id, 'movement_type' => $type, 'quantity' => $quantity, 'balance_after' => $balance,
                'reference_type' => $referenceType, 'reference_id' => $referenceId, 'reason' => $reason,
                'performed_by' => $userId, 'occurred_at' => now(),
            ]);
        });
    }
}
