<?php

namespace App\Console\Commands;

use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Deployment-readiness audit: a fresh production deployment previously had
 * no secure way to reach platform-level access at all — the only
 * superadmin USER anywhere in the codebase was DemoDataSeeder's hardcoded
 * admin@optifleet.test / "password", which must never run in production.
 * This command is the smallest secure replacement: it never invents or
 * hardcodes a credential, reads them from the environment (for scripted/
 * unattended deploys) or prompts interactively (for a human operator), and
 * assigns the existing "Platform Superadmin" role (seeded by
 * PlatformSuperadminRoleSeeder, part of the production bootstrap chain —
 * must already exist, this command never creates it).
 *
 * Idempotent by email: rerunning against an existing admin email updates
 * that user's role assignment (never silently duplicates) and only
 * changes the password if --password/PLATFORM_ADMIN_PASSWORD was
 * explicitly supplied this run.
 */
class CreatePlatformAdminCommand extends Command
{
    protected $signature = 'platform:create-admin
        {--email= : Admin email (falls back to PLATFORM_ADMIN_EMAIL env, then an interactive prompt)}
        {--password= : Admin password (falls back to PLATFORM_ADMIN_PASSWORD env, then an interactive prompt)}
        {--name=Platform Administrator : Display name for the account}';

    protected $description = 'Provision (or re-assign) the initial platform-level administrator account';

    public function handle(): int
    {
        $role = Role::query()->where('tenant_id', null)->where('name', 'Platform Superadmin')->where('scope', 'platform')->first();
        if (! $role) {
            $this->error('The "Platform Superadmin" role does not exist yet. Run "php artisan db:seed" first (PlatformSuperadminRoleSeeder creates it).');

            return self::FAILURE;
        }

        $email = $this->option('email') ?: env('PLATFORM_ADMIN_EMAIL') ?: $this->ask('Admin email');
        $password = $this->option('password') ?: env('PLATFORM_ADMIN_PASSWORD');
        $passwordSupplied = $password !== null && $password !== '';
        if (! $passwordSupplied) {
            $password = $this->secret('Admin password (min 12 characters)');
        }
        $name = $this->option('name');

        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email'], 'password' => ['required', 'string', 'min:12']]
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $existing = User::query()->where('email', $email)->first();

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            array_filter([
                'name' => $name,
                'user_type' => 'platform',
                'status' => 'active',
                // Only (re)hash a password when one was actually supplied this run — a bare
                // rerun with no --password/env value must never reset an existing password.
                'password' => (! $existing || $passwordSupplied) ? Hash::make($password) : null,
            ], fn ($value) => $value !== null)
        );

        RoleAssignment::query()->firstOrCreate(['user_id' => $user->id, 'tenant_id' => null, 'role_id' => $role->id]);

        $this->info(($existing ? 'Updated' : 'Created')." platform administrator: {$email}");

        return self::SUCCESS;
    }
}
