<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\StockValuationReview;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Inventory\Support\ValuationStatus;
use Illuminate\Support\Facades\DB;

/**
 * Manual valuation status review of one warehouse balance. A review records a FACT about the source of the value
 * (who verified it, on what basis and evidence); it never edits the unit cost (revaluation is out of scope) and is
 * appended to an immutable log. Statuses are never inferred from the cost alone.
 */
class StockValuationService
{
    /** Provenance of a balance: quantity-weighted breakdown of the inbound movements' recorded status. */
    public function sources(WarehouseStock $stock): array
    {
        return StockMovement::query()
            ->where('tenant_id', $stock->tenant_id)->where('warehouse_id', $stock->warehouse_id)->where('product_id', $stock->product_id)
            ->whereNotNull('valuation_status')
            ->selectRaw('valuation_status, valuation_basis, count(*) as movements, sum(quantity) as quantity')
            ->groupBy('valuation_status', 'valuation_basis')->orderBy('valuation_status')
            ->get()->map(fn ($r) => [
                'valuation_status' => $r->valuation_status, 'valuation_basis' => $r->valuation_basis,
                'movements' => (int) $r->movements, 'quantity' => number_format((float) $r->quantity, 4, '.', ''),
            ])->all();
    }

    public function review(string $tenantId, string $warehouseStockId, string $toStatus, string $basis, string $reason, ?string $evidence, bool $acknowledgedMixed, string $userId): StockValuationReview
    {
        $reason = trim($reason);
        $evidence = $evidence !== null ? trim($evidence) : null;
        if (! isset(ValuationStatus::REVIEW_BASES[$toStatus])) {
            throw new InventoryException('Status can not be set by review: '.$toStatus);
        }
        if (! in_array($basis, ValuationStatus::REVIEW_BASES[$toStatus], true)) {
            throw new InventoryException("Basis {$basis} is not valid for status {$toStatus}.");
        }
        if ($reason === '') {
            throw new InventoryException('A reason is required.');
        }
        if ($toStatus !== ValuationStatus::UNVERIFIED && ($evidence === null || $evidence === '')) {
            throw new InventoryException('An evidence reference is required to verify a valuation.');
        }

        return DB::transaction(function () use ($tenantId, $warehouseStockId, $toStatus, $basis, $reason, $evidence, $acknowledgedMixed, $userId) {
            $stock = WarehouseStock::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($warehouseStockId);
            $quantity = (float) $stock->quantity_on_hand;
            $cost = (float) $stock->average_unit_cost;
            if ($quantity <= 0) {
                throw new InventoryException('Only a balance with stock on hand can be reviewed.');
            }
            $from = $stock->valuation_status;
            if ($from === $toStatus) {
                throw new InventoryException('The balance already has this valuation status.');
            }
            if ($toStatus === ValuationStatus::VERIFIED && $cost <= 0) {
                throw new InventoryException('A positive unit cost is required to verify a valuation; a zero cost can only be verified as zero value.');
            }
            if ($toStatus === ValuationStatus::VERIFIED_ZERO && $cost > 0) {
                throw new InventoryException('Verified zero value requires a recorded unit cost of 0.');
            }
            if ($from === ValuationStatus::MIXED && in_array($toStatus, [ValuationStatus::VERIFIED, ValuationStatus::VERIFIED_ZERO], true) && ! $acknowledgedMixed) {
                throw new InventoryException('The balance combines sources of different status; acknowledge this to verify it as a whole.');
            }

            $stock->update([
                'valuation_status' => $toStatus,
                'valuation_basis' => $toStatus === ValuationStatus::UNVERIFIED ? null : $basis,
            ]);

            return StockValuationReview::create([
                'tenant_id' => $tenantId, 'warehouse_stock_id' => $stock->id, 'warehouse_id' => $stock->warehouse_id, 'product_id' => $stock->product_id,
                'from_status' => $from, 'to_status' => $toStatus, 'basis' => $basis, 'reason' => $reason, 'evidence_reference' => $evidence,
                'quantity_at_review' => $stock->quantity_on_hand, 'unit_cost_at_review' => $stock->average_unit_cost,
                'acknowledged_mixed_sources' => $acknowledgedMixed, 'reviewed_by' => $userId, 'reviewed_at' => now(),
            ]);
        });
    }
}
