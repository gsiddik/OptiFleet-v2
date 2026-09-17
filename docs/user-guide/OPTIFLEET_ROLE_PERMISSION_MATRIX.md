# OptiFleet — Role & Permission Matrix

**Branch/commit:** `Improvement` @ `05dd372e5bfeba9b920d3bf2d8676b2e4a908f8d`
**Sumber:** `backend/database/seeders/PermissionSeeder.php`, `backend/app/Domain/AccessControl/*`, `backend/routes/api/{app,platform}.php`, `frontend/src/App.tsx`, `frontend/src/layouts/*Layout.tsx`.

---

## 1. Model RBAC OptiFleet — Penting Dibaca Sebelum Tabel Matriks

OptiFleet **tidak** memakai daftar role tetap (bukan enum "Admin/Manager/Mechanic" yang di-hardcode). Sebaliknya:

1. **Permission** adalah unit akses granular berformat `resource.action` (contoh: `work_order.approve`, `tire.install`). Seluruh permission tersedia di `PermissionSeeder.php` — total 275 baris permission (dikonfirmasi oleh `RELEASE_READINESS_R4.md`, pengujian idempotensi seeder).
2. **Role** adalah kumpulan permission yang diberi nama bebas, dibuat oleh Admin tenant (untuk role tenant) atau Superadmin platform (untuk role platform) melalui halaman **Access → Roles**. Satu tenant boleh punya banyak role dengan nama apa pun; role bukan enum tetap.
3. **RoleAssignment** menautkan satu user ke satu atau lebih role, dalam konteks tenant tertentu (atau `tenant_id = null` untuk role platform).
4. **DataScopeAssignment** adalah lapisan **independen** dari permission — mengatur *cakupan data* apa yang bisa dijangkau user (seluruh tenant / cabang tertentu / bengkel tertentu / gudang tertentu), terpisah dari *aksi* apa yang boleh dilakukan.
5. **Enforcement selalu di backend** (`CheckPermission` middleware) — frontend hanya menyembunyikan menu/tombol yang tidak relevan (`hasPermission()` di `AuthContext.tsx`) sebagai bantuan tampilan, bukan sebagai penjaga keamanan. Menyembunyikan tombol di frontend **tidak pernah** menjadi pengganti pemeriksaan permission di server.
6. **`worker_type`** (LEAD_MECHANIC/MECHANIC/TECHNICIAN/INSPECTOR/QC pada data Worker/Mekanik) **bukan** sumber permission — ini murni atribut perencanaan tenaga kerja (skill, penjadwalan). Siapa yang boleh melakukan aksi QC, misalnya, ditentukan oleh permission `qc.perform`/`qc.approve`, bukan oleh apakah worker tersebut bertipe `QC`.
7. **`scope_type = 'OWN'`** pada Data Scope terdaftar di enum database dan tersedia di form UI, **namun belum ada logika di `DataScopeService` yang memprosesnya** — menetapkan `OWN` saat ini justru membuat user tidak memiliki akses data sama sekali (bukan "hanya data miliknya sendiri" seperti namanya menyiratkan). Jangan gunakan `OWN` sampai perilaku ini diperbaiki oleh tim pengembang; lihat `OPTIFLEET_USER_GUIDE_FINDINGS.md` F-01.

### Perbedaan Permission vs Role — Contoh Praktis

Jika perusahaan Anda ingin peran "Kepala Bengkel" yang bisa menyetujui Work Order tetapi tidak bisa menghapus data master, maka: buat role baru bernama apa saja (misalnya "Kepala Bengkel"), lalu centang permission yang relevan (`work_order.approve`, `work_order.view`, dst.) — **tanpa** memberi permission `vehicle_category.update` dsb. Nama role hanyalah label; hak akses ditentukan murni oleh kumpulan permission yang dicentang.

---

## 2. Role Contoh (Seed Demo) — Bukan Daftar Final

Role berikut ditemukan pada seeder demo (`DemoDataSeeder.php`, `CommercialSeeder.php`, `OperationsSeeder.php`) — dipakai sebagai **contoh orientasi**, bukan daftar role yang mengikat aplikasi:

