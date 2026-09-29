<?php

namespace Tests\Feature;

use App\Domain\AccessControl\Models\Permission;
use App\Domain\AccessControl\Models\Role;
use App\Domain\Inspection\Models\InspectionTemplate;
use App\Domain\Invoice\Models\Invoice;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderExternalInvoice;
use App\Jobs\Intelligence\EvaluateOutcomesJob;
use App\Jobs\Intelligence\RunPredictionJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The ten permissions that were seeded but enforced nowhere now each gate a real action.
 */
class RewiredPermissionsTest extends TestCase
{
    // ---------------------------------------------------------------- tenant

    private function workOrderTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'RWP-'.Str::random(4)]);
        foreach (['VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER', 'INSPECTION'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['default_workshop_id' => $workshop->id]);

        return [$tenant, $workshop, $vehicle, $category];
    }

    private function createWorkOrder($vehicle, $workshop, array $headers): string
    {
        return $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE', 'current_odometer' => 1000,
        ], $headers)->assertStatus(201)->json('data.id');
    }

    public function test_work_order_update_edits_a_draft_header_only(): void
    {
        [$tenant, $workshop, $vehicle] = $this->workOrderTenant();
        [, $token] = $this->makeTenantUser($tenant, ['work_order.view', 'work_order.create', 'work_order.update']);
        $headers = $this->authHeaders($token);
        $id = $this->createWorkOrder($vehicle, $workshop, $headers);

        $this->putJson("/api/v1/app/work-orders/{$id}", ['priority' => 'URGENT', 'complaint' => 'Brake noise', 'status' => 'CLOSED'], $headers)
            ->assertOk()->assertJsonPath('data.priority', 'URGENT')->assertJsonPath('data.complaint', 'Brake noise')->assertJsonPath('data.status', 'DRAFT');
        $this->putJson("/api/v1/app/work-orders/{$id}", ['priority' => 'NOPE'], $headers)->assertStatus(422);

        WorkOrder::query()->whereKey($id)->update(['status' => 'SUBMITTED']);
        $this->putJson("/api/v1/app/work-orders/{$id}", ['priority' => 'LOW'], $headers)->assertStatus(422);

        [, $viewer] = $this->makeTenantUser($tenant, ['work_order.view', 'work_order.create']);
        $this->putJson("/api/v1/app/work-orders/{$id}", ['priority' => 'LOW'], $this->authHeaders($viewer))->assertForbidden();
    }

    public function test_inspection_review_records_the_reviewer_once_after_submission(): void
    {
        [$tenant, , $vehicle, $category] = $this->workOrderTenant();
        $template = InspectionTemplate::query()->create([
            'tenant_id' => $tenant->id, 'vehicle_category_id' => $category->id, 'inspection_type' => 'PRE_TRIP', 'name' => 'T', 'status' => 'ACTIVE',
        ]);
        $item = $template->items()->create(['item_text' => 'Oil level', 'input_type' => 'PASS_FAIL', 'sequence' => 1]);
        [$reviewer, $token] = $this->makeTenantUser($tenant, ['inspection.view', 'inspection.create', 'inspection.perform', 'inspection.submit', 'inspection.review']);
        $headers = $this->authHeaders($token);

        $id = $this->postJson('/api/v1/app/inspections', ['vehicle_id' => $vehicle->id, 'inspection_template_id' => $template->id], $headers)->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/app/inspections/{$id}/review", [], $headers)->assertStatus(422);

        $this->postJson("/api/v1/app/inspections/{$id}/start", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/inspections/{$id}/submit", ['results' => [['inspection_template_item_id' => $item->id, 'passed' => true]]], $headers)->assertOk();

        [, $performer] = $this->makeTenantUser($tenant, ['inspection.view', 'inspection.submit']);
        $this->postJson("/api/v1/app/inspections/{$id}/review", [], $this->authHeaders($performer))->assertForbidden();

        $this->postJson("/api/v1/app/inspections/{$id}/review", ['review_notes' => 'Checked'], $headers)
            ->assertOk()->assertJsonPath('data.status', 'PASSED')->assertJsonPath('data.reviewed_by', $reviewer->id)->assertJsonPath('data.review_notes', 'Checked');
        $this->postJson("/api/v1/app/inspections/{$id}/review", [], $headers)->assertStatus(422);
    }

    public function test_external_invoice_cancel_needs_both_invoice_and_external_cancel_permissions(): void
    {
        [$tenant, $workshop, $vehicle] = $this->workOrderTenant();
        $base = ['work_order.view', 'work_order.create', 'work_order.prepare_external', 'work_order.finalize_external', 'external_work_order_invoice.view'];
        [, $token] = $this->makeTenantUser($tenant, [...$base, 'work_order.cancel_external', 'external_work_order_invoice.cancel']);
        $headers = $this->authHeaders($token);

        $woId = $this->createWorkOrder($vehicle, $workshop, $headers);
        $this->postJson("/api/v1/app/work-orders/{$woId}/execution-mode/external", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$woId}/external-findings", ['severity' => 'HIGH', 'description' => 'Cracked head.'], $headers)->assertStatus(201);
        $this->postJson("/api/v1/app/work-orders/{$woId}/external", [], $headers)->assertOk();
        $invoice = WorkOrderExternalInvoice::query()->where('work_order_id', $woId)->firstOrFail();

        [, $onlyInvoice] = $this->makeTenantUser($tenant, [...$base, 'external_work_order_invoice.cancel']);
        $this->postJson("/api/v1/app/external-work-order-invoices/{$invoice->id}/cancel", ['reason' => 'x'], $this->authHeaders($onlyInvoice))->assertForbidden();
        [, $onlyWorkOrder] = $this->makeTenantUser($tenant, [...$base, 'work_order.cancel_external']);
        $this->postJson("/api/v1/app/external-work-order-invoices/{$invoice->id}/cancel", ['reason' => 'x'], $this->authHeaders($onlyWorkOrder))->assertForbidden();

        $this->postJson("/api/v1/app/external-work-order-invoices/{$invoice->id}/cancel", [], $headers)->assertStatus(422);
        $this->postJson("/api/v1/app/external-work-order-invoices/{$invoice->id}/cancel", ['reason' => 'Vendor unavailable'], $headers)
            ->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->assertSame('CANCELLED', WorkOrder::query()->find($woId)->status);
        $this->postJson("/api/v1/app/external-work-order-invoices/{$invoice->id}/cancel", ['reason' => 'again'], $headers)->assertStatus(422);
    }

    // -------------------------------------------------------------- platform

    private function commercialSetup(): void
    {
        $this->makeBundle('BASIC', ['CORE', 'ORGANIZATION', 'VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER']);
        $this->makePricing('BUNDLE', 'BASIC', '2000000');
    }

    public function test_generate_billing_requires_invoice_generate_and_issue(): void
    {
        $this->commercialSetup();
        $subscription = $this->approveContract($this->makeContractDraft($this->makeTenant(), 'BASIC'))->subscription;

        [, $billingOnly] = $this->makePlatformUser(['billing.generate']);
        $this->postJson("/api/v1/platform/subscriptions/{$subscription->id}/generate-billing", [], $this->authHeaders($billingOnly))->assertForbidden();
        [, $noIssue] = $this->makePlatformUser(['billing.generate', 'invoice.generate']);
        $this->postJson("/api/v1/platform/subscriptions/{$subscription->id}/generate-billing", [], $this->authHeaders($noIssue))->assertForbidden();

        [, $token] = $this->makePlatformUser(['billing.generate', 'invoice.generate', 'invoice.issue']);
        $this->postJson("/api/v1/platform/subscriptions/{$subscription->id}/generate-billing", [], $this->authHeaders($token))->assertStatus(201);
    }

    public function test_subscription_activate_activates_only_a_pending_subscription(): void
    {
        $this->commercialSetup();
        $tenant = $this->makeTenant();
        $subscription = $this->approveContract($this->makeContractDraft($tenant, 'BASIC', ['activation_requires_payment' => true]))->subscription;
        $this->assertSame('PENDING', $subscription->status);

        [, $viewer] = $this->makePlatformUser(['subscription.view', 'subscription.reactivate']);
        $this->postJson("/api/v1/platform/subscriptions/{$subscription->id}/activate", ['reason' => 'x'], $this->authHeaders($viewer))->assertForbidden();

        [, $token] = $this->makePlatformUser(['subscription.activate']);
        $headers = $this->authHeaders($token);
        $this->postJson("/api/v1/platform/subscriptions/{$subscription->id}/activate", [], $headers)->assertStatus(422);
        $this->postJson("/api/v1/platform/subscriptions/{$subscription->id}/activate", ['reason' => 'First payment waived'], $headers)
            ->assertOk()->assertJsonPath('data.status', 'ACTIVE');
        $this->assertSame('ACTIVE', $subscription->contract->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['resource_type' => 'Subscription', 'resource_id' => $subscription->id, 'action' => 'manually_activated']);
        $this->postJson("/api/v1/platform/subscriptions/{$subscription->id}/activate", ['reason' => 'again'], $headers)->assertStatus(422);
    }

    public function test_billing_adjust_raises_an_issued_adjustment_invoice(): void
    {
        $this->commercialSetup();
        $subscription = $this->approveContract($this->makeContractDraft($this->makeTenant(), 'BASIC'))->subscription;

        [, $viewer] = $this->makePlatformUser(['billing.view', 'invoice.view']);
        $this->postJson("/api/v1/platform/subscriptions/{$subscription->id}/adjustment-invoices", ['amount' => '150000.50', 'description' => 'x'], $this->authHeaders($viewer))->assertForbidden();

        [, $token] = $this->makePlatformUser(['billing.adjust']);
        $headers = $this->authHeaders($token);
        foreach (['0', '-5', '1.234', 'abc'] as $bad) {
            $this->postJson("/api/v1/platform/subscriptions/{$subscription->id}/adjustment-invoices", ['amount' => $bad, 'description' => 'x'], $headers)->assertStatus(422);
        }

        $id = $this->postJson("/api/v1/platform/subscriptions/{$subscription->id}/adjustment-invoices", ['amount' => '150000.50', 'description' => 'On-site training'], $headers)
            ->assertStatus(201)->json('data.id');
        $invoice = Invoice::query()->with('items')->findOrFail($id);
        $this->assertSame('OUTSTANDING', $invoice->status);
        $this->assertNull($invoice->billing_id);
        $this->assertSame('150000.50', (string) $invoice->total);
        $this->assertSame('150000.50', (string) $invoice->outstanding_amount);
        $this->assertSame('On-site training', $invoice->items->first()->description);
        $this->assertDatabaseHas('audit_logs', ['resource_type' => 'Invoice', 'resource_id' => $id, 'action' => 'adjustment_raised']);
    }

    public function test_contract_update_replaces_terms_and_items_of_a_draft_only(): void
    {
        $this->commercialSetup();
        $this->makePricing('MODULE', 'HISTORY', '300000');
        $tenant = $this->makeTenant();
        $draft = $this->makeContractDraft($tenant, 'BASIC');
        $payload = [
            'tenant_id' => $this->makeTenant()->id, // never re-targets the contract
            'start_date' => now()->toDateString(), 'end_date' => now()->addYears(2)->toDateString(),
            'billing_cycle' => 'MONTHLY', 'payment_terms_days' => 30, 'currency' => 'IDR',
            'items' => [
                ['product_type' => 'BUNDLE', 'product_reference' => 'BASIC', 'description' => 'Basic', 'quantity' => 1, 'billing_frequency' => 'MONTHLY'],
                ['product_type' => 'MODULE', 'product_reference' => 'HISTORY', 'description' => 'History', 'quantity' => 1, 'billing_frequency' => 'MONTHLY'],
            ],
        ];

        [, $creator] = $this->makePlatformUser(['contract.view', 'contract.create']);
        $this->putJson("/api/v1/platform/contracts/{$draft->id}", $payload, $this->authHeaders($creator))->assertForbidden();

        [, $token] = $this->makePlatformUser(['contract.update']);
        $headers = $this->authHeaders($token);
        $this->putJson("/api/v1/platform/contracts/{$draft->id}", [...$payload, 'items' => [
            ['product_type' => 'BUNDLE', 'product_reference' => 'BASIC', 'description' => 'Basic', 'quantity' => 1, 'unit_price' => '1', 'billing_frequency' => 'MONTHLY'],
        ]], $headers)->assertStatus(422); // below the active price
        $this->assertCount(1, $draft->items()->get(), 'A rejected edit leaves the draft untouched.');

        $this->putJson("/api/v1/platform/contracts/{$draft->id}", $payload, $headers)
            ->assertOk()->assertJsonPath('data.tenant_id', $tenant->id)->assertJsonPath('data.payment_terms_days', 30)->assertJsonCount(2, 'data.items');
        $this->assertSame('2300000.00', (string) $draft->fresh()->total);

        $approved = $this->approveContract($draft->fresh());
        $this->putJson("/api/v1/platform/contracts/{$approved->id}", $payload, $headers)->assertStatus(422);
    }

    public function test_intelligence_prediction_run_and_evaluation_are_dispatched_for_entitled_tenants(): void
    {
        Queue::fake();
        $tenant = $this->makeTenant();
        $other = $this->makeTenant();
        $this->grantModule($tenant, 'MAINTENANCE_INTELLIGENCE');

        [, $viewer] = $this->makePlatformUser(['intelligence.model.view', 'intelligence.model.train']);
        $this->postJson('/api/v1/platform/intelligence/predictions/run', ['tenant_id' => $tenant->id], $this->authHeaders($viewer))->assertForbidden();
        $this->postJson('/api/v1/platform/intelligence/evaluations/run', ['tenant_id' => $tenant->id], $this->authHeaders($viewer))->assertForbidden();

        [, $token] = $this->makePlatformUser(['intelligence.prediction.run', 'intelligence.model.evaluate']);
        $headers = $this->authHeaders($token);
        $this->postJson('/api/v1/platform/intelligence/predictions/run', ['tenant_id' => $other->id], $headers)->assertStatus(422);
        $this->postJson('/api/v1/platform/intelligence/predictions/run', ['tenant_id' => $tenant->id, 'target' => 'unknown'], $headers)->assertStatus(422);

        $this->postJson('/api/v1/platform/intelligence/predictions/run', ['tenant_id' => $tenant->id, 'target' => 'vehicle_failure_risk', 'date' => '2026-09-01'], $headers)->assertStatus(202);
        Queue::assertPushed(RunPredictionJob::class, fn ($job) => $job->tenantId === $tenant->id && $job->businessDate === '2026-09-01');

        $this->postJson('/api/v1/platform/intelligence/evaluations/run', ['tenant_id' => $tenant->id], $headers)->assertStatus(202);
        Queue::assertPushed(EvaluateOutcomesJob::class, fn ($job) => $job->tenantId === $tenant->id);
        $this->assertDatabaseHas('audit_logs', ['resource_type' => 'IntelligenceModelEvaluation', 'action' => 'requested']);
    }

    // ---------------------------------------------------------------- backfill

    public function test_backfill_grants_rewired_permissions_to_roles_that_could_already_act(): void
    {
        $platformRole = Role::query()->create(['tenant_id' => null, 'name' => 'Billing Ops', 'scope' => 'platform', 'is_system' => false]);
        $platformRole->permissions()->sync(Permission::query()->where('scope', 'platform')->where('name', 'billing.generate')->pluck('id'));
        $tenant = $this->makeTenant();
        $tenantRole = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'WO Admin', 'scope' => 'tenant', 'is_system' => false]);
        $tenantRole->permissions()->sync(Permission::query()->where('scope', 'tenant')->where('name', 'work_order.cancel_external')->pluck('id'));
        $untouched = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'Viewer', 'scope' => 'tenant', 'is_system' => false]);

        $migration = require database_path('migrations/2026_09_30_000003_grant_rewired_permissions.php');
        $migration->up();
        $migration->up();

        $this->assertEqualsCanonicalizing(['billing.generate', 'invoice.generate', 'invoice.issue'], $platformRole->permissions()->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['work_order.cancel_external', 'external_work_order_invoice.cancel'], $tenantRole->permissions()->pluck('name')->all());
        $this->assertSame(0, $untouched->permissions()->count());
    }

    public function test_every_seeded_permission_is_enforced_by_a_route(): void
    {
        $enforced = collect(app('router')->getRoutes())
            ->flatMap(fn ($route) => $route->gatherMiddleware())
            ->filter(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'))
            ->map(fn ($m) => substr($m, strlen('permission:')))
            ->unique();

        // Configuration permissions are resolved per document type inside ConfigurationController.
        $configuration = ['document_template.manage', 'document_template.publish', 'numbering.manage', 'numbering.publish',
            'tire_scoring_configuration.manage', 'tire_scoring_configuration.publish', 'workflow.manage', 'workflow.publish', 'workflow.simulate'];

        $unused = Permission::query()->pluck('name')->unique()
            ->reject(fn ($name) => $enforced->contains($name) || in_array($name, $configuration, true))
            ->values()->all();

        $this->assertSame([], $unused);
    }
}
