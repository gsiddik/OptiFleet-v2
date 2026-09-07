<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Configuration\Services\DocumentNumberingService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Procurement\Models\PurchaseRequest;
use App\Domain\Procurement\Models\PurchaseRequestItem;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Support\Facades\DB;

/**
 * Section 16/25: DRAFT -> SUBMITTED -> UNDER_REVIEW -> APPROVED ->
 * PROCUREMENT, with REJECTED/CANCELLED side branches, now validated
 * through the Phase 5 workflow engine against the version pinned at
 * creation.
 */
class PurchaseRequestService
{
    private const RESOURCE_TYPE = 'purchase_request';

    public function __construct(
        private readonly DocumentNumberingService $numbers,
        private readonly WorkflowEngine $workflow,
    ) {}

    public function create(Warehouse $warehouse, array $attributes, array $items, ?string $userId): PurchaseRequest
    {
        if (empty($items)) {
            throw new ProcurementException('A purchase request needs at least one item.');
        }

        return DB::transaction(function () use ($warehouse, $attributes, $items, $userId) {
            $number = $this->numbers->generate('purchase_request', $warehouse->tenant_id, $attributes['branch_id'] ?? null, $attributes['workshop_id'] ?? null, $warehouse->id);
            $workflowVersion = $this->workflow->resolveEffective(self::RESOURCE_TYPE, $warehouse->tenant_id, $attributes['branch_id'] ?? null, $attributes['workshop_id'] ?? null);

            $pr = PurchaseRequest::query()->create(array_merge($attributes, [
                'tenant_id' => $warehouse->tenant_id,
                'pr_number' => $number['document_number'],
                'numbering_configuration_version_id' => $number['configuration_version_id'],
                'workflow_configuration_version_id' => $workflowVersion?->id,
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

            $version = $this->workflow->resolvePinnedOrEffective($locked->workflow_configuration_version_id, self::RESOURCE_TYPE, $locked->tenant_id, $locked->branch_id, $locked->workshop_id);
            if (! $this->workflow->isTransitionAllowedForVersion($version, $locked->status, $to)) {
                throw new ProcurementException("Cannot transition Purchase Request from {$locked->status} to {$to}.");
            }

            $locked->update(['status' => $to]);

            return $locked->fresh();
        });
    }
}