| Role Demo | Scope | Cara dibuat | Permission yang di-sync |
|---|---|---|---|
| **Platform Superadmin** | platform | `DemoDataSeeder` | **Seluruh** permission berscope `platform` (`Permission::where('scope','platform')->pluck('id')`) |
| **{Tenant} Admin** (mis. "PT Alpha Fleet Admin") | tenant | `DemoDataSeeder`/`CommercialSeeder` | Kumpulan luas permission tenant (representatif "administrator penuh") |
| **{Tenant} Fleet Manager** | tenant | `DemoDataSeeder` | Subset permission operasional (vehicle, maintenance, work order, dst.) — tanpa Access Management penuh |
| **Workshop Manager** (mis. "ALPHA Workshop Manager (Jakarta)") | tenant, data-scope WORKSHOP | `OperationsSeeder` | Permission operasional bengkel + `tire_retread.inspect/approve`, `tire_repair.inspect/approve` (checker dalam siklus maker-checker) |
| **Warehouse Manager** (tersirat dari `OperationsSeeder`) | tenant | `OperationsSeeder` | `tire_retread.send/receive`, `tire_repair.send/receive` (maker dalam siklus maker-checker) — dipisah dari Workshop Manager agar pemisahan tugas nyata sejak awal |
| **Tenant Admin** (generic) | tenant | `CommercialSeeder` | Permission administratif dasar untuk tenant demo komersial |

**Worker (bukan Role):** Lead Mechanic, Mechanic, QC — ini adalah nilai `worker_type` pada data pekerja bengkel (`OperationsSeeder`: Budi Santoso=LEAD_MECHANIC, Andi Wijaya=MECHANIC, Siti Rahma=QC, Dedi Kurnia=MECHANIC), **bukan** role RBAC. Seorang Worker baru punya hak akses sistem jika akun Workernya ditautkan (`link-user`) ke akun User yang memegang role dengan permission yang sesuai.

---

## 3. Matriks Permission per Kelompok Modul

Legenda: `✓` = tersedia sebagai permission yang bisa diberikan; kolom role menunjukkan **apakah role demo tersebut memilikinya pada seed data** — bukan batasan sistem. Setiap tenant bebas membuat role baru dengan kombinasi permission apa pun.

### 3.1 Platform — Komersial SaaS

| Modul/Fitur | Action | Platform Superadmin |
|---|---|---:|
| Tenant | view/create/update/activate/deactivate | ✓ |
| Module Catalog | view/manage | ✓ |
| Entitlement | view/manage | ✓ |
| Bundle | view/create/update/publish | ✓ |
| Pricing | view/create/update/publish | ✓ |
| Contract | view/create/update/submit/approve/amend/renew/terminate | ✓ |
| Subscription | view/activate/suspend/reactivate | ✓ |
| Billing | view/generate/adjust | ✓ |
| Invoice | view/generate/issue/void | ✓ |
| Payment | view/verify/reject | ✓ |
| Analytics ETL | view/run/retry/backfill | ✓ (Specialist role dapat dipisah) |
| Intelligence Model Admin | view/train/evaluate/activate/retire | ✓ (Specialist role dapat dipisah) |
| Intelligence Monitoring | view | ✓ |

### 3.2 Platform & Tenant — Access Management, Audit (bothScopes)

| Modul/Fitur | Action | Platform Superadmin | Admin Tenant |
|---|---|---:|---:|
| User | view/create/update/assign | ✓ (platform user) | ✓ (tenant user) |
| Role | view/create/update/assign_permission | ✓ (role platform) | ✓ (role tenant) |
| Audit | view | ✓ (lintas tenant) | ✓ (tenant sendiri saja) |

### 3.3 Tenant — Organization & Master Data

| Modul/Fitur | Action | Admin Tenant | Fleet Manager | Workshop Manager | Warehouse |
|---|---|---:|---:|---:|---:|
| Branch | view/create/update/activate/deactivate | ✓ | View | — | — |
| Workshop | view/create/update/activate/deactivate | ✓ | View | ✓ | — |
| Warehouse | view/create/update/activate/deactivate | ✓ | — | View | ✓ |
| Vehicle Category | view/create/update | ✓ | View | — | — |
| Vehicle Brand | view/create/update | ✓ | View | — | — |
| Component Group | view/create/update/map | ✓ | View | — | — |
| Company | view/update | ✓ | — | — | — |

