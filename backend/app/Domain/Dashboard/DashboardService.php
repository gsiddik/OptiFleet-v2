<?php

namespace App\Domain\Dashboard;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\AccessControl\Services\PermissionService;
use App\Domain\Dashboard\Widgets\Widget;
use App\Domain\Entitlement\Services\EntitlementService;
use App\Domain\Identity\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class DashboardService
{
    public function __construct(
        private readonly WidgetRegistry $registry,
        private readonly PermissionService $permissions,
        private readonly EntitlementService $entitlements,
        private readonly DataScopeService $scope,
    ) {}

    public function context(User $user, string $tenantId, DashboardFilters $filters = new DashboardFilters): DashboardContext
    {
        $timezone = (string) (Tenant::query()->whereKey($tenantId)->value('timezone') ?: config('app.timezone', 'UTC'));
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $timezone = 'UTC';
        }

        return new DashboardContext(
            tenantId: $tenantId,
            user: $user,
            timezone: $timezone,
            permissions: $this->permissions->permissionsFor($user, $tenantId)->values()->all(),
            modules: $this->entitlements->activeModuleCodes($tenantId)->values()->all(),
            branchIds: $this->scope->allowedBranchIds($user, $tenantId),
            workshopIds: $this->scope->allowedWorkshopIds($user, $tenantId),
            warehouseIds: $this->scope->allowedWarehouseIds($user, $tenantId),
            filters: $filters,
            now: CarbonImmutable::now(),
        );
    }

    /**
     * Filter choices inside the user's scope. They can only narrow what the scope already allows:
     * branches = the scoped branches plus the branches of the scoped workshops/warehouses.
     *
     * @return array{branches: list<array{id: string, name: string, code: string|null}>, workshops: list<array>, warehouses: list<array>}
     */
    public function filterOptions(DashboardContext $context): array
    {
        $pick = fn (string $table, ?array $ids) => DB::table($table)
            ->where('tenant_id', $context->tenantId)
            ->whereNull('deleted_at')
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids === [] ? ['00000000-0000-0000-0000-000000000000'] : $ids))
            ->orderBy('name')
            ->get(['id', 'name', 'code', ...($table === 'branches' ? [] : ['branch_id'])]);

        $workshops = $pick('workshops', $context->workshopIds);
        $warehouses = $pick('warehouses', $context->warehouseIds);
        $branchIds = $context->branchIds === null ? null : collect($context->branchIds)
            ->merge($workshops->pluck('branch_id'))->merge($warehouses->pluck('branch_id'))
            ->filter()->unique()->values()->all();
        $branches = $pick('branches', $branchIds);

        $row = fn ($r) => ['id' => $r->id, 'name' => $r->name, 'code' => $r->code ?? null];

        return [
            'branches' => $branches->map($row)->values()->all(),
            'workshops' => $workshops->map($row)->values()->all(),
            'warehouses' => $warehouses->map($row)->values()->all(),
        ];
    }

    public function catalog(DashboardContext $context): array
    {
        $available = [];
        foreach ($this->registry->ids() as $id) {
            $widget = $this->registry->get($id);
            if ($widget->isAvailable($context)) {
                $available[$id] = [
                    'id' => $id,
                    'kind' => $widget->kind(),
                    'unit' => $widget->unit(),
                    'filters' => $widget->filters(),
                    'drilldown' => $widget->detailRules() !== null,
                ];
            }
        }

        $presets = [];
        foreach ($this->registry->presetsFor($context) as $preset => $ids) {
            $ids = array_values(array_filter($ids, fn (string $id) => isset($available[$id])));
            if ($ids !== []) {
                $presets[] = ['id' => $preset, 'widgets' => $ids];
            }
        }

        return [
            'presets' => $presets,
            'widgets' => array_values($available),
            'filters' => [
                'options' => $this->filterOptions($context),
                'month_options' => DashboardFilters::MONTH_OPTIONS,
                'default_months' => DashboardFilters::DEFAULT_MONTHS,
            ],
            'timezone' => $context->timezone,
            'currency' => self::baseCurrency(),
            'today' => $context->todayDate(),
            'scope' => [
                'tenant_wide' => $context->branchIds === null,
            ],
        ];
    }

    public static function baseCurrency(): string
    {
        return (string) config('dashboard.base_currency', 'IDR');
    }

    /** Widget payload, cached per tenant + effective access + filters + metric version + day. */
    public function run(DashboardContext $context, Widget $widget, bool $refresh = false): array
    {
        $key = 'dashboard:widget:'.sha1(json_encode([
            $widget->id(), $widget->version(), $context->accessSignature(), $this->appliedFilters($context, $widget), $context->todayDate(),
        ]));

        if ($refresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, $widget->ttl(), fn () => $this->envelope($context, $widget, $widget->compute($context)));
    }

    public function detail(DashboardContext $context, Widget $widget, array $params): array
    {
        $result = $widget->detail($context, $params);

        return $this->envelope($context, $widget, ['data' => $result['items'], 'limitations' => $result['limitations'] ?? [], 'basis' => $result['basis'] ?? null]) + ['meta' => $result['meta'] ?? null];
    }

    private function envelope(DashboardContext $context, Widget $widget, array $computed): array
    {
        $period = $widget->kind() === Widget::KIND_PERIOD ? [
            'months' => $context->months(),
            'current_month' => $context->currentMonth(),
            'from' => $context->periodStartDate(),
            'to_exclusive' => $context->periodEndDateExclusive(),
        ] : null;

        return [
            'id' => $widget->id(),
            'kind' => $widget->kind(),
            'unit' => $widget->unit(),
            'currency' => self::baseCurrency(),
            'timezone' => $context->timezone,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'as_of_date' => $context->todayDate(),
            'period' => $period,
            'filters' => $this->appliedFilters($context, $widget),
            'limitations' => array_values($computed['limitations'] ?? []),
            'basis' => $computed['basis'] ?? null,
            'data' => $computed['data'],
        ];
    }

    private function appliedFilters(DashboardContext $context, Widget $widget): array
    {
        $applied = [];
        $declared = $widget->filters();
        $f = $context->filters;
        if (in_array('branch', $declared, true)) {
            $applied['branch_id'] = $f->branchId;
        }
        if (in_array('workshop', $declared, true)) {
            $applied['workshop_id'] = $f->workshopId;
        }
        if (in_array('warehouse', $declared, true)) {
            $applied['warehouse_id'] = $f->warehouseId;
        }
        if (in_array('period', $declared, true)) {
            $applied['months'] = $f->months;
        }
        if ($f->params !== []) {
            $params = $f->params;
            ksort($params);
            $applied['params'] = $params;
        }

        return $applied;
    }
}
