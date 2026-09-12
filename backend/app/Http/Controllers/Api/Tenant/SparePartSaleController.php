<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\WorkOrder\Models\SparePartSale;
use App\Domain\WorkOrder\Models\WorkOrderPartReturn;
use App\Domain\WorkOrder\Services\SparePartSaleService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * G-16: Sell Sparepart — sale lines drawn against a Phase B SELL_ELIGIBLE
 * disposition, gated by submit -> approve/reject maker-checker.
 */
class SparePartSaleController extends Controller
{
    public function __construct(
        private readonly SparePartSaleService $sales,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $query = SparePartSale::query()->with(['product', 'warehouse', 'partner']);

        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 20)));
    }

    public function show(SparePartSale $sparePartSale)
    {
        $this->authorizeScope($sparePartSale);

        return $this->ok($sparePartSale->load(['product', 'warehouse', 'partner', 'partReturn']));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'work_order_part_return_id' => ['required', 'uuid', 'exists:work_order_part_returns,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'sale_type' => ['required', 'string', 'in:'.implode(',', SparePartSale::SALE_TYPES)],
            'buyer_type' => ['required', 'string', 'in:'.implode(',', SparePartSale::BUYER_TYPES)],
            'partner_id' => ['nullable', 'uuid', 'exists:partners,id'],
            'buyer_name' => ['nullable', 'string', 'max:255'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $return = WorkOrderPartReturn::query()->findOrFail($validated['work_order_part_return_id']);
        abort_unless($return->tenant_id === $this->context->tenantId(), 404);

        return $this->ok($this->sales->create($return, $validated, $this->context->user()->id), 201);
    }

    public function submit(SparePartSale $sparePartSale)
    {
        $this->authorizeScope($sparePartSale);

        return $this->ok($this->sales->submit($sparePartSale, $this->context->user()->id));
    }

    public function decide(Request $request, SparePartSale $sparePartSale)
    {
        $this->authorizeScope($sparePartSale);
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:APPROVE,REJECT'],
            'note' => ['nullable', 'string'],
        ]);

        return $this->ok($this->sales->decide(
            $sparePartSale, $validated['decision'], $this->context->user()->id, $validated['note'] ?? null,
        ));
    }

    private function authorizeScope(SparePartSale $sale): void
    {
        abort_unless($sale->tenant_id === $this->context->tenantId(), 404);
    }
}
