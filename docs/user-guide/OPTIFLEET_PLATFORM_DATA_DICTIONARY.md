# OptiFleet — Platform Portal Data Dictionary

**Branch/commit:** `Improvement` @ `05dd372e5bfeba9b920d3bf2d8676b2e4a908f8d`

## 12.1 Kamus Entitas

| Entitas | Nama Bisnis | Deskripsi | Scope | Modul Pemilik | Digunakan Oleh |
|---|---|---|---|---|---|
| `Tenant` | Perusahaan Pelanggan | Satu akun perusahaan yang berlangganan OptiFleet | Global (record dikelola Platform) | Tenant Management | Seluruh modul (setiap tabel tenant-scoped mereferensikan ini) |
| `TenantUser` | Keanggotaan Tenant | Penghubung satu User ke satu Tenant + status keanggotaan | Tenant-specific | Tenant Management | Login, Access Management |
| `User` (`user_type=platform`) | Platform User | Akun login Superadmin/Specialist | Global | Access Management | Login, RBAC |
| `User` (`user_type=tenant`) | Tenant User | Akun login pengguna tenant | Tenant-specific | Tenant Management (dibuat Platform) / Access Management (dikelola tenant sendiri) | Login, RBAC |
| `Role`/`Permission`/`RoleAssignment` (scope=platform) | Role & Hak Akses Platform | Kerangka RBAC untuk sisi platform | Global | Access Management | Seluruh endpoint `/platform/*` |
| `Module`/`ModuleDependency` | Katalog Modul | Daftar modul yang dapat dijual & relasi dependensinya | Global | Module Catalog | Bundle, Entitlement |
| `TenantModuleEntitlement` | Entitlement Modul | Hak akses tenant terhadap satu modul (aktif/tidak, sumber, periode berlaku) | Tenant-specific | Entitlement | Menggerbang seluruh menu/endpoint tenant |
| `TenantCapacityLimit` | Batas Kuota | Batas maksimum jumlah resource (branch/user/vehicle/dll.) per tenant | Tenant-specific | Entitlement | Validasi create pada Organization, dst. |
| `Bundle`/`BundleVersion`/`bundle_version_modules` | Paket Modul Komersial | Kelompok modul yang dijual sebagai satu produk, versi dibekukan saat publish | Global | ProductCatalog | Contract |
| `Pricing`/`PricingVersion` | Harga Standar | Daftar harga per priceable (modul/bundle/add-on/kapasitas) × frekuensi tagihan | Global (Shared Reference) | Pricing | Contract |
| `TenantCustomPricing` | Harga Khusus Tenant | Override harga standar untuk satu tenant tertentu | Tenant-specific | Pricing | Contract (prioritas di atas harga standar) |
| `Contract`/`ContractItem`/`ContractApproval`/`ContractAmendment` | Kontrak Komersial | Perjanjian resmi tenant — dasar penagihan & entitlement | Tenant-specific | Contract | Subscription |
| `Subscription` | Subscription | Representasi operasional kontrak yang sedang ditagih; menentukan status akses tenant | Tenant-specific | Subscription | Entitlement, gating seluruh modul operasional |
| `Billing`/`BillingItem` | Billing | Catatan tagihan per periode | Tenant-specific | Billing | Invoice |
| `Invoice`/`InvoiceItem` | Invoice | Dokumen tagihan resmi | Tenant-specific | Invoice | Payment |
| `Payment`/`PaymentProof` | Pembayaran | Bukti bayar tenant & status verifikasi | Tenant-specific | Payment | Invoice, Subscription |
| `AuditLog` | Log Audit | Riwayat perubahan data | Cross-tenant Aggregate (dilihat Platform), Tenant-specific (dilihat tenant) | Audit | Seluruh modul |
| `analytics_etl_runs` (Mongo) | Riwayat Jalankan ETL | Metadata setiap eksekusi ETL per tenant×dataset×tanggal | Tenant-specific (dipicu/dipantau Platform) | Analytics | Dashboard Analytics tenant |
| `intelligence_models` (Mongo) | Model Registry | Versi model ML/registry, dapat berscope GLOBAL atau TENANT | Global atau Tenant-specific | Intelligence | Prediction Service |

