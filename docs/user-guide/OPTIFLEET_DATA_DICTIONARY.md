# OptiFleet — Data Dictionary (Kamus Data)

**Branch/commit:** `Improvement` @ `05dd372e5bfeba9b920d3bf2d8676b2e4a908f8d`
**Sumber:** migration files (`backend/database/migrations/*.php`), model files (`backend/app/Domain/*/Models/*.php`), FormRequest validation, seeder.

> **Catatan:** Dokumen ini fokus pada entitas dan field yang paling sering dipakai pengguna bisnis. Untuk pemetaan teknis lengkap setiap fitur ke file kode, lihat `OPTIFLEET_TRACEABILITY_MATRIX.md`.

---

## 5.1 Kamus Entitas

| Entitas | Nama Bisnis | Deskripsi | Modul Pemilik | Sumber Data | Digunakan Oleh |
|---|---|---|---|---|---|
| `Tenant` | Perusahaan Pelanggan | Satu perusahaan yang berlangganan OptiFleet | Identity (Platform) | Dibuat Platform Superadmin | Seluruh modul tenant |
| `User` | Pengguna | Akun login (platform atau tenant) | AccessControl | Registrasi oleh Admin | Login, RBAC |
| `Role` / `Permission` / `RoleAssignment` | Role / Hak Akses | Kerangka RBAC dinamis | AccessControl | Dibuat Admin/Superadmin | Seluruh modul |
| `DataScopeAssignment` | Lingkup Data | Batas akses data (cabang/bengkel/gudang) per user | AccessControl | Ditugaskan Admin | Organization, Vehicle, Inventory, dst. |
| `Branch`/`Workshop`/`Warehouse` | Cabang/Bengkel/Gudang | Struktur organisasi fisik tenant | Organization | Diinput Admin | Semua modul operasional |
| `VehicleCategory`/`VehicleBrand`/`VehicleModel`/`ComponentGroup` | Master Data Kendaraan | Data referensi klasifikasi kendaraan & komponen | MasterData | Platform (system) + Admin tenant | Vehicle, Maintenance Policy, Tire |
| `Product`/`ProductCategory`/`Uom` | Master Produk | Katalog spare part/ban/tools & satuan | ProductMaster | Platform (system) + Admin tenant | Inventory, Procurement, Tire |
| `Vehicle` | Kendaraan | Aset kendaraan tenant | Vehicle | Diinput Fleet Manager | Inspection, Maintenance, WorkOrder, Tire, History |
| `VehicleTransfer`/`VehicleAssignment`/`VehicleDocument` | Transfer/Penugasan/Dokumen Kendaraan | Sub-entitas siklus penempatan & legal kendaraan | Vehicle | Fleet Manager | Organization, History |
| `InspectionTemplate`/`Inspection`/`InspectionResult`/`InspectionFinding` | Template & Pelaksanaan Inspeksi | Checklist & hasil pemeriksaan kendaraan | Inspection | Diinput Admin (template), Mechanic (hasil) | Maintenance Request, History |
| `MaintenancePackage`/`MaintenanceInterval`/`MaintenancePackageItem`/`VehicleMaintenanceProfile`/`MaintenanceSchedule` | Paket & Skedul Perawatan | Definisi & status jatuh tempo perawatan preventif | MaintenancePolicy | Admin | Work Order |
| `MaintenanceRequest` | Permintaan Perawatan | Permintaan servis sebelum jadi WO | MaintenanceRequest | Berbagai sumber (User/Inspeksi/Breakdown) | Work Order, History |
| `Breakdown` | Kerusakan Mendadak | Insiden kerusakan lapangan | Breakdown | Fleet Manager/Driver | Maintenance Request, Work Order, Vehicle status |
| `WorkOrder` dan sub-entitasnya (`WorkOrderFinding`, `WorkOrderDiagnosis`, `WorkOrderCorrectiveAction`, `MaintenanceJob`, `WorkOrderPlannedPart`, `WorkOrderAdditionalWork`, `WorkOrderExternalService`) | Perintah Kerja | Transaksi inti eksekusi perawatan | WorkOrder | Workshop Manager/Mechanic | Inventory, Tire, QC, Vehicle Release, Workshop Invoice, History, Analytics |
| `WorkshopInvoice`/`WorkshopInvoiceCorrection`/`WorkshopInvoiceCancellation`/`WorkshopInvoicePayment` | Invoice & Pelunasan Bengkel Eksternal | Pencatatan invoice yang diterbitkan Partner | WorkOrder (R1) | Warehouse/Finance | Partner, Integration Outbox |
| `Worker`/`WorkerSkill`/`Workspace`/`WorkspaceReservation`/`WorkOrderMechanicAssignment`/`WorkOrderLaborLog` | Tenaga Kerja & Fasilitas Bengkel | Data mekanik, bay servis, penugasan, jam kerja | Workshop | Workshop Manager | Work Order |
| `QcInspection`/`QcFinding`/`RoadTest` | Kontrol Kualitas | Pemeriksaan mutu sebelum WO selesai | QualityControl | Lead Mechanic/QC | Work Order |
| `VehicleRelease` | Rilis Kendaraan | Bukti kendaraan dikembalikan ke operasi | VehicleRelease | Workshop Manager | Vehicle, Work Order, History |
| `WarehouseStock`/`StockMovement`/`StockTransfer`/`StockOpname`/`StockReservation` | Stok Gudang | Saldo & ledger pergerakan stok | Inventory | Warehouse | Work Order, Procurement, Analytics |
| `WorkOrderPartReturn`/`SparePartSale` | Pengembalian & Penjualan Part Bekas | Disposisi part bekas hasil WO | WorkOrder/Inventory | Warehouse | Inventory |
| `PurchaseRequest`/`Rfq`/`VendorQuotation`/`PurchaseOrder`/`GoodsReceipt`/`VendorInvoiceReference` | Pengadaan | Rangkaian proses pembelian barang | Procurement | Procurement staff | Inventory, Partner |
| `Partner` | Mitra/Vendor | Master vendor, bengkel eksternal, supplier | Partner | Procurement | Procurement, Tire, Warranty, Workshop Invoice |
| `Tire`/`TireInstallation`/`TireRotation`/`TireInspection`/`TireRemoval`/`TireRetread`/`TireRepair`/`TireScoringResult`/`TireSale`/`WheelConfiguration`/`Rim` | Aset Ban | Siklus hidup ban bernomor seri | Tire | Mechanic/Workshop Manager | Vehicle, Warranty, Analytics, Intelligence |
| `ComponentAsset`/`ComponentInstallation`/`ComponentRemoval`/`ComponentRepair` | Aset Komponen | Siklus hidup komponen bernomor seri | ComponentAsset | Mechanic | Vehicle, Warranty |
| `Warranty`/`WarrantyClaim` | Garansi & Klaim | Cakupan garansi & proses klaim | Warranty | Admin/Fleet Manager | Vehicle, Product, ComponentAsset, Tire |
| `ConfigurationSet`/`ConfigurationVersion` | Konfigurasi Bervesi | Kerangka konfigurasi Numbering/Template/Workflow/Notification/Tire Scoring | Configuration | Admin | Seluruh dokumen bernomor & mesin status |
| `WorkflowApprovalRequest`/`WorkflowApprovalStep` | Permintaan Persetujuan | Approval bertingkat konfigurable | Workflow | Sistem (dipicu event) | Procurement (PO tiered) |
| `NotificationRule`/`NotificationDeliveryLog`/`NotificationInAppMessage` | Aturan & Log Notifikasi | Pengaturan & riwayat pengiriman notifikasi | Notification | Admin | Seluruh modul (sumber event) |
| `Bundle`/`BundleVersion`/`Module`/`ModuleDependency` | Paket Modul Komersial | Definisi produk yang dijual ke tenant | ProductCatalog | Platform Superadmin | Contract |
| `Pricing`/`PricingVersion`/`TenantCustomPricing` | Harga | Daftar harga standar & khusus tenant | Pricing | Platform Superadmin | Contract |
| `Contract`/`ContractItem`/`ContractApproval`/`ContractAmendment` | Kontrak Komersial | Perjanjian tenant | Contract | Platform Superadmin | Subscription, Billing |
| `Subscription` | Subscription | Representasi operasional dari kontrak yang aktif ditagih | Subscription | Sistem (otomatis dari kontrak) | Entitlement, semua modul (gating akses) |
| `Billing`/`BillingItem` | Billing | Catatan tagihan per periode | Billing | Sistem (scheduler harian) | Invoice |
| `Invoice`/`InvoiceItem` | Invoice | Dokumen tagihan resmi ke tenant | Invoice | Sistem (dari Billing) | Payment |
| `Payment`/`PaymentProof` | Pembayaran | Bukti bayar tenant & status verifikasi | Payment | Admin Tenant | Invoice, Subscription |
| `AuditLog` | Log Audit | Riwayat perubahan data | Audit | Sistem (otomatis) | Seluruh modul |
| `daily_*` (koleksi MongoDB, 15 dataset) | Data Warehouse Analitik | Proyeksi analitik harian read-only | Analytics | ETL terjadwal | Dashboard Analytics |
| `vehicle_daily_features`/`intelligence_predictions`/`intelligence_recommendations`/`intelligence_models` | Fitur & Prediksi ML | Data pendukung Maintenance Intelligence | Intelligence | ETL/ML pipeline terjadwal | Dashboard Intelligence |

