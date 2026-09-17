# OptiFleet — Platform Role & Permission Matrix

**Branch/commit:** `Improvement` @ `05dd372e5bfeba9b920d3bf2d8676b2e4a908f8d`

## 1. Sifat Akses Superadmin — Penting Dibaca Lebih Dulu

Sebelum membaca matriks di bawah, pahami tiga fakta berikut, seluruhnya dikonfirmasi langsung dari kode pada sesi ini:

1. **"Platform Superadmin" bukan hak istimewa di level kode** — ini hanyalah nama sebuah *role* yang, pada data seed demo, kebetulan disinkronkan dengan **seluruh** permission berscope `platform` (`Permission::where('scope','platform')->pluck('id')`). Secara teknis, seorang operator dapat membuat role platform lain dengan permission yang lebih sempit — sistem akan menegakkannya sama ketat.
2. **Tidak ada bypass permission untuk siapa pun.** `CheckPermission` middleware (`backend/app/Http/Middleware/CheckPermission.php`) memanggil `PermissionService::userHasPermission()` untuk **setiap** request tanpa pengecualian jenis user atau role apa pun — dikonfirmasi dengan membaca seluruh isi file middleware ini pada sesi ini.
3. **Superadmin tetap terikat batas struktural token-nya.** Token platform hanya membawa ability `platform` (bukan `tenant:{id}` apa pun), sehingga middleware `tenant.scope` (`EnsureTenantScope`) — yang mensyaratkan `hasTenant() AND NOT isPlatformUser()` — **selalu menolak** permintaan Superadmin ke rute `/app/*`. Ini berarti akses lintas-tenant Superadmin **terbatas ketat** pada rute `/platform/*` yang memang dirancang beroperasi lintas tenant (Tenant, Contract, Invoice, dst.) — Superadmin tidak pernah "menyamar" sebagai user tenant.

## 2. Matriks Permission Superadmin

