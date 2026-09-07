<?php

namespace Database\Seeders;

use App\Domain\AccessControl\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Permissions that exist only for one scope, and permissions that exist
     * identically named in both (Access Management appears in both portals).
     */
    public function run(): void
    {
        $platformOnly = [
            'tenant' => ['view', 'create', 'update', 'activate', 'deactivate'],
            'module' => ['view', 'manage'],
            'entitlement' => ['view', 'manage'],

            // Phase 2: commercial SaaS (Section 42)
            'bundle' => ['view', 'create', 'update', 'publish'],
            'pricing' => ['view', 'create', 'update', 'publish'],
            'contract' => ['view', 'create', 'update', 'submit', 'approve', 'amend', 'renew', 'terminate'],
            'subscription' => ['view', 'activate', 'suspend', 'reactivate'],
            'billing' => ['view', 'generate', 'adjust'],
            'invoice' => ['view', 'generate', 'issue', 'void'],
            'payment' => ['view', 'verify', 'reject'],
        ];

        $tenantOnly = [
            'branch' => ['view', 'create', 'update', 'activate', 'deactivate'],
            'workshop' => ['view', 'create', 'update', 'activate', 'deactivate'],
            'warehouse' => ['view', 'create', 'update', 'activate', 'deactivate'],
            'vehicle_category' => ['view', 'create', 'update'],
            'component_group' => ['view', 'create', 'update', 'map'],
        ];

        $bothScopes = [
            'user' => ['view', 'create', 'update', 'assign'],
            'role' => ['view', 'create', 'update', 'assign_permission'],
            'audit' => ['view'],
        ];

        foreach ($platformOnly as $group => $actions) {
            $this->seedGroup($group, $actions, 'platform');
        }

        foreach ($tenantOnly as $group => $actions) {
            $this->seedGroup($group, $actions, 'tenant');
        }

        foreach ($bothScopes as $group => $actions) {
            $this->seedGroup($group, $actions, 'platform');
            $this->seedGroup($group, $actions, 'tenant');
        }

        // Tenant billing-portal permissions (Section 42/44) — 3-level dotted
        // names, seeded explicitly rather than through seedGroup's
        // "group.action" convention.
        foreach ([
            'account.subscription.view',
            'account.contract.view',
            'account.invoice.view',
            'account.invoice.download',
            'account.payment.submit',
            'account.payment.view',
        ] as $name) {
            Permission::query()->updateOrCreate(
                ['name' => $name, 'scope' => 'tenant'],
                ['group' => 'account', 'description' => str_replace('.', ' ', $name)]
            );
        }
    }

    private function seedGroup(string $group, array $actions, string $scope): void
    {
        foreach ($actions as $action) {
            Permission::query()->updateOrCreate(
                ['name' => "{$group}.{$action}", 'scope' => $scope],
                ['group' => $group, 'description' => ucfirst($action).' '.str_replace('_', ' ', $group)]
            );
        }
    }
}