---

## 5.2 Kamus Field — Entitas Utama

### Vehicle

| Field UI | Nama Teknis | Definisi | Tipe | Wajib | Sumber | Validasi | Contoh |
|---|---|---|---|---|---|---|---|
| Registration Number | `registration_number` | Nomor polisi/registrasi kendaraan | string | Tidak (tapi disarankan) | Input user | Unik per tenant (NULL diperbolehkan ganda) | `B 1234 XYZ` |
| VIN | `vin` | Vehicle Identification Number | string | Tidak | Input user | Unik per tenant | `MH1JF...` |
| Chassis Number | `chassis_number` | Nomor rangka | string | Tidak | Input user | Unik per tenant | — |
| Branch | `branch_id` | Cabang pemilik kendaraan | uuid FK | Ya | Pilihan dari Organization | Harus ada di tenant yang sama | — |
| Vehicle Category | `vehicle_category_id` | Kategori kendaraan | uuid FK | Ya | Master Data | — | Truck |
| Current Odometer | `current_odometer` | Jarak tempuh saat ini | decimal(12,2) | Tidak, default 0 | Diinput/diperbarui otomatis via inspeksi/WO | Tidak pernah berkurang otomatis | 125000.50 |
| Engine Hour | `engine_hour` | Jam operasi mesin | decimal(12,2) | Tidak | Input user | — | 3200.00 |
| Status | `status` | Status sistem kendaraan | enum | Sistem/manual | Lihat Kamus Status | — | `ACTIVE` |
| Operational Status | `operational_status` | Status ketersediaan operasional | enum (`AVAILABLE`/`IN_USE`/`ON_HOLD`) | Sistem/manual | — | — | `AVAILABLE` |