| Modul | Fitur | Action | Permission | Scope Data | Dampak | Perlu Tenant Terpilih? | Bukti |
|---|---|---|---|---|---|:-:|---|
| Tenant | Lihat daftar tenant | View | `tenant.view` | Global | Tidak ada perubahan data | Tidak | `TenantController::index` |
| Tenant | Buat tenant baru | Create | `tenant.create` | Global | Membuat 1 baris `tenants` baru berstatus DRAFT | Tidak | `TenantController::store` |
| Tenant | Ubah data legal tenant | Update | `tenant.update` | Satu tenant | Mengubah data legal tenant tersebut (Backend Only, tanpa UI) | Ya | `TenantController::update` |
| Tenant | Aktifkan tenant | Activate | `tenant.activate` | Satu tenant | **Dampak lintas tenant:** tenant baru dapat login & (jika sudah punya entitlement) memakai modulnya | Ya | `TenantController::activate` |
| Tenant | Nonaktifkan tenant | Deactivate | `tenant.deactivate` | Satu tenant | **Dampak lintas tenant:** seluruh user tenant tersebut kehilangan kemampuan login (`isActive()` tenant menjadi false) | Ya | `TenantController::deactivate` |
| Tenant → User | Lihat/buat/ubah status user tenant | View/Create/Update | `user.view/create/update` | Satu tenant | Membuat akun baru / mengubah status aktif user tenant tersebut | Ya | `TenantUserController.php` |
| Tenant → User | Assign/revoke role user tenant | Assign | `user.assign` | Satu tenant | **Dampak besar tanpa UI** — mengubah hak akses user tenant tersebut sepenuhnya (hanya lewat API langsung) | Ya | `TenantUserController::assignRole` |
| Tenant → Entitlement | Grant/revoke modul | Configure | `entitlement.manage` | Satu tenant | **Dampak lintas tenant besar:** mencabut modul langsung menyembunyikan seluruh menu terkait bagi seluruh user tenant tersebut, seketika | Ya | `TenantEntitlementsTab.tsx` |
| Tenant → Capacity | Atur batas kuota | Configure | `entitlement.manage` | Satu tenant | Membatasi/melonggarkan jumlah maksimum resource (branch/user/vehicle/dst.) yang boleh dibuat tenant | Ya | `TenantCapacityTab.tsx` |
| Tenant → Custom Pricing | Kelola harga khusus | Create/Delete | `pricing.update` | Satu tenant | Mengubah harga yang berlaku untuk tenant tersebut pada kontrak berikutnya (Backend Only) | Ya | `TenantCustomPricingController.php` |
| Module Catalog | Kelola modul & dependency | View/Manage | `module.view/manage` | Global | **Dampak lintas seluruh tenant:** mengubah dependency modul dapat memengaruhi validasi Bundle/Contract di semua tenant | Tidak | `ModuleDependencyController.php` |
| Access Management | Kelola Platform User | View/Create/Update | `user.view/create/update` | Global (user platform) | Membuat/menonaktifkan akun sesama Superadmin/Specialist | Tidak | `PlatformUserController.php` |
| Access Management | Kelola Platform Role & Permission | View/Create/Update/Assign Permission | `role.view/create/update/assign_permission` | Global | **Dampak besar:** mengubah kumpulan permission suatu role langsung mengubah hak akses **seluruh** user platform yang memegang role tersebut | Tidak | `RoleController.php` |
| Audit Log | Lihat log lintas tenant | View | `audit.view` | Cross-tenant Aggregate | Tidak ada perubahan data | Tidak | `AuditLogController.php` |
| Bundle | Susun/publish bundle | Create/Update/Publish | `bundle.view/create/update/publish` | Global | **Dampak lintas tenant:** bundle yang dipublish membekukan snapshot modul — kontrak baru memakai versi ini, kontrak lama tidak berubah | Tidak | `BundleController.php` |
| Pricing | Kelola harga standar | Create/Publish | `pricing.view/create/publish` | Global (memengaruhi seluruh tenant tanpa custom pricing) | Mengubah harga yang berlaku untuk kontrak baru ke depan | Tidak | `PricingController.php` |
| Contract | Kelola siklus kontrak | Create/Submit/Approve/Reject/Terminate/Amend/Renew | `contract.*` | Satu tenant per kontrak | **Approve memicu otomatis** pembuatan Subscription+Billing+Invoice pertama; **Terminate membatalkan Subscription** | Ya (kontrak melekat ke satu tenant) | `ContractService.php` |
| Subscription | Suspend/Reactivate | Suspend/Reactivate | `subscription.suspend/reactivate` | Satu tenant | **Dampak lintas tenant besar:** Suspend mengunci seluruh fitur operasional tenant (kecuali Account/Audit/Configuration) | Ya | `SubscriptionController.php` |
| Billing | Lihat/generate manual | View/Generate | `billing.view/generate` | Satu tenant | Generate manual membuat billing+invoice di luar jadwal harian (Backend Only) | Ya | `BillingController.php` |
| Invoice | Lihat/unduh/batalkan | View/Export/Approve(void) | `invoice.view/void` | Satu tenant | Void membatalkan tagihan (ditolak jika sudah PAID) | Ya | `InvoiceController.php` |
| Payment | Verifikasi/tolak | Approve/Reject | `payment.verify/reject` | Satu tenant | **Dampak lintas tenant:** verifikasi penuh dapat langsung mereaktivasi Subscription yang SUSPENDED & memulihkan entitlement | Ya | `PaymentVerificationService.php` |
| Analytics ETL | Jalankan/retry/backfill | Configure/Import | `analytics.etl.run/retry/backfill` | Satu tenant atau seluruh tenant (parameter eksplisit, bukan konteks tersimpan) | Memproses ulang data warehouse tenant terkait (Backend Only) | Tergantung parameter | `EtlAdminController.php` |
| Intelligence Model | Aktivasi/retire model ML | Activate | `intelligence.model.activate/retire` | Global atau satu tenant (tergantung scope model) | **Dampak lintas tenant jika model GLOBAL:** mengaktifkan model baru langsung mengganti model yang melayani prediksi risiko kegagalan kendaraan bagi tenant yang belum punya model sendiri | Tergantung scope model | `ModelRegistryService::activate()` |

## 3. Distingsi yang Diminta Section 7

| Pertanyaan | Jawaban |
|---|---|
| Apakah Superadmin memiliki akses karena *role*? | Ya — secara data seed, tetapi ini bukan properti kode; role lain dengan permission lebih sempit akan diperlakukan sama |
| Apakah Superadmin memiliki *permission eksplisit*? | Ya — setiap aksi tetap diperiksa satu-satu lewat `permission:{name}` middleware, sama seperti user tenant |
| Apakah Superadmin *melewati* pemeriksaan permission? | **Tidak pernah** — dikonfirmasi tidak ada `Gate::before` atau logika bypass serupa di seluruh codebase |
| Apakah Superadmin tetap dibatasi tenant scope? | Ya, secara struktural — tidak bisa mengakses `/app/*` sama sekali (bukan soal permission, tapi soal ability token) |
| Apakah Superadmin memiliki akses lintas tenant? | Ya, tetapi **hanya untuk domain administratif/komersial** yang memang dirancang lintas tenant (Tenant, Contract, Invoice, Payment, Audit Log, Entitlement, Capacity) — bukan untuk data operasional tenant |

## 4. Permission yang TIDAK Dimiliki Siapa Pun Saat Ini

Tidak ditemukan permission untuk: menghapus Tenant, memulihkan Tenant, impersonasi/switch-tenant oleh Superadmin, mengelola Master Data global lewat UI, mengelola Configuration default platform lewat UI, mengelola Integration lewat UI. Bukan berarti "ditolak oleh RBAC" — melainkan **tidak ada route/permission yang didefinisikan sama sekali** untuk aksi-aksi tersebut.
