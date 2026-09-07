<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Procurement\Models\PurchaseRequest;
use App\Domain\Procurement\Models\Rfq;
use App\Domain\Procurement\Services\RfqService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class RfqController extends Controller
{
    public function __construct(
        private readonly RfqService $rfqs,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $query = Rfq::query()->where('tenant_id', $tenantId)->with(['warehouse', 'items.product', 'vendors']);
        $this->scope->applyWarehouseScope($query, $this->context->user(), $tenantId, 'warehouse_id');

        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $validated = $request->validate([
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'purchase_request_id' => ['nullable', 'uuid', 'exists:purchase_requests,id'],
            'issue_date' => ['nullable', 'date'],
            'response_deadline' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $warehouse = Warehouse::query()->findOrFail($validated['warehouse_id']);
        abort_unless($warehouse->tenant_id === $tenantId, 404);
        abort_unless($this->scope->canAccessWarehouse($this->context->user(), $tenantId, $warehouse->id), 403);

        $pr = isset($validated['purchase_request_id']) ? PurchaseRequest::query()->find($validated['purchase_request_id']) : null;

        $rfq = $this->rfqs->create($warehouse, [
            'issue_date' => $validated['issue_date'] ?? null,
            'response_deadline' => $validated['response_deadline'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ], $validated['items'], $pr);

        return $this->ok($rfq, 201);
    }

    public function show(Rfq $rfq)
    {
        $this->authorizeScope($rfq);

        return $this->ok($rfq->load(['warehouse', 'items.product', 'vendors', 'quotations.partner']));
    }

    public function inviteVendors(Request $request, Rfq $rfq)
    {
        $this->authorizeScope($rfq);
        $validated = $request->validate(['partner_ids' => ['required', 'array', 'min:1'], 'partner_ids.*' => ['uuid', 'exists:partners,id']]);

        return $this->ok($this->rfqs->inviteVendors($rfq, $validated['partner_ids']));
    }

    public function close(Rfq $rfq)
    {
        $this->authorizeScope($rfq);

        return $this->ok($this->rfqs->close($rfq));
    }

    public function cancel(Rfq $rfq)
    {
        $this->authorizeScope($rfq);

        return $this->ok($this->rfqs->cancel($rfq));
    }

    public function compare(Rfq $rfq)
    {
        $this->authorizeScope($rfq);

        return $this->ok($this->rfqs->compare($rfq));
    }

    private function authorizeScope(Rfq $rfq): void
    {
        abort_unless($rfq->tenant_id === $this->context->tenantId(), 404);
        abort_unless(
            $this->scope->canAccessWarehouse($this->context->user(), $this->context->tenantId(), $rfq->warehouse_id),
            403,
            'This RFQ is outside your assigned data scope.'
        );
    }
}