### Inspection

| Field UI | Nama Teknis | Definisi | Tipe | Wajib | Validasi |
|---|---|---|---|---|---|
| Inspection Type | `inspection_type` | Jenis inspeksi | enum (`PRE_TRIP,POST_TRIP,PERIODIC,WORKSHOP,MAINTENANCE`) | Ya | Mengikuti tipe template |
| Status | `status` | Status pelaksanaan | enum | Sistem | Lihat Kamus Status |
| Odometer at Inspection | `odometer_at_inspection` | Odometer saat inspeksi dilakukan | decimal(12,2) | Tidak | Menaikkan odometer kendaraan jika lebih besar |
| Severity (Finding) | `severity` | Tingkat keparahan temuan | enum (`INFO,LOW,MEDIUM,HIGH,CRITICAL`) | Ya (per temuan) | Menentukan hasil akhir inspeksi |

### Work Order

| Field UI | Nama Teknis | Definisi | Tipe | Wajib | Validasi | Contoh |
|---|---|---|---|---|---|---|
| No. WO | `wo_number` | Nomor dokumen WO | string, unik | Sistem (auto) | Format `WO/OPTIFLEET/{tahun}/{6 digit}` | `WO/OPTIFLEET/2026/000123` |
| Maintenance Type | `maintenance_type` | Jenis pekerjaan | enum (`PREVENTIVE,CORRECTIVE,BREAKDOWN,INSPECTION,CAMPAIGN`) | Ya | — | `CORRECTIVE` |
| Priority | `priority` | Urgensi | enum (`LOW,MEDIUM,HIGH,URGENT`) | Tidak, default MEDIUM | — | — |
| Status | `status` | Status siklus WO (14 nilai) | enum | Sistem | Lihat Kamus Status | `IN_PROGRESS` |
| Estimated Labor/Parts Cost | `estimated_labor_cost`/`estimated_parts_cost` | Estimasi biaya | decimal(16,4) | Tidak | Perhitungan Money (2 desimal tampilan) | Rp 500.000 |

