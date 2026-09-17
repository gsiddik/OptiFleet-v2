# USER GUIDE OPTIFLEET PLATFORM PORTAL

## A. Informasi Dokumen

| Item | Keterangan |
|---|---|
| Nama Dokumen | User Guide OptiFleet Platform Portal — Panduan Superadmin |
| Nama Aplikasi | OptiFleet |
| Jenis Portal | Platform Portal (`/platform/*`) |
| Role Utama | Platform Superadmin (dan role platform lain dengan permission lebih sempit, bila dibuat) |
| Branch/Commit | `Improvement` @ `05dd372e5bfeba9b920d3bf2d8676b2e4a908f8d` |
| Versi Dokumen | 1.0 |
| Tanggal | 16 September 2026 |
| Status | Final — berdasarkan pemeriksaan langsung source code, siap distribusi |
| Target Pembaca | Superadmin platform, tim onboarding Superadmin baru, Technical Support, QA, Product Team, auditor |
| Ruang Lingkup | Seluruh fungsi yang hanya dapat diakses lewat Platform Portal, plus prosedur umum (login, navigasi, dsb.) yang berlaku sama bagi Superadmin |
| Sumber Analisis | `backend/routes/api/platform.php` (dibaca penuh), seluruh controller `Api/Platform/*`, `frontend/src/pages/platform/**`, `frontend/src/layouts/PlatformLayout.tsx`, serta paket dokumentasi Tenant Guide (`docs/user-guide/OPTIFLEET_*`, commit `fccee05`) sebagai bahan evaluasi reuse |

## B. Revision History

| Versi | Tanggal | Perubahan | Penulis/Reviewer |
|---|---|---|---|
| 1.0 | 16 September 2026 | Penyusunan awal Platform Portal User Guide, mencakup evaluasi reuse penuh terhadap Tenant User Guide | Tim analisis teknis |

## C. Daftar Isi

- A. Informasi Dokumen
- B. Revision History
- C. Daftar Isi
- D. Pendahuluan
- E. Arsitektur Konseptual Penggunaan
- F. Perbedaan Platform Portal dan Tenant Portal
- G. Kewenangan dan Tanggung Jawab Superadmin
- H. Login, Logout, dan Keamanan Akun
- I. Navigasi Platform Portal
- J. Dashboard Platform
- K. Tenant/Customer Management & Siklus Komersial
  - K.1 Tenant Management
  - K.2 Bundle Management
  - K.3 Pricing Management
  - K.4 Contract Management
  - K.5 Subscription Management
  - K.6 Billing Management
  - K.7 Invoice Management
  - K.8 Payment Verification
- L. Tenant Context, Switch Tenant, atau Impersonation
- M. User Management
- N. Role and Permission Management
- O. Global Master Data
- P. Platform Configuration
- Q. Audit Log dan Activity History
- R. Notification Management
- S. Integration Management
- T. Report dan Monitoring Lintas Tenant
- U. Modul Operasional yang Juga Diakses Superadmin
- V. Troubleshooting
- W. FAQ
- X. Glossary
- Y. Lampiran
- 11. Panduan End-to-End

## D. Pendahuluan

**Tujuan OptiFleet:** platform manajemen armada & perawatan kendaraan berbasis SaaS multi-tenant — satu instalasi aplikasi melayani banyak perusahaan pelanggan (tenant) secara terisolasi.

**Tujuan Platform Portal:** menjadi kendali operasional **bisnis SaaS OptiFleet itu sendiri** — mengelola siapa (tenant mana) yang berlangganan, apa yang mereka bayar dan berhak pakai, memverifikasi pembayaran, serta mengawasi aktivitas administratif lintas tenant. Platform Portal **bukan** tempat mengoperasikan armada kendaraan — itu adalah tanggung jawab Tenant Portal milik masing-masing perusahaan pelanggan.

**Target Pengguna:** Platform Superadmin dan, bila dibuat oleh operator, role platform lain dengan cakupan lebih sempit (mis. "Billing Specialist" yang hanya memegang permission `invoice.*`/`payment.*`).

**Peran Superadmin:** administrator komersial dan teknis platform — bukan operator tenant. Superadmin memutuskan akses, memantau kepatuhan pembayaran, dan menjaga integritas katalog produk (Bundle/Pricing/Module), tetapi tidak pernah menyentuh data operasional harian tenant (kendaraan, Work Order, stok, dst.).

**Manfaat Dokumentasi:** menjadi rujukan tunggal dan mandiri bagi Superadmin baru maupun berpengalaman, tim Technical Support saat menjawab pertanyaan tenant, tim QA saat menguji fitur Platform, dan auditor saat memeriksa kepatuhan proses.

**Ruang Lingkup:** seluruh fitur yang benar-benar terbukti ada di `/platform/*`, plus prosedur umum (login, navigasi) yang berlaku identik bagi Superadmin.

**Batasan Dokumentasi:** dokumen ini **tidak** membahas cara mengoperasikan modul VMS (Vehicle, Work Order, dst.) karena Superadmin **tidak memiliki akses** ke sana (dibuktikan pada Bagian F, L, dan U) — untuk itu, rujuk `OPTIFLEET_USER_GUIDE.md` (Tenant Guide) yang ditujukan bagi pengguna tenant. Dokumen ini juga tidak menyertakan screenshot.

## E. Arsitektur Konseptual Penggunaan

- **Platform Portal** — antarmuka `/platform/*`, dipakai oleh **Superadmin** (dan role platform lain). Beroperasi lintas tenant untuk domain administratif/komersial.
- **Tenant Portal** — antarmuka `/app/*`, dipakai oleh **Tenant Administrator** dan **pengguna operasional** (Fleet Manager, Workshop Manager, Mechanic, Warehouse, dst.) di dalam satu perusahaan pelanggan.
- **Tenant/Customer** — satu perusahaan pelanggan OptiFleet; unit isolasi data utama.
- **Superadmin** — pengguna Platform Portal dengan permission administratif/komersial penuh atau sebagian.
- **Tenant Administrator** — pengguna tenant dengan hak kelola penuh atas tenant-nya sendiri (bukan diatur dokumen ini — lihat Tenant Guide).
- **Data Global** — data yang sama untuk seluruh sistem (mis. katalog Module, Bundle, Pricing standar).
- **Data Tenant** — data milik satu tenant saja (mis. Contract, Subscription, Invoice tenant tersebut).
- **Data Sharing** — data global dipakai bersama seluruh tenant sebagai referensi (mis. Pricing standar berlaku untuk semua tenant kecuali di-override lewat Tenant Custom Pricing).
- **Tenant Isolation** — jaminan struktural bahwa satu tenant tidak pernah melihat data operasional tenant lain; ditegakkan lewat scope query otomatis, pemeriksaan kepemilikan eksplisit di controller, dan foreign key gabungan di database (dijelaskan penuh di `OPTIFLEET_USER_GUIDE.md` Tenant Guide, berlaku sama karena ini bagian arsitektur inti, bukan spesifik satu portal).

## F. Perbedaan Platform Portal dan Tenant Portal

Lihat `OPTIFLEET_PLATFORM_TENANT_COMPARISON.md` untuk tabel perbandingan lengkap (dijadikan lampiran teknis agar dokumen utama ini tetap ringkas dibaca). Ringkasan inti:

| Aspek | Platform Portal | Tenant Portal |
|---|---|---|
| Fokus | Bisnis SaaS: tenant, kontrak, penagihan | Operasional armada: kendaraan, perawatan, stok |
| Data yang dikelola | Tenant, Kontrak, Subscription, Invoice, Payment, Module Catalog, Role/User platform | Vehicle, Work Order, Inventory, Tire, dst. |
| Modul operasional (Vehicle/WO/dst.) | **Tidak ada** | Tersedia penuh |
| Lintas tenant | Ya, untuk domain administratif | Tidak — satu tenant saja |
| Module Entitlement | Tidak berlaku untuk Platform Portal sendiri | Menggerbang seluruh menu |

## G. Kewenangan dan Tanggung Jawab Superadmin

**Fungsi utama:** (1) memutuskan tenant mana yang aktif berlangganan; (2) mengelola katalog produk (Module, Bundle, Pricing) yang dijual; (3) mengelola siklus kontrak-penagihan-pembayaran; (4) mengelola entitlement modul & kuota kapasitas per tenant; (5) mengelola akun & hak akses sesama pengguna platform; (6) mengawasi lewat audit log lintas tenant.

**Scope akses:** administratif dan komersial lintas tenant — **bukan** operasional dalam satu tenant.

**Tanggung jawab:** memastikan hanya tenant yang berhak (kontrak aktif & lunas) yang dapat memakai modul yang benar; menjaga integritas katalog Bundle/Pricing agar tidak merugikan tenant maupun bisnis; memverifikasi pembayaran secara cermat sebelum menekan **Verify** (aksi ini langsung mereaktivasi akses tenant); menjaga kerahasiaan kredensial akun platform sesama Superadmin.

**Batasan:** Superadmin **tidak dapat dan tidak dirancang untuk** mengakses data operasional tenant (lihat Bagian F, L, U) — bila Technical Support memerlukan pemeriksaan data operasional spesifik suatu tenant, itu berada di luar cakupan Platform Portal dan memerlukan prosedur terpisah (di luar aplikasi ini) yang tidak dibahas dokumen ini.

**Risiko:** banyak aksi Superadmin berdampak **seketika dan lintas tenant** (Suspend Subscription, cabut Module Entitlement, ubah permission Role) — lihat tabel risiko lengkap pada `OPTIFLEET_PLATFORM_BUSINESS_FLOW.md` §6.

**Prinsip kehati-hatian:** selalu periksa dua kali tenant/kontrak/invoice yang benar sebelum menekan tombol beraksi (tidak ada konfirmasi modal ganda pada sebagian besar aksi berisiko); gunakan permission granular (bukan selalu memberi seluruh permission platform) untuk anggota tim Superadmin baru sesuai prinsip *least privilege*.

**Kebutuhan audit:** setiap aksi penting tercatat otomatis ke Audit Log (Bagian Q) — namun Superadmin tetap harus secara aktif meninjaunya secara berkala; sistem tidak mengirim notifikasi proaktif atas aksi administratif.

## H. Login, Logout, dan Keamanan Akun

*(Prosedur ini identik dengan Tenant Portal — diverifikasi ulang pada sesi ini terhadap `AuthController.php` dan `LoginPage.tsx`, keduanya dipakai bersama oleh kedua portal. Klasifikasi reuse: **RWC**, lihat `OPTIFLEET_TENANT_GUIDE_REUSE_MATRIX.md` RU-01 s.d. RU-04.)*

**Login:**
1. Buka halaman Login (satu formulir, tidak ada pemilihan portal/tenant di awal).
2. Isi **Email** dan **Password** → klik **Sign In**.
3. Sistem memeriksa kredensial; **Hasil sukses:** token diterbitkan dengan ability `platform` (karena akun ini bertipe `user_type=platform`), dan pengguna diarahkan otomatis ke `/platform/dashboard`.
4. **Pesan galat yang mungkin muncul:** "The provided credentials are incorrect." (email/password salah); "This account has been deactivated." (akun berstatus `inactive`).

**Perbedaan dengan login Tenant Portal:** tidak ada — formulir dan endpoint (`POST /auth/login`) **sama persis**; perbedaan ability token (`platform` vs `tenant:{id}`) ditentukan otomatis oleh backend berdasarkan `user_type` akun, bukan pilihan di layar login.

**Logout:** klik **Logout** di header → mencabut **hanya token yang sedang dipakai pada perangkat ini** (`POST /auth/logout`), bukan seluruh sesi di perangkat lain.

**Session:** berbasis token Sanctum, tersimpan di `localStorage` browser. Tidak ditemukan mekanisme *expiry* otomatis berbasis waktu di kode — token berlaku sampai dicabut lewat logout.

**Perubahan/Reset Password:** **Tidak tersedia sama sekali** — dikonfirmasi lewat pencarian menyeluruh (`forgot password`, `reset password`, `change password`) terhadap seluruh backend dan frontend: nihil hasil. `PlatformUserController::update()` dan `TenantUserController::update()` hanya menerima perubahan `status`/`name`, **tidak pernah** field password. Password hanya pernah diisi **satu kali**, saat akun dibuat pertama kali oleh Superadmin (lewat formulir "+ New User"/"+ Add User"). **Penting bagi Technical Support:** jika seorang pengguna (platform maupun tenant) lupa password, **tidak ada jalur pemulihan mandiri maupun oleh admin lewat aplikasi ini** — lihat Bagian V (Troubleshooting) dan W (FAQ) untuk penjelasan implikasinya.

**Account Status:** akun berstatus `active`/`inactive`; hanya `active` yang dapat login. Superadmin dapat mengubah status akun platform lain lewat Bagian M.

**Keamanan Credential:** password di-hash (bcrypt), tidak pernah tercatat di Audit Log dalam bentuk apa pun. **Peringatan:** jangan pernah membagikan kredensial akun Superadmin ke pihak lain — karena tidak ada mekanisme reset, kredensial yang hilang berarti akun tersebut tidak dapat dipulihkan lewat aplikasi.

## I. Navigasi Platform Portal

*(Struktur berbeda dari Tenant Portal — Platform-Specific, lihat RU-05/RU-06/RU-07 pada Reuse Matrix.)*

**Sidebar:** daftar menu **datar** (tidak berkelompok seperti Tenant Portal), berisi 13 item, masing-masing hanya digerbang **permission** (tidak ada konsep Module Entitlement di level Platform): Dashboard, Tenant Management, Module Catalog, Bundles, Pricing, Contracts, Subscriptions, Billing, Invoices, Payments, Platform Users, Platform Roles, Audit Log.

**Header/Navbar:** menampilkan nama pengguna aktif dan tombol **Logout** di kanan atas. **Tidak ada** tenant-switcher, breadcrumb, atau banner suspensi (elemen-elemen ini murni konsep Tenant Portal, tidak relevan bagi Superadmin).

**Pencarian, Filter, Sorting, Pagination:** pola sama seperti Tenant Portal — kotak pencarian teks bebas, filter dropdown status (nilai mengikuti enum entitas tersebut), tabel dengan header dapat diklik untuk mengurutkan, dan navigasi halaman di bagian bawah tabel (dilayani backend, bukan dimuat sekaligus).

**Tab pada Halaman Detail:** dipakai pada Tenant Detail (4 tab: Overview, Users, Module Entitlements, Capacity Limits).

**Tombol Aksi:** hanya muncul bila status entitas & permission pengguna mengizinkan — pola identik Tenant Portal.

