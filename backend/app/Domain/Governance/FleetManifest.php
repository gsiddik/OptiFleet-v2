<?php

namespace App\Domain\Governance;

use App\Domain\AccessControl\Services\PermissionCatalog;
use Illuminate\Support\Facades\DB;

/**
 * OptiFleet's catalog for OptiNexus, manifest v2 (audit A02; OptiNexus imports it additively: nothing is deleted, nothing becomes
 * delegable on re-import unless an operator allows it). Built from the live tables, so it cannot drift from the code:
 *
 *  - capabilities: one MODULE capability per sellable module (the commercial unit of OptiFleet, `optifleet.<module>`); the four
 *    core modules come with the application and are not capabilities;
 *  - permissions: every tenant-scope permission as "optifleet.<name>", with the capability of its module (null for the core
 *    modules and general permissions) and `delegable`, true only for the names listed in config/governance_delegation.php.
 *
 * `revision` is a content hash, so it changes exactly when the catalog changes.
 */
class FleetManifest
{
    public const VERSION = '2.0';

    public function __construct(private readonly PermissionCatalog $catalog) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $modules = DB::table('modules')->orderBy('category')->orderBy('code')->get(['code', 'name', 'category', 'is_core', 'is_sellable']);
        $sellable = $modules->filter(fn ($m) => ! $m->is_core)->values();

        $capabilities = $sellable->map(fn ($m, int $i) => [
            'type' => 'MODULE',
            'code' => GovernanceCatalog::capabilityCode($m->code),
            'name' => $m->name,
            'description' => $m->category.' module of OptiFleet.',
            'sort_order' => ($i + 1) * 10,
            'parent_code' => null,
        ])->all();
        $capabilityByModule = $sellable->mapWithKeys(fn ($m) => [$m->code => GovernanceCatalog::capabilityCode($m->code)])->all();

        $delegable = array_flip((array) config('governance_delegation.delegable'));

        $permissions = $this->catalog->forScope('tenant')->map(fn (array $p) => [
            'permission_key' => GovernanceCatalog::permissionKey($p['name']),
            'name' => $p['feature_name'].': '.$p['action_name'],
            'description' => $p['description'] ?: null,
            'capability_code' => $capabilityByModule[$p['module']] ?? null,
            'delegable' => isset($delegable[$p['name']]),
        ])->sortBy('permission_key')->values()->all();

        $revision = 'r'.substr(hash('sha256', json_encode([$capabilities, $permissions], JSON_UNESCAPED_SLASHES)), 0, 12);

        return [
            'manifest_version' => self::VERSION,
            'revision' => $revision,
            'application_code' => GovernanceCatalog::application(),
            // Lets `ecosystem:import-manifest --create-application` register the product in OptiNexus (like the OptiEntry manifest).
            'application' => [
                'application_code' => GovernanceCatalog::application(),
                'name' => 'OptiFleet',
                'description' => 'Fleet, workshop and work-order operations.',
                'frontend_url' => (string) config('optinexus.sso.frontend_url'),
            ],
            'capabilities' => $capabilities,
            'permissions' => $permissions,
        ];
    }
}
