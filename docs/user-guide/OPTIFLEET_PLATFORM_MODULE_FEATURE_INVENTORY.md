# OptiFleet — Platform Portal Module & Feature Inventory

**Branch/commit:** `Improvement` @ `05dd372e5bfeba9b920d3bf2d8676b2e4a908f8d`
**Sumber:** `backend/routes/api/platform.php` (dibaca penuh), `frontend/src/pages/platform/**`, `frontend/src/layouts/PlatformLayout.tsx`.

| No. | Modul | Fitur | Action | Halaman/Endpoint | Scope | Permission | Status | Bukti |
|--:|---|---|---|---|---|---|---|---|
| 1 | Dashboard Platform | Ringkasan lintas tenant | View | `/platform/dashboard` → `GET /platform/dashboard` | Cross-tenant Aggregate | — (tanpa permission, hanya `platform.scope`) | Implemented | `DashboardController.php` |
| 2 | Tenant Management | Daftar tenant (cari/filter status/urut/paginasi) | View | `/platform/tenants` → `GET /platform/tenants` | Global | `tenant.view` | Implemented | `TenantController::index` |
| 3 | Tenant Management | Buat tenant baru | Create | `+ New Tenant` → `POST /platform/tenants` | Global | `tenant.create` | Implemented (status awal selalu DRAFT) | `TenantController::store` |
| 4 | Tenant Management | Detail tenant (tab Overview) | View | `/platform/tenants/:id` → `GET /platform/tenants/{tenant}` | Tenant terpilih | `tenant.view` | Implemented (read-only) | `TenantDetailPage.tsx` |
| 5 | Tenant Management | Ubah data legal tenant | Update | `PUT /platform/tenants/{tenant}` | Tenant terpilih | `tenant.update` | **Backend Only** — tidak ada form edit di tab Overview | `TenantController::update`; `UpdateTenantRequest` |
| 6 | Tenant Management | Aktifkan tenant | Activate | Tombol "Activate" → `POST /platform/tenants/{tenant}/activate` | Tenant terpilih | `tenant.activate` | Implemented (→ status ACTIVE) | `TenantController::activate` |
| 7 | Tenant Management | Nonaktifkan tenant | Deactivate | Tombol "Deactivate" → `POST /platform/tenants/{tenant}/deactivate` | Tenant terpilih | `tenant.deactivate` | Implemented (→ status INACTIVE) | `TenantController::deactivate` |
| 8 | Tenant Management | Hapus tenant | Delete | — | — | — | **Tidak diimplementasikan** — tidak ada route DELETE untuk Tenant | Grep `platform.php`: nihil |
| 9 | Tenant Management | Pulihkan tenant | Restore | — | — | — | **Tidak diimplementasikan** | idem |
| 10 | Tenant Management | Kelola user tenant (tab Users) | View/Create/Update-status | `GET/POST /platform/tenants/{tenant}/users`, `PATCH .../users/{tenantUser}` | Tenant terpilih | `user.view/create/update` | Implemented (hanya nama/email/password saat create, status saat update) | `TenantUserController.php` |
| 11 | Tenant Management | Assign/revoke role ke user tenant dari sisi platform | Assign | `POST/DELETE /platform/tenants/{tenant}/users/{tenantUser}/roles(/{roleId})` | Tenant terpilih | `user.assign` | **Backend Only** — tidak dipanggil `TenantUsersTab.tsx` | `TenantUserController::assignRole/revokeRole` |
| 12 | Tenant Management | Kelola Module Entitlement (tab Module Entitlements) | Grant/Revoke | `GET /platform/tenants/{tenant}/entitlements`, `POST` toggle | Tenant terpilih | `entitlement.view/manage` | Implemented | `TenantEntitlementsTab.tsx` |
| 13 | Tenant Management | Kelola Capacity Limit (tab Capacity Limits) | Configure | `GET/PUT /platform/tenants/{tenant}/capacity-limits` | Tenant terpilih | `entitlement.view/manage` | Implemented (kosongkan field = unlimited) | `TenantCapacityTab.tsx` |
| 14 | Tenant Management | Custom Pricing per tenant | View/Create/Delete | `GET/POST/DELETE /platform/tenants/{tenant}/custom-pricing` | Tenant terpilih | `pricing.view/update` | **Backend Only** — tidak ada halaman UI sama sekali | Grep `custom-pricing` di frontend: nihil |
| 15 | Module Catalog | Daftar & detail modul + dependency | View/Manage | `/platform/modules`, `/platform/modules/:id` | Global | `module.view/manage` | Implemented | `ModuleController.php`, `ModuleDependencyController.php` |
| 16 | Access Management | Kelola Platform User | View/Create/Update(status,nama) | `/platform/access/users` | Global | `user.view/create/update` | Implemented (tanpa password change) | `PlatformUserController.php` |
| 17 | Access Management | Kelola Platform Role & Permission | View/Create/Update/Assign Permission | `/platform/access/roles` | Global | `role.view/create/update/assign_permission` | Implemented (rename role: Backend Only) | `RoleController.php` (Platform) |
| 18 | Audit Log | Log audit lintas seluruh tenant | View | `/platform/audit-logs` | Cross-tenant Aggregate | `audit.view` | Implemented | `AuditLogController.php` (Platform) |
| 19 | Bundle Management | Susun, sinkron modul, publish bundle | View/Create/Update/Publish | `/platform/bundles`, `/platform/bundles/:id` | Global | `bundle.view/create/update/publish` | Implemented | `BundleController.php` |
| 20 | Pricing Management | Daftar harga & versi harga standar | View/Create/Publish | `/platform/pricing` | Global | `pricing.view/create/publish` | Implemented (tanpa halaman detail tersendiri) | `PricingController.php` |
| 21 | Contract Management | Siklus kontrak (draft→approve→active), amandemen, perpanjangan | View/Create/Submit/Approve/Reject/Terminate/Amend/Renew | `/platform/contracts`, `/platform/contracts/:id` | Tenant terpilih (per kontrak) | `contract.*` | Implemented | `ContractController.php`, `ContractAmendmentController.php`, `ContractRenewalController.php` |
| 22 | Subscription Management | Suspend/Reactivate subscription | View/Suspend/Reactivate | `/platform/subscriptions` | Tenant terpilih | `subscription.view/suspend/reactivate` | Implemented | `SubscriptionController.php` |
| 23 | Billing Management | Daftar billing per periode | View | `/platform/billings` | Tenant terpilih | `billing.view` | Implemented | `BillingController::index` |
| 24 | Billing Management | Generate billing manual | Generate | `POST /platform/subscriptions/{id}/generate-billing` | Tenant terpilih | `billing.generate` | **Backend Only** — tidak ada tombol di UI manapun | Grep frontend: nihil |
| 25 | Invoice Management | Daftar/detail/cetak invoice | View/Export(PDF) | `/platform/invoices`, `/:id`, `.../pdf` | Tenant terpilih | `invoice.view` | Implemented | `InvoiceController.php` |
| 26 | Invoice Management | Batalkan invoice | Void | `POST /platform/invoices/{invoice}/void` | Tenant terpilih | `invoice.void` | Implemented (ditolak jika status PAID — tanpa jalur reversal) | `InvoiceService::void()` |
| 27 | Payment Verification | Verifikasi/tolak bukti bayar | Approve/Reject | `/platform/payments/:id` → `POST .../verify`/`.../reject` | Tenant terpilih | `payment.verify/reject` | Implemented | `PaymentController.php` |
| 28 | Payment Verification | Unduh bukti bayar | Export | `GET /platform/payments/{payment}/proofs/{proof}` | Tenant terpilih | `payment.view` | Implemented | `PaymentController::downloadProof` |
| 29 | Analytics ETL Administration | Lihat/jalankan/retry/backfill ETL | View/Run/Retry/Import(backfill) | `/platform/analytics/etl/*` | Cross-tenant (per tenant dipilih via parameter) | `analytics.etl.*` | **Backend Only** — tidak ada halaman frontend | Grep `frontend/src/pages/platform`: nihil |
| 30 | Analytics Reconciliation | Bandingkan hasil ETL vs PostgreSQL | View | `GET /platform/analytics/reconciliation` | Cross-tenant | `analytics.etl.view` | Backend Only | `ReconciliationController.php` |
| 31 | Intelligence Model Administration | Registry, training, aktivasi/retire model ML | View/Configure/Activate | `/platform/intelligence/models`, `/training` | Global/Tenant (model dapat berscope GLOBAL atau TENANT) | `intelligence.model.*` | Backend Only | `IntelligenceModelController.php`, `IntelligenceTrainingController.php` |
| 32 | Intelligence Monitoring | Monitoring performa & drift model | View | `/platform/intelligence/monitoring(/drift)` | Cross-tenant | `intelligence.monitoring.view` | Backend Only | `IntelligenceMonitoringController.php` |
| 33 | Master Data Global | CRUD data "system" (Vehicle Category, Component Group, dst.) | — | — | — | — | **Unverified** — tidak ditemukan route Platform untuk ini; kemungkinan hanya via seeder | Grep `Api/Platform` untuk model MasterData: nihil |
| 34 | Platform Configuration | Override/atur nilai default platform (Numbering/Template/Workflow/Notification/Tire Scoring) | — | — | — | — | **Unverified** — tidak ditemukan route Platform; nilai default dibuat `ConfigurationDefaultsSeeder` | Grep `platform.php` untuk configuration: nihil |
| 35 | Integration Management | Monitoring/retry integrasi outbox | — | — | — | — | **Tidak diimplementasikan** — `IntegrationOutboxEvent` ditulis tanpa halaman monitoring di portal manapun | `Domain/Integration/Services/IntegrationOutboxService.php` — tanpa controller/route |
| 36 | Tenant Switching / Impersonation | Berpindah ke konteks tenant tertentu sebagai Superadmin | — | — | — | — | **Tidak diimplementasikan** | Pencarian kode menyeluruh: nihil |
| 37 | Modul Operasional Tenant (Vehicle/WO/Inventory/Tire/dst.) | Akses data operasional tenant | — | — | — | — | **Tidak tersedia bagi Superadmin** (by design arsitektur — token platform ditolak middleware `tenant.scope`) | `EnsureTenantScope`, `TenantContextMiddleware.php` |

## Ringkasan

- **Modul dengan UI penuh (Implemented):** 15
- **Backend Only (endpoint ada, UI tidak ada):** 8
- **Unverified (tidak ditemukan mekanisme apa pun, kemungkinan hanya via seeder/database langsung):** 2 (Master Data Global, Platform Configuration override)
- **Tidak diimplementasikan sama sekali (dikonfirmasi negatif, bukan sekadar tidak ditemukan):** 5 (Delete Tenant, Restore Tenant, Integration Management UI, Tenant Switching/Impersonation, akses ke modul operasional tenant)
- **Total baris fitur:** 37