**Notifikasi & Pesan:** pesan sukses/galat ditampilkan inline pada formulir/halaman terkait. **Tidak ada** lonceng/kotak masuk notifikasi di Platform Portal (dan memang tidak ada mekanisme notifikasi apa pun yang menyasar Platform Portal — lihat Bagian R).

**Profile:** tidak ada halaman "My Profile" terpisah bagi Superadmin — nama & email ditampilkan di header, tidak dapat diubah sendiri lewat UI (hanya Superadmin lain dengan `user.update` dapat mengubah nama akun, lihat Bagian M).

**Perilaku Responsive:** Platform Portal memakai layout dasar yang sama dengan Tenant Portal, namun **tidak ditemukan** tombol toggle sidebar mobile ("☰ Menu") pada `PlatformLayout.tsx` seperti yang ada di `TenantLayout.tsx` — sidebar Platform tampaknya tidak memiliki penanganan khusus untuk layar sempit (Potential Defect ringan, dicatat di Findings).

## J. Dashboard Platform

#### 1. Kegunaan
Titik masuk setelah login; memberi ringkasan kondisi bisnis SaaS secara sekilas tanpa perlu membuka setiap menu satu per satu.

#### 2. Scope
Cross-tenant Aggregate — seluruh angka dihitung dari **seluruh** tenant sekaligus, bukan satu tenant terpilih.

#### 3. Role dan Permission

| Action | Role | Permission | Scope |
|---|---|---|---|
| View | Siapa pun yang login sebagai platform user | — (tanpa permission spesifik, hanya `platform.scope`) | Global |

#### 4. Fitur

| Fitur | Kegunaan | Input | Output | Dampak |
|---|---|---|---|---|
| Ringkasan Tenant | Jumlah total & aktif | — | `tenants_total`, `tenants_active` | Tidak ada |
| Ringkasan User | Jumlah user platform & tenant | — | `platform_users_total`, `tenant_users_total` | Tidak ada |
| Ringkasan Modul | Jumlah modul di katalog | — | `modules_total` | Tidak ada |
| Audit Log Terbaru | 10 aktivitas terakhir lintas tenant | — | Daftar ringkas | Tidak ada |
| Ringkasan Komersial | Subscription per status, kontrak akan berakhir, invoice outstanding/overdue, pembayaran menunggu verifikasi | — | Angka & daftar | Tidak ada |

#### 5. Data yang Digunakan
Data turunan (agregasi `COUNT`/`WHERE`) langsung dari tabel `tenants`, `users`, `modules`, `audit_logs`, `subscriptions`, `contracts`, `invoices`, `payments` — dihitung ulang setiap kali halaman dibuka (tidak di-cache).

#### 6. Prasyarat
Tidak ada — selalu tersedia begitu login berhasil.

#### 7. Jalur Akses
`Platform Portal → Dashboard` (`/platform/dashboard`)

#### 8. Prosedur Penggunaan
1. Login sebagai platform user → otomatis diarahkan ke halaman ini.
2. Baca ringkasan angka untuk memahami kondisi terkini (mis. berapa tenant aktif, berapa invoice yang telat dibayar).
3. Klik salah satu ringkasan (bila tertaut) untuk berpindah ke halaman detail terkait — **catatan:** berdasarkan pemeriksaan kode, kartu ringkasan pada dashboard bersifat tampilan statis (tidak seluruhnya berupa tautan aktif); untuk melihat detail, buka menu terkait secara manual dari sidebar.

#### 9. Penjelasan Field
Lihat kolom "Output" pada tabel Fitur di atas — seluruhnya angka `integer` hasil hitung, kecuali "Audit Log Terbaru" yang berupa daftar objek ringkas.

#### 10. Status dan Transisi
Tidak berlaku — halaman ini murni tampilan baca (read-only), tidak memiliki status sendiri.

#### 11. Aturan Bisnis
Seluruh angka dihitung real-time dari tabel transaksional saat halaman dimuat — tidak ada data warehouse/ETL yang terlibat di sini (berbeda dari Analytics tenant).

#### 12. Hubungan dengan Modul Lain
Mengagregasi data dari Tenant Management, Access Management, Module Catalog, Audit Log, dan seluruh modul Commercial (K.4–K.8).

#### 13. Risiko dan Peringatan
Tidak ada risiko — halaman ini tidak melakukan mutasi data apa pun.

#### 14. Hasil
Gambaran cepat kondisi bisnis SaaS untuk memprioritaskan pekerjaan hari itu (mis. berapa banyak pembayaran menunggu diverifikasi).

#### 15. Troubleshooting

| Masalah | Kemungkinan Penyebab | Pemeriksaan | Cara Mengatasi | Perlu Eskalasi |
|---|---|---|---|---|
| Angka terlihat janggal (mis. 0 di semua tempat) | Data memang masih kosong pada instalasi baru | Periksa langsung menu Tenant Management/Invoices | Ini normal untuk instalasi baru, bukan galat | Tidak |

#### 16. Perbedaan dengan Tenant Portal
Dashboard Tenant menampilkan ringkasan **satu tenant saja** (modul aktif, dsb.); Dashboard Platform menampilkan agregasi **seluruh tenant**. Dua kelas controller backend yang sepenuhnya terpisah.

#### 17. Sumber dari User Guide Tenant
Baru dibuat untuk Platform Portal — pola halaman (titik masuk pasca-login) sama, tetapi isi dan sumber data sepenuhnya berbeda (RWA, lihat RU-12).

---

## K. Tenant/Customer Management & Siklus Komersial

### K.1 Tenant Management

#### 1. Kegunaan
Mendaftarkan, mengaktifkan/menonaktifkan, dan memelihara data perusahaan pelanggan (tenant) — termasuk user awal, entitlement modul, dan kuota kapasitasnya.

#### 2. Scope
Platform (daftar) dan satu tenant terpilih (detail/aksi).

#### 3. Role dan Permission

| Action | Role | Permission | Scope |
|---|---|---|---|
| View | Superadmin | `tenant.view` | Global (daftar) / Satu tenant (detail) |
| Create | Superadmin | `tenant.create` | Global |
| Update (data legal) | Superadmin | `tenant.update` | Satu tenant — **Backend Only, tidak ada form UI** |
| Activate | Superadmin | `tenant.activate` | Satu tenant |
| Deactivate | Superadmin | `tenant.deactivate` | Satu tenant |
| Delete/Restore | — | — | **Tidak diimplementasikan** |

#### 4. Fitur

| Fitur | Kegunaan | Input | Output | Dampak |
|---|---|---|---|---|
| Daftar Tenant | Cari/filter/urutkan seluruh tenant | Kata kunci, status | Tabel tenant | Tidak ada |
| Buat Tenant | Mendaftarkan perusahaan baru | Code, Name | Tenant baru (status DRAFT) | Tidak ada dampak lain sampai diaktifkan |
| Tab Overview | Lihat data legal tenant | — | Legal Name, Industry, Created At | Tidak ada (read-only) |
| Activate/Deactivate | Mengubah status akses tenant | — | Status ACTIVE/INACTIVE | **Dampak lintas tenant:** mengunci/membuka kemampuan login seluruh user tenant |
| Tab Users | Kelola user tenant | Nama, Email, Password (create); status (update) | User baru / status berubah | User baru dapat login sesuai role yang nanti ditugaskan |
| Tab Module Entitlements | Grant/Revoke modul | Pilih modul, toggle | Entitlement aktif/tidak | Menu & endpoint terkait langsung muncul/hilang bagi tenant |
| Tab Capacity Limits | Atur kuota maksimum | Resource type, angka | `max_count` tersimpan | Membatasi kemampuan tenant menambah data baru jenis tersebut |

#### 5. Data yang Digunakan
Data master: `Tenant`. Data transaksi: `TenantUser`, `TenantModuleEntitlement`, `TenantCapacityLimit`. Data referensi: `Module` (katalog).

#### 6. Prasyarat
Tidak ada untuk membuat tenant baru. Untuk memberi entitlement modul secara bermakna, katalog `Module` harus sudah ada (sudah di-seed sejak awal instalasi).

#### 7. Jalur Akses
`Platform Portal → Tenant Management` (`/platform/tenants`) → klik baris tenant → `/platform/tenants/:id`

#### 8. Prosedur Penggunaan

**Mendaftarkan Tenant Baru:**
1. (Superadmin, tanpa perlu memilih tenant lain lebih dulu) Buka `Tenant Management` → klik **"+ New Tenant"**.
2. Isi **Code** (wajib, unik, contoh: `ACME`) dan **Name** (wajib, nama perusahaan).
3. Klik **Create Tenant**. **Validasi:** kode harus unik — bila sudah dipakai, muncul pesan galat di field Code.
4. **Hasil:** tenant baru tersimpan dengan **status DRAFT** — belum dapat dipakai siapa pun.
5. Klik baris tenant baru untuk membuka detailnya.
6. Pada tab **Overview**, klik tombol **Activate** (di header halaman, bersebelahan dengan badge status). **Hasil:** status berubah ke `ACTIVE`. **Prasyarat implisit yang disarankan** (tidak dipaksakan sistem): sebaiknya Kontrak & entitlement modul sudah/segera disiapkan agar tenant benar-benar dapat memakai aplikasi begitu user pertamanya dibuat.
7. Pindah ke tab **Users** → klik **"+ Add User"** → isi Nama, Email, Password → **Add User**. **Hasil:** akun user tenant pertama dibuat, berstatus `active`. **Penting:** akun ini belum memiliki role/permission apa pun — penugasan role dilakukan lewat Tenant Portal sendiri oleh user tersebut, atau lewat panggilan API langsung dari sisi Platform (lihat catatan Backend Only di bawah), **karena tab Users pada Platform Portal tidak menyediakan UI penugasan role**.
8. Pindah ke tab **Module Entitlements** → untuk setiap modul yang menjadi hak tenant ini (biasanya mengikuti isi Bundle pada kontraknya), klik **Grant**. **Hasil:** badge berubah dari "⬜ Not entitled" ke "✅ Active"; menu terkait langsung muncul di sidebar Tenant Portal tenant tersebut pada permintaan berikutnya.
9. (Opsional) pindah ke tab **Capacity Limits** → isi **Max Limit** untuk resource yang ingin dibatasi (kosongkan untuk *unlimited*) → klik **Save** per baris.

**Menonaktifkan Tenant:**
1. Buka detail tenant → klik **Deactivate** pada header. **Peringatan:** tidak ada dialog konfirmasi tambahan — klik langsung mengeksekusi. **Dampak lintas tenant:** seluruh user tenant tersebut langsung tidak dapat login sampai tenant diaktifkan kembali.

**Catatan/Peringatan penting — tidak ditemukan di UI:**
- **Mengubah data legal tenant** (Legal Name, Industry, Tax ID, Address, dst.) **tidak dapat dilakukan lewat tampilan mana pun** meski endpoint backend (`PUT /platform/tenants/{tenant}`) mendukungnya penuh — ini murni keterbatasan antarmuka saat ini, bukan pembatasan kewenangan.
- **Assign role ke user tenant dari sisi Platform tidak tersedia di UI** meski endpoint backend ada (`POST /platform/tenants/{tenant}/users/{tenantUser}/roles`) — penugasan role praktis harus dilakukan dari Tenant Portal (oleh Admin tenant sendiri).
- **Tidak ada tombol Delete/Restore** untuk Tenant di mana pun — status hanya dapat bergerak antara `ACTIVE` dan `INACTIVE` lewat UI ini.

#### 9. Penjelasan Field

| Field UI | Deskripsi | Tipe | Wajib | Sumber | Validasi | Contoh | Scope |
|---|---|---|---|---|---|---|---|
| Code | Kode unik tenant | string | Ya | Input Superadmin | `required, unique, max:50` | `ACME` | Global |
| Name | Nama perusahaan | string | Ya | Input Superadmin | `required, max:255` | `PT Alpha Fleet` | Global |
| Legal Name (Overview, read-only) | Nama badan hukum | string | — | Ditampilkan saja | — | `PT Alpha Fleet Indonesia` | Tenant-specific |
| Resource (Capacity) | Jenis resource dibatasi | enum | — | Sistem | `branch/user/vehicle/workshop/warehouse` | `vehicle` | Tenant-specific |
| Max Limit | Batas maksimum | integer/null | Tidak (kosong = unlimited) | Input Superadmin | `min:0` | `100` | Tenant-specific |

#### 10. Status dan Transisi

| Status | Arti | Pemicu | Actor | Status Berikutnya | Dampak |
|---|---|---|---|---|---|
| DRAFT | Baru dibuat, belum aktif | Create | Superadmin | ACTIVE (via tombol Activate) | Tenant tidak dapat dipakai sama sekali |
| ACTIVE | Tenant beroperasi normal | Activate | Superadmin | INACTIVE | User tenant dapat login (bila akun mereka juga aktif) |
| INACTIVE | Tenant dibekukan | Deactivate | Superadmin | ACTIVE | Seluruh login user tenant ditolak |
| ~~SUSPENDED~~ | *(terdaftar di enum database, tidak pernah dapat dicapai lewat UI atau kode aktif manapun — jangan bingung dengan `Subscription.status=SUSPENDED` yang sungguh-sungguh dipakai)* | — | — | — | — |

#### 11. Aturan Bisnis
Kode tenant unik secara global. Tenant baru selalu `DRAFT`, tidak pernah otomatis `ACTIVE`. Status hanya dapat berpindah lewat dua endpoint eksplisit (`activate`/`deactivate`) — `UpdateTenantRequest` bahkan tidak menerima field `status` sama sekali, sehingga status tidak dapat "tersasar" berubah lewat form edit data legal (bila form itu ada).

#### 12. Hubungan dengan Modul Lain
Tenant menjadi prasyarat bagi Contract (K.4), yang pada gilirannya membentuk Subscription (K.5) yang menggerbang seluruh modul operasional tenant tersebut. Module Entitlement dan Capacity Limit di sini bekerja independen dari siklus kontrak formal (dapat diubah kapan saja tanpa perlu amandemen kontrak).

#### 13. Risiko dan Peringatan
**Peringatan:** Deactivate tenant berdampak seketika ke seluruh usernya tanpa peringatan dini otomatis dari sistem ke tenant tersebut. **Dampak lintas tenant:** mencabut Module Entitlement yang sedang aktif dipakai (mis. tenant punya Work Order berjalan di modul WORK_ORDER) langsung memutus akses tampilan modul tersebut — data tidak hilang, tapi tidak dapat diakses sampai entitlement diberikan kembali.

#### 14. Hasil
Tenant baru siap dipakai; entitlement & kuota sesuai kebutuhan bisnis; status tenant mencerminkan keputusan komersial terkini.

#### 15. Troubleshooting

