<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Invoice\Services\NumberSequenceService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Procurement\Models\PurchaseRequest;
use App\Domain\Procurement\Models\PurchaseRequestItem;
use Illuminate\Support\Facades\DB;

/**
 * Section 16: DRAFT -> SUBMITTED -> UNDER_REVIEW -> APPROVED -> PROCUREMENT,
 * with REJECTED/CANCELLED side branches, built as a swappable transition
 * table (Section 65: Phase 5's configurable workflow engine replaces this
 * later, not Phase 4's callers of it).
 */
class PurchaseRequestService
{
    private const TRANSITIONS = [
        'DRAFT' => ['SUBMITTED', 'CANCELLED'],
        'SUBMITTED' => ['UNDER_REVIEW', 'CANCELLED'],
        'UNDER_REVIEW' => ['APPROVED', 'REJECTED', 'CANCELLED'],
        'APPROVED' => ['PROCUREMENT', 'CANCELLED'],
    ];

    public function __construct(private readonly NumberSequenceService $numbers) {}

    public function create(Warehouse $warehouse, array $attributes, array $items, ?string $userId): PurchaseRequest
    {
        if (empty($items)) {
            throw new ProcurementException('A purchase request needs at least one item.');
        }

        return DB::transaction(function () use ($warehouse, $attributes, $items, $userId) {
            $number = sprintf('PR/%d/%06d', (int) now()->format('Y'), $this->numbers->next('purchase_request', (int) now()->format('Y')));

            $pr = PurchaseRequest::query()->create(array_merge($attributes, [
                'tenant_id' => $warehouse->tenant_id,
                'pr_number' => $number,
                'warehouse_id' => $warehouse->id,
                'requested_by' => $userId,
                'status' => 'DRAFT',
            ]));

            foreach ($items as $line) {
                PurchaseRequestItem::query()->create([
                    'purchase_request_id' => $pr->id,
                    'product_id' => $line['product_id'],
                    'requested_quantity' => $line['requested_quantity'],
                    'estimated_unit_price' => $line['estimated_unit_price'] ?? null,
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            return $pr->fresh('items');
        });
    }

    public function transition(PurchaseRequest $pr, string $to): PurchaseRequest
    {
        return DB::transaction(function () use ($pr, $to) {
            $locked = PurchaseRequest::query()->lockForUpdate()->findOrFail($pr->id);

            if (! in_array($to, self::TRANSITIONS[$locked->status] ?? [], true)) {
                throw new ProcurementException("Cannot transition Purchase Request from {$locked->status} to {$to}.");
            }

            $locked->update(['status' => $to]);

            return $locked->fresh();
        });
    }
}
