# OptiFleet — Platform Portal Traceability Matrix

**Branch/commit:** `Improvement` @ `05dd372e5bfeba9b920d3bf2d8676b2e4a908f8d`

| ID | Modul/Fitur | Portal | Frontend | Backend | Database | Permission | Test | Dokumentasi Tenant | Status |
|---|---|---|---|---|---|---|---|---|---|
| P-01 | Dashboard Platform | Platform | `pages/platform/DashboardPage.tsx` | `Api/Platform/DashboardController.php` | `tenants`,`users`,`modules`,`audit_logs`,`subscriptions`,`contracts`,`invoices`,`payments` | — | Phase 1 regression suite (tidak spesifik dashboard) | Bagian J (baru) | Implemented |
| P-02 | Tenant Management (CRUD dasar + Activate/Deactivate) | Platform | `pages/platform/tenants/{TenantListPage,TenantDetailPage}.tsx` | `Api/Platform/TenantController.php` | `tenants` | `tenant.view/create/update/activate/deactivate` | `TenantIsolationTest.php` (isolasi, bukan CRUD spesifik) | Tenant Guide §A2 (Module Inventory) | Implemented (Update: Backend Only) |
| P-03 | Tenant Delete/Restore | Platform | — | — | `tenants.deleted_at` (kolom ada, tidak terpakai) | — | — | — | Tidak diimplementasikan (PF-03) |
| P-04 | Tenant → Users tab | Platform | `tabs/TenantUsersTab.tsx` | `Api/Platform/TenantUserController.php` | `tenant_users`, `users` | `user.view/create/update/assign` | — | Tenant Guide §A5 | Implemented (Assign Role: Backend Only, PF-06) |
| P-05 | Tenant → Module Entitlements tab | Platform | `tabs/TenantEntitlementsTab.tsx` | `Api/Platform/TenantEntitlementController.php` | `tenant_module_entitlements` | `entitlement.view/manage` | Phase 1 regression suite | Tenant Guide §A3 | Implemented |
| P-06 | Tenant → Capacity Limits tab | Platform | `tabs/TenantCapacityTab.tsx` | `Api/Platform/TenantCapacityController.php` | `tenant_capacity_limits` | `entitlement.view/manage` | Phase 1 regression suite | Tenant Guide §A4 | Implemented |
| P-07 | Tenant Custom Pricing | Platform | — (tidak ada) | `Api/Platform/TenantCustomPricingController.php` | `tenant_custom_pricings` | `pricing.view/update` | Phase 2 commercial suite | Tenant Guide F-12 | Backend Only |
| P-08 | Module Catalog & Dependency | Platform | `pages/platform/modules/{ModuleCatalogPage,ModuleDetailPage}.tsx` | `Api/Platform/{ModuleController,ModuleDependencyController}.php` | `modules`, `module_dependencies` | `module.view/manage` | — | Tenant Guide §A7 | Implemented |
| P-09 | Platform Access — Users | Platform | `pages/platform/access/PlatformUsersPage.tsx` | `Api/Platform/PlatformUserController.php` | `users` (`user_type=platform`) | `user.view/create/update` | Phase 1 RBAC regression | Tenant Guide §A19 | Implemented (tanpa password change, PF-01) |
| P-10 | Platform Access — Roles | Platform | `pages/platform/access/PlatformRolesPage.tsx` (via `RoleManager.tsx`) | `Api/Platform/{RoleController,PermissionController}.php` | `roles`,`permissions`,`role_permissions` | `role.view/create/update/assign_permission` | Phase 1 RBAC regression | Tenant Guide §A19 | Implemented (assign role ke user: PF-02) |
| P-11 | Audit Log Platform | Platform | `pages/platform/audit/PlatformAuditLogPage.tsx` (via `AuditLogTable.tsx`) | `Api/Platform/AuditLogController.php` | `audit_logs` | `audit.view` | Audit isolation test | Tenant Guide §A20 | Implemented |
| P-12 | Bundle Management | Platform | `pages/platform/bundles/{BundleListPage,BundleDetailPage}.tsx` | `Domain/ProductCatalog/Services/BundleService.php`; `Api/Platform/BundleController.php` | `bundles`,`bundle_modules`,`bundle_versions`,`bundle_version_modules` | `bundle.view/create/update/publish` | Phase 2 commercial suite | Tenant Guide §A8 | Implemented |
| P-13 | Pricing Management | Platform | `pages/platform/pricing/PricingListPage.tsx` | `Domain/Pricing/Services/PricingResolutionService.php`; `Api/Platform/PricingController.php` | `pricings`,`pricing_versions` | `pricing.view/create/publish` | Phase 2 commercial suite | Tenant Guide §A9 | Implemented |
| P-14 | Contract Management (+Amendment/Renewal) | Platform | `pages/platform/contracts/{ContractListPage,ContractDetailPage}.tsx` | `Domain/Contract/Services/{ContractService,AmendmentService,RenewalService}.php` | `contracts`,`contract_items`,`contract_approvals`,`contract_amendments` | `contract.*` | Phase 2 commercial suite (draft→approve→active→amend→renew) | Tenant Guide §A11-A13 | Implemented |
| P-15 | Subscription Management | Platform | `pages/platform/subscriptions/SubscriptionListPage.tsx` | `Domain/Subscription/Services/{SubscriptionService,DunningService}.php`; `Api/Platform/SubscriptionController.php` | `subscriptions` | `subscription.view/suspend/reactivate` | Dunning pipeline test | Tenant Guide §A14 | Implemented |
| P-16 | Billing Management | Platform | `pages/platform/billing/BillingListPage.tsx` | `Domain/Billing/Services/BillingGenerationService.php`; `Api/Platform/BillingController.php` | `billings`,`billing_items` | `billing.view/generate` | Billing idempotency test | Tenant Guide §A15-A16 | Implemented (generate manual: Backend Only, PF-08) |
| P-17 | Invoice Management | Platform | `pages/platform/invoices/{InvoiceListPage,InvoiceDetailPage}.tsx` | `Domain/Invoice/Services/InvoiceService.php`; `Api/Platform/InvoiceController.php` | `invoices`,`invoice_items` | `invoice.view/void` | Invoice numbering/concurrency test | Tenant Guide §A17 | Implemented (void-after-paid: PF-09) |
| P-18 | Payment Verification | Platform | `pages/platform/payments/{PaymentListPage,PaymentDetailPage}.tsx` | `Domain/Payment/Services/PaymentVerificationService.php`; `Api/Platform/PaymentController.php` | `payments`,`payment_proofs` | `payment.view/verify/reject` | Payment verification/rejection test | Tenant Guide §A18 | Implemented (PF-10) |
| P-19 | Analytics ETL Administration | Platform | — (tidak ada) | `Api/Platform/Analytics/{EtlAdminController,ReconciliationController}.php` | `analytics_etl_runs` (Mongo) | `analytics.etl.*` | `AnalyticsApiTest.php` (cakupan umum, bukan UI Platform) | Tenant Guide §A21-A22 | Backend Only |
| P-20 | Intelligence Model Administration | Platform | — (tidak ada) | `Api/Platform/Intelligence/{IntelligenceModelController,IntelligenceTrainingController,IntelligenceMonitoringController}.php` | `intelligence_models` (Mongo) | `intelligence.model.*`, `intelligence.monitoring.view` | `IntelligenceAdminApiTest.php` | Tenant Guide §A23-A24 | Backend Only |
| P-21 | Tenant Switching/Impersonation | Platform | — | — | — | — | — | — | Tidak diimplementasikan (PF-15) |
| P-22 | Master Data Global CRUD (Platform) | Platform | — | — | `vehicle_categories`, dst. (`tenant_id=null` rows) | — | — | — | Unverified (PF-07) |
| P-23 | Platform Configuration Override | Platform | — | — | `configuration_sets` (`tenant_id=null` rows) | — | — | — | Unverified (PF-07) |
| P-24 | Integration Management UI | Platform | — | — | `integration_outbox_events` | — | — | — | Tidak diimplementasikan (PF-16) |
| P-25 | Login/Logout (dipakai bersama) | Platform+Tenant | `pages/LoginPage.tsx`, `auth/AuthContext.tsx` | `Api/Auth/AuthController.php` | `users`, Sanctum `personal_access_tokens` | — (`auth:sanctum`) | Phase 1 regression (login/tenant switching) | Tenant Guide Bagian E/H | Implemented (RWC) |
| P-26 | Password Reset/Change | Platform+Tenant | — | — | — | — | — | Tenant Guide Bagian E (Unverified→dikonfirmasi negatif di sini) | Tidak diimplementasikan (PF-01) |

---

## Catatan Metodologi

- Baris P-01 s.d. P-20 merujuk fitur yang benar-benar ada rute/kelasnya (Implemented atau Backend Only).
- Baris P-21 s.d. P-24, P-26 sengaja disertakan dengan kolom Frontend/Backend/Database/Test kosong untuk menegaskan **secara eksplisit** bahwa ketiadaan ini sudah diverifikasi, bukan luput diperiksa.
- Kolom **Dokumentasi Tenant** merujuk ke bagian/temuan yang relevan pada paket dokumentasi Tenant Guide (commit `fccee05`) sebagai jejak silang, sesuai permintaan Section 13 tugas.
- Untuk daftar 275 permission lengkap, rujuk langsung `backend/database/seeders/PermissionSeeder.php` — tidak diduplikasi di sini.
