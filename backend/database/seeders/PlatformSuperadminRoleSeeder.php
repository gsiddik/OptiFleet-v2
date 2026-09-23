<?php

namespace Database\Seeders;

use App\Domain\AccessControl\Models\Permission;
use App\Domain\AccessControl\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Deployment-readiness audit: "Platform Superadmin" was previously only
 * created inside DemoDataSeeder, bundled together with a hardcoded demo
 * admin USER (admin@optifleet.test / "password") — meaning a production
 * deployment either skipped DemoDataSeeder and had NO way to reach
 * platform-level access at all, or ran it and shipped a publicly-known
 * credential. This seeder extracts only the ROLE (system-owned platform
 * access definition, safe in every environment) from that entanglement.
 * The actual admin USER must be provisioned separately and securely — see
 * `php artisan platform:create-admin` (App\Console\Commands\
 * CreatePlatformAdminCommand). DemoDataSeeder's own admin user creation is
 * untouched and remains a dev/demo-only convenience, never part of this
 * production-safe seeder chain.
 */
class PlatformSuperadminRoleSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::query()->updateOrCreate(
            ['tenant_id' => null, 'name' => 'Platform Superadmin', 'scope' => 'platform'],
            ['is_system' => true, 'description' => 'Full platform access.']
        );
        $role->permissions()->sync(Permission::query()->where('scope', 'platform')->pluck('id'));
    }
}
