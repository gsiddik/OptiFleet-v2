# OptiFleet — Tenant Guide Reuse Matrix

**Branch/commit:** `Improvement` @ `05dd372e5bfeba9b920d3bf2d8676b2e4a908f8d`
**Sumber Tenant Guide yang dievaluasi:** `OPTIFLEET_USER_GUIDE.md`, `OPTIFLEET_DATA_DICTIONARY.md`, `OPTIFLEET_ROLE_PERMISSION_MATRIX.md`, `OPTIFLEET_BUSINESS_FLOW.md`, `OPTIFLEET_MODULE_FEATURE_INVENTORY.md`, `OPTIFLEET_USER_GUIDE_FINDINGS.md`, `OPTIFLEET_TRACEABILITY_MATRIX.md` (commit `fccee05`, sesi analisis sebelumnya). Setiap prosedur di bawah divalidasi ulang terhadap route/permission/komponen aktual pada sesi ini — bukan disalin otomatis.

**Legenda Klasifikasi:** Reusable Without Change (**RWC**) · Reusable With Adaptation (**RWA**) · Platform-Specific (**PS**) · Tenant-Specific (**TS**) · Obsolete (**OBS**) · Unverified (**UNV**)

| ID | Modul/Prosedur Tenant | Superadmin Dapat Pakai? | Klasifikasi | Penyesuaian yang Diperlukan | Bukti Implementasi | Dimasukkan ke Bab |
|---|---|---|---|---|---|---|
| RU-01 | Login (email+password, satu form) | Ya | **RWC** | Tidak ada — mekanisme identik, hanya ability token berbeda (`platform` vs `tenant:{id}`) | `AuthController::login`; `LoginPage.tsx` | H |
| RU-02 | Logout (cabut token aktif saja) | Ya | **RWC** | Tidak ada | `AuthController::logout` | H |
| RU-03 | Reset/ubah password | Tidak — fitur tidak ada untuk role mana pun | **OBS** (dianggap "usang sebelum pernah ada" — bukan pernah dihapus, memang tidak pernah diimplementasikan) | — | Pencarian menyeluruh `forgot/reset/change password` di backend & frontend: nihil hasil; `PlatformUserController::update`/`TenantUserController::update` tidak menerima field password sama sekali | H (dicatat sebagai keterbatasan, bukan prosedur) |
| RU-04 | Session berbasis token, tidak ada expiry eksplisit | Ya | **RWC** | Tidak ada | `TenantContextMiddleware.php` | H |
| RU-05 | Struktur sidebar berkelompok, digerbang permission+modul | Tidak — Platform sidebar **flat**, tidak berkelompok, dan **tidak ada konsep Module Entitlement di level platform** (semua item hanya digerbang permission) | **PS** (pola navigasinya berbeda secara struktural, bukan sekadar beda menu) | Ditulis ulang total sebagai bagian tersendiri | `PlatformLayout.tsx` vs `TenantLayout.tsx` | I |
| RU-06 | Header/navbar (nama user, tombol Logout) | Ya | **RWC** | Platform tidak punya tenant-switcher/banner suspensi di header (elemen tenant tidak relevan) | `PlatformLayout.tsx` | I |
| RU-07 | Pencarian, filter status, sorting, pagination pada halaman daftar | Ya | **RWA** | Pola sama (search box + filter dropdown/chip + tabel + pagination backend), tetapi filter status dan endpoint berbeda per modul Platform | `useApiList` hook dipakai identik di kedua portal | I |
| RU-08 | Tab pada halaman detail | Ya | **RWA** | Pola tab sama, tetapi Tenant Detail hanya py 4 tab (Overview/Users/Module Entitlements/Capacity Limits) dan **Overview bersifat read-only** (beda dari pola Tenant Portal yang umumnya punya form edit di Overview) | `TenantDetailPage.tsx` | K |
| RU-09 | Tombol aksi muncul sesuai status+permission, bukan disabled | Ya | **RWC** | Tidak ada | Konsisten di seluruh frontend (`hasPermission()` gating) | I |
| RU-10 | Validasi frontend ringan + validasi backend mengikat | Ya | **RWC** | Tidak ada | `FormRequest` classes di kedua portal | I |
| RU-11 | Unggah berkas privat, diunduh lewat endpoint terautentikasi | Sebagian — Superadmin mengunduh **PDF invoice** dan **bukti bayar** (proof), tapi tidak pernah **mengunggah** berkas apa pun di Platform Portal | **RWA** | Hanya aspek "download" yang relevan; aspek "upload" tidak berlaku bagi Superadmin | `InvoiceController::downloadPdf`, `PaymentController::downloadProof` | S/Commercial |
| RU-12 | Dashboard sebagai titik masuk, sumber ringkasan | Ya | **RWA** | Tenant Dashboard menampilkan modul aktif tenant sendiri; Platform Dashboard menampilkan ringkasan **lintas seluruh tenant** (jumlah tenant, subscription, invoice, dst.) — isi & tujuan berbeda meski pola halaman sama | `DashboardController.php` (Platform) vs Tenant `DashboardController` | J |
| RU-13 | Access Management: kelola User (buat, aktif/nonaktifkan) | Ya | **RWA** | Pola sama, tetapi Platform User **tidak punya Data Scope** (konsep ini hanya ada untuk user tenant); pembuatan user platform tidak melalui alur "tenant membership" | `PlatformUserController.php` vs `Api/Tenant/UserController.php` | M |
| RU-14 | Access Management: kelola Role & permission (grouped checkbox, Select All/Clear All) | Ya | **RWA** | Komponen `RoleManager.tsx` **sama persis** dipakai kedua portal, hanya endpoint (`/platform/roles` vs `/app/roles`) dan daftar permission (`scope=platform` vs `scope=tenant`) berbeda | `RoleManager.tsx` (shared component) | N |
| RU-15 | Assign Role ke user dalam konteks tertentu | Tidak konsisten — backend punya endpoint (`POST /platform/tenants/{t}/users/{u}/roles`) tetapi **tidak ada UI-nya** di Platform Portal (beda dari Tenant Portal yang punya modal "Manage Access" lengkap) | **RWA dengan catatan gap** | Prosedur ditulis sebagai "hanya lewat API", bukan langkah UI normal | `TenantUsersTab.tsx` tidak memanggil endpoint assign-role sama sekali | M — dicatat sebagai Backend Only |
| RU-16 | Data Scope (Branch/Workshop/Warehouse per user) | Tidak — konsep ini murni tenant (butuh Branch/Workshop/Warehouse yang adalah data tenant) | **TS** | Tidak dimasukkan sebagai prosedur Platform | `DataScopeController` hanya ada di `Api/Tenant/*` | Tidak dimasukkan |
| RU-17 | Audit Log: filter teks bebas Resource Type/Action, tabel Before/After | Ya | **RWA** | Platform melihat **lintas seluruh tenant** (kolom Tenant tambahan) sedangkan tenant hanya melihat miliknya sendiri; filter tambahan `actor_user_id` hanya ada di Platform | `AuditLogTable.tsx` (shared component, prop `showTenantColumn`) | Q |
| RU-18 | Company Profile (edit data legal tenant sendiri) | Tidak langsung — Superadmin **mengedit data tenant LAIN** lewat mekanisme berbeda (`PUT /platform/tenants/{tenant}`, dan itu pun **Backend Only**, tidak ada form UI) | **PS** (konsep terbalik: bukan "profil sendiri" tapi "kelola profil tenant lain", dan belum ada UI) | Ditulis sebagai catatan Backend Only pada bab Tenant Management | `CompanyProfileController.php` (tenant) vs `TenantController::update` (platform) | K |
| RU-19 | Master Data global vs tenant (Vehicle Category, dst. dengan `is_system`) | Sebagian — Superadmin **tidak memiliki halaman UI** untuk mengelola master data "system" (Vehicle Category/Component Group/Product Category/dll. dengan `tenant_id=null`); data ini tampaknya hanya dibuat lewat seeder (`MasterDataSeeder`, `SupplyChainSeeder`), bukan lewat form Platform Portal manapun | **UNV** (tidak ditemukan endpoint Platform untuk CRUD master data global — kemungkinan hanya dikelola lewat seeder/migrasi, belum ada UI Superadmin) | Tidak ditulis sebagai prosedur, dicatat sebagai temuan | Grep `Api/Platform` untuk VehicleCategory/ComponentGroup/Product: nihil | O — dicatat sebagai gap, bukan prosedur |
| RU-20 | Modul operasional (Vehicle/Work Order/Inspection/Maintenance/Inventory/Tire/Worker/Partner/History) | **Tidak** — Superadmin tidak memiliki akses sama sekali (lihat analisis batas Bagian F/L) | **TS** murni | Tidak dimasukkan sebagai prosedur; dijelaskan eksplisit sebagai batas kewenangan | Token platform (`ability=platform`) ditolak middleware `tenant.scope`; nihil menu operasional di `PlatformLayout.tsx` | U — dijelaskan sebagai "tidak tersedia", bukan prosedur |
| RU-21 | Notification Rules (Configuration → Notification) | Tidak — hanya ada di Tenant Portal; Platform tidak memiliki halaman pengelolaan notifikasi sama sekali (event "platform-locked" bersifat hardcode di `NotificationEventCatalog`, hanya *terlihat* dari sisi tenant sebagai read-only) | **TS** | Tidak dimasukkan sebagai prosedur Platform | Grep route notification: hanya ada di `routes/api/app.php` | R — dijelaskan sebagai "tidak tersedia" |
| RU-22 | Konfigurasi (Numbering/Template/Workflow/Tire Scoring) | Tidak — seluruhnya per-tenant (`ConfigurationSet.tenant_id`), Platform tidak punya halaman override; platform default dibuat lewat seeder (`ConfigurationDefaultsSeeder`), bukan lewat UI Superadmin | **TS/UNV** | Tidak dimasukkan sebagai prosedur Platform bab P; dicatat sebagai temuan bahwa "default platform" hanya dapat diubah lewat seeder, bukan UI | Grep route configuration: hanya ada di `routes/api/app.php`, tidak ada di `platform.php` | P — dijelaskan sebagai gap |
| RU-23 | Bundle Management (susun & publish) | Ya, ini murni Platform | **PS** | — | `BundleController.php` (Platform only) | Commercial |
| RU-24 | Pricing & Tenant Custom Pricing | Ya (Pricing), Backend Only (Custom Pricing — sudah dicatat di Findings Tenant F-12) | **PS**, dengan catatan gap UI Custom Pricing | Prosedur "Pricing" ditulis lengkap; "Custom Pricing" ditulis sebagai catatan Backend Only | `PricingController.php`, `TenantCustomPricingController.php` | Commercial |
| RU-25 | Contract lifecycle & Amendment & Renewal | Ya, murni Platform | **PS** | — | `ContractService.php`, dst. | Commercial |
| RU-26 | Subscription Suspend/Reactivate | Ya, murni Platform | **PS** | — | `SubscriptionController.php` | Commercial |
| RU-27 | Billing (lihat + generate manual — Backend Only) | Ya (lihat), Backend Only (generate manual — sudah dicatat F-23) | **PS**, dengan catatan gap UI | — | `BillingController.php` | Commercial |
| RU-28 | Invoice (lihat/unduh PDF/void) | Ya, murni Platform | **PS** | Catat batasan void-after-PAID (F-14 pada Findings Tenant, berlaku juga di sini) | `InvoiceController.php` | Commercial |
| RU-29 | Payment Verification (verify/reject) | Ya, murni Platform | **PS** | — | `PaymentController.php` | Commercial |
| RU-30 | Analytics dashboard (14 domain + Overview) | **Tidak** — Superadmin tidak memiliki akses (modul ini hanya tersedia di Tenant Portal, per-tenant) | **TS** | Tidak dimasukkan sebagai prosedur pemakaian dashboard; hanya bagian **administrasi ETL lintas-tenant** (Backend Only) yang relevan bagi Superadmin | `routes/api/app.php` untuk dashboard analitik; `platform.php` hanya punya endpoint admin ETL | T |
| RU-31 | Maintenance Intelligence dashboard | **Tidak** — sama seperti Analytics; hanya administrasi model (Backend Only) yang menjadi bagian Platform | **TS**, dengan sisi admin **PS/Backend Only** | Bagian "Intelligence Model Administration" ditulis sebagai fitur Platform tersendiri, bukan pemakaian dashboard | `routes/api/app.php` intelligence vs `platform.php` intelligence admin | T |