## 12.2 Kamus Field — Entitas Kunci

### Tenant

| Field UI | Nama Teknis | Definisi | Tipe Data | Format | Wajib | Default | Validasi | Contoh | Scope | Sensitivitas |
|---|---|---|---|---|---|---|---|---|---|---|
| Code | `code` | Kode unik tenant | string | bebas | Ya | — | `required, unique, max:50` | `ACME` | Global | Rendah |
| Name | `name` | Nama tampilan tenant | string | bebas | Ya | — | `required, max:255` | `PT Alpha Fleet` | Global | Rendah |
| Legal Name | `legal_name` | Nama badan hukum resmi | string | bebas | Tidak | null | `nullable, max:255` | `PT Alpha Fleet Indonesia` | Tenant-specific | Sedang |
| Industry | `industry` | Sektor industri tenant | string | bebas | Tidak | null | `nullable, max:255` | `Logistics` | Tenant-specific | Rendah |
| Status | `status` | Status akun tenant | enum | — | Sistem | `DRAFT` | `in:DRAFT,ACTIVE,INACTIVE,SUSPENDED` (hanya `DRAFT`/`ACTIVE`/`INACTIVE` yang benar-benar dapat dicapai lewat UI) | `ACTIVE` | Global | Rendah |
| Tax ID | `tax_id` | NPWP tenant | string | bebas | Tidak | null | `nullable, max:100` | — | Tenant-specific | **Tinggi** (identitas pajak) |
| Address/Phone/Email/Website | `address`/`phone`/`email`/`website` | Data kontak tenant | string | bebas/email | Tidak | null | `nullable` (email divalidasi format) | — | Tenant-specific | Sedang |

### Subscription

| Field UI | Nama Teknis | Definisi | Tipe | Wajib | Validasi | Contoh |
|---|---|---|---|---|---|---|
| Status | `status` | Status akses operasional tenant | enum | Sistem | `PENDING,ACTIVE,EXPIRING,PAST_DUE,GRACE_PERIOD,SUSPENDED,EXPIRED,CANCELLED` | `ACTIVE` |
| Next Billing Date | `next_billing_date` | Tanggal periode tagihan berikutnya | date | Sistem | dihitung otomatis | — |
| Grace Period End | `grace_period_end` | Batas akhir masa tenggang sebelum suspend | date | Sistem (nullable) | dihitung dari `contract.grace_period_days` | — |

### Invoice

| Field UI | Nama Teknis | Definisi | Tipe | Wajib | Validasi | Contoh |
|---|---|---|---|---|---|---|
| No. Invoice | `invoice_number` | Nomor dokumen | string, unik | Sistem | Format `INV/OPTIFLEET/{tahun}/{6 digit}` | `INV/OPTIFLEET/2026/000045` |
| Status | `status` | Status pelunasan | enum | Sistem | `DRAFT,ISSUED,OUTSTANDING,PARTIALLY_PAID,PAID,OVERDUE,VOID` | `OUTSTANDING` |
| Total/Paid/Outstanding Amount | `total`/`paid_amount`/`outstanding_amount` | Nilai tagihan & pelunasan | decimal(14,2) | Sistem (dihitung ulang server) | Tidak pernah diterima mentah dari klien | Rp 5.000.000 |

### Payment

| Field UI | Nama Teknis | Definisi | Tipe | Wajib | Validasi | Contoh |
|---|---|---|---|---|---|---|
| Payment Method | `payment_method` | Metode pembayaran | string bebas | Ya | Tidak ada daftar `in:` di backend (bebas teks) | `BANK_TRANSFER` |
| Status | `status` | Status verifikasi | enum | Sistem | `DRAFT,SUBMITTED,UNDER_REVIEW,VERIFIED,REJECTED,REVERSED*` | `VERIFIED` |
| Bukti Bayar | `PaymentProof.path` | Berkas bukti transfer | file (disimpan privat) | Ya | JPG/PNG/WEBP/PDF, maks 5MB, nama file di-UUID-kan | — |