| Masalah | Kemungkinan Penyebab | Pemeriksaan | Cara Mengatasi | Perlu Eskalasi |
|---|---|---|---|---|
| Tenant baru tidak bisa dipakai penggunanya | Status masih DRAFT | Cek badge status di header Tenant Detail | Klik Activate | Tidak |
| Menu tertentu tidak muncul di Tenant Portal tenant | Modul terkait belum di-Grant | Cek tab Module Entitlements | Grant modul yang relevan | Tidak |
| Tidak bisa mengubah Legal Name/Industry tenant | Fitur belum ada di UI (Backend Only) | — | Sampaikan ke tim teknis untuk pembaruan lewat API langsung bila mendesak | Ya, jika mendesak |
| Ingin menugaskan role ke user tenant dari Platform | UI tidak tersedia | — | Minta Admin tenant menugaskan role dari Tenant Portal mereka sendiri | Tidak |

#### 16. Perbedaan dengan Tenant Portal
Tenant Portal tidak memiliki konsep ini sama sekali (tenant tidak bisa "melihat tenant lain"). Sebagian isi (Company Profile) berkorespondensi terbalik: tenant mengedit profilnya sendiri lewat Tenant Portal; Superadmin mengedit profil tenant lain lewat sini (namun UI-nya belum ada — Backend Only).

#### 17. Sumber dari User Guide Tenant
Baru dibuat untuk Platform Portal — tidak ada padanan langsung di Tenant Guide selain referensi silang ke Company Profile (RU-18).

---

### K.2 Bundle Management

#### 1. Kegunaan
Menyusun paket jual (kelompok modul) yang ditawarkan ke tenant, dengan mekanisme versi yang dibekukan saat dipublikasikan agar kontrak lama tidak terganggu oleh perubahan komposisi bundle di kemudian hari.

#### 2. Scope
Global.

#### 3. Role dan Permission

| Action | Role | Permission | Scope |
|---|---|---|---|
| View | Superadmin | `bundle.view` | Global |
| Create/Update | Superadmin | `bundle.create`/`bundle.update` | Global |
| Publish | Superadmin | `bundle.publish` | Global |

#### 4. Fitur

| Fitur | Kegunaan | Input | Output | Dampak |
|---|---|---|---|---|
| Daftar Bundle | Lihat seluruh bundle & statusnya | — | Tabel (code/name/jumlah modul/status) | Tidak ada |
| Buat Bundle | Mendaftarkan bundle baru | Code, Name, Description | Bundle baru (DRAFT) | Tidak ada |
| Susun Komposisi | Pilih modul anggota bundle | Checklist modul | Komposisi tersimpan | Hanya berlaku selagi status DRAFT |
| Publish | Membekukan versi & mengaktifkan bundle | — | `BundleVersion` baru, status PUBLISHED | **Dampak lintas tenant:** kontrak baru ke depan memakai versi ini |

#### 5. Data yang Digunakan
Data master: `Module` (katalog). Data transaksi: `Bundle`, `bundle_modules` (komposisi hidup), `BundleVersion`+`bundle_version_modules` (snapshot beku per versi).

#### 6. Prasyarat
Modul-modul yang ingin dimasukkan ke bundle harus sudah terdaftar di Module Catalog, dan seluruh dependency modulnya (bila ada) turut disertakan.

#### 7. Jalur Akses
`Platform Portal → Bundles` (`/platform/bundles`) → `/platform/bundles/:id`

#### 8. Prosedur Penggunaan
1. `Bundles` → **"+ New Bundle"** → isi Code, Name, Description → **Create**.
2. Buka detail bundle → centang modul-modul anggota pada grid checkbox → klik **"Save Composition"**.
3. **Validasi otomatis:** sistem memeriksa "missing dependencies" — bila modul yang dipilih bergantung pada modul lain yang belum ikut dicentang, banner merah akan muncul menyebutkan modul yang kurang. **Publish akan ditolak** selama banner ini masih tampil.
4. Setelah komposisi lengkap, klik **"Publish New Version"**. **Hasil:** versi baru (`BundleVersion`) dibuat dengan komposisi modul dibekukan persis seperti saat itu; status Bundle berubah ke `PUBLISHED`.
5. **Penting:** setelah `PUBLISHED`, komposisi (`syncModules`) **tidak dapat diubah lagi** — mengedit bundle yang sudah dipublikasikan berarti harus mempublikasikan **versi baru** (checkbox tetap dapat dicentang ulang di UI, tetapi backend akan menolak simpan dengan pesan galat; UI saat ini tidak menonaktifkan checkbox untuk kondisi ini, lihat Findings).

#### 9. Penjelasan Field

| Field UI | Deskripsi | Tipe | Wajib | Sumber | Validasi | Contoh | Scope |
|---|---|---|---|---|---|---|---|
| Code | Kode unik bundle | string | Ya | Input | `required, unique, max:50` | `OPTIFLEET_BASIC` | Global |
| Name | Nama tampilan bundle | string | Ya | Input | `required` | `OptiFleet Basic` | Global |
| Modul anggota | Daftar modul dalam bundle | multi-select | Ya (min. 1 untuk publish) | Pilihan dari Module Catalog | Harus lolos pemeriksaan dependency | `VEHICLE, INSPECTION` | Global |

#### 10. Status dan Transisi

| Status | Arti | Pemicu | Actor | Status Berikutnya | Dampak |
|---|---|---|---|---|---|
| DRAFT | Komposisi masih dapat diedit bebas | Create | Superadmin | PUBLISHED | Belum dapat dipakai kontrak |
| PUBLISHED | Versi dibekukan, siap dipakai kontrak baru | Publish | Superadmin | *(tidak ada jalur balik ke DRAFT)* | Kontrak baru dapat memilih bundle ini |
| ~~ARCHIVED~~ | *(ada di enum, tidak pernah di-set oleh kode manapun — dead value)* | — | — | — | — |

#### 11. Aturan Bisnis
Publish memvalidasi seluruh dependency modul terpenuhi sebelum berhasil. Versi lama tetap dipertahankan utuh — kontrak yang sudah memakai versi lama tidak pernah terpengaruh publikasi versi baru.

#### 12. Hubungan dengan Modul Lain
Bundle dipilih sebagai item pada Contract (K.4); saat Subscription tenant aktif, modul-modul dalam `BundleVersion` yang dipilih itulah yang diberikan sebagai Module Entitlement (K.1).

#### 13. Risiko dan Peringatan
**Peringatan:** kesalahan komposisi modul pada bundle yang baru dipublikasikan akan terbawa ke **setiap** kontrak baru yang memilihnya sampai diperbaiki lewat versi berikutnya.

#### 14. Hasil
Katalog produk siap ditawarkan lewat Contract.

#### 15. Troubleshooting

| Masalah | Kemungkinan Penyebab | Pemeriksaan | Cara Mengatasi | Perlu Eskalasi |
|---|---|---|---|---|
| Publish gagal/ditolak | Ada dependency modul yang belum dicentang | Lihat banner merah "missing dependencies" | Centang modul yang disebutkan lalu simpan ulang | Tidak |
| Tidak bisa mengubah komposisi bundle lama | Bundle sudah PUBLISHED | Cek status bundle | Buat/publikasikan versi baru, bukan mengedit yang lama | Tidak |

#### 16. Perbedaan dengan Tenant Portal
Tidak ada padanan — konsep ini murni Platform.

#### 17. Sumber dari User Guide Tenant
Baru dibuat untuk Platform Portal (Platform-Specific, RU-23).

---

### K.3 Pricing Management

#### 1. Kegunaan
Menetapkan harga standar untuk setiap modul/bundle/add-on/kapasitas per frekuensi tagihan, dengan riwayat versi harga yang tidak pernah ditimpa.

#### 2. Scope
Global (harga khusus per tenant ditangani terpisah, lihat catatan di bawah).

#### 3. Role dan Permission

| Action | Role | Permission | Scope |
|---|---|---|---|
| View | Superadmin | `pricing.view` | Global |
| Create | Superadmin | `pricing.create` | Global |
| Publish versi baru | Superadmin | `pricing.publish` | Global |
| Kelola harga khusus tenant | Superadmin | `pricing.update` | Satu tenant — **Backend Only, tidak ada UI** |

#### 4. Fitur

| Fitur | Kegunaan | Input | Output | Dampak |
|---|---|---|---|---|
| Daftar Pricing | Lihat seluruh daftar harga & versi aktif | — | Tabel | Tidak ada |
| Buat Pricing baru | Menetapkan harga awal suatu priceable | Tipe, Kode, Metode, Frekuensi, Amount, Tanggal berlaku | Pricing + versi pertama (ACTIVE) | Tidak ada dampak ke kontrak lama |
| Terbitkan versi baru | Mengubah harga mulai tanggal tertentu | Amount baru, Tanggal berlaku | Versi baru ACTIVE, versi lama ditutup otomatis | Kontrak **baru** memakai harga baru; item kontrak lama tidak berubah (harga sudah dibekukan saat ditambahkan) |

#### 5. Data yang Digunakan
Data transaksi: `Pricing`, `PricingVersion`, `TenantCustomPricing` (Backend Only).

#### 6. Prasyarat
Tidak ada.

#### 7. Jalur Akses
`Platform Portal → Pricing` (`/platform/pricing`)

#### 8. Prosedur Penggunaan
1. `Pricing` → **"+ New Pricing"** → pilih **Priceable Type** (MODULE/BUNDLE/ADD_ON/CAPACITY), isi **Code** (harus cocok dengan kode modul/bundle terkait), **Pricing Method**, **Billing Frequency**, **Amount**, **Effective From** → **Create**.
2. Untuk menaikkan/menurunkan harga di kemudian hari: pada baris Pricing terkait, klik **"New Version"** → isi Amount baru & Effective From → **Save**. **Hasil:** versi lama otomatis ditutup (`effective_until` diisi sehari sebelum versi baru mulai berlaku) sehingga tidak ada tumpang tindih periode.
3. **Harga Khusus Tenant (Custom Pricing):** fitur ini **ada di backend** (memprioritaskan di atas harga standar untuk satu tenant tertentu) tetapi **tidak memiliki halaman di Platform Portal manapun** — saat ini hanya dapat diatur lewat pemanggilan API langsung oleh tim teknis.

#### 9. Penjelasan Field

| Field UI | Deskripsi | Tipe | Wajib | Sumber | Validasi | Contoh | Scope |
|---|---|---|---|---|---|---|---|
| Priceable Type | Jenis objek yang diberi harga | enum | Ya | Pilihan | `MODULE/BUNDLE/ADD_ON/CAPACITY` | `MODULE` | Global |
| Pricing Method | Cara hitung harga | enum | Ya | Pilihan | `FLAT/PER_VEHICLE/.../TIERED/CUSTOM` | `PER_VEHICLE` | Global |
| Billing Frequency | Siklus tagihan | enum | Ya | Pilihan | `MONTHLY/QUARTERLY/SEMIANNUAL/ANNUAL/CUSTOM` | `MONTHLY` | Global |
| Amount | Nominal harga | decimal | Ya | Input | `required, numeric` | `50000` | Global |

#### 10. Status dan Transisi

| Status | Arti | Pemicu | Actor |
|---|---|---|---|
| Pricing: DRAFT/ACTIVE/ARCHIVED | Status daftar harga induk | Otomatis ACTIVE saat versi pertama dipublikasikan | Sistem |
| PricingVersion: DRAFT/ACTIVE/EXPIRED | Status satu versi harga | Publish versi baru menutup versi lama | Superadmin |

#### 11. Aturan Bisnis
Prioritas resolusi harga saat kontrak dibuat: **TenantCustomPricing (bila ada & berlaku) > Pricing standar aktif**. Satu kombinasi (tipe, kode, frekuensi) hanya boleh punya satu baris Pricing (unique constraint).

#### 12. Hubungan dengan Modul Lain
Dipakai sebagai sumber harga saat menambah item pada Contract (K.4) — harga dibekukan ke `ContractItem` saat itu juga, tidak pernah berubah lagi meski Pricing standarnya berubah kemudian.

#### 13. Risiko dan Peringatan
**Catatan:** menerbitkan versi harga baru tidak berdampak pada kontrak yang sudah berjalan — hanya kontrak baru yang terkena.

#### 14. Hasil
Daftar harga siap dipakai saat menyusun Contract.

#### 15. Troubleshooting

| Masalah | Kemungkinan Penyebab | Pemeriksaan | Cara Mengatasi | Perlu Eskalasi |
|---|---|---|---|---|
| Tidak bisa memberi harga khusus satu tenant | UI belum tersedia | — | Ajukan ke tim teknis untuk pengaturan lewat API | Ya |

#### 16. Perbedaan dengan Tenant Portal
Tidak ada padanan — murni Platform. Tenant hanya melihat hasil akhirnya (nominal tagihan) lewat Account → Invoices di Tenant Portal.

#### 17. Sumber dari User Guide Tenant
Baru dibuat untuk Platform Portal (Platform-Specific, RU-24).

---

### K.4 Contract Management

#### 1. Kegunaan
Mengelola perjanjian komersial resmi dengan tenant — dasar penagihan dan pemberian entitlement modul.

#### 2. Scope
Satu tenant per kontrak.

#### 3. Role dan Permission

| Action | Role | Permission | Scope |
|---|---|---|---|
| View | Superadmin | `contract.view` | Satu tenant |
| Create | Superadmin | `contract.create` | Satu tenant |
| Submit | Superadmin | `contract.submit` | Satu tenant |
| Approve/Reject | Superadmin | `contract.approve` | Satu tenant |
| Amend | Superadmin | `contract.amend` | Satu tenant |
| Renew | Superadmin | `contract.renew` | Satu tenant |
| Terminate | Superadmin | `contract.terminate` | Satu tenant |

#### 4. Fitur

| Fitur | Kegunaan | Input | Output | Dampak |
|---|---|---|---|---|
| Daftar & Detail Kontrak | Kelola siklus kontrak | Tenant, item (Bundle/Module/Add-on/Capacity), billing cycle, termin, grace period | Kontrak dengan status berjalan | — |
| Amandemen | Ubah komposisi kontrak aktif | Tambah/hapus item | Kontrak termodifikasi + proration bila relevan | Entitlement disesuaikan otomatis setelah amandemen disetujui |
| Perpanjangan | Sambung kontrak yang berakhir | — | Kontrak **baru** | Kontrak lama tidak berubah |
| Terminate | Hentikan kontrak | Alasan | Status TERMINATED, Subscription CANCELLED | **Dampak lintas tenant besar, tidak dapat dibatalkan** |

