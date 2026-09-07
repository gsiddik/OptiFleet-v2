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