`*` `REVERSED` terdaftar di enum tetapi tidak pernah di-set oleh service manapun (dead value, konsisten dengan temuan Tenant Guide F-20 s.d. F-22 kategori serupa).

## 12.3 Kamus Status

| Status UI | Nilai Teknis | Entitas | Arti | Pemicu | Actor | Dampak |
|---|---|---|---|---|---|---|
| Status Tenant | `DRAFT, ACTIVE, INACTIVE, SUSPENDED*` | Tenant | Kondisi akun tenant | Create (→DRAFT) / Activate / Deactivate | Superadmin | ACTIVE: user tenant dapat login; INACTIVE: seluruh login tenant ditolak |
| Status Subscription | `PENDING, ACTIVE, EXPIRING, PAST_DUE, GRACE_PERIOD, SUSPENDED, EXPIRED, CANCELLED` | Subscription | Kondisi akses operasional tenant | Approval kontrak / pembayaran / dunning otomatis / Suspend-Reactivate manual | Sistem (mayoritas) / Superadmin (Suspend/Reactivate manual) | SUSPENDED mengunci seluruh fitur operasional tenant |
| Status Kontrak | `DRAFT, PENDING_APPROVAL, APPROVED, ACTIVE, EXPIRING, EXPIRED, REJECTED, CANCELLED, TERMINATED` | Contract | Tahap siklus kontrak | Submit/Approve/(otomatis via Subscription)/Terminate | Superadmin | APPROVED memicu pembuatan Subscription otomatis |
| Status Invoice | `DRAFT, ISSUED, OUTSTANDING, PARTIALLY_PAID, PAID, OVERDUE, VOID` | Invoice | Tahap penagihan | Generate/Bayar/Void/Dunning otomatis | Sistem / Superadmin (Void) | VOID membatalkan tagihan (tidak bisa jika sudah PAID) |
| Status Payment | `DRAFT, SUBMITTED, UNDER_REVIEW, VERIFIED, REJECTED, REVERSED*` | Payment | Tahap verifikasi | Submit (tenant) / Verify/Reject (Superadmin) | Tenant + Superadmin | VERIFIED dapat memicu reaktivasi Subscription |
| Status Bundle | `DRAFT, PUBLISHED, ARCHIVED*` | Bundle | Tahap siklus bundle | Publish | Superadmin | PUBLISHED membekukan snapshot modul untuk kontrak baru |
| Status Billing | `DRAFT, GENERATED, INVOICED, PAID, PARTIALLY_PAID, PAST_DUE, CANCELLED` | Billing | Tahap pemrosesan tagihan periode | Generate otomatis/manual → Invoice → sinkron dari Payment | Sistem | — |

`*` Nilai terkonfirmasi **tidak pernah** benar-benar tercapai lewat kode aktif manapun (lihat `OPTIFLEET_PLATFORM_USER_GUIDE_FINDINGS.md`).

## 12.4 Kamus Enum

| Enum | Nilai | Label | Arti | Digunakan Pada |
|---|---|---|---|---|
| Tipe Priceable | `MODULE, BUNDLE, ADD_ON, CAPACITY` | — | Jenis objek yang diberi harga | Pricing |
| Metode Pricing | `FLAT, PER_VEHICLE, PER_USER, PER_BRANCH, PER_WORKSHOP, PER_WAREHOUSE, TIERED, CUSTOM` | — | Cara hitung harga | Pricing |
| Frekuensi Tagihan | `MONTHLY, QUARTERLY, SEMIANNUAL, ANNUAL, CUSTOM` | — | Siklus penagihan | Pricing, Contract |
| Tipe Item Kontrak | `BUNDLE, MODULE, ADD_ON, CAPACITY, SETUP_FEE, OTHER` | — | Jenis baris item kontrak | ContractItem |
| Resource Type (Capacity) | `branch, user, vehicle, workshop, warehouse` | — | Jenis resource yang dibatasi kuotanya | TenantCapacityLimit |
| Kategori Modul | `Foundation, Fleet, Maintenance, Supply Chain, Intelligence, Reporting` | — | Pengelompokan modul di katalog | Module |
| Status Model Intelligence | `DRAFT, TRAINING, EVALUATED, ACTIVE, RETIRED, FAILED` | — | Tahap siklus model ML | IntelligenceModel |
| Scope Model Intelligence | `GLOBAL, TENANT` | — | Model berlaku untuk semua tenant atau satu tenant tertentu | IntelligenceModel |

