<?php

namespace Tests\Feature\Governance;

use App\Domain\AccessControl\Models\Permission;
use App\Domain\Governance\RouteAuthorizationMatrix;
use App\Http\Middleware\AuthenticateAccountingFeed;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithCentralGovernance;
use Tests\TestCase;

/**
 * The authorization route matrix of OptiFleet (audit A02/R05), checked against the real route table instead of described:
 *  1. static: every tenant route carries a permission gate, or is on the short list of routes that check inside the controller
 *     (through PermissionService, which is the central resolver for centrally governed tenants);
 *  2. every module gate names a real module;
 *  3. dynamic, on every tenant route: a person OptiNexus lets in with NO grants never gets through (no 2xx, no validation
 *     response), and a person with EVERY grant in a tenant that holds no sellable module never reaches a sellable module's route.
 * docs/integration/AUTHORIZATION_ROUTE_MATRIX.md is generated from the same table by `php artisan optinexus:route-matrix`.
 */
class AuthorizationRouteMatrixTest extends TestCase
{
    use InteractsWithCentralGovernance;

    /** Routes that decide inside the controller or service (PermissionService / EntitlementService), not in route middleware. */
    private const IN_CONTROLLER = RouteAuthorizationMatrix::IN_CONTROLLER;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCentralGovernance();
        // The sweep sends hundreds of requests as one person: the per-user rate limit is not what is being checked here.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /** @return array<int, array{route: Route, uri: string, method: string, permission: ?string, module: ?string}> */
    private function tenantRoutes(): array
    {
        $out = [];
        foreach (RouteFacade::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/app/')) {
                continue;
            }
            $middleware = array_filter($route->gatherMiddleware(), 'is_string');
            $permission = collect($middleware)->first(fn ($m) => str_contains($m, 'CheckPermission:') || str_starts_with($m, 'permission:'));
            $module = collect($middleware)->first(fn ($m) => str_contains($m, 'CheckModuleEntitlement:') || str_starts_with($m, 'module:'));
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $out[] = [
                    'route' => $route, 'uri' => $route->uri(), 'method' => $method,
                    'permission' => $permission ? Str::after($permission, ':') : null,
                    'module' => $module ? Str::after($module, ':') : null,
                ];
            }
        }

        return $out;
    }

    public function test_every_tenant_route_has_a_permission_gate_or_is_on_the_in_controller_list(): void
    {
        $ungated = collect($this->tenantRoutes())->filter(fn ($r) => $r['permission'] === null)->pluck('uri')->unique()->values()->all();

        $this->assertEqualsCanonicalizing(self::IN_CONTROLLER, $ungated, 'A tenant route without a permission gate must be on IN_CONTROLLER (and check in the controller), and nothing else may be.');
    }

    public function test_every_permission_a_route_names_exists_in_the_catalog(): void
    {
        $known = Permission::query()->where('scope', 'tenant')->pluck('name')->all();
        $unknown = collect($this->tenantRoutes())->pluck('permission')->filter()->unique()->diff($known)->values()->all();

        $this->assertSame([], $unknown, 'Routes name permissions that do not exist, so nobody could ever be granted them: '.implode(', ', $unknown));
    }

    public function test_every_module_gate_names_a_real_module(): void
    {
        $modules = DB::table('modules')->pluck('code')->all();
        $unknown = collect($this->tenantRoutes())->pluck('module')->filter()->unique()->diff($modules)->values()->all();

        $this->assertSame([], $unknown);
    }

    private function url(string $uri): string
    {
        return '/'.preg_replace('/\{[^}]+\??\}/', (string) Str::uuid(), $uri);
    }

    public function test_a_person_with_no_grants_gets_through_no_tenant_route(): void
    {
        $tenant = $this->makeCentralTenant([]);
        [, $token] = $this->makeCentralUser($tenant, []);
        $failures = [];
        $checked = 0;
        $denied = 0;

        foreach ($this->tenantRoutes() as $r) {
            $res = $this->json($r['method'], $this->url($r['uri']), [], $this->authHeaders($token));
            $checked++;
            $denied += $res->status() === 403 ? 1 : 0;
            $inController = in_array($r['uri'], self::IN_CONTROLLER, true);
            $bad = $res->status() >= 500
                || ($res->status() >= 200 && $res->status() < 300 && ! ($inController && str_starts_with($r['uri'], 'api/v1/app/dashboard')))
                || ($res->status() === 422 && ! $inController);
            if ($bad) {
                $failures[] = "{$r['method']} {$r['uri']} -> {$res->status()}";
            }
        }

        $this->assertGreaterThan(600, $checked);
        $this->assertGreaterThan(150, $denied, 'most routes must answer 403 (the rest 404: the random record does not exist)');
        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_every_grant_without_the_module_reaches_no_sellable_module_route(): void
    {
        $tenant = $this->makeCentralTenant([]); // no sellable module is entitled
        [, $token] = $this->makeCentralUser($tenant, Permission::query()->where('scope', 'tenant')->pluck('name')->all());
        $sellable = DB::table('modules')->where('is_core', false)->pluck('code')->all();
        $failures = [];
        $strict = 0;

        foreach ($this->tenantRoutes() as $r) {
            if (! in_array($r['module'], $sellable, true)) {
                continue;
            }
            $res = $this->json($r['method'], $this->url($r['uri']), [], $this->authHeaders($token));
            $hasBinding = str_contains($r['uri'], '{');
            // With route parameters the (random) record is looked up first and is not found; without, the module gate answers.
            $ok = $hasBinding ? in_array($res->status(), [403, 404], true) : ($res->status() === 403 && str_contains((string) $res->json('message'), 'Module not entitled'));
            $strict += $hasBinding ? 0 : 1;
            if (! $ok) {
                $failures[] = "{$r['method']} {$r['uri']} ({$r['module']}) -> {$res->status()}";
            }
        }

        $this->assertGreaterThan(50, $strict);
        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_every_exported_or_uploaded_resource_is_behind_the_same_gates(): void
    {
        // Exports, downloads and uploads are ordinary authenticated routes: none is public, and each names a permission.
        $sensitive = collect($this->tenantRoutes())->filter(fn ($r) => preg_match('#(export|download|upload|import|pdf|proof|photo|document|attachment|logo|print)#i', $r['uri']) === 1);
        $this->assertGreaterThan(20, $sensitive->count());
        $this->assertSame([], $sensitive->filter(fn ($r) => $r['permission'] === null)->pluck('uri')->unique()->values()->all());

        $public = collect(RouteFacade::getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/') && ! in_array('auth:sanctum', $route->gatherMiddleware(), true)
            && ! collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_contains($m, 'Authenticate')))
            ->map(fn ($route) => implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri())->values()->all();
        sort($public);

        $this->assertSame([
            'GET api/v1/auth/sso/callback', 'GET api/v1/auth/sso/redirect', 'GET api/v1/auth/sso/status',
            'POST api/v1/auth/login', 'POST api/v1/auth/sso/backchannel-logout', 'POST api/v1/auth/sso/exchange',
        ], $public, 'The only routes without a session are sign-in and the signed back-channel logout (the accounting feed has its own credential).');

        $feed = RouteFacade::getRoutes()->match(Request::create('/api/v1/integration/accounting-feed', 'GET'));
        $this->assertContains(AuthenticateAccountingFeed::class.':accounting.feed.read', $feed->gatherMiddleware());
    }

    public function test_the_documented_matrix_is_current(): void
    {
        $this->artisan('optinexus:route-matrix', ['--check' => true])->assertSuccessful();
    }
}
