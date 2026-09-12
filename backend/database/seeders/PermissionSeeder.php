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

            // Phase 3: Core VMS Operations (Section 47)
            'vehicle' => ['view', 'create', 'update', 'assign', 'transfer', 'status.update'],
            'inspection' => ['view', 'create', 'perform', 'submit', 'review'],
            'maintenance_policy' => ['view', 'manage'],
            'maintenance_schedule' => ['view', 'manage'],
            'maintenance_request' => ['view', 'create', 'review', 'approve', 'reject', 'convert_work_order'],
            'breakdown' => ['view', 'report', 'review', 'resolve'],
            'work_order' => ['view', 'create', 'update', 'submit', 'approve', 'reject', 'assign', 'schedule', 'start', 'pause', 'complete', 'cancel', 'close'],
            'diagnosis' => ['manage'],
            'maintenance_job' => ['manage'],
            'worker' => ['view', 'manage', 'assign'],
            'workspace' => ['view', 'manage', 'reserve', 'block'],
            'qc' => ['view', 'perform', 'approve', 'reject'],
            'vehicle_release' => ['perform'],
            'maintenance_history' => ['view'],

            // Phase 4: Supply Chain & Asset Lifecycle (Section 46)
            'product' => ['view', 'create', 'update'],
            'inventory' => ['view', 'reserve', 'issue', 'return', 'adjust', 'stock_opname', 'scrap'],
            'sparepart_sale' => ['view', 'create', 'approve'],
            'stock_transfer' => ['view', 'create', 'approve', 'dispatch', 'receive'],
            'purchase_request' => ['view', 'create', 'submit', 'approve'],
            'rfq' => ['view', 'manage'],
            'quotation' => ['view', 'manage', 'select'],
            'purchase_order' => ['view', 'create', 'approve', 'issue'],
            'goods_receipt' => ['view', 'create', 'post'],
            'partner' => ['view', 'manage'],
            'tire' => ['view', 'manage', 'install', 'rotate', 'inspect', 'remove', 'scrap'],
            'used_part' => ['view', 'inspect', 'dispose', 'approve'],
            'component_asset' => ['view', 'manage', 'install', 'remove', 'replace'],
            'warranty' => ['view', 'manage'],
            'warranty_claim' => ['create', 'review', 'approve'],

            // Phase 5: Tenant Configuration & Business Rules (Section 37)
            'configuration' => ['view'],
            'numbering' => ['manage', 'publish'],
            'document_template' => ['manage', 'publish'],
            'workflow' => ['manage', 'publish', 'simulate'],
            'notification_rule' => ['manage'],
            'configuration_history' => ['view'],
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

        // Phase 6: Analytics & Data Warehouse (Section 49) — 3-level
        // dotted names, one per dashboard section, tenant-scoped.
        foreach ([
            'analytics.overview.view',
            'analytics.fleet.view',
            'analytics.maintenance.view',
            'analytics.work_order.view',
            'analytics.breakdown.view',
            'analytics.workshop.view',
            'analytics.mechanic.view',
            'analytics.inventory.view',
            'analytics.procurement.view',
            'analytics.vendor.view',
            'analytics.cost.view',
            'analytics.tire.view',
            'analytics.component.view',
            'analytics.warranty.view',
            'analytics.export',
        ] as $name) {
            Permission::query()->updateOrCreate(
                ['name' => $name, 'scope' => 'tenant'],
                ['group' => 'analytics', 'description' => str_replace('.', ' ', $name)]
            );
        }

        // Phase 6 Section 49: ETL operational controls are platform-scope
        // — running/retrying/backfilling analytics is an infrastructure
        // operation, not a tenant business action.
        foreach ([
            'analytics.etl.view',
            'analytics.etl.run',
            'analytics.etl.retry',
            'analytics.etl.backfill',
        ] as $name) {
            Permission::query()->updateOrCreate(
                ['name' => $name, 'scope' => 'platform'],
                ['group' => 'analytics_etl', 'description' => str_replace('.', ' ', $name)]
            );
        }

        // Phase 7: Maintenance Intelligence (Section 58) — dashboard/
        // detail read access plus recommendation review are tenant-scope
        // business actions.
        foreach ([
            'intelligence.overview.view',
            'intelligence.vehicle.view',
            'intelligence.component.view',
            'intelligence.tire.view',
            'intelligence.inventory.view',
            'intelligence.recommendation.view',
            'intelligence.recommendation.review',
            'intelligence.recommendation.accept',
            'intelligence.recommendation.reject',
            'intelligence.recommendation.convert',
        ] as $name) {
            Permission::query()->updateOrCreate(
                ['name' => $name, 'scope' => 'tenant'],
                ['group' => 'intelligence', 'description' => str_replace('.', ' ', $name)]
            );
        }

        // Phase 7 Section 58: model administration/training/prediction
        // control is platform-scope — same rationale as analytics.etl.*.
        foreach ([
            'intelligence.model.view',
            'intelligence.model.train',
            'intelligence.model.evaluate',
            'intelligence.model.activate',
            'intelligence.model.retire',
            'intelligence.prediction.run',
            'intelligence.monitoring.view',
        ] as $name) {
            Permission::query()->updateOrCreate(
                ['name' => $name, 'scope' => 'platform'],
                ['group' => 'intelligence_admin', 'description' => str_replace('.', ' ', $name)]
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