### Tire

| Field UI | Nama Teknis | Definisi | Tipe | Wajib | Validasi |
|---|---|---|---|---|---|
| Serial Number | `serial_number` | Nomor seri unik ban (identitas fisik) | string | Ya | Unik per tenant; spasi tepi dipangkas otomatis |
| Current Status | `current_status` | Status siklus hidup ban | enum (12 nilai) | Sistem | Lihat Kamus Status |
| Current Position | `current_position` | Posisi roda saat ini (bila terpasang) | string | Sistem | Satu posisi = satu ban aktif (dijaga di level database) |
| Rim Diameter (inch) | `rim_diameter_inch` | Diameter pelek yang cocok | decimal(1dp) | Tidak | Data spesifikasi ban, **bukan** tertaut ke entitas Rim |

### Contract / Invoice / Payment (Ringkas — Platform)

| Field UI | Nama Teknis | Definisi | Tipe | Wajib | Validasi |
|---|---|---|---|---|---|
| Contract Number | `contract_number` | Nomor kontrak | string, unik | Sistem | Format `CTR/OPTIFLEET/{tahun}/{6 digit}` |
| Status (Contract) | `status` | Status siklus kontrak | enum (9 nilai) | Sistem | Lihat Kamus Status |
| Total | `subtotal/discount/tax/total` | Nilai kontrak/invoice | decimal(14,2) | Sistem (dihitung ulang server, tidak pernah diterima dari klien) | Selalu dihitung ulang dari baris item |
| Invoice Number | `invoice_number` | Nomor invoice | string, unik | Sistem | Format `INV/OPTIFLEET/{tahun}/{6 digit}` |
| Paid Amount | `paid_amount` | Total yang telah diverifikasi lunas | decimal(14,2) | Sistem | Dihitung dari seluruh Payment berstatus VERIFIED |

---

## 5.3 Kamus Status (Ringkasan Lintas Modul)

