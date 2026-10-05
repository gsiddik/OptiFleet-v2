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
            // 'activate'/'deactivate'/'delete' added for Perbaikan OptiFleet
            // Section 7/8 (bundle & pricing lifecycle + soft delete).
            'bundle' => ['view', 'create', 'update', 'publish', 'activate', 'deactivate', 'delete'],
            'pricing' => ['view', 'create', 'update', 'publish', 'activate', 'deactivate', 'delete'],
            'contract' => ['view', 'create', 'update', 'submit', 'approve', 'amend', 'renew', 'terminate'],
            'subscription' => ['view', 'activate', 'suspend', 'reactivate'],
            'billing' => ['view', 'generate', 'adjust'],
            'invoice' => ['view', 'generate', 'issue', 'void'],
            'payment' => ['view', 'verify', 'reject'],

            // "Next Improvement Tenant Portal - Products": Product Categories
            // move from tenant-governed to Superadmin-only management.
            'product_category' => ['view', 'create', 'update', 'delete'],

            // Component Group Master: platform management of the shared
            // baseline (tenant_id NULL). Same names as the tenant set below,
            // distinct rows by scope (like user/role/audit).
            'component_group' => ['view', 'create', 'update', 'delete'],
            // Component Classification Master (platform baseline taxonomy).
            'component_category' => ['view', 'create', 'update', 'delete'],
            'component_subcategory' => ['view', 'create', 'update', 'delete'],
        ];

        $tenantOnly = [
            'branch' => ['view', 'create', 'update', 'activate', 'deactivate'],
            'workshop' => ['view', 'create', 'update', 'activate', 'deactivate'],
            'warehouse' => ['view', 'create', 'update', 'activate', 'deactivate'],
            'vehicle_category' => ['view', 'create', 'update'],
            'vehicle_brand' => ['view', 'create', 'update'],
            'company' => ['view', 'update'],
            // 'delete' split out of 'update' for the Component Group Master
            // improvement (granted to every role holding 'update' by migration
            // 2026_09_29_000004, so no existing user loses the ability).
            'component_group' => ['view', 'create', 'update', 'delete', 'map'],
            // Component Classification Master (Category / Subcategory); granted by
            // migration 2026_09_29_000007 to every role holding the matching
            // component_group action.
            'component_category' => ['view', 'create', 'update', 'delete'],
            'component_subcategory' => ['view', 'create', 'update', 'delete'],

            // Phase 3: Core VMS Operations (Section 47)
            'vehicle' => ['view', 'create', 'update', 'assign', 'transfer', 'status.update'],
            'inspection' => ['view', 'create', 'perform', 'submit', 'review'],
            'maintenance_policy' => ['view', 'manage'],
            // 'create'/'convert_maintenance_request' added for Enhancement
            // Section 12 (Planning and Schedule: manual schedule creation and
            // schedule -> Maintenance Request conversion).
            'maintenance_schedule' => ['view', 'manage', 'create', 'convert_work_order', 'convert_maintenance_request'],
            'maintenance_request' => ['view', 'create', 'review', 'approve', 'reject', 'convert_work_order'],
            'breakdown' => ['view', 'report', 'review', 'resolve'],
            'work_order' => [
                'view', 'create', 'update', 'submit', 'approve', 'reject', 'assign', 'schedule', 'start', 'pause', 'complete', 'cancel', 'close', 'estimate',
                // Consolidated External Workshop business rules: kept separate from the generic
                // actions above so an External Work Order's Findings-only flow (a distinct action
                // set — prepare/finalize/revise/cancel) can never be granted via the internal ones.
                'prepare_external', 'finalize_external', 'revise_external', 'cancel_external', 'view_workshop_invoice_reference',
            ],
            // "Perbaikan Tenant Portal - Work Order Status External dan Workshop Invoice": the
            // External Work Order Invoice lifecycle (New External WO -> ... -> Paid) has its own
            // permission group, deliberately separate from 'workshop_invoice' (the pre-existing,
            // unrelated R1 externally-issued-invoice-recording feature for towing/3rd-party memos).
            'external_work_order_invoice' => [
                'view', 'generate_authorization', 'deliver', 'acknowledge', 'complete', 'settle', 'cancel',
            ],
            'diagnosis' => ['manage'],
            'maintenance_job' => ['manage'],
            // Phase 5: Request Parts — deliberately its own maker-checker permission set
            // (mechanic 'create'/'cancel' vs warehouse/supervisor 'approve'/'reject'), separate
            // from 'maintenance_job.manage' which gates Planned Parts (same pattern as
            // stock_transfer/workshop_invoice above).
            'part_request' => ['view', 'create', 'approve', 'reject', 'cancel', 'issue'],
            // Returned (not used) new parts: the Return list and Returned Parts Processing.
            'part_return' => ['view', 'process'],
            'work_order_external_service' => ['create', 'complete', 'cancel'],
            // R1 (Workshop Invoice and Settlement): "record" — never "create" or "issue" —
            // OptiFleet only records an externally-issued document. Correction/cancellation
            // request+verify are deliberately separate permissions so maker-checker can be
            // enforced by RBAC (same pattern as tire_retread/tire_repair above).
            'workshop_invoice' => [
                'record', 'view', 'upload_payment',
                'request_correction', 'request_cancellation',
                'verify_correction', 'verify_cancellation',
                'view_settlement_history',
            ],
            'worker' => ['view', 'manage', 'assign'],
            'workspace' => ['view', 'manage', 'reserve', 'approve', 'block'],
            'qc' => ['view', 'perform', 'approve', 'reject'],
            'vehicle_release' => ['perform'],
            'maintenance_history' => ['view'],

            // Phase 4: Supply Chain & Asset Lifecycle (Section 46)
            'product' => ['view', 'create', 'update', 'delete'],
            'inventory' => ['view', 'issue', 'return', 'adjust', 'stock_opname', 'scrap'],
            'sparepart_sale' => ['view', 'create', 'approve'],
            'stock_transfer' => ['view', 'create', 'approve', 'dispatch', 'receive'],
            'purchase_request' => ['view', 'create', 'submit', 'approve'],
            'rfq' => ['view', 'manage'],
            'quotation' => ['view', 'manage', 'select'],
            'purchase_order' => ['view', 'create', 'approve', 'issue'],
            'goods_receipt' => ['view', 'post'],
            'purchase_return' => ['create', 'decide', 'receive_redelivery'],
            'vendor_invoice' => ['view', 'pay'],
            'partner' => ['view', 'manage'],
            'tire' => ['view', 'manage', 'install', 'rotate', 'inspect', 'remove', 'scrap', 'sell'],
            // Vehicle → Wheel Configuration assignment (configuration masters themselves use tire.view/tire.manage).
            'wheel_configuration' => ['map_vehicle'],
            'rim' => ['view', 'manage'],
            // Phase E (G-32): send/receive/inspect/approve are separate permissions per
            // cycle type, so a maker-checker separation can actually be enforced by RBAC.
            'tire_retread' => ['send', 'receive', 'inspect', 'approve'],
            'tire_repair' => ['send', 'receive', 'inspect', 'approve'],
            // Tire Scoring (tire_scoring.*, tire_scoring_configuration.*) is retired — replaced by the
            // Used Tire Inspection engine. Existing permission rows and role assignments are kept as
            // history (never deleted) but are no longer seeded or listed (PermissionCatalog).
            // Used Tire Management inspection: inspecting is tire.inspect; applying the disposition
            // (REUSE / REPAIR / RETREAD / HOLD / SCRAP) is a separate approval; rule profiles hold
            // the per-category thresholds the decision engine uses.
            'tire_used_inspection' => ['approve'],
            'tire_rule_profile' => ['manage'],
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
