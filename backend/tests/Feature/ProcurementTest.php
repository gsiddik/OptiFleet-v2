<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseRequest;
use App\Domain\Procurement\Models\PurchaseRequestItem;
use App\Domain\Procurement\Models\Rfq;
use App\Domain\Procurement\Models\VendorQuotation;
use App\Domain\Workflow\Services\WorkflowDefinitionService;
use App\Domain\WorkOrder\Models\WorkOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcurementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local'); // quotation documents never touch the real private disk in tests
    }

    private function setUpScenario(): array
    {
        $tenant = $this->makeTenant(['code' => 'PROC-'.Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        $this->grantModule($tenant, 'PROCUREMENT');
        $this->grantModule($tenant, 'PARTNER');
        $branch = $this->makeBranch($tenant);
        $warehouse = $this->makeWarehouse($tenant, $branch);
        $product = $this->makeProduct($tenant);
        $vendor = $this->makePartner($tenant, ['partner_type' => 'SPARE_PART_SUPPLIER']);

        return [$tenant, $warehouse, $product, $vendor];
    }

    private function fullPermissions(): array
    {
        return [
            'purchase_request.view', 'purchase_request.create', 'purchase_request.submit', 'purchase_request.approve',
            'rfq.view', 'rfq.manage',
            'quotation.view', 'quotation.manage', 'quotation.select',
            'purchase_order.view', 'purchase_order.create', 'purchase_order.approve', 'purchase_order.issue',
            'goods_receipt.view', 'goods_receipt.create', 'goods_receipt.post',
            'partner.view',
        ];
    }

    public function test_purchase_request_full_lifecycle(): void
    {
        [$tenant, $warehouse, $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/purchase-requests', [
            'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'requested_quantity' => 10, 'estimated_unit_price' => 5]],
        ], $headers)->assertStatus(201);
        $pr = PurchaseRequest::query()->findOrFail($create->json('data.id'));

        $this->postJson("/api/v1/app/purchase-requests/{$pr->id}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/purchase-requests/{$pr->id}/review", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/purchase-requests/{$pr->id}/approve", [], $headers)->assertOk()
            ->assertJsonPath('data.status', 'APPROVED');
    }

    public function test_purchase_request_can_be_linked_to_a_work_order(): void
    {
        [$tenant, $warehouse, $product] = $this->setUpScenario();
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        $workOrder = WorkOrder::query()->create([
            'tenant_id' => $tenant->id, 'wo_number' => 'WO-'.Str::upper(Str::random(6)),
            'branch_id' => $branch->id, 'workshop_id' => $workshop->id, 'vehicle_id' => $vehicle->id,
            'maintenance_type' => 'CORRECTIVE',
        ]);
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/purchase-requests', [
            'warehouse_id' => $warehouse->id,
            'source_type' => 'WORK_ORDER',
            'work_order_id' => $workOrder->id,
            'items' => [['product_id' => $product->id, 'requested_quantity' => 5]],
        ], $headers)->assertStatus(201);

        $this->assertSame($workOrder->id, $create->json('data.work_order_id'));
        $this->getJson("/api/v1/app/purchase-requests/{$create->json('data.id')}", $headers)->assertOk()
            ->assertJsonPath('data.work_order.id', $workOrder->id);
    }

    public function test_purchase_request_line_can_be_held_or_rejected_independently(): void
    {
        [$tenant, $warehouse, $product] = $this->setUpScenario();
        $otherProduct = $this->makeProduct($tenant);
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/purchase-requests', [
            'warehouse_id' => $warehouse->id,
            'items' => [
                ['product_id' => $product->id, 'requested_quantity' => 5],
                ['product_id' => $otherProduct->id, 'requested_quantity' => 3],
            ],
        ], $headers)->assertStatus(201);
        $items = PurchaseRequestItem::query()->where('purchase_request_id', $create->json('data.id'))->get();
        $this->assertTrue($items->every(fn ($i) => $i->line_status === 'PENDING'));

        $held = $items->first();
        $this->putJson("/api/v1/app/purchase-requests/{$create->json('data.id')}/items/{$held->id}/line-status", [
            'line_status' => 'ON_HOLD', 'line_reason' => 'Awaiting budget confirmation',
        ], $headers)->assertOk()->assertJsonPath('data.line_status', 'ON_HOLD')->assertJsonPath('data.line_reason', 'Awaiting budget confirmation');

        $other = $items->last();
        $this->assertSame('PENDING', $other->fresh()->line_status);
    }

    public function test_purchase_request_invalid_transition_is_rejected(): void
    {
        [$tenant, $warehouse, $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/purchase-requests', [
            'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'requested_quantity' => 10]],
        ], $headers)->assertStatus(201);
        $pr = PurchaseRequest::query()->findOrFail($create->json('data.id'));

        $this->postJson("/api/v1/app/purchase-requests/{$pr->id}/approve", [], $headers)->assertStatus(422);
    }

    public function test_rfq_invite_vendors_and_quotation_comparison_picks_lowest_total(): void
    {
        [$tenant, $warehouse, $product, $vendorA] = $this->setUpScenario();
        $vendorB = $this->makePartner($tenant, ['partner_type' => 'SPARE_PART_SUPPLIER', 'code' => 'VEN-B']);
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/rfqs', [
            'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10]],
        ], $headers)->assertStatus(201);
        $rfq = Rfq::query()->findOrFail($create->json('data.id'));

        $this->postJson("/api/v1/app/rfqs/{$rfq->id}/vendors", [
            'partner_ids' => [$vendorA->id, $vendorB->id],
        ], $headers)->assertOk();

        $quoteA = $this->postJson("/api/v1/app/rfqs/{$rfq->id}/quotations", [
            'partner_id' => $vendorA->id,
            'attachment' => $this->quotationDocument(),
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 12, 'discount_percent' => 0, 'tax_percent' => 10]],
        ], $headers)->assertStatus(201);
        $this->assertSame(132.0, (float) $quoteA->json('data.total'));

        $quoteB = $this->postJson("/api/v1/app/rfqs/{$rfq->id}/quotations", [
            'partner_id' => $vendorB->id,
            'attachment' => $this->quotationDocument(),
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 10, 'discount_percent' => 0, 'tax_percent' => 10]],
        ], $headers)->assertStatus(201);
        $this->assertSame(110.0, (float) $quoteB->json('data.total'));

        $compare = $this->getJson("/api/v1/app/rfqs/{$rfq->id}/compare", $headers)->assertOk();
        $ranked = $compare->json('data');
        $this->assertSame($vendorB->id, $ranked[0]['partner']['id']);
        $this->assertSame(110.0, (float) $ranked[0]['total']);
    }

    public function test_quotation_total_is_server_calculated_not_client_trusted(): void
    {
        [$tenant, $warehouse, $product, $vendor] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/rfqs', [
            'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ], $headers)->assertStatus(201);
        $rfq = Rfq::query()->findOrFail($create->json('data.id'));
        $this->postJson("/api/v1/app/rfqs/{$rfq->id}/vendors", ['partner_ids' => [$vendor->id]], $headers)->assertOk();

        $response = $this->postJson("/api/v1/app/rfqs/{$rfq->id}/quotations", [
            'partner_id' => $vendor->id,
            'attachment' => $this->quotationDocument(),
            'total' => 1, // client-provided total must be ignored
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 20, 'discount_percent' => 10, 'tax_percent' => 0]],
        ], $headers)->assertStatus(201);

        // 5 * 20 = 100, less 10% discount = 90
        $this->assertSame(90.0, (float) $response->json('data.total'));
    }

    /** Recording a quotation requires the vendor's quotation document. */
    private function quotationDocument(): UploadedFile
    {
        return UploadedFile::fake()->create('vendor-quotation.pdf', 40, 'application/pdf');
    }

    private function createSelectedQuotation(array $tenantAndDeps, string $token): VendorQuotation
    {
        [$tenant, $warehouse, $product, $vendor] = $tenantAndDeps;
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/rfqs', [
            'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10]],
        ], $headers)->assertStatus(201);
        $rfq = Rfq::query()->findOrFail($create->json('data.id'));
        $this->postJson("/api/v1/app/rfqs/{$rfq->id}/vendors", ['partner_ids' => [$vendor->id]], $headers)->assertOk();

        $quoteResponse = $this->postJson("/api/v1/app/rfqs/{$rfq->id}/quotations", [
            'partner_id' => $vendor->id,
            'attachment' => $this->quotationDocument(),
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 8, 'discount_percent' => 0, 'tax_percent' => 0]],
        ], $headers)->assertStatus(201);
        $quotation = VendorQuotation::query()->findOrFail($quoteResponse->json('data.id'));

        $this->postJson("/api/v1/app/quotations/{$quotation->id}/select", [], $headers)->assertOk();

        return $quotation->fresh();
    }

    public function test_purchase_order_tiered_approval_when_tenant_publishes_an_approval_rule(): void
    {
        $scenario = $this->setUpScenario();
        [$tenant, $warehouse, , $vendor] = $scenario;
        [, $requesterToken] = $this->makeTenantUser($tenant, $this->fullPermissions());
        [$approverUser, $approverToken] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $requesterHeaders = $this->authHeaders($requesterToken);
        $approverHeaders = $this->authHeaders($approverToken);

        $definitions = app(WorkflowDefinitionService::class);
        $set = $definitions->findOrCreateSet($tenant->id, 'purchase_order', 'TENANT', null, 'PO Approval');
        $payload = [
            'statuses' => [
                ['code' => 'DRAFT', 'display_name' => 'Draft', 'is_start' => true],
                ['code' => 'SUBMITTED', 'display_name' => 'Submitted'],
                ['code' => 'APPROVED', 'display_name' => 'Approved'],
                ['code' => 'REJECTED', 'display_name' => 'Rejected'],
                ['code' => 'CANCELLED', 'display_name' => 'Cancelled'],
            ],
            'transitions' => [
                ['from_status' => 'DRAFT', 'to_status' => 'SUBMITTED', 'action_code' => 'submitted'],
                ['from_status' => 'DRAFT', 'to_status' => 'CANCELLED', 'action_code' => 'cancelled'],
                [
                    'from_status' => 'SUBMITTED', 'to_status' => 'APPROVED', 'action_code' => 'approved',
                    'approval_rule' => [
                        'type' => 'SEQUENTIAL',
                        'steps' => [
                            ['step_number' => 1, 'approver_type' => 'PERMISSION', 'approver_identifier' => 'purchase_order.approve'],
                            ['step_number' => 2, 'approver_type' => 'PERMISSION', 'approver_identifier' => 'purchase_order.approve'],
                        ],
                    ],
                ],
                ['from_status' => 'SUBMITTED', 'to_status' => 'REJECTED', 'action_code' => 'rejected'],
            ],
        ];
        $definitions->publish($definitions->createDraft($set, $payload, null), null);

        $quotation = $this->createSelectedQuotation($scenario, $requesterToken);
        $poResponse = $this->postJson("/api/v1/app/quotations/{$quotation->id}/purchase-order", [
            'delivery_warehouse_id' => $warehouse->id,
        ], $requesterHeaders)->assertStatus(201);
        $po = PurchaseOrder::query()->findOrFail($poResponse->json('data.id'));

        $this->postJson("/api/v1/app/purchase-orders/{$po->id}/submit", [], $requesterHeaders)->assertOk();

        // approve() now opens a 2-step approval request instead of going straight to APPROVED.
        $this->postJson("/api/v1/app/purchase-orders/{$po->id}/approve", [], $requesterHeaders)
            ->assertOk()->assertJsonPath('data.status', 'PENDING_APPROVAL');

        // The requester cannot decide their own request's steps.
        $this->postJson("/api/v1/app/purchase-orders/{$po->id}/decide-approval", ['decision' => 'APPROVED'], $requesterHeaders)
            ->assertStatus(422);

        // Step 1 decided -> still PENDING_APPROVAL (one more step outstanding).
        $this->postJson("/api/v1/app/purchase-orders/{$po->id}/decide-approval", ['decision' => 'APPROVED'], $approverHeaders)
            ->assertOk()->assertJsonPath('data.status', 'PENDING_APPROVAL');

        // Step 2 decided -> fully approved.
        $this->postJson("/api/v1/app/purchase-orders/{$po->id}/decide-approval", ['decision' => 'APPROVED'], $approverHeaders)
            ->assertOk()->assertJsonPath('data.status', 'APPROVED');

        $this->assertSame($approverUser->id, PurchaseOrder::query()->findOrFail($po->id)->approved_by);
    }

    public function test_purchase_order_tiered_approval_can_be_rejected_mid_chain(): void
    {
        $scenario = $this->setUpScenario();
        [$tenant, $warehouse] = $scenario;
        [, $requesterToken] = $this->makeTenantUser($tenant, $this->fullPermissions());
        [, $approverToken] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $requesterHeaders = $this->authHeaders($requesterToken);
        $approverHeaders = $this->authHeaders($approverToken);

        $definitions = app(WorkflowDefinitionService::class);
        $set = $definitions->findOrCreateSet($tenant->id, 'purchase_order', 'TENANT', null, 'PO Approval');
        $payload = [
            'statuses' => [
                ['code' => 'DRAFT', 'display_name' => 'Draft', 'is_start' => true],
                ['code' => 'SUBMITTED', 'display_name' => 'Submitted'],
                ['code' => 'APPROVED', 'display_name' => 'Approved'],
                ['code' => 'REJECTED', 'display_name' => 'Rejected'],
            ],
            'transitions' => [
                ['from_status' => 'DRAFT', 'to_status' => 'SUBMITTED', 'action_code' => 'submitted'],
                [
                    'from_status' => 'SUBMITTED', 'to_status' => 'APPROVED', 'action_code' => 'approved',
                    'approval_rule' => [
                        'type' => 'SINGLE',
                        'steps' => [['step_number' => 1, 'approver_type' => 'PERMISSION', 'approver_identifier' => 'purchase_order.approve']],
                    ],
                ],
                ['from_status' => 'SUBMITTED', 'to_status' => 'REJECTED', 'action_code' => 'rejected'],
            ],
        ];
        $definitions->publish($definitions->createDraft($set, $payload, null), null);

        $quotation = $this->createSelectedQuotation($scenario, $requesterToken);
        $poResponse = $this->postJson("/api/v1/app/quotations/{$quotation->id}/purchase-order", [
            'delivery_warehouse_id' => $warehouse->id,
        ], $requesterHeaders)->assertStatus(201);
        $po = PurchaseOrder::query()->findOrFail($poResponse->json('data.id'));
        $this->postJson("/api/v1/app/purchase-orders/{$po->id}/submit", [], $requesterHeaders)->assertOk();
        $this->postJson("/api/v1/app/purchase-orders/{$po->id}/approve", [], $requesterHeaders)->assertOk();

        $this->postJson("/api/v1/app/purchase-orders/{$po->id}/decide-approval", ['decision' => 'REJECTED', 'note' => 'Over budget'], $approverHeaders)
            ->assertOk()->assertJsonPath('data.status', 'REJECTED');
    }

    public function test_purchase_order_lifecycle_with_partial_receipt_and_over_receipt_rejection(): void
    {
        $scenario = $this->setUpScenario();
        [$tenant, $warehouse, $product, $vendor] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $quotation = $this->createSelectedQuotation($scenario, $token);

        $poResponse = $this->postJson("/api/v1/app/quotations/{$quotation->id}/purchase-order", [
            'delivery_warehouse_id' => $warehouse->id,
        ], $headers)->assertStatus(201);
        $po = PurchaseOrder::query()->findOrFail($poResponse->json('data.id'));
        $this->assertSame(80.0, (float) $po->total);

        $this->postJson("/api/v1/app/purchase-orders/{$po->id}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/purchase-orders/{$po->id}/approve", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/purchase-orders/{$po->id}/issue", [], $headers)->assertOk();

        $item = $po->items()->first();

        // Partial receipt: 6 of 10.
        $this->postJson("/api/v1/app/purchase-orders/{$po->id}/goods-receipts", [
            'lines' => [['purchase_order_item_id' => $item->id, 'quantity_accepted' => 6]],
        ], $headers)->assertStatus(201);

        $po->refresh();
        $this->assertSame('PARTIALLY_RECEIVED', $po->status);
        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame(6.0, (float) $stock->quantity_on_hand);

        // Over-receipt beyond remaining (4 left) must be rejected.
        $this->postJson("/api/v1/app/purchase-orders/{$po->id}/goods-receipts", [
            'lines' => [['purchase_order_item_id' => $item->id, 'quantity_accepted' => 5]],
        ], $headers)->assertStatus(422);

        // Remaining 4 completes the PO.
        $this->postJson("/api/v1/app/purchase-orders/{$po->id}/goods-receipts", [
            'lines' => [['purchase_order_item_id' => $item->id, 'quantity_accepted' => 4]],
        ], $headers)->assertStatus(201);

        $po->refresh();
        $this->assertSame('RECEIVED', $po->status);
        $stock->refresh();
        $this->assertSame(10.0, (float) $stock->quantity_on_hand);
    }

    public function test_duplicate_purchase_order_conversion_from_same_quotation_is_rejected(): void
    {
        $scenario = $this->setUpScenario();
        [$tenant, $warehouse] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $quotation = $this->createSelectedQuotation($scenario, $token);

        $this->postJson("/api/v1/app/quotations/{$quotation->id}/purchase-order", [
            'delivery_warehouse_id' => $warehouse->id,
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/quotations/{$quotation->id}/purchase-order", [
            'delivery_warehouse_id' => $warehouse->id,
        ], $headers)->assertStatus(422);

        $this->assertSame(1, PurchaseOrder::query()->where('vendor_quotation_id', $quotation->id)->count());
    }

    public function test_partner_records_are_tenant_isolated(): void
    {
        [$tenant, , , $vendor] = $this->setUpScenario();
        $otherTenant = $this->makeTenant(['code' => 'PROCB-'.Str::random(4)]);
        $this->grantModule($otherTenant, 'PARTNER');
        [, $otherToken] = $this->makeTenantUser($otherTenant, ['partner.view']);

        $this->getJson("/api/v1/app/partners/{$vendor->id}", $this->authHeaders($otherToken))->assertStatus(404);
    }
}
