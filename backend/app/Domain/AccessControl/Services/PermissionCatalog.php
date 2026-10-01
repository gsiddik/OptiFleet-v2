<?php

namespace App\Domain\AccessControl\Services;

use App\Domain\AccessControl\Models\Permission;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Presents permissions as Module -> Feature -> Action for the Role editor.
 *
 * There is no stored permission-to-module metadata, so the module is derived
 * from the routes themselves: every tenant route that requires a permission
 * sits inside a `module:CODE` entitlement group, and that is exactly the
 * module the permission belongs to. Feature is the permission's `group`
 * (e.g. work_order), Action is the rest of its name (e.g. approve). A
 * permission used on no module-gated route (Access Management, account,
 * platform administration, …) falls back to a readable group-based module.
 */
class PermissionCatalog
{
    /**
     * User-facing names that differ from the stored permission keys. The keys stay unchanged (they
     * are referenced by routes, role assignments and history); only the label shown in role
     * management is clarified. `workshop_invoice` is the third-party Service Invoice recorded on an
     * internal Work Order — not the External Workshop invoice of an External Work Order.
     */
    private const FEATURE_NAMES = ['workshop_invoice' => 'Service Invoice'];

    private const ACTION_NAMES = ['work_order.view_workshop_invoice_reference' => 'View External Workshop Invoice Reference'];

    public function __construct(private readonly Router $router) {}

    /** @return Collection<int, array<string, mixed>> */
    public function forScope(string $scope): Collection
    {
        $moduleByPermission = $this->moduleByPermission($scope);
        $moduleNames = DB::table('modules')->pluck('name', 'code');

        return Permission::query()->where('scope', $scope)->orderBy('group')->orderBy('name')->get()
            ->map(function (Permission $permission) use ($moduleByPermission, $moduleNames) {
                $moduleCode = $moduleByPermission[$permission->name] ?? null;
                $action = str_starts_with($permission->name, $permission->group.'.')
                    ? substr($permission->name, strlen($permission->group) + 1)
                    : $permission->name;

                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'group' => $permission->group,
                    'scope' => $permission->scope,
                    'description' => $permission->description,
                    'module' => $moduleCode ?? 'GENERAL',
                    'module_name' => $moduleCode ? ($moduleNames[$moduleCode] ?? $moduleCode) : $this->fallbackModule($permission->group),
                    'feature' => $permission->group,
                    'feature_name' => self::FEATURE_NAMES[$permission->group] ?? ucwords(str_replace(['_', '.'], ' ', $permission->group)),
                    'action' => $action,
                    'action_name' => self::ACTION_NAMES[$permission->name] ?? ucwords(str_replace(['_', '.'], ' ', $action)),
                ];
            })
            ->values();
    }

    /** @return array<string, string> permission name => module code (first module-gated route wins) */
    private function moduleByPermission(string $scope): array
    {
        $prefix = $scope === 'platform' ? 'api/v1/platform/' : 'api/v1/app/';
        $map = [];
        foreach ($this->router->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), $prefix)) {
                continue;
            }
            $middleware = array_filter($route->gatherMiddleware(), 'is_string');
            $module = null;
            $permissions = [];
            foreach ($middleware as $m) {
                if (str_starts_with($m, 'module:')) {
                    $module = substr($m, 7);
                } elseif (str_starts_with($m, 'permission:')) {
                    $permissions = array_merge($permissions, explode('|', substr($m, 11)));
                }
            }
            if ($module === null) {
                continue;
            }
            foreach ($permissions as $permission) {
                $map[$permission] ??= $module;
            }
        }

        return $map;
    }

    private function fallbackModule(string $group): string
    {
        return match (true) {
            in_array($group, ['user', 'role', 'audit'], true) => 'Access Management',
            $group === 'account' => 'Account & Subscription',
            in_array($group, ['configuration', 'numbering', 'document_template', 'workflow', 'notification_rule', 'configuration_history', 'tire_scoring_configuration'], true) => 'Configuration',
            in_array($group, ['tenant', 'module', 'entitlement', 'bundle', 'pricing', 'contract', 'subscription', 'billing', 'invoice', 'payment'], true) => 'Commercial',
            in_array($group, ['analytics', 'analytics_etl'], true) => 'Analytics',
            in_array($group, ['intelligence', 'intelligence_admin'], true) => 'Maintenance Intelligence',
            in_array($group, ['product_category', 'component_group', 'component_category', 'component_subcategory'], true) => 'Master Data',
            default => self::FEATURE_NAMES[$group] ?? ucwords(str_replace('_', ' ', $group)),
        };
    }
}
