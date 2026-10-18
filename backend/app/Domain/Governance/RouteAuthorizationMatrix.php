<?php

namespace App\Domain\Governance;

use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;

/**
 * Reads the authorization gates of every route straight from the live route table (audit A02/R05), so the documented matrix
 * (docs/integration/AUTHORIZATION_ROUTE_MATRIX.md, written by `php artisan optinexus:route-matrix`) cannot drift from the code.
 *
 * Gate kinds, outermost first: session (Sanctum) -> tenant context (also the central-governance gate: SSO session and access
 * snapshot from OptiNexus) -> scope (tenant / platform) -> module entitlement -> permission -> `not.central` (mutations that
 * would mint local identity or grants in a centrally governed tenant) -> controller/service checks (data scope, ownership,
 * accounting invariants).
 */
class RouteAuthorizationMatrix
{
    /** Tenant routes that decide inside the controller or service, through PermissionService (the central resolver). */
    public const IN_CONTROLLER = [
        'api/v1/app/configuration/sets/{set}/restore-default',
        'api/v1/app/configuration/versions',
        'api/v1/app/configuration/versions/{version}',
        'api/v1/app/configuration/versions/{version}/archive',
        'api/v1/app/configuration/versions/{version}/layout',
        'api/v1/app/configuration/versions/{version}/publish',
        'api/v1/app/dashboard',
        'api/v1/app/dashboard/catalog',
        'api/v1/app/dashboard/widgets/{widget}',
        'api/v1/app/dashboard/widgets/{widget}/details',
        'api/v1/app/workflow/available-transitions',
    ];

    /**
     * @return Collection<int, array{area: string, method: string, uri: string, gate: string, permission: ?string, module: ?string, not_central: bool, action: string}>
     */
    public function rows(): Collection
    {
        $rows = collect();

        foreach (RouteFacade::getRoutes() as $route) {
            /** @var Route $route */
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }

            $middleware = collect(app('router')->gatherRouteMiddleware($route))->filter(fn ($m) => is_string($m))->values();
            $permission = $middleware->first(fn (string $m) => str_contains($m, 'CheckPermission:') || str_starts_with($m, 'permission:'));
            $module = $middleware->first(fn (string $m) => str_contains($m, 'CheckModuleEntitlement:') || str_starts_with($m, 'module:'));
            $notCentral = $middleware->contains(fn (string $m) => str_contains($m, 'RejectWhenCentrallyGoverned') || $m === 'not.central');
            $feed = $middleware->first(fn (string $m) => str_contains($m, 'AuthenticateAccountingFeed'));
            $session = $middleware->contains(fn (string $m) => str_contains($m, 'Authenticate:sanctum'));
            $tenantScope = $middleware->contains(fn (string $m) => str_contains($m, 'EnsureTenantScope'));
            $platformScope = $middleware->contains(fn (string $m) => str_contains($m, 'EnsurePlatformScope'));

            $area = $this->area($uri);
            $permissionName = $permission ? Str::after($permission, ':') : null;

            $gate = match (true) {
                $feed !== null => 'feed credential: '.Str::after($feed, ':').' (tenant bound to the credential)',
                ! $session => 'public (sign-in or signed OptiNexus message)',
                $permissionName !== null && $platformScope => 'platform session + permission',
                $permissionName !== null && $tenantScope => 'tenant session + permission'.($module ? ' + module' : ''),
                $permissionName === null && $tenantScope && in_array($uri, self::IN_CONTROLLER, true) => 'tenant session + check in controller',
                $permissionName === null => 'session only (own account)',
                default => 'session + permission',
            };

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $rows->push([
                    'area' => $area,
                    'method' => $method,
                    'uri' => $uri,
                    'gate' => $gate,
                    'permission' => $permissionName,
                    'module' => $module ? Str::after($module, ':') : null,
                    'not_central' => $notCentral,
                    'action' => class_basename(explode('@', (string) $route->getActionName())[0]),
                ]);
            }
        }

        return $rows->sortBy([['area', 'asc'], ['uri', 'asc'], ['method', 'asc']])->values();
    }

    private function area(string $uri): string
    {
        $parts = explode('/', $uri);

        return match ($parts[2] ?? '') {
            'app' => 'app/'.($parts[3] ?? ''),
            'platform' => 'platform/'.($parts[3] ?? ''),
            default => $parts[2] ?? 'other',
        };
    }

    /** Markdown for docs/integration/AUTHORIZATION_ROUTE_MATRIX.md. */
    public function markdown(): string
    {
        $rows = $this->rows();
        $byGate = $rows->groupBy(fn (array $r) => explode(' (', explode(':', $r['gate'])[0])[0])->map->count()->sortDesc();

        $out = [];
        $out[] = '# Authorization route matrix';
        $out[] = '';
        $out[] = '_Generated by `php artisan optinexus:route-matrix` from the live route table; `tests/Feature/Governance/AuthorizationRouteMatrixTest` fails when this file is stale. Do not edit by hand._';
        $out[] = '';
        $out[] = 'Every `/api/v1/app/*` request passes, in order: Sanctum session, `TenantContextMiddleware` (tenant status; for a centrally governed tenant also the SSO-session check and the OptiNexus access snapshot, which fail closed), `EnsureTenantScope`, the module gate (entitlement; for a centrally governed tenant the capabilities OptiNexus holds), the permission gate (`CheckPermission` -> `PermissionService`; for a centrally governed tenant the OptiNexus grants, narrowed by local roles), then the controller/service checks (data scope, ownership, accounting invariants). Routes marked `not.central` refuse to mint local users or entitlements in a centrally governed tenant (409 `GOVERNED_CENTRALLY`). Exports, downloads and uploads are ordinary routes of this table; background jobs and streams re-check the entitlement through `ChecksCentralEntitlement` / `EntitlementService`.';
        $out[] = '';
        $out[] = sprintf('Routes: %d method+path pairs. By gate: %s.', $rows->count(), $byGate->map(fn (int $n, string $g) => "{$g} {$n}")->implode('; '));
        $out[] = '';
        $out[] = 'Routes with the check inside the controller (`IN_CONTROLLER`): '.count(self::IN_CONTROLLER).' (configuration versions/restore, dashboard, workflow available-transitions); they call `PermissionService` / `EntitlementService`, the same central resolver.';
        $out[] = '';

        foreach ($rows->groupBy('area') as $area => $group) {
            $out[] = "## {$area} ({$group->count()})";
            $out[] = '';
            $out[] = '| Method | Path | Permission | Module | Gate | Notes |';
            $out[] = '|---|---|---|---|---|---|';
            foreach ($group as $r) {
                $notes = array_filter([$r['not_central'] ? '`not.central`' : null, in_array($r['uri'], self::IN_CONTROLLER, true) ? 'in controller' : null]);
                $out[] = sprintf('| %s | `%s` | %s | %s | %s | %s |', $r['method'], Str::after($r['uri'], 'api/v1/'),
                    $r['permission'] ? '`'.$r['permission'].'`' : '-', $r['module'] ? '`'.$r['module'].'`' : '-', $r['gate'], implode(', ', $notes) ?: '-');
            }
            $out[] = '';
        }

        return implode("\n", $out);
    }
}
