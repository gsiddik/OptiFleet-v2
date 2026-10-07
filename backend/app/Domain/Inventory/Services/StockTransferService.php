<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Configuration\Services\DocumentNumberingService;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\StockTransferItem;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Section 12/13: warehouse-to-warehouse transfer. Section 12 is explicit
 * that destination inventory must never increase before receipt — the
 * DISPATCHED step only ever decreases the source (via
 * InventoryService::transferOut, a TRANSFER_OUT movement); IN_TRANSIT is a
 * pure paperwork status with no further stock effect (the "in-transit"
 * quantity is simply the sum of quantity_sent on transfers currently in
 * DISPATCHED/IN_TRANSIT, queryable straight off this table — no separate
 * in_transit column to keep in sync); RECEIVED is the only step that
 * increases the destination, and only by the quantity actually accepted
 * (Section 13: damaged/lost quantities are recorded, never silently folded
 * into the received count).
 */
class StockTransferService
{
    private const RESOURCE_TYPE = 'stock_transfer';

    private const HISTORY_ORDER = ['DRAFT', 'REQUESTED', 'APPROVED', 'PREPARED', 'DISPATCHED', 'IN_TRANSIT', 'RECEIVED', 'COMPLETED', 'REJECTED', 'CANCELLED'];

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly DocumentNumberingService $numbers,
        private readonly WorkflowEngine $workflow,
    ) {}

    public function create(Warehouse $from, Warehouse $to, array $items, ?string $userId): StockTransfer
    {
        if ($from->id === $to->id) {
            throw new StockTransferException('Source and destination warehouse must differ.');
        }
        if (empty($items)) {
            throw new StockTransferException('A transfer needs at least one item.');
        }

        return DB::transaction(function () use ($from, $to, $items, $userId) {
            $number = $this->numbers->generate('stock_transfer', $from->tenant_id, $from->branch_id ?? null, $from->workshop_id ?? null, $from->id);
            $workflowVersion = $this->workflow->resolveEffective(self::RESOURCE_TYPE, $from->tenant_id, $from->branch_id ?? null, $from->workshop_id ?? null);

            $transfer = StockTransfer::query()->create([
                'tenant_id' => $from->tenant_id,
                'transfer_number' => $number['document_number'],
                'numbering_configuration_version_id' => $number['configuration_version_id'],
                'workflow_configuration_version_id' => $workflowVersion?->id,
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $to->id,
                'status' => 'DRAFT',
                'requested_by' => $userId,
            ]);

            foreach ($items as $line) {
                StockTransferItem::query()->create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $line['product_id'],
                    'quantity_sent' => $line['quantity'],
                ]);
            }

            return $transfer->fresh('items');
        });
    }

    public function transition(StockTransfer $transfer, string $to): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $to) {
            $locked = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);

            $version = $this->workflow->resolvePinnedOrEffective($locked->workflow_configuration_version_id, self::RESOURCE_TYPE, $locked->tenant_id);
            if (! $this->workflow->isTransitionAllowedForVersion($version, $locked->status, $to)) {
                throw new StockTransferException("Cannot transition Stock Transfer from {$locked->status} to {$to}.");
            }

            $locked->update(['status' => $to]);

            return $locked->fresh();
        });
    }

    public function dispatch(StockTransfer $transfer, ?string $userId): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $userId) {
            $locked = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($locked->status !== 'PREPARED') {
                throw new StockTransferException('Only a prepared transfer can be dispatched.');
            }

            $from = Warehouse::query()->findOrFail($locked->from_warehouse_id);

            foreach ($locked->items()->get() as $item) {
                $product = Product::query()->findOrFail($item->product_id);
                $stockBefore = WarehouseStock::query()->where('warehouse_id', $from->id)->where('product_id', $product->id)->first();
                $this->inventory->transferOut($from, $product, (float) $item->quantity_sent, StockTransfer::class, $locked->id, $userId);
                $item->update(['unit_cost' => $stockBefore?->average_unit_cost ?? 0, 'valuation_status' => $stockBefore?->valuation_status ?? 'UNVERIFIED']);
            }

            $locked->update(['status' => 'DISPATCHED', 'dispatched_at' => now(), 'dispatched_by' => $userId]);

            return $locked->fresh('items');
        });
    }

    /**
     * @param  array<array{item_id:string, quantity_received:float, quantity_damaged?:float, quantity_lost?:float, discrepancy_reason?:string}>  $receipts
     */
    public function receive(StockTransfer $transfer, array $receipts, ?string $userId): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $receipts, $userId) {
            $locked = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($locked->status !== 'IN_TRANSIT') {
                throw new StockTransferException('Only an in-transit transfer can be received.');
            }

            $to = Warehouse::query()->findOrFail($locked->to_warehouse_id);
            $byItemId = collect($receipts)->keyBy('item_id');

            foreach ($locked->items()->get() as $item) {
                $receipt = $byItemId->get($item->id);
                if (! $receipt) {
                    throw new StockTransferException("Missing receipt data for transfer item {$item->id}.");
                }

                $received = (float) ($receipt['quantity_received'] ?? 0);
                $damaged = (float) ($receipt['quantity_damaged'] ?? 0);
                $lost = (float) ($receipt['quantity_lost'] ?? 0);
                $sent = (float) $item->quantity_sent;

                if ($received + $damaged + $lost > $sent) {
                    throw new StockTransferException("Received+damaged+lost for {$item->product_id} exceeds quantity sent.");
                }
                if (($received + $damaged + $lost) < $sent && empty($receipt['discrepancy_reason'])) {
                    throw new StockTransferException("A discrepancy reason is required when received+damaged+lost is short of quantity sent for {$item->product_id}.");
                }

                if ($received > 0) {
                    $product = Product::query()->findOrFail($item->product_id);
                    $this->inventory->receive($to, $product, $received, (float) ($item->unit_cost ?? 0), 'TRANSFER_IN', StockTransfer::class, $locked->id, $userId, null,
                        ['status' => $item->valuation_status ?? 'UNVERIFIED', 'basis' => 'TRANSFER_FROM_SOURCE_BALANCE']);
                }

                $item->update([
                    'quantity_received' => $received,
                    'quantity_damaged' => $damaged,
                    'quantity_lost' => $lost,
                    'discrepancy_reason' => $receipt['discrepancy_reason'] ?? null,
                ]);
            }

            $locked->update(['status' => 'RECEIVED', 'received_at' => now(), 'received_by' => $userId]);

            return $locked->fresh('items');
        });
    }

    /**
     * Every status the transfer has passed through, oldest first, with when it happened and the
     * name of the user who did it. Sourced from the transfer's audit trail (each create/update of
     * an Auditable model is logged with its actor), not from `updated_at`, so intermediate steps
     * (Requested, Approved, Prepared, In Transit) keep their own time and actor.
     *
     * @return list<array{status: string, at: string, by: string|null}>
     */
    public function statusHistory(StockTransfer $transfer): array
    {
        $order = array_flip(self::HISTORY_ORDER);

        return DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.actor_user_id')
            ->where('audit_logs.tenant_id', $transfer->tenant_id)
            ->where('audit_logs.resource_type', class_basename(StockTransfer::class))
            ->where('audit_logs.resource_id', $transfer->id)
            ->whereNotNull('audit_logs.new_values->status')
            ->get(['audit_logs.new_values', 'audit_logs.created_at', 'users.name'])
            ->map(fn ($row) => [
                'status' => (string) json_decode($row->new_values, true)['status'],
                'at' => Carbon::parse($row->created_at),
                'by' => $row->name,
            ])
            // Audit timestamps are second-precision: steps taken within the same second are
            // ordered by their position in the lifecycle.
            ->sort(fn ($a, $b) => [$a['at']->getTimestamp(), $order[$a['status']] ?? 99] <=> [$b['at']->getTimestamp(), $order[$b['status']] ?? 99])
            ->map(fn ($entry) => ['status' => $entry['status'], 'at' => $entry['at']->toIso8601String(), 'by' => $entry['by']])
            ->values()
            ->all();
    }
}