#### 5. Data yang Digunakan
Data transaksi: `Contract`, `ContractItem`, `ContractApproval`, `ContractAmendment`+`ContractAmendmentItem`. Data referensi: `Tenant`, `BundleVersion`, `PricingVersion`.

#### 6. Prasyarat
Tenant harus sudah terdaftar; Bundle/Pricing yang ingin dipakai sebagai item harus sudah tersedia.

#### 7. Jalur Akses
`Platform Portal → Contracts` (`/platform/contracts`) → `/platform/contracts/:id`

#### 8. Prosedur Penggunaan

**Membuat & Menyetujui Kontrak:**
1. `Contracts` → **"+ New Contract"** → pilih **Tenant**, isi **Billing Cycle**, **Start/End Date**, **Payment Terms Days** (default 14), **Grace Period Days** (default 7), centang/hilangkan **"Activation requires payment"** (default tercentang).
2. Tambahkan baris item: **Product Type** (BUNDLE/MODULE/ADD_ON/CAPACITY/SETUP_FEE/OTHER), **Product Reference** (kode Bundle/Module terkait), **Billing Frequency**, **Description**, **Qty**, **Unit Price** (dapat dikosongkan untuk diisi otomatis dari Pricing aktif), **Discount**, **Tax %**.
3. Klik **Create** → status `DRAFT`.
4. Buka detail kontrak → klik **"Submit for Approval"** → status `PENDING_APPROVAL`.
5. Klik **"Approve"** (isi catatan opsional). **Hasil seketika dan otomatis:** status → `APPROVED`; sistem membuat **Subscription** (K.5) berstatus `PENDING`, **Billing** pertama, dan **Invoice** pertama dalam satu transaksi. Bila "Activation requires payment" **tidak** dicentang, Subscription langsung `ACTIVE` dan entitlement modul langsung diberikan; bila tercentang (default), tenant harus melunasi invoice pertama dahulu (lihat K.8).
6. Alternatif: klik **"Reject"** (isi catatan) → status `REJECTED` (akhir untuk draft ini).

**Amandemen (mengubah kontrak aktif):**
1. Pada kontrak berstatus `ACTIVE` (tombol muncul untuk status ini pada UI, meski backend juga mengizinkan dari `APPROVED`), klik **"Create Amendment"**.
2. **"Add Item"** untuk menambah modul/add-on baru, atau **pilih item existing untuk dihapus (Remove)**. **Validasi penting:** menghapus modul yang menjadi dependency modul lain yang masih aktif pada kontrak ini akan **ditolak** — sistem menyebutkan modul mana yang memblokir.
3. **"Submit for Approval"** → **"Approve"**. **Hasil:** item lama ditutup/item baru dibuat efektif sesuai tanggal amandemen; bila periode tagihan sedang berjalan, selisih hari dihitung proporsional (proration) dan menghasilkan invoice penyesuaian terpisah; entitlement modul tenant otomatis disesuaikan.

**Perpanjangan (Renew):**
1. Pada kontrak `ACTIVE`/`EXPIRING`, klik **"Renew"** → item kontrak lama ditampilkan dapat diedit (qty/harga) sebelum disimpan → **Create Renewal**. **Hasil:** kontrak **baru** dibuat (tanggal mulai menyambung tanggal akhir kontrak lama), harus melalui alur submit→approve sendiri. Kontrak lama **tidak diubah sama sekali**.

**Menghentikan Kontrak (Terminate):**
1. Pada kontrak `ACTIVE`/`APPROVED`/`EXPIRING`, klik tombol **Terminate** (ditampilkan berwarna merah) → isi alasan. **Peringatan:** aksi ini **tidak dapat dibatalkan** — akan langsung membatalkan (`CANCELLED`) Subscription terkait, mengunci seluruh akses operasional tenant. Untuk melanjutkan layanan, harus dibuatkan kontrak baru dari awal.

#### 9. Penjelasan Field

| Field UI | Deskripsi | Tipe | Wajib | Sumber | Validasi | Contoh | Scope |
|---|---|---|---|---|---|---|---|
| Tenant | Pemilik kontrak | relasi | Ya | Pilihan | `required, exists` | — | Global→Tenant |
| Billing Cycle | Siklus tagihan | enum | Ya | Pilihan | — | `MONTHLY` | Tenant-specific |
| Payment Terms Days | Batas hari jatuh tempo invoice | integer | Tidak | Input | default 14 | `14` | Tenant-specific |
| Grace Period Days | Masa tenggang sebelum suspend | integer | Tidak | Input | default 7 | `7` | Tenant-specific |
| Activation Requires Payment | Apakah butuh bayar dulu sebelum aktif | boolean | Tidak | Checkbox | default true | `true` | Tenant-specific |
| Product Type (item) | Jenis item kontrak | enum | Ya | Pilihan | `in:` 6 nilai | `MODULE` | — |
| Quantity | Kuantitas item | decimal | Ya | Input | `min:0.01` | `1` | — |
| Tax % | Persentase pajak baris | decimal | Tidak | Input | `0-100` | `11` | — |

#### 10. Status dan Transisi

| Status | Arti | Pemicu | Actor | Status Berikutnya |
|---|---|---|---|---|
| DRAFT | Sedang disusun | Create | Superadmin | PENDING_APPROVAL, CANCELLED |
| PENDING_APPROVAL | Menunggu persetujuan | Submit | Superadmin | APPROVED, REJECTED |
| APPROVED | Disetujui, Subscription dibuat | Approve | Superadmin | ACTIVE (otomatis via Subscription) |
| ACTIVE | Berjalan normal | Otomatis (Subscription aktif) | Sistem | EXPIRING, TERMINATED |
| EXPIRING | Mendekati tanggal berakhir (≤30 hari) | Evaluasi otomatis harian | Sistem | EXPIRED, TERMINATED |
| EXPIRED | Sudah lewat tanggal berakhir | Evaluasi otomatis harian | Sistem | — |
| REJECTED | Ditolak saat review | Reject | Superadmin | — (akhir) |
| TERMINATED | Dihentikan paksa | Terminate | Superadmin | — (akhir, tidak dapat dibatalkan) |

#### 11. Aturan Bisnis
Total kontrak (`subtotal/discount/tax/total`) **selalu dihitung ulang di server** dari baris item — tidak pernah dipercaya dari input klien. Persetujuan kontrak dikunci dengan row-lock database untuk mencegah dua persetujuan bersamaan memicu dua Subscription. Amandemen yang menghapus dependency modul aktif ditolak otomatis.

#### 12. Hubungan dengan Modul Lain
Contract → Subscription (K.5, otomatis saat Approve) → Billing (K.6) → Invoice (K.7) → Payment (K.8). Item kontrak mereferensikan `BundleVersion` (K.2, versi beku) dan `PricingVersion` (K.3, harga beku).

#### 13. Risiko dan Peringatan
**Dampak lintas tenant:** Approve memicu efek berantai otomatis (Subscription+Billing+Invoice) — pastikan seluruh item kontrak benar sebelum menekan Approve, karena total dihitung ulang tapi item yang salah tetap akan tertagih. **Peringatan:** Terminate tidak dapat dibatalkan.

#### 14. Hasil
Kontrak `ACTIVE` menjadi dasar sah bagi Subscription tenant untuk memakai modul yang dikontrakkan.

#### 15. Troubleshooting

| Masalah | Kemungkinan Penyebab | Pemeriksaan | Cara Mengatasi | Perlu Eskalasi |
|---|---|---|---|---|
| Approve gagal | Kontrak bukan status PENDING_APPROVAL, atau race dua approval bersamaan | Cek status terkini | Refresh & coba ulang | Tidak |
| Amandemen hapus item ditolak | Item tersebut adalah dependency modul lain yang masih aktif | Baca pesan galat, sebutkan modul pemblokir | Hapus/nonaktifkan dulu modul yang bergantung, baru hapus item ini | Tidak |
| Kontrak baru tidak kunjung ACTIVE | Subscription masih PENDING menunggu pembayaran pertama | Cek K.5/K.8 | Verifikasi pembayaran tenant | Tidak |

#### 16. Perbedaan dengan Tenant Portal
Tenant hanya dapat **melihat** kontraknya sendiri (read-only) lewat `Account → Contract` di Tenant Portal — seluruh aksi mutasi (submit/approve/amend/renew/terminate) murni kewenangan Platform.

#### 17. Sumber dari User Guide Tenant
Baru dibuat untuk Platform Portal (Platform-Specific, RU-25) — sisi baca-saja tenant sudah ada di Tenant Guide Bagian I.22.

---

### K.5 Subscription Management

#### 1. Kegunaan
Mengendalikan status akses operasional tenant secara langsung — di luar/di atas siklus kontrak formal, untuk kasus seperti penanganan tunggakan atau pemulihan darurat.

#### 2. Scope
Satu tenant.

#### 3. Role dan Permission

| Action | Role | Permission | Scope |
|---|---|---|---|
| View | Superadmin | `subscription.view` | Satu tenant |
| Suspend | Superadmin | `subscription.suspend` | Satu tenant |
| Reactivate | Superadmin | `subscription.reactivate` | Satu tenant |

#### 4. Fitur

| Fitur | Kegunaan | Input | Output | Dampak |
|---|---|---|---|---|
| Daftar Subscription | Pantau status seluruh tenant | Filter status | Tabel | Tidak ada |
| Suspend manual | Menghentikan akses operasional tenant | Alasan | Status SUSPENDED | **Dampak lintas tenant besar, seketika** |
| Reactivate manual | Memulihkan akses | — | Status ACTIVE, entitlement dipulihkan | Tenant dapat memakai modul lagi |

#### 5. Data yang Digunakan
Data transaksi: `Subscription`.

#### 6. Prasyarat
Kontrak harus sudah `APPROVED` (Subscription dibuat otomatis pada titik ini).

#### 7. Jalur Akses
`Platform Portal → Subscriptions` (`/platform/subscriptions`)

#### 8. Prosedur Penggunaan
1. Buka daftar, filter berdasarkan tab status (PENDING/ACTIVE/PAST_DUE/GRACE_PERIOD/SUSPENDED/dst.).
2. Untuk menangguhkan tenant secara manual (di luar proses dunning otomatis): klik **"Suspend"** pada baris berstatus ACTIVE/PAST_DUE/GRACE_PERIOD → isi **alasan** (wajib) → **Hasil:** status `SUSPENDED`, seluruh fitur operasional tenant terkunci seketika (kecuali Account/Audit Log/Configuration di sisi tenant).
3. Untuk memulihkan: klik **"Reactivate"** pada baris `SUSPENDED` (tanpa modal tambahan) → **Hasil:** status `ACTIVE`, entitlement modul dipulihkan otomatis (dari isi kontrak aktifnya).

**Catatan penting:** proses normal (tunggakan pembayaran) berjalan **otomatis** lewat scheduled command harian (lihat `OPTIFLEET_PLATFORM_BUSINESS_FLOW.md` §2) — Suspend/Reactivate manual di sini biasanya dipakai untuk kasus di luar alur normal tersebut (mis. pelanggaran kebijakan, permintaan khusus tenant).

#### 9. Penjelasan Field

| Field UI | Deskripsi | Tipe | Wajib | Sumber | Validasi | Contoh | Scope |
|---|---|---|---|---|---|---|---|
| Alasan (Suspend) | Catatan alasan penangguhan | text | Ya | Input | `required` | "Permintaan tenant" | Tenant-specific |

#### 10. Status dan Transisi

| Status | Arti | Pemicu | Actor |
|---|---|---|---|
| PENDING | Menunggu aktivasi pertama | Approve kontrak | Sistem |
| ACTIVE | Beroperasi normal | Aktivasi/Reactivate/Verifikasi bayar lunas | Sistem/Superadmin |
| EXPIRING | Mendekati akhir periode kontrak | Evaluasi otomatis | Sistem |
| PAST_DUE | Ada invoice lewat jatuh tempo | Evaluasi otomatis harian | Sistem |
| GRACE_PERIOD | Masa tenggang sebelum suspend | Evaluasi otomatis harian | Sistem |
| SUSPENDED | Akses operasional terkunci | Evaluasi otomatis (lewat grace) / manual | Sistem/Superadmin |
| EXPIRED | Kontrak sudah berakhir | Evaluasi otomatis | Sistem |
| CANCELLED | Kontrak diterminasi | Terminate Contract | Superadmin (via K.4) |

#### 11. Aturan Bisnis
Reaktivasi otomatis oleh sistem hanya terjadi bila invoice **lunas penuh** (bukan sebagian) diverifikasi. Reactivate manual oleh Superadmin tidak memeriksa status pembayaran — murni keputusan administratif.

#### 12. Hubungan dengan Modul Lain
Status Subscription menggerbang seluruh middleware `subscription.access` di sisi Tenant Portal — perubahan di sini berefek langsung ke pengalaman seluruh user operasional tenant tersebut.

#### 13. Risiko dan Peringatan
**Dampak lintas tenant:** Suspend manual sama kerasnya dengan suspend otomatis dunning — seluruh fitur operasional terkunci seketika.

#### 14. Hasil
Status Subscription mencerminkan hak akses operasional tenant yang berlaku saat itu.

#### 15. Troubleshooting

| Masalah | Kemungkinan Penyebab | Pemeriksaan | Cara Mengatasi | Perlu Eskalasi |
|---|---|---|---|---|
| Tenant komplain tiba-tiba tidak bisa akses | Subscription berpindah ke SUSPENDED (otomatis dunning atau manual) | Cek status & riwayat Invoice terkait | Bila tunggakan sudah lunas, verifikasi pembayarannya (K.8) — akan reaktivasi otomatis; bila tidak ada tunggakan, cek apakah di-suspend manual dan pertimbangkan Reactivate | Tergantung kebijakan |

#### 16. Perbedaan dengan Tenant Portal
Tenant hanya melihat status Subscription-nya sendiri (read-only) lewat `Account → Subscription`, termasuk banner peringatan bila SUSPENDED/GRACE_PERIOD/PAST_DUE.

#### 17. Sumber dari User Guide Tenant
Baru dibuat untuk Platform Portal (Platform-Specific, RU-26).

---

### K.6 Billing Management

#### 1. Kegunaan
Melihat (dan, lewat API, memicu manual) catatan tagihan per periode yang menjadi dasar pembuatan Invoice.

#### 2. Scope
Satu tenant.

#### 3. Role dan Permission

| Action | Role | Permission | Scope |
|---|---|---|---|
| View | Superadmin | `billing.view` | Satu tenant |
| Generate manual | Superadmin | `billing.generate` | Satu tenant — **Backend Only** |

#### 4. Fitur