## 12.5 Kamus Scope Data

| Data | Global | Tenant-Specific | Shared | System-Generated | Catatan |
|---|:-:|:-:|:-:|:-:|---|
| Tenant record | ✓ | | | | Dikelola sepenuhnya oleh Platform |
| Bundle/Pricing standar | ✓ | | ✓ | | Dipakai lintas tenant kecuali di-override |
| TenantCustomPricing | | ✓ | | | |
| Contract/Subscription/Billing/Invoice/Payment | | ✓ | | Invoice & Billing dibuat otomatis | |
| Platform User/Role/Permission | ✓ | | | | |
| AuditLog | | ✓ per baris | | ✓ | Platform melihat gabungan (Cross-tenant Aggregate) |
| Platform Dashboard summary | | | | ✓ | Hasil agregasi COUNT lintas tabel |
| Module Entitlement/Capacity Limit | | ✓ | | | Ditetapkan Platform, berlaku per tenant |

## 12.6 Data Ownership dan Lineage

| Data | Dibuat Oleh | Dibuat di Modul | Scope | Diperbarui Oleh | Digunakan Oleh | Dampak Perubahan |
|---|---|---|---|---|---|---|
| Tenant | Superadmin | Tenant Management | Global | Superadmin | Seluruh sistem | Deactivate mengunci login seluruh user tenant |
| Contract | Superadmin | Contract Management | Tenant-specific | Superadmin (amend/renew/terminate) | Subscription | Approve memicu Subscription+Billing+Invoice otomatis |
| Subscription | Sistem (dari Contract) | Subscription | Tenant-specific | Sistem (dunning) + Superadmin (suspend/reactivate manual) | Entitlement, seluruh gating modul operasional | Status menentukan bisa/tidaknya tenant memakai aplikasi |
| Module Entitlement | Sistem (dari approval Subscription) atau Superadmin (manual grant/revoke) | Entitlement | Tenant-specific | Superadmin | Sidebar & middleware `module:` tenant | Efek langsung seketika ke akses menu |
| AuditLog | Sistem (otomatis, trait `Auditable`) | Audit | Tenant-specific + Cross-tenant view | Tidak pernah diperbarui (append-only) | Investigasi, kepatuhan | — |

## 12.7 Data Sensitif

| Data | Sensitivitas | Perlakuan |
|---|---|---|
| Password Platform/Tenant User | Kredensial | Di-hash; **tidak ada mekanisme lihat/reset** di aplikasi manapun |
| Token sesi Sanctum | Kredensial | Tersimpan `localStorage`; hanya token aktif yang dicabut saat logout |
| Bukti Pembayaran (file) | Finansial | Disk privat, nama file UUID, diunduh hanya lewat endpoint terautentikasi + pemeriksaan kepemilikan |
| Tax ID / NPWP Tenant | Identitas pajak | Hanya terlihat oleh permission `tenant.view` |
| Nilai Kontrak/Invoice/Harga | Finansial/komersial | Hanya terlihat oleh permission Platform terkait dan tenant pemiliknya sendiri (Account) |
| AuditLog | Riwayat perubahan data lain | Password dikecualikan; hanya untuk permission `audit.view` |

**Peringatan:** dokumen ini tidak pernah menyalin nilai password, token, API key, atau isi `.env` aktual.