| Nama Status | Nilai Teknis | Entitas | Arti Bisnis | Pemicu | Role Terkait |
|---|---|---|---|---|---|
| Status Kendaraan | `ACTIVE, IN_MAINTENANCE, BREAKDOWN, OUT_OF_SERVICE, INACTIVE, DISPOSED` | Vehicle | Kondisi operasional kendaraan | Manual atau otomatis (breakdown/release) | Fleet Manager |
| Status Inspeksi | `CREATED, ASSIGNED, STARTED, SUBMITTED, PASSED, WARNING, FAILED` | Inspection | Tahap pelaksanaan & hasil | Assign/Start/Submit | Mechanic/Inspector |
| Status Skedul Perawatan | `UPCOMING, DUE_SOON, DUE, OVERDUE, SCHEDULED*, COMPLETED` | MaintenanceSchedule | Urgensi jatuh tempo | Evaluasi otomatis berdasarkan odometer/tanggal | Sistem |
| Status Permintaan Perawatan | `DRAFT, SUBMITTED, UNDER_REVIEW, APPROVED, WORK_ORDER_CREATED, REJECTED, NEED_INFORMATION, CANCELLED` | MaintenanceRequest | Tahap persetujuan | Submit/Review/Approve/Reject | Workshop Manager |
| Status Breakdown | `REPORTED, VERIFIED, ASSESSED, REPAIR_REQUIRED, WORK_ORDER_CREATED, RESOLVED` | Breakdown | Tahap penanganan kerusakan | Verify/Assess/Require Repair/Resolve | Fleet Manager |
| Status Work Order | `DRAFT, SUBMITTED, APPROVED, ASSIGNED, SCHEDULED, IN_PROGRESS, ON_HOLD, WAITING_PART, QC_PENDING, REWORK, COMPLETED, CLOSED, REJECTED, CANCELLED` | WorkOrder | Tahap eksekusi perawatan | Lihat Business Flow §8 | Workshop Manager/Mechanic |
| Status QC | `QC_PENDING, QC_STARTED, PASS, FAIL, COMPLETED` | QcInspection | Tahap kontrol kualitas | Start/Pass/Fail/Complete | Lead Mechanic/QC |
| Status Planned Part | `PLANNED, REQUESTED, RESERVED, PARTIALLY_RESERVED, ISSUED, PARTIALLY_ISSUED, CONSUMED, RETURNED, CANCELLED` | WorkOrderPlannedPart | Tahap pergerakan stok untuk WO | Reserve/Issue/Consume/Return | Warehouse |
| Status Workshop Invoice | `RECORDED, CORRECTION_REQUESTED, CANCELLATION_REQUESTED, CANCELLED` | WorkshopInvoice | Tahap pencatatan invoice eksternal | Record/Request Correction/Cancel | Warehouse/Finance |
| Status Maintenance Memo | `REQUESTED, COMPLETED, CANCELLED, BILLED, PAID` | WorkOrderExternalService | Tahap layanan eksternal & pelunasan | Complete/Record Invoice/Upload Payment | Workshop Manager |
| Status Ban | `IN_STOCK, RESERVED, INSTALLED, IN_USE, REMOVED, UNDER_INSPECTION, RETREAD, REPAIR, QUARANTINED, SCRAPPED, SOLD, LOST` | Tire | Tahap siklus hidup ban | Install/Remove/Retread/Repair/Approve/Sell | Mechanic |
| Status Disposisi Part Bekas | `PENDING_INSPECTION, INSPECTED, PENDING_APPROVAL, FINALIZED, REJECTED` | WorkOrderPartReturn | Tahap disposisi part bekas | Inspect/Propose/Decide | Warehouse |
| Status Sale (Sparepart) | `DRAFT, PENDING_APPROVAL, APPROVED, REJECTED` (disposisi Sparepart Sale) | SparePartSale | Tahap penjualan part bekas | Submit/Decide | Warehouse |
| Status Stock Transfer | `DRAFT, REQUESTED, APPROVED, PREPARED, DISPATCHED, IN_TRANSIT, RECEIVED, COMPLETED, REJECTED, CANCELLED` | StockTransfer | Tahap perpindahan stok antar gudang | Submit/Approve/Dispatch/Receive | Warehouse |
| Status Purchase Request | `DRAFT, SUBMITTED, UNDER_REVIEW, APPROVED, PROCUREMENT, REJECTED, CANCELLED` | PurchaseRequest | Tahap permintaan pembelian | Submit/Review/Approve | Procurement |
| Status Purchase Order | `DRAFT, SUBMITTED, APPROVED, ISSUED, PARTIALLY_RECEIVED, RECEIVED, CLOSED, REJECTED, CANCELLED` | PurchaseOrder | Tahap order pembelian | Submit/Approve/Issue/Receive | Procurement |
| Status Klaim Garansi | `DRAFT, SUBMITTED, UNDER_REVIEW, APPROVED, REPLACEMENT/REPAIR, SETTLED, CLOSED, REJECTED` | WarrantyClaim | Tahap klaim garansi | Submit/Review/Approve/Settle | Fleet Manager |
| Status Kontrak | `DRAFT, PENDING_APPROVAL, APPROVED, ACTIVE, EXPIRING, EXPIRED, REJECTED, CANCELLED, TERMINATED` | Contract | Tahap siklus kontrak komersial | Submit/Approve/(otomatis via Subscription)/Terminate | Platform Superadmin |
| Status Subscription | `PENDING, ACTIVE, EXPIRING, PAST_DUE, GRACE_PERIOD, SUSPENDED, EXPIRED, CANCELLED` | Subscription | Tahap akses operasional tenant | Approval kontrak/Pembayaran/Dunning otomatis | Platform Superadmin |
| Status Invoice | `DRAFT, ISSUED, OUTSTANDING, PARTIALLY_PAID, PAID, OVERDUE, VOID` | Invoice | Tahap penagihan | Generate/Bayar/Void | Platform Superadmin |
| Status Payment | `DRAFT, SUBMITTED, UNDER_REVIEW, VERIFIED, REJECTED, REVERSED*` | Payment | Tahap verifikasi bukti bayar | Submit/Verify/Reject | Platform Superadmin |

