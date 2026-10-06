<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\ComponentAsset\Models\ComponentAsset;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Shared\Support\Messages;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireUsedInspection;
use App\Domain\Workflow\Models\WorkflowApprovalRequest;
use App\Domain\Workflow\Services\WorkflowApprovalService;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Domain\WorkOrder\Models\SparePartSale;
use App\Domain\WorkOrder\Models\WorkOrderPartReturn;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * G-16: Sell Sparepart. A sale can only be created against a
 * work_order_part_returns row that Phase B finalized as SELL_ELIGIBLE —
 * "unprocessed items cannot be sold" is enforced structurally, not just
 * by convention. The eligible quantity is a shared, contested resource
 * (several sale lines can draw against the same return), so create()
 * locks the return row before summing existing active sales against it
 * — the same TOCTOU fix pattern as G-18's returnPart().
 */
class SparePartSaleService
{
    private const RESOURCE_TYPE = 'sparepart_sale';

    public function __construct(
        private readonly WorkflowEngine $workflow,
        private readonly WorkflowApprovalService $approvals,
        private readonly InventoryService $inventory,
    ) {}

    public function create(WorkOrderPartReturn $return, array $data, string $userId): SparePartSale
    {
        $saleType = $data['sale_type'];
        $buyerType = $data['buyer_type'];
        $quantity = (float) $data['quantity'];
        $unitPrice = (float) $data['unit_price'];

        if (! in_array($saleType, SparePartSale::SALE_TYPES, true)) {
            throw new WorkOrderException(Messages::text('errors.workOrder.saleTypeMustBeOneOf', ['values' => implode(', ', SparePartSale::SALE_TYPES)]));
        }
        if (! in_array($buyerType, SparePartSale::BUYER_TYPES, true)) {
            throw new WorkOrderException(Messages::text('errors.workOrder.buyerTypeMustBeOneOf', ['values' => implode(', ', SparePartSale::BUYER_TYPES)]));
        }
        if ($buyerType === 'PARTNER' && empty($data['partner_id'])) {
            throw new WorkOrderException('A PARTNER sale requires partner_id.');
        }
        if ($buyerType === 'EXTERNAL' && empty($data['buyer_name'])) {
            throw new WorkOrderException('An EXTERNAL sale requires buyer_name.');
        }
        if ($quantity <= 0) {
            throw new WorkOrderException('Sale quantity must be positive.');
        }
        if ($unitPrice < 0) {
            throw new WorkOrderException('Unit price cannot be negative.');
        }

        return DB::transaction(function () use ($return, $saleType, $buyerType, $quantity, $unitPrice, $data, $userId) {
            $locked = WorkOrderPartReturn::query()->lockForUpdate()->findOrFail($return->id);

            if ($locked->disposition_status !== 'FINALIZED' || $locked->disposition !== 'SELL_ELIGIBLE') {
                throw new WorkOrderException('Only a return finalized with disposition SELL_ELIGIBLE can be sold.');
            }
            // Defense in depth: Phase B already blocks USED_FAULTY from ever reaching
            // SELL_ELIGIBLE, but a sale explicitly labelled for operational reuse must
            // never be able to represent an unsafe item, even if that upstream gate
            // were ever weakened or bypassed by a direct write.
            if ($saleType === 'OPERATIONAL_REUSE' && $locked->condition === 'USED_FAULTY') {
                throw new WorkOrderException('A USED_FAULTY-sourced item cannot be sold as OPERATIONAL_REUSE.');
            }
            if ($quantity > $locked->remainingEligibleQuantity()) {
                throw new WorkOrderException("Cannot sell more than the remaining eligible quantity ({$locked->remainingEligibleQuantity()}).");
            }

            return SparePartSale::query()->create([
                'tenant_id' => $locked->tenant_id,
                'work_order_part_return_id' => $locked->id,
                'product_id' => $locked->product_id,
                'warehouse_id' => $locked->warehouse_id,
                'quantity' => $quantity,
                'sale_type' => $saleType,
                'buyer_type' => $buyerType,
                'partner_id' => $data['partner_id'] ?? null,
                'buyer_name' => $data['buyer_name'] ?? null,
                'unit_price' => $unitPrice,
                'total_amount' => round($quantity * $unitPrice, 4),
                'status' => 'DRAFT',
                'requested_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    /**
     * Scrapped tires sent from Used Tire Management → Scrap (row Sell or bulk Sell): one DRAFT sale
     * line per physical tire, keeping its identity (serial, status, condition). Only tires that are
     * SCRAPPED and not already in an active sale are accepted; a scrapped tire is sold as material.
     *
     * @param  list<string>  $tireIds
     * @return Collection<int, SparePartSale>
     */
    public function createForScrappedTires(string $tenantId, array $tireIds, array $data, string $userId): Collection
    {
        $tireIds = array_values(array_unique($tireIds));
        if ($tireIds === []) {
            throw new WorkOrderException('Select at least one scrapped tire to sell.');
        }
        if (($data['sale_type'] ?? 'SCRAP_MATERIAL') !== 'SCRAP_MATERIAL') {
            throw new WorkOrderException('A scrapped tire can only be sold as SCRAP_MATERIAL.');
        }
        $buyerType = $data['buyer_type'] ?? null;
        if (! in_array($buyerType, SparePartSale::BUYER_TYPES, true)) {
            throw new WorkOrderException(Messages::text('errors.workOrder.buyerTypeMustBeOneOf', ['values' => implode(', ', SparePartSale::BUYER_TYPES)]));
        }
        if ($buyerType === 'PARTNER' && empty($data['partner_id'])) {
            throw new WorkOrderException('A PARTNER sale requires partner_id.');
        }
        if ($buyerType === 'EXTERNAL' && empty($data['buyer_name'])) {
            throw new WorkOrderException('An EXTERNAL sale requires buyer_name.');
        }
        try {
            $unitPrice = BigDecimal::of((string) $data['unit_price'])->toScale(4, RoundingMode::HALF_UP);
        } catch (MathException) {
            throw new WorkOrderException('Unit price must be a number.');
        }
        if ($unitPrice->isNegative()) {
            throw new WorkOrderException('Unit price cannot be negative.');
        }

        try {
            return DB::transaction(function () use ($tenantId, $tireIds, $data, $buyerType, $unitPrice, $userId) {
                $tires = Tire::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('id', $tireIds)->lockForUpdate()->get()->keyBy('id');
                $sales = collect();
                foreach ($tireIds as $id) {
                    $tire = $tires->get($id);
                    if (! $tire) {
                        throw new WorkOrderException('A selected tire was not found.');
                    }
                    if ($tire->current_status !== 'SCRAPPED') {
                        throw new WorkOrderException("Tire {$tire->serial_number} is {$tire->current_status}; only a scrapped tire (Recently Scrapped) can be sold.");
                    }
                    $sales->push(DB::transaction(fn () => SparePartSale::query()->create([
                        'tenant_id' => $tenantId, 'source_type' => SparePartSale::SOURCE_SCRAPPED_TIRE,
                        'tire_id' => $tire->id, 'tire_serial_number' => $tire->serial_number, 'tire_status' => $tire->current_status,
                        'tire_condition' => $this->tireCondition($tire),
                        'product_id' => $tire->product_id, 'warehouse_id' => null, 'quantity' => 1,
                        'sale_type' => 'SCRAP_MATERIAL', 'buyer_type' => $buyerType,
                        'partner_id' => $data['partner_id'] ?? null, 'buyer_name' => $data['buyer_name'] ?? null,
                        'unit_price' => (string) $unitPrice, 'total_amount' => (string) $unitPrice,
                        'status' => 'DRAFT', 'requested_by' => $userId, 'notes' => $data['notes'] ?? null,
                    ])));
                }

                return $sales;
            });
        } catch (UniqueConstraintViolationException) {
            throw new WorkOrderException('A selected tire is already in an open or approved sale.');
        }
    }

    /**
     * Sell Sparepart for physical Component Assets (one DRAFT sale per asset, quantity 1): only a
     * SCRAPPED or REMOVED asset can be sold, a scrapped one only as scrap material; an asset can be
     * in at most one active sale. Approval marks the asset SOLD (location cleared, history kept).
     *
     * @param  list<string>  $assetIds
     */
    public function createForComponentAssets(string $tenantId, array $assetIds, array $data, string $userId): Collection
    {
        $assetIds = array_values(array_unique($assetIds));
        if ($assetIds === []) {
            throw new WorkOrderException('Select at least one component asset to sell.');
        }
        $saleType = $data['sale_type'] ?? null;
        if (! in_array($saleType, SparePartSale::SALE_TYPES, true)) {
            throw new WorkOrderException(Messages::text('errors.workOrder.saleTypeMustBeOneOf', ['values' => implode(', ', SparePartSale::SALE_TYPES)]));
        }
        $buyerType = $data['buyer_type'] ?? null;
        if (! in_array($buyerType, SparePartSale::BUYER_TYPES, true)) {
            throw new WorkOrderException(Messages::text('errors.workOrder.buyerTypeMustBeOneOf', ['values' => implode(', ', SparePartSale::BUYER_TYPES)]));
        }
        if ($buyerType === 'PARTNER' && empty($data['partner_id'])) {
            throw new WorkOrderException('A PARTNER sale requires partner_id.');
        }
        if ($buyerType === 'EXTERNAL' && empty($data['buyer_name'])) {
            throw new WorkOrderException('An EXTERNAL sale requires buyer_name.');
        }
        try {
            $unitPrice = BigDecimal::of((string) $data['unit_price'])->toScale(4, RoundingMode::HALF_UP);
        } catch (MathException) {
            throw new WorkOrderException('Unit price must be a number.');
        }
        if ($unitPrice->isNegative()) {
            throw new WorkOrderException('Unit price cannot be negative.');
        }

        try {
            return DB::transaction(function () use ($tenantId, $assetIds, $data, $saleType, $buyerType, $unitPrice, $userId) {
                $assets = ComponentAsset::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereIn('id', $assetIds)->lockForUpdate()->get()->keyBy('id');
                $sales = collect();
                foreach ($assetIds as $id) {
                    $asset = $assets->get($id);
                    if (! $asset) {
                        throw new WorkOrderException('A selected component asset was not found.');
                    }
                    if (! in_array($asset->current_status, ComponentAsset::SELLABLE, true)) {
                        throw new WorkOrderException("Asset {$asset->asset_number} is {$asset->current_status}; only a SCRAPPED or REMOVED component asset can be sold.");
                    }
                    if ($asset->current_status === 'SCRAPPED' && $saleType !== 'SCRAP_MATERIAL') {
                        throw new WorkOrderException("Asset {$asset->asset_number} is scrapped: it can only be sold as SCRAP_MATERIAL.");
                    }
                    $sales->push(DB::transaction(fn () => SparePartSale::query()->create([
                        'tenant_id' => $tenantId, 'source_type' => SparePartSale::SOURCE_COMPONENT_ASSET,
                        'component_asset_id' => $asset->id, 'asset_number' => $asset->asset_number ?? $asset->serial_number,
                        'product_id' => $asset->product_id, 'warehouse_id' => $asset->current_warehouse_id, 'quantity' => 1,
                        'sale_type' => $saleType, 'buyer_type' => $buyerType,
                        'partner_id' => $data['partner_id'] ?? null, 'buyer_name' => $data['buyer_name'] ?? null,
                        'unit_price' => (string) $unitPrice, 'total_amount' => (string) $unitPrice,
                        'status' => 'DRAFT', 'requested_by' => $userId, 'notes' => $data['notes'] ?? null,
                    ])));
                }

                return $sales;
            });
        } catch (UniqueConstraintViolationException) {
            throw new WorkOrderException('A selected component asset is already in an open or approved sale.');
        }
    }

    /** Why the tire was scrapped: its approved inspection's reasons, else the last removal condition. */
    private function tireCondition(Tire $tire): ?string
    {
        $inspection = TireUsedInspection::query()->withoutGlobalScopes()->where('tire_id', $tire->id)->where('status', TireUsedInspection::APPROVED)
            ->where('final_disposition', 'SCRAP')->latest('approved_at')->first();
        if ($inspection) {
            return mb_substr(implode(' ', $inspection->reasons ?? []), 0, 255) ?: 'Inspection: SCRAP';
        }

        return DB::table('tire_removals')->where('tire_id', $tire->id)->orderByDesc('removed_at')->value('condition')
            ?? DB::table('tire_removals')->where('tire_id', $tire->id)->orderByDesc('removed_at')->value('removal_reason');
    }

    public function submit(SparePartSale $sale, string $userId): SparePartSale
    {
        return DB::transaction(function () use ($sale, $userId) {
            $locked = SparePartSale::query()->lockForUpdate()->findOrFail($sale->id);

            if ($locked->status !== 'DRAFT') {
                throw new WorkOrderException("Cannot submit a sale that is {$locked->status} (must be DRAFT).");
            }

            $version = $this->workflow->resolveEffective(self::RESOURCE_TYPE, $locked->tenant_id);
            if (! $version) {
                throw new WorkOrderException('No published sparepart-sale workflow configuration is available for this tenant.');
            }

            $request = $this->approvals->createRequest(
                $locked->tenant_id,
                self::RESOURCE_TYPE,
                $locked->id,
                $version->id,
                'approve',
                'PENDING_APPROVAL',
                'APPROVED',
                ['type' => 'SINGLE', 'steps' => [
                    ['step_number' => 1, 'approver_type' => 'PERMISSION', 'approver_identifier' => 'sparepart_sale.approve'],
                ]],
                ['sale_type' => $locked->sale_type, 'total_amount' => (float) $locked->total_amount],
                $userId,
            );

            $locked->update([
                'status' => 'PENDING_APPROVAL',
                'requested_by' => $userId,
                'workflow_configuration_version_id' => $version->id,
                'workflow_approval_request_id' => $request->id,
            ]);

            return $locked->fresh();
        });
    }

    public function decide(SparePartSale $sale, string $decision, string $userId, ?string $note = null): SparePartSale
    {
        if (! in_array($decision, ['APPROVE', 'REJECT'], true)) {
            throw new WorkOrderException("Invalid decision '{$decision}' — must be APPROVE or REJECT.");
        }

        return DB::transaction(function () use ($sale, $decision, $userId, $note) {
            $locked = SparePartSale::query()->lockForUpdate()->findOrFail($sale->id);

            if ($locked->status !== 'PENDING_APPROVAL') {
                throw new WorkOrderException("Cannot decide a sale that is {$locked->status} (must be PENDING_APPROVAL).");
            }
            if ($userId === $locked->requested_by) {
                throw new WorkOrderException('The maker who submitted this sale cannot also approve or reject it.');
            }

            $request = WorkflowApprovalRequest::query()->findOrFail($locked->workflow_approval_request_id);
            $step = $request->steps()->where('status', 'PENDING')->orderBy('step_number')->firstOrFail();
            $this->approvals->decide($step, $decision === 'APPROVE' ? 'APPROVED' : 'REJECTED', $userId, $note);

            if ($decision === 'REJECT') {
                $locked->update(['status' => 'REJECTED', 'decided_by' => $userId, 'decided_at' => now(), 'rejection_reason' => $note]);

                return $locked->fresh();
            }

            // A scrapped tire is a serial, not warehouse quantity: approval marks the physical tire SOLD.
            if ($locked->source_type === SparePartSale::SOURCE_SCRAPPED_TIRE) {
                $tire = Tire::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($locked->tire_id);
                if ($tire->current_status !== 'SCRAPPED') {
                    throw new WorkOrderException("Tire {$tire->serial_number} is {$tire->current_status}; only a scrapped tire can be sold.");
                }
                $tire->update(['current_status' => 'SOLD', 'current_vehicle_id' => null, 'current_position' => null, 'current_warehouse_id' => null]);
                $locked->update(['status' => 'APPROVED', 'decided_by' => $userId, 'decided_at' => now()]);

                return $locked->fresh();
            }

            // A physical Component Asset: approval marks it SOLD and clears its location; the asset
            // row, its installations / removals / repairs and its audit history are all kept.
            if ($locked->source_type === SparePartSale::SOURCE_COMPONENT_ASSET) {
                $asset = ComponentAsset::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($locked->component_asset_id);
                if (! in_array($asset->current_status, ComponentAsset::SELLABLE, true)) {
                    throw new WorkOrderException("Asset {$asset->asset_number} is {$asset->current_status}; it can no longer be sold.");
                }
                $asset->update(['current_status' => 'SOLD', 'current_vehicle_id' => null, 'current_warehouse_id' => null, 'sold_at' => now(), 'spare_part_sale_id' => $locked->id]);
                $locked->update(['status' => 'APPROVED', 'decided_by' => $userId, 'decided_at' => now()]);

                return $locked->fresh();
            }

            // The sale never touched quantity_on_hand (the return it draws from never
            // did either) — this SALE entry is a zero-balance-effect audit/history
            // marker, exactly like Phase A's CONSUME movement.
            $warehouse = Warehouse::query()->findOrFail($locked->warehouse_id);
            $product = Product::query()->findOrFail($locked->product_id);
            $movement = $this->inventory->recordSale($warehouse, $product, (float) $locked->quantity, SparePartSale::class, $locked->id, $userId, 'Sparepart sold');

            $locked->update([
                'status' => 'APPROVED', 'decided_by' => $userId, 'decided_at' => now(), 'stock_movement_id' => $movement->id,
            ]);

            return $locked->fresh();
        });
    }
}