### 3.4 Tenant — Core VMS Operations

| Modul/Fitur | Action | Fleet Manager | Workshop Manager | Mechanic | Warehouse |
|---|---|---:|---:|---:|---:|
| Vehicle | view/create/update/assign/transfer/status.update | ✓ | View | View | — |
| Inspection | view/create/perform/submit/review | View | ✓ | Perform/Submit | — |
| Maintenance Policy | view/manage | ✓ | View | — | — |
| Maintenance Schedule | view/manage/convert_work_order | ✓ | ✓ | — | — |
| Maintenance Request | view/create/review/approve/reject/convert_work_order | ✓ | ✓ Approve | Create | — |
| Breakdown | view/report/review/resolve | ✓ Report/Resolve | ✓ Review | Report | — |
| Work Order | view/create/update/submit/approve/reject/assign/schedule/start/pause/complete/cancel/close/estimate | View | ✓ (hampir semua action) | Start/Pause/Complete-oriented sesuai assignment | — |
| Diagnosis / Maintenance Job | manage | — | ✓ | ✓ | — |
| Work Order External Service | create/complete/cancel | — | ✓ | — | — |
| Workshop Invoice | record/view/upload_payment/request_correction/verify_correction/request_cancellation/verify_cancellation/view_settlement_history | — | ✓ (verify — checker) | — | ✓ (record/upload — maker) |
| Worker | view/manage/assign | — | ✓ | View | — |
| Workspace | view/manage/reserve/block | — | ✓ | View | — |
| QC | view/perform/approve/reject | — | ✓ Approve/Reject | Perform (bukan mekanik yang sama dengan yang mengerjakan WO — lihat Aturan Bisnis) | — |
| Vehicle Release | perform | — | ✓ | — | — |
| Maintenance History | view | ✓ | ✓ | View | — |

**Aturan bisnis penting (Self-QC Block):** siapa pun pemegang permission `qc.perform`, sistem tetap **menolak secara struktural** jika worker yang sama sedang tercatat sebagai mekanik aktif (`WorkOrderMechanicAssignment` yang belum `unassigned_at`) pada Work Order yang sama — bukan berdasarkan nama role atau `worker_type`, melainkan pengecekan riwayat penugasan mekanik pada WO tersebut.

### 3.5 Tenant — Supply Chain (Inventory, Procurement, Partner)

| Modul/Fitur | Action | Warehouse | Procurement | Workshop Manager |
|---|---|---:|---:|---:|
| Product | view/create/update/delete | ✓ | View | — |
| Inventory | view/reserve/issue/return/adjust/stock_opname/scrap | ✓ | View | Reserve/Issue/Return (lewat WO) |
| Sparepart Sale | view/create/approve | ✓ | — | — |
| Stock Transfer | view/create/approve/dispatch/receive | ✓ | — | — |
| Purchase Request | view/create/submit/approve | View | ✓ | Create (bisa ditautkan ke WO) |
| RFQ / Quotation | view/manage/select | — | ✓ | — |
| Purchase Order | view/create/approve/issue | View | ✓ | — |
| Goods Receipt | view/create/post | ✓ | View | — |
| Partner | view/manage | View | ✓ | — |
| Used Part | view/inspect/dispose/approve | ✓ (maker) | — | ✓ (checker) |

### 3.6 Tenant — Tire, Component, Warranty

| Modul/Fitur | Action | Mechanic | Workshop Manager | Warehouse |
|---|---|---:|---:|---:|
| Tire | view/manage/install/rotate/inspect/remove/scrap/sell | ✓ Install/Rotate/Inspect/Remove | ✓ | View |
| Rim | view/manage | View | — | ✓ |
| Tire Retread | send/receive/inspect/approve | — | Inspect/Approve (checker) | Send/Receive (maker) |
| Tire Repair | send/receive/inspect/approve | — | Inspect/Approve (checker) | Send/Receive (maker) |
| Tire Scoring | calculate/finalize | Calculate | Finalize (berbeda actor dari yang menghitung — BD-4) | — |
| Tire Scoring Configuration | manage/publish | — | ✓ (publisher ≠ pembuat draft) | — |
| Component Asset | view/manage/install/remove/replace | ✓ | View | — |
| Warranty | view/manage | ✓ View | ✓ | — |
| Warranty Claim | create/review/approve | Create | Review/Approve | — |