`*` = nilai status ada di database namun **tidak pernah benar-benar dipakai/di-set oleh kode manapun** — dicatat di sini agar pengguna tidak salah menafsirkan status yang muncul (lihat Findings).

---

## 5.4 Kamus Enum dan Referensi (Tambahan yang Sering Ditanya)

| Nama Referensi | Nilai | Arti | Digunakan Pada |
|---|---|---|---|
| Trigger Interval Perawatan | `ODOMETER, ENGINE_HOUR, CALENDAR_DAY, MONTH, COMBINATION, CONDITION_BASED` | Basis pemicu jatuh tempo servis | Maintenance Interval |
| Tipe Sumber Permintaan Perawatan | `USER, INSPECTION, SCHEDULE, BREAKDOWN, TELEMATICS, MECHANIC, INTELLIGENCE` | Asal permintaan | Maintenance Request (hanya USER/INSPECTION/BREAKDOWN/INTELLIGENCE yang punya jalur otomatis) |
| Keparahan Breakdown | `MINOR, MAJOR, IMMOBILIZED` | Tingkat kerusakan | Breakdown |
| Tipe Item Checklist Inspeksi | `CHECKBOX, PASS_FAIL, TEXT, NUMBER, SELECT, PHOTO` | Jenis input checklist | Inspection Template Item (SELECT/PHOTO belum sepenuhnya didukung UI) |
| Tipe Bengkel | `INTERNAL, SATELLITE, MOBILE` | Klasifikasi bengkel | Workshop |
| Tipe Gudang | `CENTRAL, BRANCH, WORKSHOP, TIRE, CONSUMABLE, SCRAP, QUARANTINE` | Klasifikasi gudang | Warehouse |
| Tipe Bay (Workspace) | `GENERAL_SERVICE_BAY, HEAVY_VEHICLE_BAY, INSPECTION_BAY, ELECTRICAL_BAY, TIRE_BAY, QC_BAY, WASHING_BAY, PARKING_LOT, HOLDING_AREA, OTHER` | Jenis area kerja bengkel | Workspace |
| Tipe Pekerja (bukan role!) | `LEAD_MECHANIC, MECHANIC, TECHNICIAN, INSPECTOR, QC` | Atribut keahlian/penjadwalan | Worker |
| Movement Type (Ledger Stok) | `OPENING, RECEIPT, RESERVATION, RELEASE_RESERVATION, ISSUE, RETURN, TRANSFER_OUT, TRANSFER_IN, ADJUSTMENT_PLUS, ADJUSTMENT_MINUS, STOCK_OPNAME, SCRAP, CONSUME, SALE` | Jenis mutasi stok | Stock Movement |
| Disposisi Part Bekas | `REPAIR, REUSE, QUARANTINE, SCRAP, SELL_ELIGIBLE` | Keputusan tindak lanjut part bekas | Work Order Part Return |
| Jenis Jual Sparepart | `OPERATIONAL_REUSE, SCRAP_MATERIAL` | Jenis penjualan part bekas (hanya 2 jenis) | Sparepart Sale |
| Jenis Jual Ban | `SELL_FOR_OPERATIONAL_REUSE, SELL_AS_RETREADABLE_CASING, SELL_AS_SCRAP_OR_RECYCLABLE_MATERIAL` | Jenis penjualan ban (3 jenis — beda dari Sparepart Sale) | Tire Sale |
| Disposisi Lepas Ban | `REUSE, RETREAD, REPAIR, SCRAP` | Tindak lanjut ban yang dilepas | Tire Removal |
| Hasil Inspeksi Akhir Retread/Repair | `SAFE, UNSAFE` | Keselamatan ban pasca servis eksternal | Tire Retread/Repair |
| Disposisi Persetujuan Retread/Repair | `RETURN_TO_SERVICE, SCRAP, QUARANTINE` | Keputusan akhir siklus retread/repair | Tire Retread/Repair |
| Basis Cakupan Garansi | `DATE, MILEAGE, ENGINE_HOUR, COMBINATION` | Basis perhitungan garansi | Warranty |
| Tipe Data Scope | `TENANT, BRANCH, WORKSHOP, WAREHOUSE, OWN*` | Lingkup akses data user | DataScopeAssignment (`OWN` belum berfungsi) |
| Metode Pricing | `FLAT, PER_VEHICLE, PER_USER, PER_BRANCH, PER_WORKSHOP, PER_WAREHOUSE, TIERED, CUSTOM` | Cara hitung harga | Pricing |
| Frekuensi Tagihan | `MONTHLY, QUARTERLY, SEMIANNUAL, ANNUAL, CUSTOM` | Siklus penagihan | Pricing/Contract |