| Fitur | Kegunaan | Input | Output | Dampak |
|---|---|---|---|---|
| Daftar Billing | Lihat riwayat tagihan per periode | Filter status | Tabel | Tidak ada |

#### 5. Data yang Digunakan
Data transaksi: `Billing`, `BillingItem`.

#### 6. Prasyarat
Subscription harus `ACTIVE` (atau baru saja diaktifkan) agar billing pertama terbentuk.

#### 7. Jalur Akses
`Platform Portal → Billing` (`/platform/billings`)

#### 8. Prosedur Penggunaan
1. Buka daftar, gunakan tab status (DRAFT/GENERATED/INVOICED/PAID/PARTIALLY_PAID/PAST_DUE/CANCELLED) untuk menyaring.
2. Klik baris untuk melihat rincian item billing periode tersebut (read-only).
3. **Catatan penting:** billing normal terbentuk **otomatis setiap hari pukul 01:00 UTC** lewat scheduled command — Superadmin **tidak perlu** melakukan apa pun secara rutin. Pemicu manual (`generate-billing`) tersedia di backend untuk kasus khusus (mis. mempercepat pembuatan billing untuk pengujian atau situasi mendesak) tetapi **belum memiliki tombol di UI mana pun** — hanya dapat dijalankan lewat pemanggilan API langsung oleh tim teknis.

#### 9. Penjelasan Field
Lihat `OPTIFLEET_PLATFORM_DATA_DICTIONARY.md` §12.2 untuk rincian.

#### 10. Status dan Transisi

| Status | Arti | Pemicu |
|---|---|---|
| DRAFT→GENERATED | Billing baru dibuat & dihitung | Scheduled command harian (atau manual API) |
| INVOICED | Invoice sudah diterbitkan dari billing ini | Otomatis mengikuti pembuatan Invoice |
| PAID/PARTIALLY_PAID | Sinkron dari status Invoice-nya | Verifikasi Payment |
| PAST_DUE | Invoice terkait lewat jatuh tempo | Evaluasi otomatis harian |
| CANCELLED | Dibatalkan | (jarang terjadi lewat alur normal) |

#### 11. Aturan Bisnis
Satu Subscription tidak dapat memiliki dua Billing untuk periode yang identik (dijaga unik di database) — mencegah duplikasi meski proses berjalan bersamaan.

#### 12. Hubungan dengan Modul Lain
Billing → Invoice (K.7) satu-ke-satu setiap kali billing berhasil dibuat.

#### 13. Risiko dan Peringatan
Tidak ada risiko mutasi langsung dari UI (halaman ini read-only).

#### 14. Hasil
Riwayat tagihan tersedia untuk rekonsiliasi.

#### 15. Troubleshooting

| Masalah | Kemungkinan Penyebab | Pemeriksaan | Cara Mengatasi | Perlu Eskalasi |
|---|---|---|---|---|
| Billing periode tertentu belum muncul | Scheduled command belum berjalan untuk periode tersebut | Cek tanggal hari ini vs periode yang diharapkan | Tunggu jadwal harian, atau minta tim teknis memicu manual | Ya, jika mendesak |

#### 16. Perbedaan dengan Tenant Portal
Tenant tidak memiliki akses ke data Billing sama sekali (hanya melihat hasil akhirnya di Invoice, Account → Invoices).

#### 17. Sumber dari User Guide Tenant
Baru dibuat untuk Platform Portal (Platform-Specific, RU-27).

---

### K.7 Invoice Management

#### 1. Kegunaan
Menerbitkan, menampilkan, dan (bila perlu) membatalkan dokumen tagihan resmi ke tenant.

#### 2. Scope
Satu tenant.

#### 3. Role dan Permission

| Action | Role | Permission | Scope |
|---|---|---|---|
| View | Superadmin | `invoice.view` | Satu tenant |
| Void | Superadmin | `invoice.void` | Satu tenant |
| Export (PDF) | Superadmin | `invoice.view` | Satu tenant |

#### 4. Fitur

| Fitur | Kegunaan | Input | Output | Dampak |
|---|---|---|---|---|
| Daftar & Detail Invoice | Lihat status penagihan | Filter status | Tabel/detail | — |
| Download PDF | Cetak invoice | — | Berkas PDF (dibuat on-demand, selalu mencerminkan status pelunasan terkini) | — |
| Void | Batalkan invoice | Alasan | Status VOID | Tidak dapat dilakukan jika status sudah PAID |

#### 5. Data yang Digunakan
Data transaksi: `Invoice`, `InvoiceItem`.

#### 6. Prasyarat
Billing terkait harus sudah terbentuk (invoice dibuat otomatis segera setelahnya).

#### 7. Jalur Akses
`Platform Portal → Invoices` (`/platform/invoices`) → `/platform/invoices/:id`

#### 8. Prosedur Penggunaan
1. Buka daftar, filter berdasarkan tab status (DRAFT/ISSUED/OUTSTANDING/PARTIALLY_PAID/PAID/OVERDUE/VOID).
2. Klik baris untuk membuka detail → klik **"Download PDF"** untuk mengunduh dokumen invoice (dibuka di tab baru via blob URL).
3. Untuk membatalkan: klik **"Void"** (hanya muncul bila status bukan VOID/PAID) → isi alasan → **Hasil:** status `VOID`. **Peringatan:** invoice yang sudah `PAID` **tidak dapat** di-void — sistem menolak dengan pesan galat, dan **tidak ada mekanisme pembalikan pembayaran (reversal)** di seluruh aplikasi untuk mengoreksinya lebih lanjut.

#### 9. Penjelasan Field
Lihat `OPTIFLEET_PLATFORM_DATA_DICTIONARY.md` §12.2 "Invoice".

#### 10. Status dan Transisi

| Status | Arti | Pemicu | Actor |
|---|---|---|---|
| DRAFT→ISSUED→OUTSTANDING | Invoice baru diterbitkan | Otomatis dari Billing | Sistem |
| PARTIALLY_PAID/PAID | Sinkron dari total Payment terverifikasi | Verifikasi Payment | Superadmin (aksi verifikasi) |
| OVERDUE | Lewat jatuh tempo, belum lunas | Evaluasi otomatis harian | Sistem |
| VOID | Dibatalkan | Void | Superadmin |

#### 11. Aturan Bisnis
Nomor invoice dijamin unik meski dibuat bersamaan (row-lock database). Void ditolak keras untuk status PAID.

#### 12. Hubungan dengan Modul Lain
Dibuat dari Billing (K.6); dilunasi lewat Payment (K.8); status memengaruhi Billing induknya secara sinkron.

#### 13. Risiko dan Peringatan
**Peringatan:** kesalahan pada invoice yang sudah lunas **tidak dapat dikoreksi lewat aplikasi** — pertimbangkan proses akuntansi manual di luar sistem bila hal ini terjadi.

#### 14. Hasil
Tenant menerima dokumen tagihan resmi yang dapat diunduh lewat Tenant Portal mereka sendiri (Account → Invoices).

#### 15. Troubleshooting

| Masalah | Kemungkinan Penyebab | Pemeriksaan | Cara Mengatasi | Perlu Eskalasi |
|---|---|---|---|---|
| Tidak bisa Void invoice | Status sudah PAID | Cek status invoice | Tidak dapat dikoreksi lewat aplikasi; catat secara manual di luar sistem | Ya |

#### 16. Perbedaan dengan Tenant Portal
Tenant hanya dapat **melihat dan mengunduh** invoicenya sendiri (`Account → Invoices`, disposisi unduhan "attachment") — tidak dapat void atau melihat invoice tenant lain.

#### 17. Sumber dari User Guide Tenant
Baru dibuat untuk Platform Portal (Platform-Specific, RU-28) — sisi baca/unduh tenant sudah ada di Tenant Guide.

---

### K.8 Payment Verification

#### 1. Kegunaan
Memverifikasi bukti bayar yang diunggah tenant, memicu pelunasan invoice dan (bila lunas penuh) reaktivasi Subscription.

#### 2. Scope
Satu tenant.

#### 3. Role dan Permission

| Action | Role | Permission | Scope |
|---|---|---|---|
| View | Superadmin | `payment.view` | Satu tenant |
| Verify | Superadmin | `payment.verify` | Satu tenant |
| Reject | Superadmin | `payment.reject` | Satu tenant |

#### 4. Fitur

| Fitur | Kegunaan | Input | Output | Dampak |
|---|---|---|---|---|
| Daftar & Detail Payment | Tinjau pengajuan pembayaran tenant | — | Tabel/detail | — |
| Download bukti bayar | Lihat berkas bukti transfer | — | Berkas (JPG/PNG/WEBP/PDF) | — |
| Verify | Menyetujui pembayaran | Catatan opsional | Status VERIFIED; Invoice/Subscription diperbarui | **Dampak lintas tenant:** dapat mereaktivasi akses operasional tenant seketika |
| Reject | Menolak pembayaran | Catatan **wajib** | Status REJECTED | Tenant harus mengajukan ulang dari Tenant Portal |

#### 5. Data yang Digunakan
Data transaksi: `Payment`, `PaymentProof`.

#### 6. Prasyarat
Tenant harus sudah mengunggah pengajuan pembayaran dari Tenant Portal mereka (`Account → Payments`).

#### 7. Jalur Akses
`Platform Portal → Payments` (`/platform/payments`) → `/platform/payments/:id`

#### 8. Prosedur Penggunaan
1. Buka daftar pembayaran, cari yang berstatus `SUBMITTED`/`UNDER_REVIEW`.
2. Buka detail → klik **"Download"** pada bukti bayar untuk memeriksa keaslian & kesesuaian nominal transfer secara manual (sistem **tidak** melakukan pencocokan otomatis nominal terhadap outstanding invoice).
3. Bila sesuai: klik **Verify** → isi catatan (opsional) → **Hasil:** status `VERIFIED`; sistem menjumlahkan **seluruh** pembayaran VERIFIED atas invoice tersebut untuk menghitung ulang status pelunasan invoice (mendukung pembayaran bertahap lintas beberapa baris Payment); bila total mencapai lunas penuh **dan** Subscription tenant sedang `PENDING/SUSPENDED/PAST_DUE/GRACE_PERIOD`, Subscription **otomatis** kembali `ACTIVE` dan entitlement modul dipulihkan — dalam permintaan yang sama.
4. Bila tidak sesuai: klik **Reject** → **wajib isi catatan alasan** → status `REJECTED`. Invoice tetap outstanding; tenant harus mengajukan pembayaran baru dari Tenant Portal mereka.

**Peringatan penting:** kedua aksi (Verify/Reject) **bersifat final** — tidak ada tombol untuk membatalkan keputusan setelah ditekan. Verifikasi yang keliru (mis. salah menghitung nominal) dapat mereaktivasi tenant yang seharusnya belum berhak, tanpa jalur pembalikan otomatis.

#### 9. Penjelasan Field

| Field UI | Deskripsi | Tipe | Wajib | Sumber | Validasi | Contoh | Scope |
|---|---|---|---|---|---|---|---|
| Catatan (Verify) | Catatan opsional saat verifikasi | text | Tidak | Input | — | — | Tenant-specific |
| Catatan (Reject) | Alasan penolakan | text | **Ya** | Input | `required` (tombol nonaktif bila kosong) | "Nominal tidak sesuai" | Tenant-specific |

#### 10. Status dan Transisi

| Status | Arti | Pemicu | Actor | Status Berikutnya |
|---|---|---|---|---|
| SUBMITTED | Baru diajukan tenant | Submit (tenant, di Tenant Portal) | Tenant | UNDER_REVIEW, VERIFIED, REJECTED |
| UNDER_REVIEW | Sedang diperiksa | (opsional, jarang dipakai jalur ini) | — | VERIFIED, REJECTED |
| VERIFIED | Disetujui | Verify | Superadmin | — (akhir) |
| REJECTED | Ditolak | Reject | Superadmin | (tenant dapat submit baru) |

#### 11. Aturan Bisnis
Status pelunasan invoice dihitung dari **jumlah seluruh** Payment berstatus VERIFIED (bukan hanya payment terakhir) — mendukung pelunasan bertahap lewat beberapa kali pengajuan. Reaktivasi Subscription otomatis hanya terjadi pada pelunasan **penuh** (bukan sebagian).

#### 12. Hubungan dengan Modul Lain
Payment → Invoice (memperbarui status pelunasan) → Subscription (K.5, reaktivasi otomatis bila lunas penuh).

#### 13. Risiko dan Peringatan
**Peringatan:** tidak ada validasi otomatis kecocokan nominal — kesalahan verifikasi manual sepenuhnya menjadi tanggung jawab operator dan tidak dapat dibatalkan lewat aplikasi.

#### 14. Hasil
Invoice tercermin lunas sesuai kenyataan; Subscription tenant terpulihkan bila relevan.

#### 15. Troubleshooting

| Masalah | Kemungkinan Penyebab | Pemeriksaan | Cara Mengatasi | Perlu Eskalasi |
|---|---|---|---|---|
| Tenant sudah bayar tapi belum aktif lagi | Pembayaran belum diverifikasi Superadmin | Cek daftar Payments status SUBMITTED | Verifikasi pembayarannya | Tidak |
| Salah Verify pembayaran yang ternyata tidak valid | Tidak ada tombol pembatalan | — | Tidak ada jalur otomatis; perlu koreksi manual di luar sistem (mis. Suspend manual + catatan) | Ya |

#### 16. Perbedaan dengan Tenant Portal
Tenant hanya dapat **mengajukan** (submit) pembayaran & melihat statusnya sendiri lewat `Account → Payments` — tidak pernah dapat memverifikasi pembayarannya sendiri.

#### 17. Sumber dari User Guide Tenant
Baru dibuat untuk Platform Portal (Platform-Specific, RU-29) — sisi pengajuan tenant sudah ada di Tenant Guide Bagian I.22.

---

## L. Tenant Context, Switch Tenant, atau Impersonation

**Hasil verifikasi eksplisit (bukan asumsi):** OptiFleet **tidak memiliki** mekanisme bagi Superadmin untuk "masuk ke dalam" konteks satu tenant tertentu (baik berupa impersonation penuh maupun sekadar tenant-switcher). Ini dikonfirmasi lewat tiga cara independen pada sesi ini:

