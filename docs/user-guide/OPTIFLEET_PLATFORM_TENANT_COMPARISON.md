# OptiFleet — Platform Portal vs Tenant Portal Comparison

**Branch/commit:** `Improvement` @ `05dd372e5bfeba9b920d3bf2d8676b2e4a908f8d`

## 1. Perbandingan Umum

| Area | Platform Portal / Superadmin | Tenant Portal | Catatan |
|---|---|---|---|
| Tujuan | Mengoperasikan OptiFleet sebagai bisnis SaaS: tenant, kontrak, penagihan, akses lintas tenant | Mengoperasikan armada & bengkel satu perusahaan (satu tenant) | Dua portal terpisah total, tidak ada UI gabungan |
| URL prefix | `/platform/*` (frontend), `/api/v1/platform/*` (API) | `/app/*` (frontend), `/api/v1/app/*` (API) | `App.tsx` |
| Ability token | `platform` | `tenant:{uuid}` | Sanctum ability string, sumber kebenaran satu-satunya untuk konteks |
| Middleware gerbang | `platform.scope` (`EnsurePlatformScope`) | `tenant.scope` (`EnsureTenantScope`) + `subscription.access` | Saling eksklusif — satu token tidak bisa lolos keduanya |
| Sidebar | Flat, 13 item, hanya digerbang permission | Berkelompok (16 grup), digerbang permission **dan** module entitlement | `PlatformLayout.tsx` vs `TenantLayout.tsx` |
| Konsep Module Entitlement | Tidak berlaku untuk portal ini sendiri (Superadmin selalu punya akses penuh ke fitur Platform yang permission-nya dimiliki) | Berlaku penuh — modul tenant harus di-*grant* dulu oleh Platform | `TenantModuleEntitlement` |
| Data Scope (Branch/Workshop/Warehouse) | Tidak berlaku (tidak ada konsep cabang/bengkel/gudang di level platform) | Berlaku, membatasi user ke sebagian data tenant | `DataScopeAssignment` |
| Dashboard | Ringkasan **lintas seluruh tenant**: jumlah tenant, user platform/tenant, modul, subscription per status, kontrak akan berakhir, invoice outstanding/overdue, pembayaran menunggu verifikasi, 10 audit log terbaru | Ringkasan **satu tenant**: modul aktif, dsb. | `DashboardController.php` (dua kelas berbeda, satu di `Api/Platform`, satu di `Api/Tenant`) |
| Tenant/Customer | Objek yang dikelola (Tenant Management) | Diri sendiri (tidak ada konsep "tenant lain") | |
| User | Platform User (tanpa Data Scope, tanpa keanggotaan tenant) dan dapat melihat/membuat Tenant User (terbatas: nama/email/password/status saja) | Tenant User penuh (role, Data Scope, keanggotaan) | |
| Role & Permission | Role platform (permission `scope=platform`) | Role tenant (permission `scope=tenant`) | Baris `permissions` terpisah walau nama sama, lihat unique `(name, scope)` |
| Vehicle | **Tidak tersedia** | Tersedia penuh | Tidak ada route Platform untuk Vehicle |
| Work Order | **Tidak tersedia** | Tersedia penuh | idem |
| Scheduled Maintenance | **Tidak tersedia** | Tersedia penuh | idem |
| Inventory/Warehouse | **Tidak tersedia** (operasional); Warehouse sebagai *master data lokasi* juga murni tenant | Tersedia penuh | idem |
| Worker | **Tidak tersedia** | Tersedia penuh | idem |
| Partner | **Tidak tersedia** | Tersedia penuh | idem |
| Tire | **Tidak tersedia** | Tersedia penuh | idem |
| Master Data (Vehicle Category, dst.) | **Tidak ada UI Platform** — data "system" (`tenant_id=null`) tampaknya hanya dapat dibuat lewat seeder, bukan lewat aplikasi (lihat Reuse Matrix RU-19) | Tersedia penuh (data sendiri + melihat data "system") | Gap/Unverified, dicatat di Findings |
| Configuration (Numbering/Template/Workflow/Notification/Tire Scoring) | **Tidak ada UI Platform** — nilai default platform dibuat lewat seeder (`ConfigurationDefaultsSeeder`) | Tersedia penuh per tenant | idem |
| Report/Analytics operasional | **Tidak tersedia** — Superadmin tidak melihat dashboard Analytics/Intelligence tenant mana pun | Tersedia penuh (14 domain + Overview) | Hanya sisi **administrasi ETL/Model** lintas tenant yang menjadi bagian Platform |
| Notification (aturan & pengiriman) | **Tidak tersedia** — tidak ada halaman pengelolaan di Platform | Tersedia (aturan notifikasi tenant) | Event "platform-locked" bersifat hardcode, hanya *terlihat read-only* dari sisi tenant |
| Audit Log | Melihat **lintas seluruh tenant**, filter tambahan `actor_user_id`, `tenant_id` | Melihat **hanya tenant sendiri** | Backend hard-filter berbeda per controller |
| Integration | Tidak ada UI pengelolaan di kedua portal — `IntegrationOutboxEvent` (Workshop Invoice → akuntansi) ditulis backend tanpa konsumen dan tanpa halaman monitoring di mana pun | Sama — tidak ada UI | Berlaku sama di kedua sisi (fitur belum matang) |
| Import/Export | Tidak ada fitur import/export apa pun di Platform Portal | Export CSV tersedia (Analytics) | |
| Data Deletion | **Tidak ada** — tidak ada endpoint hapus Tenant; hapus Role/Permission juga tidak ada di kedua portal | Soft-delete pada sebagian master data (Vehicle Category/Component Group/Vehicle Brand/Model) via aksi "Deactivate"/"Delete" | Tenant punya soft-delete granular; Platform (level Tenant) tidak punya sama sekali |
| Data Restoration | **Tidak ada** di kedua portal untuk data manapun yang diperiksa | Tidak ada | Kolom `deleted_at` (Laravel SoftDeletes) ada di banyak tabel tapi tidak ada endpoint restore |
| Tenant Switching (multi-tenant user) | Tidak relevan — user platform tidak memiliki keanggotaan tenant | Ada — user dengan >1 keanggotaan tenant dapat berpindah via dropdown (menerbitkan token baru) | `switchTenant()` di `AuthContext.tsx` — ini murni fitur **Tenant Portal**, bukan kemampuan Superadmin |
| Impersonation | **Tidak ada** di mana pun | Tidak relevan | Dikonfirmasi lewat pencarian kode menyeluruh — tidak ditemukan bukti implementasi apa pun |
| Cross-tenant Access | Terbatas pada domain **komersial/administratif** (Tenant record, User, Entitlement, Capacity, Contract, Invoice, Payment, Audit Log) — **tidak pernah** mencakup data operasional tenant (Vehicle, WO, dst.) | Tidak relevan (satu tenant saja) | |

