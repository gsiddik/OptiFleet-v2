<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\DashboardFilters;
use App\Domain\Dashboard\DashboardService;
use App\Domain\Dashboard\WidgetRegistry;
use App\Domain\Dashboard\Widgets\Widget;
use App\Domain\Shared\Support\Messages;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tenant dashboard: the capability-based widget catalog and one endpoint per widget (plus its
 * drill-down), so each widget loads, fails and retries on its own. Every request rebuilds the
 * user's effective permissions, entitled modules and data scope on the server; filter ids outside
 * that scope are refused, never silently widened or ignored.
 */
class DashboardWidgetController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DashboardService $dashboard,
        private readonly WidgetRegistry $registry,
    ) {}

    public function catalog(): JsonResponse
    {
        return $this->ok($this->dashboard->catalog($this->baseContext()));
    }

    public function show(Request $request, string $widget): JsonResponse
    {
        [$context, $instance] = $this->resolve($request, $widget);
        if ($instance instanceof JsonResponse) {
            return $instance;
        }

        return $this->ok($this->dashboard->run($context, $instance, $request->boolean('refresh')));
    }

    public function detail(Request $request, string $widget): JsonResponse
    {
        [$context, $instance] = $this->resolve($request, $widget);
        if ($instance instanceof JsonResponse) {
            return $instance;
        }
        $rules = $instance->detailRules();
        if ($rules === null) {
            return $this->error('dashboard.errors.noDrilldown', 404);
        }
        $params = $request->validate($rules + [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Drill-down ids (vehicle, partner, …) are re-checked inside the widget's own scoped query:
        // an id outside the scope simply matches nothing.
        $result = $this->dashboard->detail($context, $instance, $params);

        return response()->json(['data' => $result]);
    }

    /** @return array{0: DashboardContext|null, 1: Widget|JsonResponse} */
    private function resolve(Request $request, string $widget): array
    {
        if (! $this->registry->has($widget)) {
            return [null, $this->error('dashboard.errors.unknownWidget', 404)];
        }
        $validated = $request->validate([
            'branch_id' => ['nullable', 'uuid'],
            'workshop_id' => ['nullable', 'uuid'],
            'warehouse_id' => ['nullable', 'uuid'],
            'months' => ['nullable', 'integer', Rule::in(DashboardFilters::MONTH_OPTIONS)],
            'refresh' => ['nullable', 'boolean'],
        ]);

        $base = $this->baseContext();
        $instance = $this->registry->get($widget);
        foreach ($instance->modules() as $module) {
            if (! $base->hasModule($module)) {
                return [null, $this->error('dashboard.errors.moduleInactive', 403)];
            }
        }
        if (! $instance->isAvailable($base)) {
            return [null, $this->error('dashboard.errors.widgetForbidden', 403)];
        }

        $options = $this->dashboard->filterOptions($base);
        foreach (['branch_id' => 'branches', 'workshop_id' => 'workshops', 'warehouse_id' => 'warehouses'] as $field => $list) {
            $id = $validated[$field] ?? null;
            if ($id !== null && ! collect($options[$list])->contains('id', $id)) {
                return [null, $this->error('dashboard.errors.filterOutOfScope', 403)];
            }
        }

        $params = array_filter($request->validate($instance->paramRules()), fn ($v) => $v !== null && $v !== '');

        $filters = new DashboardFilters(
            branchId: $validated['branch_id'] ?? null,
            workshopId: $validated['workshop_id'] ?? null,
            warehouseId: $validated['warehouse_id'] ?? null,
            months: (int) ($validated['months'] ?? DashboardFilters::DEFAULT_MONTHS),
            params: $params,
        );

        return [$this->dashboard->context($base->user, $base->tenantId, $filters), $instance];
    }

    private function baseContext(): DashboardContext
    {
        return $this->dashboard->context($this->tenantContext->user(), $this->tenantContext->tenantId());
    }

    private function error(string $code, int $status): JsonResponse
    {
        return response()->json(['message' => Messages::localized($code), 'code' => $code], $status);
    }
}
