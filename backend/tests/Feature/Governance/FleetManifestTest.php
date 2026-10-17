<?php

namespace Tests\Feature\Governance;

use App\Domain\AccessControl\Services\PermissionCatalog;
use App\Domain\Governance\FleetManifest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The manifest OptiFleet exports to OptiNexus (manifest v2, audit A02/R08) obeys the importer's own rules, which are copied
 * here from OptiNexus' ImportManifestRequest / ManifestImportService (no cross-repository dependency): version, key syntax
 * and namespace, unknown capability references, duplicates, and the explicit `delegable` flag.
 */
class FleetManifestTest extends TestCase
{
    private function manifest(): array
    {
        return app(FleetManifest::class)->build();
    }

    public function test_it_satisfies_the_nexus_import_request_rules(): void
    {
        $manifest = $this->manifest();

        $validator = Validator::make($manifest, [
            'manifest_version' => ['required', 'string', 'in:2.0'],
            'revision' => ['required', 'string', 'max:100'],
            'capabilities' => ['sometimes', 'array', 'max:500'],
            'capabilities.*.type' => ['required', 'in:APPLICATION,MODULE,FEATURE,ACTION,LIMIT'],
            'capabilities.*.code' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'capabilities.*.name' => ['required', 'string', 'max:255'],
            'capabilities.*.description' => ['nullable', 'string', 'max:1000'],
            'capabilities.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'capabilities.*.parent_code' => ['nullable', 'string', 'max:100'],
            'permissions' => ['sometimes', 'array', 'max:2000'],
            'permissions.*.permission_key' => ['required', 'string', 'max:150', 'regex:/^[a-z][a-z0-9_-]*(\.[A-Za-z0-9_-]+)+$/'],
            'permissions.*.name' => ['required', 'string', 'max:255'],
            'permissions.*.description' => ['nullable', 'string', 'max:1000'],
            'permissions.*.capability_code' => ['nullable', 'string', 'max:100'],
            'permissions.*.delegable' => ['sometimes', 'boolean'],
        ]);

        $this->assertSame([], $validator->errors()->all());
        $this->assertSame('2.0', $manifest['manifest_version']);
        $this->assertSame('optifleet', $manifest['application_code']);
    }

    public function test_every_key_is_in_the_application_namespace_and_unique(): void
    {
        $manifest = $this->manifest();
        $keys = array_column($manifest['permissions'], 'permission_key');

        $this->assertSame(count($keys), count(array_unique($keys)), 'Duplicate permission keys.');
        foreach ($keys as $key) {
            $this->assertStringStartsWith('optifleet.', $key);
            $this->assertStringStartsNotWith('cgo.', $key);
        }

        $codes = array_column($manifest['capabilities'], 'code');
        $this->assertSame(count($codes), count(array_unique($codes)));
    }

    public function test_every_permission_of_the_tenant_catalog_is_listed_exactly_once(): void
    {
        $catalog = app(PermissionCatalog::class)->forScope('tenant')->pluck('name')->sort()->values()->all();
        $listed = collect($this->manifest()['permissions'])->map(fn ($p) => substr($p['permission_key'], strlen('optifleet.')))->sort()->values()->all();

        $this->assertSame($catalog, $listed);
        $this->assertGreaterThan(200, count($listed));
    }

    public function test_capability_references_resolve_and_core_modules_are_not_capabilities(): void
    {
        $manifest = $this->manifest();
        $codes = array_column($manifest['capabilities'], 'code');

        foreach ($manifest['capabilities'] as $capability) {
            $this->assertSame('MODULE', $capability['type']);
            $this->assertNull($capability['parent_code']);
        }
        foreach ($manifest['permissions'] as $permission) {
            if ($permission['capability_code'] !== null) {
                $this->assertContains($permission['capability_code'], $codes, "{$permission['permission_key']} names an unknown capability");
            }
        }

        // The core modules (identity, organization, access control, general) come with the application.
        $core = DB::table('modules')->where('is_core', true)->pluck('code')->map(fn ($c) => 'optifleet.'.strtolower($c))->all();
        $this->assertSame([], array_values(array_intersect($core, $codes)));
    }

    public function test_delegable_is_explicit_default_false_and_matches_the_reviewed_list(): void
    {
        $manifest = $this->manifest();
        $reviewed = (array) config('governance_delegation.delegable');

        foreach ($manifest['permissions'] as $permission) {
            $this->assertIsBool($permission['delegable'], 'delegable must be an explicit boolean for '.$permission['permission_key']);
        }

        $delegable = collect($manifest['permissions'])->where('delegable', true)->map(fn ($p) => substr($p['permission_key'], strlen('optifleet.')))->sort()->values()->all();
        $this->assertSame(collect($reviewed)->sort()->values()->all(), $delegable);

        // Every reviewed name still exists in the catalog (a stale entry would silently stop delegating).
        $catalog = app(PermissionCatalog::class)->forScope('tenant')->pluck('name')->all();
        $this->assertSame([], array_values(array_diff($reviewed, $catalog)));

        // Permissions that can move tenant ownership, platform rights or the governance itself are never delegable.
        foreach ($manifest['permissions'] as $permission) {
            if (preg_match('/^optifleet\.(access\.|identity\.|tenant\.|sso\.|integration\.)/', $permission['permission_key'])) {
                $this->assertFalse($permission['delegable'], $permission['permission_key'].' must not be delegable');
            }
        }
    }

    public function test_the_revision_is_deterministic_and_follows_the_content(): void
    {
        $a = $this->manifest();
        $b = $this->manifest();
        $this->assertSame($a['revision'], $b['revision']);
        $this->assertMatchesRegularExpression('/^r[0-9a-f]{12}$/', $a['revision']);

        config(['governance_delegation.delegable' => array_slice((array) config('governance_delegation.delegable'), 1)]);
        $this->assertNotSame($a['revision'], $this->manifest()['revision']);
    }

    public function test_the_export_command_prints_the_manifest_and_a_summary(): void
    {
        $this->artisan('optinexus:manifest --summary')->expectsOutputToContain('manifest 2.0')->assertSuccessful();

        $path = sys_get_temp_dir().'/fleet-manifest-'.uniqid().'.json';
        $this->artisan('optinexus:manifest', ['--output' => $path])->assertSuccessful();
        $written = json_decode((string) file_get_contents($path), true);
        @unlink($path);

        $this->assertSame($this->manifest()['revision'], $written['revision']);
        $this->assertArrayHasKey('permissions', $written);
    }
}