1. **Pencarian kode menyeluruh** untuk istilah `impersonat`, `act as tenant`, `assume tenant` di seluruh `backend/app` dan `frontend/src` — nihil hasil.
2. **Analisis struktural token** — token Superadmin membawa ability `platform` saja, tidak pernah `tenant:{id}` apa pun. Middleware `EnsureTenantScope` (alias `tenant.scope`) yang menjaga seluruh rute `/app/*` secara eksplisit mensyaratkan `hasTenant() AND NOT isPlatformUser()` — sebuah token platform **tidak akan pernah lolos** pemeriksaan ini, berapa pun permission yang dimilikinya.
3. **Fitur "Switch Tenant" yang memang ada di aplikasi** (`AuthContext.tsx: switchTenant()`) adalah murni kemampuan **Tenant Portal**, dipakai oleh seorang **user tenant** yang kebetulan menjadi anggota lebih dari satu tenant sekaligus (jarang terjadi, tapi didukung skema `TenantUser`). Fitur ini menerbitkan token `tenant:{id}` baru — **sama sekali berbeda** dari konsep "Superadmin memilih tenant untuk dilihat", dan **tidak dapat dipicu oleh akun platform**.

**Kesimpulan bagi Superadmin:** setiap fitur Platform Portal yang beroperasi "untuk satu tenant" (Contract, Subscription, Invoice, dst. — Bagian K) melakukannya lewat **parameter rute eksplisit** (`{tenant}` di URL, mis. `/platform/tenants/{tenant}/entitlements`), **bukan** lewat konsep "tenant context aktif" yang tersimpan di sesi seperti pada Tenant Portal. Tidak ada "indikator tenant aktif" untuk keluar-masuk, karena tidak pernah ada konteks semacam itu untuk dimasuki.

**Implikasi keamanan (positif):** karena tidak ada impersonation, Superadmin **tidak pernah** dapat melakukan aksi operasional atas nama user tenant tertentu, dan tidak ada risiko "lupa keluar dari mode tenant" yang lazim ditemukan pada aplikasi SaaS lain dengan fitur impersonation.

---

## M. User Management

#### 1. Kegunaan
Mengelola akun login untuk dua populasi berbeda: **Platform User** (sesama Superadmin/Specialist) dan **Tenant User** (dibuat atas nama tenant, biasanya user Admin pertama tenant tersebut).

#### 2. Scope
Platform User: Global. Tenant User (dibuat dari sini): satu tenant terpilih.

#### 3. Role dan Permission

| Action | Role | Permission | Scope |
|---|---|---|---|
| View/Create/Update Platform User | Superadmin | `user.view/create/update` (scope platform) | Global |
| View/Create/Update Tenant User (dari Platform) | Superadmin | `user.view/create/update` (scope platform, dicek terhadap tenant tsb.) | Satu tenant |
| Assign Role (Tenant User, dari Platform) | Superadmin | `user.assign` | Satu tenant — **Backend Only** |

#### 4. Fitur

| Fitur | Kegunaan | Input | Output | Dampak |
|---|---|---|---|---|
| Kelola Platform User | CRUD terbatas akun sesama Superadmin | Nama, Email, Password (create); Status/Nama (update) | Akun platform baru/terbarui | Akun baru dapat login setelah diberi Role (Bagian N) |
| Kelola Tenant User (dari Platform) | Membuat user awal untuk tenant tertentu | Nama, Email, Password | Akun tenant baru | User dapat login ke Tenant Portal tenant tsb., namun belum punya role |

#### 5. Data yang Digunakan
Data transaksi: `User` (dibedakan oleh kolom `user_type`: `platform`/`tenant`), `TenantUser` (khusus populasi tenant).

#### 6. Prasyarat
Untuk Tenant User: tenant tujuan harus sudah terdaftar (Bagian K.1).

#### 7. Jalur Akses
`Platform Portal → Platform Users` (`/platform/access/users`); Tenant User dikelola lewat `Platform Portal → Tenant Management → [pilih tenant] → tab Users`.

#### 8. Prosedur Penggunaan

**Membuat Platform User baru:**
1. `Platform Users` → **"+ New User"** (atau tombol serupa) → isi Nama, Email, Password → **Create**.
2. **Hasil:** akun baru aktif, **belum memiliki Role/permission apa pun** — tanpa penugasan Role, akun ini tidak dapat melakukan aksi apa pun selain login dan melihat Dashboard.
3. Lanjutkan ke Bagian N untuk menugaskan Role.

**Mengubah status/nama Platform User:**
1. Pada daftar, buka/pilih user → ubah **Status** (active/inactive) dan/atau **Nama**. **Catatan:** **password tidak dapat diubah lewat sini** (lihat Bagian H).

**Membuat Tenant User pertama (dari sisi Platform):**
1. Buka `Tenant Management → [pilih tenant] → tab Users` → **"+ Add User"** → isi Nama, Email, Password → **Add User**.
2. **Hasil:** akun tenant baru, status `active`, **belum memiliki Role**. **Penting:** penugasan Role untuk akun ini **harus** dilakukan dari Tenant Portal (oleh user itu sendiri setelah login pertama tidak mungkin — karena dia sendiri belum punya permission `role.view`/`user.assign`; dalam praktiknya, Superadmin **perlu meminta bantuan API langsung dari tim teknis**, atau organisasi perlu memiliki **lebih dari satu** user Admin tenant awal yang salah satunya sudah punya Role sejak dibuat lewat mekanisme lain, seperti seeder — ini adalah kondisi *ayam-dan-telur* yang perlu disadari Superadmin: **UI Platform Portal saat ini tidak menyediakan cara menugaskan Role sekaligus saat membuat Tenant User pertama**).

#### 9. Penjelasan Field

| Field UI | Deskripsi | Tipe | Wajib | Sumber | Validasi | Contoh | Scope |
|---|---|---|---|---|---|---|---|
| Name | Nama pengguna | string | Ya | Input | `required` | "Budi Santoso" | — |
| Email | Alamat email login | string | Ya | Input | `required, email, unique` | `admin@tenant.test` | — |
| Password | Kata sandi awal | string | Ya (saat create) | Input | `required, min length` (lihat validasi FormRequest) | — (tidak ditampilkan) | **Sensitif** |
| Status | Status aktif akun | enum | Sistem | Toggle | `active/inactive` | `active` | — |

#### 10. Status dan Transisi

| Status | Arti | Pemicu | Actor |
|---|---|---|---|
| active | Dapat login | Create (default) / toggle | Superadmin |
| inactive | Tidak dapat login | Toggle | Superadmin |

#### 11. Aturan Bisnis
Email harus unik. Tidak ada batas jumlah Platform User. Jumlah Tenant User dibatasi oleh Capacity Limit (`resource_type=user`) tenant tersebut, bila diatur.

#### 12. Hubungan dengan Modul Lain
Bertaut erat dengan Role Management (Bagian N) — user tanpa Role tidak dapat melakukan aksi apa pun meski statusnya aktif.

#### 13. Risiko dan Peringatan
**Peringatan:** karena tidak ada reset password, **kesalahan input password saat membuat akun berarti akun tersebut tidak dapat dipakai** sampai dibuatkan ulang atau diubah lewat akses database langsung (di luar cakupan aplikasi ini).

#### 14. Hasil
Akun baru siap ditugaskan Role agar dapat benar-benar dipakai.

#### 15. Troubleshooting

| Masalah | Kemungkinan Penyebab | Pemeriksaan | Cara Mengatasi | Perlu Eskalasi |
|---|---|---|---|---|
| User baru tidak bisa melakukan apa pun setelah login | Belum ditugaskan Role | Cek Bagian N | Tugaskan Role yang sesuai | Tidak |
| User terkunci karena lupa password | Tidak ada reset password | — | Buat akun baru dengan email berbeda, atau eskalasi ke tim teknis untuk pembaruan password langsung di database | Ya |

#### 16. Perbedaan dengan Tenant Portal
Tenant Portal memiliki UI lengkap "Manage Access" (assign Role + Data Scope dalam satu modal) untuk usernya sendiri; Platform Portal untuk Tenant User hanya sebatas create + toggle status, tanpa assign Role.

#### 17. Sumber dari User Guide Tenant
Reusable With Adaptation (RU-13, RU-15) — pola dasar sama, tetapi kelengkapan fitur berbeda signifikan.

---

## N. Role and Permission Management

#### 1. Kegunaan
Menentukan kumpulan hak akses (permission) yang dimiliki setiap Platform User, lewat Role bernama bebas.

#### 2. Scope
Global.

#### 3. Role dan Permission

| Action | Role | Permission | Scope |
|---|---|---|---|
| View | Superadmin | `role.view` | Global |
| Create | Superadmin | `role.create` | Global |
| Update (permission) | Superadmin | `role.assign_permission` | Global |
| Update (nama/deskripsi) | Superadmin | `role.update` | Global — **Backend Only, tidak ada UI** |

#### 4. Fitur

| Fitur | Kegunaan | Input | Output | Dampak |
|---|---|---|---|---|
| Daftar Role | Lihat seluruh role platform | — | Kartu role + jumlah permission | — |
| Buat Role | Role baru dengan kumpulan permission | Nama, Deskripsi, checklist permission | Role baru | Langsung dapat ditugaskan ke user manapun |
| Edit Permissions | Ubah kumpulan permission role yang ada | Checklist permission | Permission tersinkron | **Dampak langsung ke seluruh user** yang memegang role ini |

#### 5. Data yang Digunakan
Data master: daftar `Permission` scope=platform (di-seed, tidak dapat diubah pengguna). Data transaksi: `Role`, `role_permissions`.

#### 6. Prasyarat
Tidak ada.

#### 7. Jalur Akses
`Platform Portal → Platform Roles` (`/platform/access/roles`)

#### 8. Prosedur Penggunaan
1. `Platform Roles` → **"+ New Role"** → isi Nama & Deskripsi.
2. Centang permission dari daftar berkelompok (gunakan **"Select All"/"Clear All"** per kelompok untuk mempercepat, mis. kelompok `contract`, `invoice`, `payment`).
3. Klik **Create**. **Hasil:** Role baru langsung tersedia untuk ditugaskan ke Platform User mana pun (Bagian M) — assignment dilakukan lewat panggilan `POST /platform/users/{user}/roles`... **catatan: berdasarkan pemeriksaan `platform.php`, tidak ditemukan route eksplisit untuk assign role ke Platform User** (`role_assignments` untuk platform user tampaknya hanya dapat dibuat lewat seeder atau langsung di database — ini adalah **temuan penting**, lihat Findings). Assignment Role untuk **Tenant User** (bukan Platform User) memang ada endpoint-nya (Bagian K.1/M) meski tanpa UI.
4. Untuk mengubah kumpulan permission Role yang sudah ada: klik **"Edit permissions"** (tidak tersedia untuk role bertanda `SYSTEM`, mis. "Platform Superadmin" bawaan) → sesuaikan centang → **Save**.

**Peringatan:** mengubah permission suatu Role **langsung** memengaruhi **setiap** user yang memegangnya pada permintaan berikutnya (cache permission disegarkan otomatis) — termasuk berpotensi mengunci diri sendiri dari fitur tertentu bila tidak berhati-hati.

#### 9. Penjelasan Field

| Field UI | Deskripsi | Tipe | Wajib | Sumber | Validasi | Contoh | Scope |
|---|---|---|---|---|---|---|---|
| Nama Role | Label bebas | string | Ya | Input | `required, max:100, unique` (per scope) | "Billing Specialist" | Global |
| Permission | Hak aksi granular dipilih | multi-select | Tidak (boleh kosong, meski tidak berguna) | Daftar seed | ID harus valid | `invoice.view` | Global |
| is_system | Penanda role bawaan | boolean | Sistem | Otomatis | Tidak dapat diedit/dihapus | `true` (untuk "Platform Superadmin") | Global |

#### 10. Status dan Transisi
Tidak ada mesin status — Role tidak memiliki status aktif/nonaktif, hanya ada/tidaknya penugasan.

#### 11. Aturan Bisnis
Nama Role unik dalam scope platform. Role `is_system=true` tidak dapat diedit permission-nya maupun dihapus. **Tidak ada bypass permission** untuk Role manapun termasuk "Platform Superadmin" bawaan — hak akses sepenuhnya ditentukan oleh permission yang ter-assign.

#### 12. Hubungan dengan Modul Lain
Menggerbang **seluruh** endpoint `/platform/*` lewat middleware `permission:`.

#### 13. Risiko dan Peringatan
**Peringatan:** tidak ada pengaman "jangan hapus permission terakhir milikmu sendiri" — Superadmin dapat secara tidak sengaja mengunci dirinya sendiri dari fitur penting dengan mengubah permission Role yang sedang dipegangnya.

#### 14. Hasil
Role siap ditugaskan (Tenant User) atau — dengan catatan gap di atas — dipakai sebagai referensi kumpulan hak akses.

#### 15. Troubleshooting

| Masalah | Kemungkinan Penyebab | Pemeriksaan | Cara Mengatasi | Perlu Eskalasi |
|---|---|---|---|---|
| Tidak bisa mengedit Role tertentu | Role bertanda SYSTEM | Cek badge pada kartu Role | Buat Role baru sebagai gantinya | Tidak |
| Tidak menemukan cara menugaskan Role baru ke Platform User | Tidak ditemukan route/UI untuk ini | — | Eskalasi ke tim teknis — kemungkinan memerlukan penambahan fitur atau penugasan lewat seeder/database | Ya |

#### 16. Perbedaan dengan Tenant Portal
Komponen `RoleManager.tsx` **sama persis** dipakai kedua portal (Reusable With Adaptation) — hanya daftar permission (`scope=platform` vs `scope=tenant`) dan endpoint yang berbeda.

#### 17. Sumber dari User Guide Tenant
Reusable With Adaptation (RU-14).

---

## O. Global Master Data

**Hasil verifikasi:** **Tidak ditemukan** satu pun route atau halaman di Platform Portal untuk mengelola data master "system" (`tenant_id=null`) seperti Vehicle Category, Component Group, Vehicle Brand, Product Category, dsb. Data-data ini **ada** dan **dipakai bersama** seluruh tenant (dibuktikan lewat kolom `is_system=true` dan trait `BelongsToTenantOrPlatform` pada model-model tersebut), tetapi berdasarkan pemeriksaan `backend/routes/api/platform.php` secara menyeluruh serta seluruh direktori `frontend/src/pages/platform/`, **tidak ada mekanisme UI maupun API Platform** untuk membuat/mengubahnya.

**Klasifikasi:** **Unverified/Tidak ditemukan implementasinya** — data ini kemungkinan besar hanya dapat dibuat/diubah lewat **seeder** (`MasterDataSeeder.php`, `SupplyChainSeeder.php`) yang dijalankan tim teknis di lingkungan server, bukan lewat aksi Superadmin di aplikasi berjalan.

**Implikasi bagi Superadmin:** bila diperlukan penambahan/perubahan data master global baru (mis. kategori kendaraan baku baru yang berlaku untuk semua tenant), permintaan ini **harus dieskalasi ke tim teknis** untuk dieksekusi lewat perubahan seeder/database — bukan sesuatu yang dapat dilakukan sendiri lewat Platform Portal saat ini. Ini dicatat sebagai temuan pada `OPTIFLEET_PLATFORM_USER_GUIDE_FINDINGS.md`.