---

## Catatan Metodologi Validasi

Untuk setiap baris di atas, kesepuluh langkah validasi wajib (§3.2 tugas) diterapkan sebagai berikut:

1. **Route frontend** — dicocokkan terhadap `frontend/src/App.tsx` (baik grup `/platform/*` maupun `/app/*`).
2. **Menu & halaman aktual** — dicocokkan terhadap `PlatformLayout.tsx`/`TenantLayout.tsx`.
3. **Endpoint backend** — dicocokkan terhadap `backend/routes/api/platform.php` (dibaca penuh ulang pada sesi ini) dan `backend/routes/api/app.php`.
4. **Role & permission Superadmin** — dicocokkan terhadap `PermissionSeeder.php` kelompok `platformOnly`/`bothScopes`.
5. **Validasi** — dicocokkan terhadap `FormRequest` class terkait bila ada (mis. `StoreTenantRequest`, `UpdateTenantRequest` dibaca ulang penuh pada sesi ini).
6. **Status & transisi status** — dicocokkan terhadap migration/model (mis. `tenants.status` enum dibaca ulang dari migration aslinya).
7. **Perbedaan scope data** — dianalisis eksplisit per baris (kolom "Superadmin Dapat Pakai?").
8. **Kebutuhan pemilihan tenant** — dianalisis (mayoritas fitur Platform beroperasi pada satu tenant by route parameter `{tenant}`, bukan lewat "tenant context" tersimpan seperti token tenant).
9. **Dampak berbeda** — dijelaskan pada kolom "Penyesuaian".
10. **Perubahan sejak Tenant Guide dibuat** — tidak ada; commit sama (`fccee05` dibuat dari `05dd372e5bfeba9b920d3bf2d8676b2e4a908f8d`, tidak ada commit baru di antaranya).

## Ringkasan

| Klasifikasi | Jumlah |
|---|---:|
| Reusable Without Change (RWC) | 6 |
| Reusable With Adaptation (RWA) | 9 |
| Platform-Specific (PS) | 8 |
| Tenant-Specific (TS) | 5 |
| Obsolete (OBS) | 1 |
| Unverified (UNV) | 1 |
| **Total prosedur dievaluasi** | **31** (beberapa baris bersilangan kategori PS+gap, dihitung pada kategori utamanya) |
