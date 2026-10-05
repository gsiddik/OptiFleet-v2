<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\AccessControl\Services\PermissionService;
use App\Domain\Breakdown\Models\Breakdown;
use App\Domain\Configuration\Models\ConfigurationSet;
use App\Domain\Configuration\Models\ConfigurationVersion;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseRequest;
use App\Domain\Vehicle\Models\VehicleTransfer;
use App\Domain\Warranty\Models\WarrantyClaim;
use App\Domain\Workflow\Models\WorkflowLayout;
use App\Domain\Workflow\Services\WorkflowCatalog;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Domain\Workflow\Services\WorkflowGraphAnalyzer;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Visual Workflow Builder support. The workflow itself is the existing versioned WORKFLOW
 * configuration (statuses + transitions) edited through ConfigurationController; this adds:
 *  - validate: every graph problem at once (errors / warnings tied to a status or transition);
 *  - layout: node positions per tenant workflow version (presentation only, never versioned);
 *  - available transitions: the actions the published workflow allows on a record right now,
 *    so module pages show exactly the transitions the runtime accepts.
 */
class WorkflowBuilderController extends Controller
{
    /** resource type => [model, view permission, scope column, scope kind] */
    private const RESOURCES = [
        'maintenance_request' => [MaintenanceRequest::class, 'maintenance_request.view', 'branch_id', 'branch'],
        'work_order' => [WorkOrder::class, 'work_order.view', 'branch_id', 'branch'],
        'breakdown' => [Breakdown::class, 'breakdown.view', 'branch_id', 'branch'],
        'vehicle_transfer' => [VehicleTransfer::class, 'vehicle.transfer', 'from_branch_id', 'branch'],
        'stock_transfer' => [StockTransfer::class, 'stock_transfer.view', 'from_warehouse_id', 'warehouse'],
        'purchase_request' => [PurchaseRequest::class, 'purchase_request.view', 'branch_id', 'branch'],
        'purchase_order' => [PurchaseOrder::class, 'purchase_order.view', null, null],
        'warranty_claim' => [WarrantyClaim::class, 'warranty.view', null, null],
    ];

    public function __construct(
        private readonly WorkflowGraphAnalyzer $analyzer,
        private readonly WorkflowCatalog $catalog,
        private readonly WorkflowEngine $engine,
        private readonly PermissionService $permissions,
        private readonly DataScopeService $dataScope,
        private readonly TenantContext $context,
    ) {}

    public function validateGraph(Request $request)
    {
        $validated = $request->validate(['code' => ['required', 'string'], 'payload' => ['required', 'array']]);

        return $this->ok($this->analyzer->analyze($validated['payload'], $this->catalog->forResource($validated['code'])));
    }

    public function showLayout(ConfigurationVersion $version)
    {
        $set = $this->workflowSet($version, false);
        $layout = $set->tenant_id === null ? null
            : WorkflowLayout::query()->where('configuration_version_id', $version->id)->first();

        return $this->ok(['positions' => $layout?->positions, 'viewport' => $layout?->viewport]);
    }

    public function saveLayout(Request $request, ConfigurationVersion $version)
    {
        $this->workflowSet($version, true);
        $this->requirePermission('workflow.manage');
        $validated = $request->validate([
            'positions' => ['required', 'array', 'max:200'],
            'positions.*.x' => ['required', 'numeric', 'between:-100000,100000'],
            'positions.*.y' => ['required', 'numeric', 'between:-100000,100000'],
            'viewport' => ['nullable', 'array'],
            'viewport.x' => ['required_with:viewport', 'numeric'],
            'viewport.y' => ['required_with:viewport', 'numeric'],
            'viewport.zoom' => ['required_with:viewport', 'numeric', 'between:0.05,4'],
        ]);
        $codes = array_flip(array_map(fn ($s) => (string) ($s['code'] ?? ''), $version->payload['statuses'] ?? []));
        $positions = [];
        foreach ($validated['positions'] as $code => $point) {
            if (isset($codes[$code])) {
                $positions[$code] = ['x' => round((float) $point['x'], 1), 'y' => round((float) $point['y'], 1)];
            }
        }
        $viewport = isset($validated['viewport']) ? array_map('floatval', array_intersect_key($validated['viewport'], array_flip(['x', 'y', 'zoom']))) : null;
        $layout = WorkflowLayout::query()->updateOrCreate(
            ['configuration_version_id' => $version->id],
            ['tenant_id' => $this->context->tenantId(), 'positions' => $positions, 'viewport' => $viewport, 'updated_by' => $this->context->user()->id],
        );

        return $this->ok(['positions' => $layout->positions, 'viewport' => $layout->viewport]);
    }

    public function availableTransitions(Request $request)
    {
        $validated = $request->validate(['resource_type' => ['required', 'string'], 'resource_id' => ['required', 'uuid']]);
        $definition = self::RESOURCES[$validated['resource_type']] ?? null;
        abort_unless($definition !== null, 422, 'Unknown workflow resource type.');
        [$modelClass, $viewPermission, $scopeColumn, $scopeKind] = $definition;
        $this->requirePermission($viewPermission);

        /** @var Model $record */
        $record = $modelClass::query()->findOrFail($validated['resource_id']);
        $tenantId = $this->context->tenantId();
        $user = $this->context->user();
        abort_unless($record->getAttribute('tenant_id') === $tenantId, 404);
        $scopeId = $scopeColumn ? $record->getAttribute($scopeColumn) : null;
        if ($scopeId) {
            $allowed = $scopeKind === 'warehouse'
                ? $this->dataScope->canAccessWarehouse($user, $tenantId, $scopeId)
                : $this->dataScope->canAccessBranch($user, $tenantId, $scopeId);
            abort_unless($allowed, 404);
        }

        $version = $this->engine->resolvePinnedOrEffective(
            $record->getAttribute('workflow_configuration_version_id'), $validated['resource_type'], $tenantId,
            $record->getAttribute('branch_id'), $record->getAttribute('workshop_id'),
        );
        $status = (string) $record->getAttribute('status');
        $transitions = $version ? $this->engine->availableTransitions($version, $status, $user, $tenantId, $record->attributesToArray()) : [];

        return $this->ok([
            'status' => $status,
            'workflow_configuration_version_id' => $version?->id,
            'transitions' => array_map(fn (array $t) => [
                'action_code' => $t['action_code'],
                'action_label' => $t['action_label'] ?? $t['action_code'],
                'to_status' => $t['to_status'],
                'requires_approval' => ! empty($t['approval_rule']),
            ], $transitions),
        ]);
    }

    private function workflowSet(ConfigurationVersion $version, bool $tenantOwned): ConfigurationSet
    {
        // Loaded unscoped so this check is the only thing between a version and cross-tenant access.
        $set = ConfigurationSet::query()->withoutGlobalScopes()->find($version->configuration_set_id);
        abort_unless($set && $set->type === ConfigurationSet::TYPE_WORKFLOW, 404);
        abort_unless($set->tenant_id === $this->context->tenantId() || (! $tenantOwned && $set->tenant_id === null), 404);

        return $set;
    }

    private function requirePermission(string $permission): void
    {
        abort_unless($this->permissions->userHasPermission($this->context->user(), $permission, $this->context->tenantId()), 403, "Missing required permission: {$permission}");
    }
}