---

## P. Platform Configuration

**Hasil verifikasi:** sama seperti Master Data Global (Bagian O), **tidak ditemukan** route Platform untuk mengelola nilai default platform pada sistem Configuration (Numbering/Document Template/Workflow/Notification Rule/Tire Scoring). Seluruh route Configuration hanya ada di `backend/routes/api/app.php` (tenant-scoped). Nilai default platform (`ConfigurationSet` dengan `tenant_id=null`) tampak dibuat oleh `ConfigurationDefaultsSeeder.php` — sekali lagi lewat seeder, bukan UI Superadmin.

**Perbedaan Konfigurasi Global vs Tenant:** setiap tenant dapat mempublikasikan **versi miliknya sendiri** yang menimpa (bagi tenant tersebut) nilai default platform — mekanisme ini ("tenant override, platform default sebagai fallback") sudah bekerja dengan baik dari sisi *pembacaan* (`EffectiveConfigurationResolver`), tetapi sisi *penulisan* nilai default platform itu sendiri tidak memiliki jalur UI di Platform Portal.

**Implikasi bagi Superadmin:** perubahan default platform (mis. format penomoran default untuk seluruh tenant baru) memerlukan eskalasi ke tim teknis.

---

## Q. Audit Log dan Activity History

#### 1. Kegunaan
Menyediakan jejak audit lintas seluruh tenant atas perubahan data penting — dasar investigasi, kepatuhan, dan dukungan teknis di level platform.

#### 2. Scope
Cross-tenant Aggregate.

#### 3. Role dan Permission

| Action | Role | Permission | Scope |
|---|---|---|---|
| View | Superadmin | `audit.view` (scope platform) | Cross-tenant Aggregate |

#### 4. Fitur

| Fitur | Kegunaan | Input | Output | Dampak |
|---|---|---|---|---|
| Daftar Audit Log | Telusuri riwayat perubahan lintas tenant | Tenant ID, Resource Type, Action, Actor User ID, rentang tanggal | Tabel log | Tidak ada (read-only) |

#### 5. Data yang Digunakan
Data transaksi: `AuditLog`, ditulis otomatis oleh trait `Auditable` pada model-model penting (`Tenant`, `Contract`, `Role`, `RoleAssignment`, `User`, dst.) tiap kali dibuat/diubah/dihapus/dinonaktifkan.

#### 6. Prasyarat
Tidak ada — pencatatan berjalan otomatis sejak awal.

#### 7. Jalur Akses
`Platform Portal → Audit Log` (`/platform/audit-logs`)

#### 8. Prosedur Penggunaan
1. Buka halaman Audit Log.
2. **Filter tersedia** (khusus Platform, lebih lengkap dari sisi Tenant): `tenant_id`, **Resource Type** (kotak teks bebas, mis. `Tenant`, `Role`, `Contract`), **Action** (kotak teks bebas, mis. `created`, `updated`, `deactivated`), `actor_user_id`, rentang tanggal (`from`/`to`).
3. Klik baris **"Changes"** untuk melihat rincian nilai sebelum/sesudah dalam format JSON mentah.

**Catatan:** kolom filter Resource Type/Action berupa **teks bebas, bukan dropdown** — Superadmin perlu mengetahui nama kelas/aksi yang tepat (Bahasa Inggris, sesuai konvensi kode) untuk memfilter secara efektif.

#### 9. Penjelasan Field

| Field UI | Deskripsi | Tipe | Contoh |
|---|---|---|---|
| Actor | Pengguna yang melakukan aksi | string (nama) atau "System" | "Jane Doe" / "System" |
| Tenant | Tenant yang terdampak (kosong untuk aksi murni platform) | relasi | "PT Alpha Fleet" |
| Resource Type/ID | Jenis & identitas data yang diubah | string/uuid | `Contract` / `uuid` |
| Action | Jenis aksi | string | `created`, `updated`, `deleted`, `deactivated` |
| Old/New Values | Nilai sebelum/sesudah | json | `{"status":"DRAFT"}` → `{"status":"ACTIVE"}` |

#### 10. Status dan Transisi
Tidak berlaku — log bersifat catatan permanen (append-only), bukan entitas bertransisi status.

#### 11. Aturan Bisnis
Password **tidak pernah** tercatat di Audit Log dalam bentuk apa pun. Platform melihat log **seluruh** tenant sekaligus (tidak difilter otomatis), berbeda dari Tenant Portal yang selalu hard-filter ke tenant sendiri.

#### 12. Hubungan dengan Modul Lain
Mencatat perubahan dari hampir seluruh modul Platform (Tenant, Contract, Role, User, dst.).

#### 13. Risiko dan Peringatan
Tidak ada risiko mutasi — halaman ini murni baca. **Catatan:** tidak ada fitur ekspor (CSV/dsb.) pada Audit Log manapun (baik Platform maupun Tenant).

#### 14. Hasil
Jejak audit lengkap lintas tenant untuk kebutuhan investigasi & kepatuhan.

#### 15. Troubleshooting

| Masalah | Kemungkinan Penyebab | Pemeriksaan | Cara Mengatasi | Perlu Eskalasi |
|---|---|---|---|---|
| Filter tidak menemukan hasil meski yakin data ada | Nilai Resource Type/Action tidak cocok persis | Coba variasi kapitalisasi/istilah, atau kosongkan filter teks dan andalkan rentang tanggal + tenant_id | Sesuaikan istilah pencarian | Tidak |

#### 16. Perbedaan dengan Tenant Portal
Tenant Portal hard-filter ke tenant sendiri saja, tanpa kolom Tenant dan tanpa filter `actor_user_id`. Komponen tabel (`AuditLogTable.tsx`) sama persis, dibedakan lewat prop `showTenantColumn`.

#### 17. Sumber dari User Guide Tenant
Reusable With Adaptation (RU-17).

---

## R. Notification Management

**Hasil verifikasi:** **Tidak ada** halaman atau endpoint pengelolaan notifikasi di Platform Portal. Seluruh route `notification-rules` hanya ada di `backend/routes/api/app.php` (tenant-scoped). Event yang bersifat "platform-locked" (`subscription.expiring`, `invoice.due`, `subscription.suspended`, `payment.verification_required`) memang didefinisikan di `NotificationEventCatalog` sebagai kategori terpisah, tetapi ini hanya berarti tenant **tidak dapat mengedit/menonaktifkan** aturan untuk event tersebut dari Tenant Portal mereka (aturan tetap **dilihat** read-only di sana) — **bukan** berarti ada halaman pengelolaan tersendiri di Platform Portal untuk mengarang/mengubah aturan tersebut.

**Implikasi bagi Superadmin:** Superadmin tidak memiliki cara mengubah kanal, penerima, atau isi pesan notifikasi platform-locked lewat aplikasi — perubahan semacam itu memerlukan perubahan kode/konfigurasi oleh tim teknis.

---

## S. Integration Management

**Hasil verifikasi:** **Tidak ada** halaman pengelolaan integrasi di portal manapun (Platform maupun Tenant). Satu-satunya mekanisme integrasi yang ditemukan di seluruh codebase adalah `IntegrationOutboxEvent`/`IntegrationOutboxService` (dipakai oleh fitur Workshop Invoice di Tenant Portal untuk mencatat event yang **suatu saat nanti** akan dikonsumsi sistem akuntansi eksternal). Baris-baris outbox ini **ditulis tetapi tidak pernah dikonsumsi** — tidak ada worker/consumer, tidak ada halaman monitoring status pengiriman, retry, atau log error untuk integrasi ini di Platform Portal maupun di mana pun.

**Implikasi bagi Superadmin:** tidak ada tindakan yang dapat/perlu dilakukan Superadmin terkait integrasi saat ini — fitur ini bersifat "disiapkan untuk masa depan", belum menjadi kapabilitas operasional.

---

## T. Report dan Monitoring Lintas Tenant

**Hasil verifikasi:** Superadmin **tidak memiliki akses** ke dashboard Analytics maupun Maintenance Intelligence tenant manapun (dashboard tersebut murni fitur Tenant Portal, per-tenant, digerbang module entitlement `ANALYTICS`/`MAINTENANCE_INTELLIGENCE` yang hanya berlaku di sisi tenant). Yang **tersedia** bagi Superadmin hanyalah fungsi **administrasi infrastruktur** di baliknya:

#### Analytics ETL Administration & Reconciliation (Backend Only)
- **Kegunaan:** menjalankan, mengulang (retry), atau mengisi ulang (backfill) proses ETL yang membentuk data warehouse analitik tenant; membandingkan (`reconciliation`) hasil ETL terhadap data PostgreSQL asli untuk memastikan tidak ada penyimpangan.
- **Scope:** dapat menyasar satu tenant tertentu atau seluruh tenant, tergantung parameter yang dikirim — **bukan** lewat "tenant context" tersimpan.
- **Permission:** `analytics.etl.view/run/retry/backfill`.
- **Status implementasi:** **Backend Only** — endpoint (`/platform/analytics/etl/*`, `/platform/analytics/reconciliation`) berfungsi penuh dan diaudit setiap pemakaiannya, tetapi **tidak ada halaman frontend** untuk memicunya. Saat ini hanya dapat dijalankan lewat pemanggilan API langsung atau command-line (`analytics:run`, `analytics:backfill`, `analytics:reconcile`) oleh tim teknis.

#### Intelligence Model Administration & Monitoring (Backend Only)
- **Kegunaan:** meninjau, melatih, mengaktifkan, atau menonaktifkan (retire) model Machine Learning yang melayani prediksi risiko kegagalan kendaraan; memantau performa model dan pergeseran data (drift).
- **Scope:** model dapat berscope GLOBAL (melayani seluruh tenant yang belum punya model sendiri) atau TENANT (khusus satu tenant).
- **Permission:** `intelligence.model.view/train/evaluate/activate/retire`, `intelligence.monitoring.view`.
- **Status implementasi:** **Backend Only** — sama seperti ETL Administration, tidak ada halaman frontend Platform untuk ini.
- **Peringatan/Dampak lintas tenant:** mengaktifkan model berscope GLOBAL baru **langsung memengaruhi** hasil prediksi yang dilihat **seluruh** tenant yang belum memiliki model khusus mereka sendiri — aktivasi hanya diizinkan bila model memenuhi kriteria akurasi minimum yang sudah ditetapkan sistem (*acceptance criteria*), tidak dapat dipaksakan meski secara teknis diminta.

**Implikasi bagi Superadmin non-teknis:** kedua fungsi di atas, meski tercatat sebagai kewenangan Superadmin (memiliki permission-nya sendiri), **dalam praktiknya memerlukan bantuan tim teknis** untuk benar-benar dieksekusi selama belum ada antarmuka pengguna.

---

## U. Modul Operasional yang Juga Diakses Superadmin

**Hasil verifikasi eksplisit:** setelah pemeriksaan menyeluruh terhadap `backend/routes/api/platform.php` dan seluruh halaman di `frontend/src/pages/platform/`, **tidak ditemukan satu pun** akses Superadmin ke modul operasional berikut: Vehicle, Scheduled Maintenance, Work Order (beserta Complaint/Finding/Estimation/Mechanic Assignment), Stock Request/Spare Part Issuance/Return, Maintenance Result, Inventory, Warehouse, Tire Management, Worker, Partner, atau History.

**Alasan struktural (bukan sekadar menu yang disembunyikan):** seluruh modul ini berada di bawah rute `/app/*` yang dijaga middleware `tenant.scope` — middleware ini secara eksplisit menolak (bukan menyembunyikan, tetapi **menolak dengan galat**) permintaan dari token yang tidak membawa ability `tenant:{id}`. Token Superadmin tidak pernah membawa ability tersebut. Artinya, **sekalipun** seorang Superadmin diberi permission `vehicle.view` dsb. (permission tersebut bahkan tidak terdaftar untuk scope platform di `PermissionSeeder.php`), permintaannya akan tetap ditolak pada level middleware sebelum pemeriksaan permission sempat dilakukan.

**Kesimpulan:** ini bukan kekurangan yang perlu "diperbaiki" secara tersirat oleh dokumen ini — ini adalah **keputusan arsitektur yang konsisten**: Platform Portal murni untuk administrasi bisnis SaaS, Tenant Portal murni untuk operasional armada. Bila suatu saat Technical Support benar-benar memerlukan peninjauan data operasional tenant tertentu (mis. untuk mendiagnosis keluhan), satu-satunya jalur yang mungkin (di luar cakupan dokumen ini) adalah lewat akses database langsung oleh tim teknis berwenang, atau — bila tersedia — meminta Admin tenant tersebut untuk login dan menunjukkan datanya sendiri.

---

## V. Troubleshooting

| Masalah | Kemungkinan Penyebab | Pemeriksaan | Cara Mengatasi | Perlu Eskalasi |
|---|---|---|---|---|
| Superadmin tidak bisa login | Akun `inactive`, atau kredensial salah | Cek pesan galat persis di layar | Bila kredensial salah, ulangi; bila akun nonaktif, minta Superadmin lain mengaktifkan lewat Bagian M | Ya, jika tidak ada Superadmin lain aktif |
| Superadmin lupa password | Tidak ada mekanisme reset password | — | **Tidak dapat dipulihkan lewat aplikasi** — perlu pembaruan password langsung di database oleh tim teknis, atau buat akun baru bila kebijakan mengizinkan | **Ya, selalu** |
| Tenant tidak muncul di daftar Tenant Management | Kemungkinan terhapus lewat proses lain (tidak ada UI penghapusan resmi, tetapi kolom `deleted_at` ada di skema) — atau kesalahan pencarian/filter | Cek filter status & kata kunci pencarian | Kosongkan filter; bila tetap tidak muncul, eskalasi | Ya, jika data hilang tidak wajar |
| Tombol suatu aksi tidak muncul | Permission tidak dimiliki, atau status entitas tidak mengizinkan aksi tersebut saat ini | Cek status entitas & bandingkan dengan tabel Status-Transisi modul terkait | Minta permission tambahan (Bagian N) bila memang berwenang, atau tunggu status berubah | Tidak, kecuali permission memang perlu ditambah |
| Tenant komplain tidak bisa akses aplikasi | Subscription SUSPENDED (dunning otomatis atau manual) atau Tenant di-Deactivate | Cek K.5 (Subscription) dan K.1 (status Tenant) | Verifikasi pembayaran (K.8) bila tunggakan, atau Reactivate/Activate bila keputusan administratif sudah selesai | Tergantung kebijakan |
| Modul tertentu tidak muncul di Tenant Portal suatu tenant | Module Entitlement belum di-Grant | Cek tab Module Entitlements pada Tenant Detail | Grant modul yang relevan | Tidak |
| Data master/konfigurasi global perlu diubah tapi tidak ada menunya | Fitur ini memang belum memiliki UI (Bagian O, P) | — | Eskalasi ke tim teknis untuk perubahan lewat seeder/database | **Ya** |
| Verifikasi pembayaran keliru sudah terlanjur ditekan | Tidak ada tombol pembatalan | — | Pertimbangkan Suspend manual (K.5) sebagai mitigasi sementara sambil dikoreksi manual di luar sistem | Ya |