### 3.7 Tenant — Configuration, Analytics, Intelligence

| Modul/Fitur | Action | Admin | Manajemen |
|---|---|---:|---:|
| Configuration | view | ✓ | — |
| Numbering / Document Template / Workflow | manage/publish(/simulate) | ✓ | — |
| Notification Rule | manage | ✓ | — |
| Configuration History | view | ✓ | — |
| Analytics (overview + 14 domain) | `analytics.<domain>.view` | — | ✓ |
| Analytics Export | export | — | ✓ |
| Intelligence (overview/vehicle/component/tire) | view | — | ✓ |
| Intelligence Recommendation | view/review/accept/reject/convert | — | ✓ (Fleet Manager biasanya) |

### 3.8 Tenant — Account (Billing-only, tetap dapat diakses saat SUSPENDED)

| Modul/Fitur | Action | Admin Tenant |
|---|---|---:|
| account.subscription | view | ✓ |
| account.contract | view | ✓ |
| account.invoice | view/download | ✓ |
| account.payment | view/submit | ✓ |
| company | view/update | ✓ |

---

## 4. Keterangan Notasi Tambahan (sesuai permintaan format)

- `✓` — role demo memiliki permission ini secara penuh.
- `View` — role demo hanya memiliki aksi `.view` untuk kelompok tersebut, bukan create/update/approve.
- `Create`, `Approve`, `Reject`, `Delete`, `Export` — role demo hanya memegang aksi spesifik yang disebut.
- `—` — role demo tidak memiliki permission ini pada seed data (bisa diberikan kapan saja oleh Admin melalui halaman Roles).
- **Tidak ada notasi `Unverified`** pada matriks ini karena seluruh permission di atas tervalidasi langsung terhadap `PermissionSeeder.php` dan penggunaannya di route middleware — cocok 100% tanpa penyimpangan (dikonfirmasi oleh riset cluster Identity/AccessControl).

---

## 5. Lapisan Akses yang Berlaku Bersamaan

Permintaan ke `/app/*` harus melewati **empat lapisan independen** sebelum berhasil (urutan pemeriksaan aktual di `routes/api/app.php`):

1. `tenant.scope` — memastikan token adalah token tenant (bukan platform) dan tenant aktif.
2. `subscription.access` — memblokir seluruh operasional (kecuali grup Account, Audit Log, dan Configuration) jika subscription tenant berstatus `SUSPENDED`.
3. `module:{CODE}` — memastikan tenant memiliki entitlement modul terkait (misalnya modul `TIRE` untuk mengakses menu Tire Management). **Access Management juga digerbang modul** — tenant baru yang belum diberi modul `ACCESS_MANAGEMENT` oleh platform tidak bisa mengelola user/role-nya sendiri sampai modul tersebut diaktifkan oleh Superadmin.
4. `permission:{name}` — pemeriksaan RBAC granular seperti dijabarkan di atas.

Data Scope (branch/workshop/warehouse) diterapkan **di dalam** controller (bukan middleware terpisah) sebagai lapisan kelima yang independen dari keempat lapisan di atas — seorang user bisa memiliki permission penuh tetapi tetap dibatasi hanya melihat/mengubah data pada cabang yang ditugaskan kepadanya.

---

## 6. Catatan Data Scope (ringkas — detail lengkap di User Guide bagian F)

| scope_type | Efek |
|---|---|
| `TENANT` | Tidak terbatas — melihat/mengubah seluruh data tenant. |
| `BRANCH` | Terbatas ke cabang yang ditugaskan; otomatis mencakup seluruh workshop & warehouse di bawah cabang tersebut. |
| `WORKSHOP` | Terbatas ke bengkel yang ditugaskan; mencakup warehouse di bawah bengkel tersebut. |
| `WAREHOUSE` | Terbatas ke gudang yang ditugaskan saja. |
| `OWN` | **Terdaftar di sistem namun belum berfungsi** — saat ini berperilaku sama seperti "tidak ada akses data" karena `DataScopeService` belum memiliki logika untuknya. Jangan gunakan sampai diperbaiki. |