---

## 5.5 Kamus Satuan

| Data | Satuan | Format | Presisi | Catatan |
|---|---|---|---|---|
| Odometer / Jarak Tempuh | kilometer (km) | decimal | 2 desimal | `current_odometer`, tidak pernah berkurang otomatis |
| Jam Mesin | jam (engine hour) | decimal | 2 desimal | `engine_hour` |
| Kedalaman Tapak Ban (Tread Depth) | milimeter (mm) | decimal | 2 desimal | Wajib untuk perhitungan skor ban (BD-3); sistem **menolak** menghitung jika referensi tidak ada/nol — tidak pernah mengira-ngira |
| Diameter Pelek | inci (inch) | decimal | 1 desimal | Field `rim_diameter_inch` pada Tire |
| Uang (harga, biaya, tagihan) | Rupiah (default `IDR`, mendukung mata uang lain di level kontrak) | decimal(14,2) atau decimal(16,4) internal | 2 desimal tampilan | Selalu memakai pustaka desimal presisi tetap (`brick/math`), **tidak pernah** memakai bilangan pecahan biner (float) |
| Kuantitas Stok | sesuai UoM produk (pcs, liter, dst.) | decimal | 4 desimal internal | `quantity_on_hand`, `quantity_reserved` |
| Tanggal & Waktu | UTC di database, ditampilkan sesuai zona waktu tenant untuk kebutuhan analitik | ISO 8601 | — | `business_date` (Analytics) dihitung dari `tenants.timezone`, default UTC |
| Durasi Pekerjaan (Labor) | menit (disimpan), ditampilkan sebagai jam | integer/decimal | — | `actual_minutes` pada Labor Log |
| Interval Perawatan | kilometer / jam mesin / hari kalender / bulan | integer | — | Sesuai `trigger_type` |