## 2. Klasifikasi Scope Data

| Data | Global | Tenant-specific | Shared Reference | Cross-tenant Aggregate | System-generated |
|---|:-:|:-:|:-:|:-:|:-:|
| Tenant record | ✓ (dikelola platform) | — | — | — | — |
| Module catalog (`modules`, `module_dependencies`) | ✓ | — | — | — | — |
| Bundle/BundleVersion | ✓ | — | — | — | — |
| Pricing/PricingVersion | ✓ | — | ✓ (dipakai lintas tenant kecuali di-override) | — | — |
| TenantCustomPricing | — | ✓ | — | — | — |
| Contract/Invoice/Payment/Subscription/Billing | — | ✓ (satu baris = satu tenant) | — | — | Invoice/Billing dibuat otomatis sistem |
| Platform User, Platform Role/Permission | ✓ | — | — | — | — |
| Tenant User, Tenant Role/Permission, DataScopeAssignment | — | ✓ | — | — | — |
| Master Data "system" (`tenant_id=null`) — Vehicle Category, Component Group, dst. | ✓ (dibuat via seeder) | (tenant dapat menambah miliknya sendiri, `is_system=false`) | ✓ — dipakai bersama seluruh tenant sebagai referensi awal | — | — |
| Configuration default platform | ✓ (via seeder) | (tenant meng-override dengan versi sendiri) | ✓ | — | — |
| AuditLog | — | ✓ (`tenant_id` per baris, `null` untuk aksi level-platform) | — | ✓ (Platform melihat gabungan seluruh tenant) | ✓ (ditulis otomatis oleh `AuditService`) |
| Platform Dashboard summary | — | — | — | ✓ (agregasi COUNT lintas tabel `tenants`/`subscriptions`/`invoices`/dst.) | ✓ |
| `daily_*` Analytics/Intelligence collections | — | ✓ (per tenant, ETL berjalan per tenant) | — | — | ✓ (dibuat ETL terjadwal) |

## 3. Ringkasan Batas Kewenangan (Bahasa Sederhana)

Superadmin adalah **pengelola bisnis platform**, bukan **operator armada tenant**. Superadmin memutuskan *siapa* (tenant mana) yang boleh memakai OptiFleet dan *modul apa* yang mereka bayar untuk dipakai, memverifikasi bahwa mereka membayar, dan mengawasi lewat audit log serta ringkasan administratif — tetapi **tidak pernah** masuk ke dalam data operasional harian tenant (kendaraan, Work Order, stok, dst.) melalui aplikasi ini. Bila dukungan teknis memerlukan pemeriksaan data operasional tenant tertentu, hal itu **di luar cakupan Platform Portal** dan harus dilakukan lewat akses database/administratif terpisah (di luar aplikasi) — bukan lewat fitur yang tersedia di UI.