## W. FAQ

**Mengapa data tenant tertentu tidak terlihat?**
Kemungkinan besar filter status/pencarian sedang aktif dan menyembunyikannya — coba kosongkan filter. Bila tetap tidak terlihat, data tersebut mungkin belum pernah dibuat, atau (jarang) ada masalah pada query — eskalasi ke tim teknis.

**Mengapa tombol tertentu tidak tersedia bagi saya?**
Dua kemungkinan: (1) Anda tidak memiliki permission yang relevan (cek Bagian N untuk melihat/menambah permission Role Anda), atau (2) entitas yang sedang dilihat berada pada status yang tidak mengizinkan aksi tersebut saat ini (lihat tabel "Status dan Transisi" pada modul terkait).

**Bagaimana memastikan tenant tertentu aktif?**
Buka `Tenant Management → [pilih tenant]`, lihat badge status di header (`ACTIVE`/`INACTIVE`/`DRAFT`). Untuk memastikan mereka benar-benar dapat memakai fitur tertentu, periksa juga status Subscription (K.5) dan Module Entitlement (K.1) — status Tenant `ACTIVE` saja tidak cukup bila Subscription-nya `SUSPENDED`.

**Bagaimana mengetahui tenant yang "sedang dipilih"?**
Konsep ini **tidak berlaku** bagi Superadmin (lihat Bagian L) — setiap halaman Platform yang beroperasi atas satu tenant selalu menampilkan secara eksplisit tenant mana yang sedang dilihat (nama & kode tenant tertulis di judul halaman/breadcrumb halaman detail), bukan lewat "konteks tersimpan" yang bisa lupa diganti.

**Apa dampak menonaktifkan tenant?**
Seluruh user tenant tersebut langsung tidak dapat login (lihat K.1 §13). Data mereka tidak terhapus — hanya akses login yang terkunci. Dapat dibalik kapan saja lewat Activate.

**Apakah perubahan master data global memengaruhi tenant?**
Secara teori ya (data "system" dipakai bersama), tetapi Platform Portal **tidak memiliki UI** untuk mengubahnya (Bagian O) — jadi dalam praktik, perubahan semacam itu hanya terjadi lewat pembaruan seeder/database oleh tim teknis, bukan aksi harian Superadmin.

**Bagaimana memeriksa aktivitas seorang user (Superadmin atau tenant)?**
Buka Audit Log (Bagian Q), filter `actor_user_id` sesuai user tersebut (Platform) atau lihat Audit Log Tenant Portal untuk aktivitas dalam satu tenant tertentu (di luar cakupan akses Superadmin langsung, kecuali lewat kolom `tenant_id` pada Audit Log Platform).

**Bagaimana menangani user yang terkunci (lupa password)?**
**Tidak ada jalur pemulihan mandiri maupun oleh admin lewat aplikasi ini** (lihat Bagian H, V). Opsi yang tersedia: buat akun baru dengan email berbeda (bila kebijakan organisasi mengizinkan), atau eskalasi ke tim teknis untuk pembaruan password langsung di database. Ini berlaku sama baik untuk Platform User maupun Tenant User.

**Bagaimana keluar dari mode tenant/impersonation?**
Pertanyaan ini tidak berlaku bagi Superadmin — **tidak ada mode tenant/impersonation** yang bisa dimasuki (lihat Bagian L). Bila yang dimaksud adalah fitur "Switch Tenant" pada Tenant Portal, itu murni kemampuan user tenant dengan lebih dari satu keanggotaan, bukan kemampuan Superadmin.

## X. Glossary

| Istilah | Singkatan | Definisi | Scope | Konteks |
|---|---|---|---|---|
| Tenant/Customer | — | Satu perusahaan pelanggan OptiFleet | Global | Tenant Management |
| Superadmin | — | Pengguna Platform Portal dengan permission administratif/komersial | Global | Access Management |
| Ability (token) | — | String pada token Sanctum yang menentukan konteks akses (`platform` atau `tenant:{id}`) | Global | Autentikasi |
| Module Entitlement | — | Hak akses tenant terhadap satu modul tertentu | Tenant-specific | Tenant Management |
| Capacity Limit | — | Batas maksimum jumlah resource yang boleh dibuat tenant | Tenant-specific | Tenant Management |
| Bundle | — | Paket jual berisi kelompok modul, versi dibekukan saat publish | Global | Bundle Management |
| Contract | Kontrak | Perjanjian komersial resmi tenant, dasar penagihan | Tenant-specific | Contract Management |
| Subscription | — | Representasi operasional kontrak yang sedang ditagih; menentukan status akses | Tenant-specific | Subscription Management |
| Billing | — | Catatan tagihan per periode, sumber pembuatan Invoice | Tenant-specific | Billing Management |
| Invoice | — | Dokumen tagihan resmi ke tenant | Tenant-specific | Invoice Management |
| Payment | — | Bukti bayar yang diajukan tenant untuk diverifikasi | Tenant-specific | Payment Verification |
| Dunning | — | Proses otomatis penagihan berjenjang atas tagihan telat | Tenant-specific | Subscription |
| Proration | — | Perhitungan biaya proporsional untuk periode tidak penuh | Tenant-specific | Contract Amendment |
| Impersonation | — | Kemampuan admin "menyamar" sebagai user lain — **tidak diimplementasikan** di OptiFleet | — | Tenant Context |
| Tenant Isolation | — | Jaminan struktural satu tenant tidak melihat data operasional tenant lain | Global (prinsip arsitektur) | Seluruh sistem |
| Cross-tenant Aggregate | — | Data hasil agregasi/gabungan dari banyak tenant sekaligus | — | Dashboard, Audit Log Platform |
| RBAC | Role-Based Access Control | Sistem kendali akses berbasis kumpulan permission dalam Role | Global | Access Management |
| ETL | Extract-Transform-Load | Proses pengolahan data transaksi menjadi data warehouse analitik | Tenant-specific (dipicu Platform) | Analytics Administration |
| Maker-Checker | — | Pola dua pihak: pengusul ≠ penyetuju — berlaku juga di beberapa alur komersial (mis. Correction/Cancellation Workshop Invoice di sisi tenant) | — | Referensi arsitektur umum |

## Y. Lampiran

- `OPTIFLEET_PLATFORM_ROLE_PERMISSION_MATRIX.md` — matriks permission Superadmin lengkap.
- `OPTIFLEET_PLATFORM_DATA_DICTIONARY.md` — kamus status lengkap & kamus data Platform.
- `OPTIFLEET_PLATFORM_TRACEABILITY_MATRIX.md` — traceability ke source code & dokumentasi Tenant.
- `OPTIFLEET_TENANT_GUIDE_REUSE_MATRIX.md` — daftar prosedur Tenant Guide yang dipakai ulang, disesuaikan, atau ditolak.
- `OPTIFLEET_PLATFORM_USER_GUIDE_FINDINGS.md` — daftar temuan/gap/area belum terverifikasi.
- `OPTIFLEET_PLATFORM_TENANT_COMPARISON.md` — tabel perbandingan lengkap Platform vs Tenant Portal.
- `OPTIFLEET_PLATFORM_MODULE_FEATURE_INVENTORY.md` — inventaris modul & fitur Platform.
- `OPTIFLEET_PLATFORM_BUSINESS_FLOW.md` — alur proses bisnis & analisis risiko lengkap.

---

## 11. Panduan End-to-End

### 11.1 Membuat dan Mengaktifkan Tenant
**Tujuan:** menghidupkan akses tenant baru. **Aktor:** Superadmin. **Scope:** Global→Satu tenant. **Prasyarat:** Bundle & Pricing sudah siap. **Langkah:** Bagian K.1 §8 "Mendaftarkan Tenant Baru" (langkah 1-6). **Status:** DRAFT→ACTIVE. **Output:** Tenant siap dipakai. **Dampak:** Tidak ada dampak ke tenant lain. **Audit trail:** tercatat `created`/`activated` pada AuditLog Platform. **Kondisi gagal:** Code sudah dipakai tenant lain → ulangi dengan Code berbeda. **Verifikasi keberhasilan:** badge status `ACTIVE` pada Tenant Detail.

### 11.2 Membuat Administrator Tenant
**Tujuan:** menyediakan akun pertama bagi tenant untuk mulai mengelola dirinya sendiri. **Aktor:** Superadmin. **Prasyarat:** Tenant sudah `ACTIVE`. **Langkah:** Bagian K.1 §8 langkah 7, atau Bagian M "Membuat Tenant User pertama". **Output:** akun tenant baru, status `active`, **belum berRole**. **Kondisi gagal/keterbatasan:** tidak ada cara menugaskan Role langsung dari Platform Portal — lihat catatan *ayam-dan-telur* pada Bagian M. **Verifikasi:** akun dapat login ke Tenant Portal (meski awalnya tanpa akses fitur apa pun sampai Role ditugaskan).

### 11.3 Menetapkan Role dan Permission
**Tujuan:** memberi hak akses pada Platform User. **Aktor:** Superadmin. **Langkah:** Bagian N §8. **Output:** Role dengan kumpulan permission tersinkron. **Kondisi gagal:** tidak ditemukan cara menugaskan Role baru ke Platform User lewat UI (lihat Findings) — eskalasi ke tim teknis.

### 11.4 Menyiapkan Data Awal Tenant (Kontrak, Entitlement, Kuota)
**Tujuan:** memastikan tenant benar-benar dapat memakai modul yang dibayar. **Aktor:** Superadmin. **Langkah:** K.4 (buat & approve Contract) → K.1 tab Module Entitlements (Grant modul) → K.1 tab Capacity Limits (atur kuota, opsional). **Output:** tenant memiliki akses modul & kuota sesuai kontrak. **Verifikasi:** login sebagai user tenant tersebut (atau minta konfirmasi mereka) dan pastikan menu yang diharapkan muncul di sidebar Tenant Portal.

### 11.5 Memilih atau Berpindah Tenant
**Tidak berlaku bagi Superadmin** — lihat Bagian L. Setiap fitur Platform yang beroperasi atas satu tenant memakai parameter rute eksplisit, bukan "tenant aktif" tersimpan.

### 11.6 Mengakses Data Tenant
**Tidak berlaku untuk data operasional** (lihat Bagian U). Untuk data administratif/komersial (Kontrak, Invoice, dst.), akses dilakukan lewat Bagian K dengan membuka tenant yang bersangkutan secara eksplisit dari daftar.

### 11.7 Menonaktifkan Tenant
**Tujuan:** menghentikan akses tenant (mis. kontrak berakhir tanpa perpanjangan, pelanggaran kebijakan). **Aktor:** Superadmin. **Langkah:** Bagian K.1 §8 "Menonaktifkan Tenant". **Peringatan:** tidak ada dialog konfirmasi tambahan; dampak seketika ke seluruh user tenant. **Verifikasi:** badge status `INACTIVE`; coba login sebagai user tenant tersebut harus ditolak.

### 11.8 Menangani User Tenant Bermasalah
**Tujuan:** menonaktifkan akses satu user tenant tertentu tanpa menonaktifkan seluruh tenant. **Aktor:** Superadmin. **Langkah:** Bagian K.1 tab Users → toggle status user yang bersangkutan ke `inactive`. **Kondisi gagal:** bila masalahnya adalah lupa password, tidak ada solusi lewat aplikasi (lihat W). **Verifikasi:** status user berubah menjadi badge "inactive" pada tabel.

### 11.9 Mengubah Master Data Global
**Tujuan:** memperbarui data referensi bersama seluruh tenant. **Status:** **tidak dapat dilakukan lewat Platform Portal** (Bagian O). **Prosedur sebenarnya:** eskalasi tertulis ke tim teknis, menyebutkan data apa yang perlu ditambah/diubah, untuk dieksekusi lewat pembaruan seeder/migrasi di lingkungan yang sesuai.

### 11.10 Memeriksa Audit Aktivitas Tenant
**Tujuan:** menelusuri siapa mengubah apa pada satu tenant tertentu. **Aktor:** Superadmin. **Langkah:** Bagian Q §8, filter berdasarkan `tenant_id`. **Output:** daftar log kronologis dengan nilai sebelum/sesudah. **Verifikasi:** hasil filter menampilkan baris yang relevan dengan aktivitas yang dicari.

### 11.11 Memantau Proses Lintas Tenant
**Tujuan:** memastikan pipeline ETL Analytics/Intelligence berjalan sehat untuk seluruh tenant. **Aktor:** Superadmin (dengan bantuan tim teknis, karena Backend Only). **Langkah:** Bagian T. **Kondisi gagal:** kegagalan ETL tercatat di `analytics_etl_runs` dengan `retry_count` — memerlukan tim teknis untuk memicu retry lewat API/CLI.

### 11.12 Menjalankan Proses Operasional atas Nama Tenant
**Tidak dapat dilakukan** — lihat Bagian U dan L. Tidak ada jalur bagi Superadmin untuk mengeksekusi aksi operasional (membuat Work Order, dsb.) atas nama tenant mana pun.

### 11.13 Keluar dari Tenant Context
**Tidak berlaku** — lihat Bagian L. Tidak ada konteks tenant yang pernah dimasuki Superadmin untuk kemudian keluar darinya.

### 11.14 Troubleshooting Konfigurasi
**Tujuan:** mendiagnosis mengapa suatu fitur/modul tidak berperilaku sesuai harapan bagi tenant tertentu. **Aktor:** Superadmin. **Langkah:** (1) periksa status Tenant (K.1) dan Subscription (K.5) — pastikan `ACTIVE`; (2) periksa Module Entitlement (K.1) — pastikan modul terkait ter-Grant; (3) periksa Capacity Limit bila masalahnya "tidak bisa menambah data baru"; (4) periksa Audit Log (Q) untuk melihat riwayat perubahan terkait; (5) bila akar masalah ada di Configuration/Master Data tenant itu sendiri, arahkan ke Admin tenant untuk memeriksa dari Tenant Portal mereka (di luar akses Superadmin).