---

## 5.6 Data Ownership dan Data Lineage

| Data | Dibuat di Modul | Diperbarui di Modul | Digunakan di Modul | Dampak Perubahan |
|---|---|---|---|---|
| Status Kendaraan | Vehicle | Vehicle (manual), Breakdown (otomatis), Vehicle Release (otomatis) | Work Order, Analytics, Intelligence | Kendaraan `BREAKDOWN`/`IN_MAINTENANCE` biasanya tidak dijadwalkan operasional baru |
| Odometer Kendaraan | Vehicle | Inspection (submit), Vehicle Release (release_odometer) | Maintenance Schedule (evaluasi jatuh tempo), Tire (lifetime mileage) | Salah input odometer memengaruhi keakuratan jatuh tempo servis & analitik jarak |
| Saldo Stok (`quantity_on_hand`) | Inventory (Goods Receipt/Adjustment) | Inventory, Work Order (issue/consume/return), Procurement (receipt) | Analytics Inventory, Procurement | Selalu dapat direkonstruksi dari ledger `stock_movements` — jangan pernah mengubah saldo tanpa ledger |
| Status Ban | Tire | Tire (seluruh aksi lifecycle) | Vehicle (posisi terpasang), Warranty, Analytics/Intelligence Tire | Ban `QUARANTINED`/`SCRAPPED`/`SOLD` tidak dapat dipasang lagi melalui jalur apa pun |
| Entitlement Modul Tenant | Subscription (approval kontrak) | Subscription (aktivasi/pencabutan), Contract (amandemen) | Seluruh modul (module-gate) | Modul yang dicabut langsung memblokir akses fitur terkait di seluruh tenant |
| Data Warehouse Analitik (`daily_*`) | Analytics ETL | Analytics ETL (re-run harian/backfill) | Dashboard Analytics, Intelligence (feature store turunan) | Selalu dapat direproduksi ulang dari data PostgreSQL — proyeksi, bukan sumber kebenaran |

---

## 5.7 Data Sensitif

| Data | Sensitivitas | Perlakuan dalam Sistem |
|---|---|---|
| Password | Kredensial | Di-hash (`bcrypt`), **tidak pernah** muncul di audit log, respons API, atau dokumentasi ini |
| Token sesi (Sanctum) | Kredensial | Disimpan di `localStorage` browser; hanya token yang sedang dipakai yang dicabut saat logout |
| Bukti Pembayaran (file) | Finansial | Disimpan di disk privat (`local`), nama file di-UUID-kan, diunduh hanya lewat endpoint terautentikasi dengan pemeriksaan kepemilikan |
| Dokumen Kendaraan (STNK, dsb.) | Data legal/identitas | Disk privat, tidak ada URL publik |
| Nomor Rekening/Bank Partner | Finansial | Field `bank`, `account_holder`, `account_number` pada Partner — hanya terlihat oleh user berpermission `partner.view/manage` |
| Data Kontak Personal (telepon, email, alamat) | Data pribadi | Tersimpan pada User, Tenant, Partner, Worker — tunduk pada permission modul masing-masing |
| Data Biaya & Harga Kontrak | Finansial/komersial sensitif | Hanya terlihat oleh permission Platform (`contract.view`, `pricing.view`, dst.) dan Account tenant sendiri |
| Log Audit | Riwayat perubahan data lain | Password dikecualikan; hanya dapat dilihat sesuai permission `audit.view`, terisolasi per tenant |

**Peringatan:** dokumen ini dan seluruh dokumen pendamping (User Guide, Business Flow, dsb.) **tidak pernah** menyalin nilai kredensial, token, atau secret aktual dari environment mana pun.
