# OptiFleet — Module & Feature Inventory

**Branch/commit yang dianalisis:** `Improvement` @ `05dd372e5bfeba9b920d3bf2d8676b2e4a908f8d`
**Metode:** pemeriksaan langsung terhadap source code backend (`backend/app/Domain/*`, `backend/routes/api/*.php`, migrations, seeders) dan frontend (`frontend/src/pages/*`, `frontend/src/App.tsx`, layout/sidebar).
**Klasifikasi status implementasi** mengikuti definisi wajib: Implemented, Partially Implemented, Backend Only, Frontend Only, Documented Only, Unverified, Deprecated/Legacy, Potential Defect.

> **Catatan penting tentang RBAC:** OptiFleet menggunakan RBAC dinamis — role dibuat bebas per tenant/platform dan diberi kumpulan permission. Kolom "Role" pada tabel di bawah menyebutkan role yang **di-seed sebagai contoh demo** (Platform Superadmin, Admin, Fleet Manager, Workshop Manager) semata-mata untuk orientasi; akses sesungguhnya ditentukan oleh **permission** pada kolom berikutnya, bukan oleh nama role. Lihat `OPTIFLEET_ROLE_PERMISSION_MATRIX.md` untuk penjelasan penuh.

---

## A. PLATFORM PORTAL (Superadmin)

| No. | Modul | Submodul/Fitur | Halaman/Endpoint | Kegunaan | Role (contoh) | Permission | Status | Bukti |
|--:|---|---|---|---|---|---|---|---|
| A1 | Dashboard Platform | Ringkasan platform | `/platform/dashboard` — `PlatformDashboardPage.tsx` | Titik masuk portal platform | Semua user platform | — (tanpa permission) | Implemented | `frontend/src/pages/platform/DashboardPage.tsx` |
| A2 | Tenant Management | Daftar & detail tenant | `GET/POST /platform/tenants`, `/platform/tenants/{id}` — `TenantListPage.tsx`, `TenantDetailPage.tsx` | Membuat & mengelola akun tenant (perusahaan pelanggan) | Platform Superadmin | `tenant.view/create/update/activate/deactivate` | Implemented | `backend/app/Http/Controllers/Api/Platform/TenantController.php` |
| A3 | Tenant Management | Tab Module Entitlement | `TenantEntitlementsTab.tsx` | Memberi/mencabut modul yang dibeli tenant | Platform Superadmin | `entitlement.view/manage` | Implemented | `Domain/Entitlement/Services/EntitlementService.php` |
| A4 | Tenant Management | Tab Capacity Limit | `TenantCapacityTab.tsx` | Mengatur batas kuota (jumlah branch/user/vehicle, dst) per tenant | Platform Superadmin | `entitlement.manage` | Implemented | `Domain/Entitlement/Services/CapacityService.php` |
| A5 | Tenant Management | Tab Users (tenant) | `TenantUsersTab.tsx` | Menambah user tenant & mengubah status aktif dari sisi platform | Platform Superadmin | `user.view/update` | Partially Implemented | Backend punya endpoint assign-role dari sisi Platform (`POST /platform/tenants/{t}/users/{u}/roles`) tetapi **tidak ada UI-nya** di tab ini — assignment role tenant user hanya bisa dilakukan dari portal tenant sendiri |
| A6 | Tenant Management | Edit profil tenant (legal name, alamat, dst) | `PUT /platform/tenants/{id}` | Mengubah data legal tenant dari sisi platform | Platform Superadmin | `tenant.update` | Backend Only | Endpoint ada; `TenantDetailPage.tsx` tab Overview bersifat read-only, tidak ada form edit |
| A7 | Module Catalog | Katalog modul & dependency | `/platform/modules` — `ModuleCatalogPage.tsx`, `ModuleDetailPage.tsx` | Daftar modul yang bisa dijual & relasi dependency antarmodul | Platform Superadmin | `module.view/manage` | Implemented | `Domain/ProductCatalog/Models/Module.php`, `ModuleDependency.php` |
| A8 | Bundle Management | Susun & publish bundle modul | `/platform/bundles`, `/platform/bundles/:id` | Mengelompokkan modul menjadi paket jual (bundle) dengan versi yang dibekukan saat publish | Platform Superadmin | `bundle.view/create/update/publish` | Implemented | `Domain/ProductCatalog/Services/BundleService.php` |
| A9 | Pricing Management | Daftar harga & versi harga | `/platform/pricing` — `PricingListPage.tsx` | Menetapkan harga standar per modul/bundle/add-on/capacity per siklus tagihan | Platform Superadmin | `pricing.view/create/publish` | Implemented | `Domain/Pricing/Services/PricingResolutionService.php` |
| A10 | Pricing Management | Harga khusus tenant (Tenant Custom Pricing) | `POST /platform/tenants/{t}/custom-pricing` | Memberi harga negosiasi khusus ke tenant tertentu (prioritas di atas harga standar) | Platform Superadmin | `pricing.view/update` | **Backend Only** | Tidak ada satu pun halaman frontend yang memanggil endpoint ini — fitur hanya bisa dipakai lewat API langsung |
| A11 | Contract Management | Draft → Approval → Aktif kontrak komersial | `/platform/contracts`, `/platform/contracts/:id` | Perjanjian komersial tenant; sumber kebenaran penagihan & entitlement | Platform Superadmin | `contract.view/create/submit/approve/amend/renew/terminate` | Implemented | `Domain/Contract/Services/ContractService.php` |
| A12 | Contract Management | Amandemen kontrak (tambah/hapus item) | Tab Amendments di `ContractDetailPage.tsx` | Mengubah komposisi kontrak aktif dengan proration otomatis | Platform Superadmin | `contract.amend/approve` | Implemented | `Domain/Contract/Services/AmendmentService.php` |
| A13 | Contract Management | Perpanjangan kontrak (Renew) | Tombol "Renew" | Membuat draft kontrak baru menyambung kontrak yang berakhir | Platform Superadmin | `contract.renew` | Implemented | `Domain/Contract/Services/RenewalService.php` |
| A14 | Subscription Management | Suspend/Reactivate subscription | `/platform/subscriptions` | Menjeda/mengaktifkan kembali akses operasional tenant | Platform Superadmin | `subscription.view/suspend/reactivate` | Implemented | `Domain/Subscription/Services/SubscriptionService.php` |
| A15 | Billing Management | Daftar billing per periode | `/platform/billings` | Melihat catatan penagihan per periode subscription | Platform Superadmin | `billing.view` | Implemented | `Domain/Billing/Services/BillingGenerationService.php` |
| A16 | Billing Management | Generate billing manual | `POST /platform/subscriptions/{id}/generate-billing` | Memicu pembuatan billing di luar jadwal harian otomatis | Platform Superadmin | `billing.generate` | **Backend Only** | Tidak ada tombol/form pemanggil di frontend manapun — hanya dapat dipicu API langsung atau oleh scheduler harian |
| A17 | Invoice Management | Daftar & detail invoice, void invoice | `/platform/invoices`, `/platform/invoices/:id` | Melihat/mencetak/membatalkan invoice tenant | Platform Superadmin | `invoice.view/void` | Implemented | `Domain/Invoice/Services/InvoiceService.php` |
| A18 | Payment Verification | Verifikasi/tolak bukti bayar tenant | `/platform/payments`, `/platform/payments/:id` | Memverifikasi pembayaran yang diunggah tenant; memicu pelunasan invoice & reaktivasi subscription | Platform Superadmin | `payment.view/verify/reject` | Implemented | `Domain/Payment/Services/PaymentVerificationService.php` |
| A19 | Access Management (Platform) | User & Role platform | `/platform/access/users`, `/platform/access/roles` | Mengelola akun & role level platform | Platform Superadmin | `user.view/create/update/assign`, `role.view/create/update/assign_permission` | Implemented | `Domain/AccessControl/*` |
| A20 | Audit Log (Platform) | Log audit seluruh tenant | `/platform/audit-logs` | Melihat riwayat perubahan data lintas tenant | Platform Superadmin | `audit.view` | Implemented | `Domain/Audit/Services/AuditService.php` |
| A21 | Analytics ETL Administration | Jalankan/retry/backfill ETL analitik | `/platform/analytics/etl/*` (tanpa UI khusus) | Mengoperasikan pipeline ETL data warehouse lintas tenant | Platform Superadmin/Specialist | `analytics.etl.view/run/retry/backfill` | **Backend Only** | Endpoint & audit logging lengkap; tidak ditemukan halaman frontend platform untuk ETL admin |
| A22 | Analytics Reconciliation | Bandingkan hasil ETL vs PostgreSQL | `GET /platform/analytics/reconciliation` | Memastikan data Mongo analitik konsisten dengan data transaksi asli | Platform Superadmin | `analytics.etl.view` | Backend Only | `AnalyticsReconciliationService` |
| A23 | Intelligence Model Administration | Registry, training, aktivasi model ML | `/platform/intelligence/models`, `/training` | Mengelola model prediksi risiko kegagalan kendaraan | Platform Superadmin/Specialist | `intelligence.model.view/train/evaluate/activate/retire` | **Backend Only** | Tidak ditemukan halaman frontend platform untuk administrasi model |
| A24 | Intelligence Monitoring | Monitoring performa model & drift | `/platform/intelligence/monitoring`, `/monitoring/drift` | Memantau akurasi model & pergeseran data (drift) | Platform Superadmin/Specialist | `intelligence.monitoring.view` | Backend Only | `ModelMonitoringService`, `DriftAssessmentService` |

---

## B. TENANT PORTAL

### B1. Dashboard & Account

| No. | Modul | Submodul/Fitur | Halaman/Endpoint | Kegunaan | Role | Permission | Status | Bukti |
|--:|---|---|---|---|---|---|---|---|
| B1.1 | Dashboard Tenant | Ringkasan operasional tenant | `/app/dashboard` | Titik masuk portal tenant; sumber daftar modul aktif untuk sidebar | Semua user tenant | — | Implemented | `frontend/src/pages/tenant/DashboardPage.tsx` |
| B1.2 | Account | Profil Perusahaan (Company Profile) | `/app/account/company` | Tenant mengedit data legal perusahaannya sendiri | Admin Tenant | `company.view/update` | Implemented | `Domain/Organization` (field pada `Tenant`), `CompanyProfileController.php` |
| B1.3 | Account | Subscription tenant (baca) | `/app/account/subscription` | Melihat status subscription, modul aktif, kuota pemakaian | Admin Tenant | `account.subscription.view` | Implemented | `AccountSubscriptionController` |
| B1.4 | Account | Contract tenant (baca) | `/app/account/contract` | Melihat kontrak komersial yang berlaku | Admin Tenant | `account.contract.view` | Implemented | `AccountContractController` |
| B1.5 | Account | Invoice tenant (lihat/unduh PDF) | `/app/account/invoices`, `/:id` | Melihat & mengunduh invoice tagihan | Admin Tenant | `account.invoice.view/download` | Implemented | `AccountInvoiceController` |
| B1.6 | Account | Submit & resubmit pembayaran | `/app/account/payments`, `/:id` | Mengunggah bukti bayar terhadap invoice outstanding | Admin Tenant | `account.payment.submit/view` | Partially Implemented | `resubmit()` endpoint ada di backend, tetapi tidak ada tombol "Resubmit" pada `AccountPaymentDetailPage.tsx` — tenant harus submit pembayaran baru yang tidak tertaut riwayat penolakan |

### B2. Organization & Master Data

| No. | Modul | Submodul/Fitur | Halaman/Endpoint | Kegunaan | Role | Permission | Status | Bukti |
|--:|---|---|---|---|---|---|---|---|
| B2.1 | Organization | Branch (cabang) | `/app/organization/branches` | CRUD cabang perusahaan | Admin/Fleet Manager | `branch.view/create/update/activate/deactivate` | Partially Implemented | Field Latitude/Longitude/Phone/Email/PIC/Jam Operasional ada di backend & validasi, **tidak ditampilkan di form UI** |
| B2.2 | Organization | Workshop (bengkel) | `/app/organization/workshops` | CRUD bengkel, tertaut ke branch | Admin/Workshop Manager | `workshop.view/create/update/activate/deactivate` | Partially Implemented | Field Address/PIC/Jam Operasional tidak ada di form UI |
| B2.3 | Organization | Warehouse (gudang) | `/app/organization/warehouses` | CRUD gudang, tertaut branch/workshop | Admin/Warehouse | `warehouse.view/create/update/activate/deactivate` | Partially Implemented | Field Address/PIC tidak ada di form UI |
| B2.4 | Master Data | Kategori Kendaraan | `/app/master-data/vehicle-categories` | Klasifikasi kendaraan + mapping ke Component Group | Admin | `vehicle_category.view/create/update`, `component_group.map` | Implemented | `VehicleCategoryController.php` |
| B2.5 | Master Data | Component Group | `/app/master-data/component-groups` | Klasifikasi komponen kendaraan berjenjang (hierarki) | Admin | `component_group.view/create/update/map` | Implemented | `ComponentGroupController.php` |
| B2.6 | Master Data | Merek & Model Kendaraan | `/app/master-data/vehicle-brands`, `/vehicle-models` | Master merek/model kendaraan (opsional, melengkapi field teks bebas) | Admin | `vehicle_brand.view/create/update` | Implemented | `VehicleBrandController.php`, `VehicleModelController.php` |
| B2.7 | Master Data | Kategori Produk & UoM | `/app/master-data/product-categories`, `/uoms` | Master kategori spare part/ban/tools & satuan | Admin/Warehouse | `product.view` | Implemented | `ProductMaster` domain |

### B3. Access & Audit

| No. | Modul | Submodul/Fitur | Halaman/Endpoint | Kegunaan | Role | Permission | Status | Bukti |
|--:|---|---|---|---|---|---|---|---|
| B3.1 | Access Management | User tenant + assign role + assign data scope | `/app/access/users` | Mengelola akun user tenant, penugasan role & lingkup data | Admin | `user.view/create/update/assign` | Implemented | `TenantUsersPage.tsx` + `ManageAccessModal` |
| B3.2 | Access Management | Role & permission tenant | `/app/access/roles` | Membuat role kustom & memilih kumpulan permission | Admin | `role.view/create/update/assign_permission` | Implemented (rename role: Backend Only) | `RoleManager.tsx` — tidak ada UI untuk mengubah nama/deskripsi role setelah dibuat |
| B3.3 | Audit Log | Log audit tenant | `/app/audit-logs` | Riwayat perubahan data milik tenant sendiri | Admin | `audit.view` | Implemented | `Domain/Audit` |

### B4. Vehicle

| No. | Modul | Submodul/Fitur | Halaman/Endpoint | Kegunaan | Role | Permission | Status | Bukti |
|--:|---|---|---|---|---|---|---|---|
| B4.1 | Vehicle | Daftar & detail kendaraan | `/app/vehicles`, `/:id` | Registrasi & spesifikasi kendaraan | Fleet Manager | `vehicle.view/create/update` | Implemented | `VehicleController.php` |
| B4.2 | Vehicle | Ubah status kendaraan | Tombol "Change Status" | Mengubah status operasional (ACTIVE/IN_MAINTENANCE/dst) secara manual | Fleet Manager | `vehicle.status.update` | Implemented | `VehicleController::updateStatus` |
| B4.3 | Vehicle | Reassignment cabang/bengkel (langsung) | Tab Assignment | Override langsung tanpa approval, mencatat riwayat | Fleet Manager | `vehicle.assign` | Implemented | `VehicleAssignmentService.php` |
| B4.4 | Vehicle | Transfer antarcabang (dengan approval) | `/app/vehicle-transfers` + Tab Transfer | Perpindahan kendaraan resmi dengan alur DRAFT→...→COMPLETED | Fleet Manager | `vehicle.transfer` | Implemented | `VehicleTransferService.php` |
| B4.5 | Vehicle | Dokumen kendaraan (STNK, dsb.) | Tab Documents | Unggah/unduh dokumen legal kendaraan | Fleet Manager | `vehicle.view/update` | Implemented | `VehicleDocumentService.php` |
| B4.6 | Vehicle | Riwayat kendaraan (History) | Tab History, `/app/vehicle-history` | Linimasa gabungan inspeksi/request/breakdown/WO/QC/release | Fleet Manager | `maintenance_history.view` | Implemented | `HistoryService::forVehicle` |

### B5. Inspection

| No. | Modul | Submodul/Fitur | Halaman/Endpoint | Kegunaan | Role | Permission | Status | Bukti |
|--:|---|---|---|---|---|---|---|---|
| B5.1 | Inspection | Template Inspeksi | `/app/inspection-templates` | Mendesain checklist inspeksi per kategori kendaraan | Admin/Workshop Manager | `inspection.view/create` | Implemented | `InspectionTemplateController.php` |
| B5.2 | Inspection | Pelaksanaan inspeksi | `/app/inspections`, `/:id` | Assign→Start→Submit inspeksi, hasil PASSED/WARNING/FAILED | Mechanic/Inspector | `inspection.view/create/perform/submit` | Partially Implemented | Tipe item SELECT & PHOTO dirender sebagai input teks biasa, bukan dropdown/upload foto sesungguhnya |
| B5.3 | Inspection | Buat Maintenance Request dari hasil FAILED/WARNING | Tombol "Create Maintenance Request" | Menindaklanjuti temuan inspeksi menjadi permintaan perawatan | Workshop Manager | `maintenance_request.create` | Implemented (manual, bukan otomatis) | `InspectionController::createMaintenanceRequest` |

### B6. Maintenance (Policy, Schedule, Request, Breakdown)

| No. | Modul | Submodul/Fitur | Halaman/Endpoint | Kegunaan | Role | Permission | Status | Bukti |
|--:|---|---|---|---|---|---|---|---|
| B6.1 | Maintenance Package | Paket & Interval perawatan preventif | `/app/maintenance-policies`, `/:id` | Definisi paket servis + trigger (odometer/jam mesin/kalender) | Admin | `maintenance_policy.view/manage` | Implemented | `MaintenancePackageController.php` |
| B6.2 | Maintenance Package | Assign paket ke kendaraan | Tombol "Assign to Vehicle" | Mengaitkan kendaraan ke paket & langsung membuat skedul | Admin | `maintenance_policy.manage` | Implemented (satu aksi atomik) | `assignToVehicle()` |
| B6.3 | Maintenance Schedule | Skedul & status jatuh tempo | `/app/maintenance-schedules` | UPCOMING/DUE_SOON/DUE/OVERDUE per kendaraan+paket | Fleet Manager | `maintenance_schedule.view/manage` | Implemented (status `SCHEDULED` tidak pernah dipakai — enum mati) | `MaintenanceDueService.php` |
| B6.4 | Maintenance Schedule | Convert ke Work Order | Tombol "Convert to WO" | Membuat WO langsung dari skedul yang jatuh tempo | Workshop Manager | `maintenance_schedule.convert_work_order` | Implemented | `WorkOrderController::fromMaintenanceSchedule` |
| B6.5 | Maintenance Request | Permintaan perawatan | `/app/maintenance-requests`, `/:id` | DRAFT→SUBMITTED→UNDER_REVIEW→APPROVED→WORK_ORDER_CREATED | Fleet Manager/Workshop Manager | `maintenance_request.view/create/review/approve/reject` | Implemented | `MaintenanceRequestService.php` |
| B6.6 | Breakdown | Lapor & tindak lanjut kerusakan mendadak | `/app/breakdowns`, `/:id` | REPORTED→VERIFIED→ASSESSED→REPAIR_REQUIRED→(WO)→RESOLVED | Fleet Manager/Workshop Manager | `breakdown.view/report/review/resolve` | Partially Implemented | Konversi Breakdown→MaintenanceRequest→WorkOrder tidak menutup status breakdown secara otomatis ke `WORK_ORDER_CREATED` — lihat Findings |

### B7. Work Order & Workshop

| No. | Modul | Submodul/Fitur | Halaman/Endpoint | Kegunaan | Role | Permission | Status | Bukti |
|--:|---|---|---|---|---|---|---|---|
| B7.1 | Work Order | Siklus hidup WO (14 status) | `/app/work-orders`, `/:id` | Transaksi inti perawatan, DRAFT→...→CLOSED | Workshop Manager/Mechanic | `work_order.view/create/submit/approve/assign/schedule/start/pause/complete/close/cancel` | Implemented | `WorkOrderTransitionService.php` |
| B7.2 | Work Order | Complaint/Finding/Diagnosis/Corrective Action | Tab Complaint/Diagnosis | Mencatat keluhan, temuan, diagnosis, akar masalah, tindakan | Mechanic | `diagnosis.manage` | Implemented | `WorkOrderExecutionService.php` |
| B7.3 | Work Order | Maintenance Job & Labor Timer | Tab Jobs/Mechanic | Penjadwalan pekerjaan & pencatatan waktu kerja mekanik (Start/Pause/Resume/Finish) | Mechanic | `maintenance_job.manage` | Implemented | `LaborTimerService.php` |
| B7.4 | Work Order | Planned Parts (rencana part) | Tab Planned Parts | Merencanakan kebutuhan part (belum memindahkan stok) | Mechanic | `maintenance_job.manage` | Implemented (perlu penjelasan: reserve/issue/consume/return dikelola modul Inventory) | `WorkOrderExecutionService::addPlannedPart` |
| B7.5 | Work Order | Additional Work (pekerjaan tambahan) | Tab Diagnosis/Jobs | Mengajukan & menyetujui pekerjaan di luar rencana awal | Workshop Manager | `maintenance_job.manage`, `work_order.approve` | Implemented | `WorkOrderExecutionService::requestAdditionalWork` |
| B7.6 | Work Order | External Service (Maintenance Memo) | Tab External Services | Mengirim pekerjaan ke Partner/bengkel eksternal | Workshop Manager | `work_order_external_service.create/complete/cancel` | Implemented | `WorkOrderExternalServiceController.php` |
| B7.7 | Workshop Invoice (R1) | Rekam invoice eksternal & pembayaran | `/app/workshop-invoices`, `/:id` | Mencatat invoice yang diterbitkan Partner, bukan menerbitkan invoice sendiri | Warehouse/Finance | `workshop_invoice.record/view/upload_payment/request_correction/verify_correction/request_cancellation/verify_cancellation` | Implemented | `WorkshopInvoiceService.php` |
| B7.8 | Quality Control | QC setelah eksekusi WO | Tab QC (dalam WO Detail, bukan halaman terpisah) | Start→Findings→Pass/Fail(Rework)→Complete | Lead Mechanic/QC | `qc.view/perform/approve/reject` | Implemented (Potential Defect: tombol Pass & Fail di UI sama-sama digerbang permission `qc.approve`, padahal backend Fail memerlukan `qc.reject`) | `QualityControlService.php` |
| B7.9 | Vehicle Release | Rilis kendaraan setelah WO selesai | Tab Road Test (dalam WO Detail) | Menutup WO ke CLOSED & mengembalikan kendaraan ke status ACTIVE | Workshop Manager | `vehicle_release.perform` | Implemented | `VehicleReleaseService.php` |
| B7.10 | Workshop Operations | Worker/Mekanik | `/app/workers`, `/workers/workload` | Data pekerja bengkel & skill, beban kerja | Workshop Manager | `worker.view/manage/assign` | Implemented | `WorkerController.php` |
| B7.11 | Workshop Operations | Workspace (Bay) & Reservation | `/app/workspaces`, `/workshop-scheduler`, `/workspace-reservations` | Manajemen bay servis & pemesanan slot waktu | Workshop Manager | `workspace.view/manage/reserve/block` | Partially Implemented | Endpoint `POST /workspace-reservations` (buat reservasi baru) tidak memiliki form pembuatan di UI; aksi "Schedule" pada WO juga tidak membuat baris reservasi |

### B8. Inventory & Procurement

| No. | Modul | Submodul/Fitur | Halaman/Endpoint | Kegunaan | Role | Permission | Status | Bukti |
|--:|---|---|---|---|---|---|---|---|
| B8.1 | Inventory | Produk & Katalog | `/app/products`, `/:id` | Master item (spare part/ban/tools) | Warehouse | `product.view/create/update/delete` | Implemented | `ProductController.php` |
| B8.2 | Inventory | Saldo stok gudang | `/app/inventory` | Quantity on hand/reserved per gudang, status reorder | Warehouse | `inventory.view` | Implemented | `WarehouseStockController.php` |
| B8.3 | Inventory | Adjustment & Scrap stok | Modal Adjustment/Scrap | Koreksi manual & pemusnahan stok baik | Warehouse | `inventory.adjust/scrap` | Implemented | `InventoryService::adjust/scrap` |
| B8.4 | Inventory | Stock Movement (kartu stok) | `/app/stock-movements` | Ledger append-only seluruh mutasi stok | Warehouse | `inventory.view` | Implemented | `StockMovement` model |
| B8.5 | Inventory | Reservation | `/app/stock-reservations` | Menahan stok untuk kebutuhan WO | Warehouse | `inventory.view/reserve` | Implemented | `StockReservationService.php` |
| B8.6 | Inventory | Stock Transfer antargudang | `/app/stock-transfers`, `/:id` | DRAFT→...→COMPLETED perpindahan stok antar gudang | Warehouse | `stock_transfer.view/create/approve/dispatch/receive` | Implemented | `StockTransferService.php` |
| B8.7 | Inventory | Stock Opname (perhitungan fisik) | `/app/stock-opnames`, `/:id` | Hitung fisik & posting selisih ke ledger | Warehouse | `inventory.stock_opname` | Implemented | `StockOpnameService.php` |
| B8.8 | Inventory | Used Sparepart Processing | `/app/used-part-returns` | Inspeksi & disposisi part bekas (REUSE/REPAIR/QUARANTINE/SCRAP/SELL_ELIGIBLE) | Warehouse/Workshop Manager | `used_part.view/inspect/dispose/approve` | Implemented (maker-checker) | `UsedPartDispositionService.php` |
| B8.9 | Inventory | Sell Sparepart | `/app/sparepart-sales` | Menjual part bekas yang SELL_ELIGIBLE (reuse operasional/scrap) | Warehouse | `sparepart_sale.view/create/approve` | Implemented (hanya 2 jenis penjualan: OPERATIONAL_REUSE & SCRAP_MATERIAL — jangan disamakan dengan penjualan ban) | `SparePartSaleService.php` |
| B8.10 | Procurement | Purchase Request | `/app/purchase-requests`, `/:id` | Permintaan pembelian; bisa ditautkan ke WO | Warehouse | `purchase_request.view/create/submit/approve` | Implemented | `PurchaseRequestService.php` |
| B8.11 | Procurement | RFQ & Vendor Quotation | `/app/rfqs`, `/quotations` | Minta & bandingkan penawaran vendor | Procurement | `rfq.view/manage`, `quotation.view/manage/select` | Implemented | `RfqService.php` |
| B8.12 | Procurement | Purchase Order | `/app/purchase-orders`, `/:id` | Order resmi ke vendor terpilih, opsional persetujuan berjenjang | Procurement | `purchase_order.view/create/approve/issue` | Implemented (persetujuan berjenjang: framework ada, tidak aktif default) | `PurchaseOrderService.php` |
| B8.13 | Procurement | Goods Receipt | `/app/goods-receipts` | Penerimaan barang & update stok + harga rata-rata; setiap GR wajib dicatat bersama Vendor Invoice Reference | Warehouse | `goods_receipt.view/post` | Implemented | `GoodsReceiptService.php` |
| B8.14 | Procurement | Vendor Invoice Reference | `/app/vendor-invoice-references` | Invoice vendor per Goods Receipt, due date hari kerja, status NEW/DUE_SOON/LATE/PAID, pembayaran + bukti | Procurement / Finance | `vendor_invoice.view/pay` | Implemented | `VendorInvoiceReferenceService.php`, `VendorInvoicePaymentService.php` |
| B8.15 | Partner | Vendor/Supplier/Workshop Partner | `/app/partners`, `/suppliers`, `/:id` | Master data mitra bisnis (vendor, bengkel eksternal, dsb.) | Procurement | `partner.view/manage` | Implemented (halaman "Suppliers" adalah tampilan terfilter dari data Partner yang sama, bukan entitas terpisah) | `Domain/Partner` |

### B9. Tire, Component Asset, Warranty

| No. | Modul | Submodul/Fitur | Halaman/Endpoint | Kegunaan | Role | Permission | Status | Bukti |
|--:|---|---|---|---|---|---|---|---|
| B9.1 | Tire Management | Rim (master data pelek) | `/app/rims` | Katalog pelek — **berdiri sendiri, tidak tertaut ke Tire/Vehicle** | Warehouse | `rim.view/manage` | Implemented | `RimController.php` |
| B9.2 | Tire Management | Wheel Configuration | `/app/wheel-configurations` | Tata letak posisi roda per kategori kendaraan | Admin | `tire.view/manage` | Implemented (permisif jika belum dikonfigurasi) | `WheelConfiguration` model |
| B9.3 | Tire Management | Tire (aset ban bernomor seri) | `/app/tires`, `/:id` | Install/Rotate/Swap/Inspect/Remove/Replace/Scrap/Sell | Mechanic/Workshop Manager | `tire.view/manage/install/rotate/inspect/remove/scrap/sell` | Implemented | `TireService.php` |
| B9.4 | Tire Management | Retread & Repair (siklus maker-checker) | Dalam `TireDetailPage.tsx` | Send→Receive→Final Inspect→Approve ke partner eksternal | Warehouse (send/receive)/Workshop Manager (inspect/approve) | `tire_retread.*`, `tire_repair.*` | Implemented | `TireService::retread/repair` |
| B9.5 | Tire Management | Tire Scoring (SPA/KA/KF) | Dalam `TireDetailPage.tsx` | Skor kelayakan ban berbasis kedalaman tapak & kondisi | Inspector/Workshop Manager | `tire_scoring.calculate/finalize` | Framework Implemented, **tidak aktif produksi sampai tenant publish konfigurasi sendiri** | `TireScoringService.php` |
| B9.6 | Configuration | Tire Scoring Configuration | `/app/configuration/tire-scoring` | Menyusun & mempublikasikan aturan skor ban (band, bobot, legal/casing/lifecycle) | Admin | `configuration.view`, `tire_scoring_configuration.manage/publish` | Implemented | `TireScoringConfigurationValidator.php` |
| B9.7 | Component Management | Component Asset | `/app/component-assets`, `/:id` | Aset komponen bernomor seri (aki, alternator, dst.) — Install/Remove/Repair | Mechanic | `component_asset.view/manage/install/remove/replace` | Partially Implemented | Aksi **Replace tersedia di backend & permission `component_asset.replace`, namun tidak ada tombol/pemanggilnya di UI** |
| B9.8 | Warranty | Data garansi | `/app/warranties`, `/:id` | Cakupan garansi per produk/komponen/ban/WO | Admin | `warranty.view/manage` | Implemented | `WarrantyEligibilityService.php` |
| B9.9 | Warranty | Klaim garansi | `/app/warranty-claims`, `/:id` | DRAFT→...→SETTLED→CLOSED | Fleet Manager | `warranty_claim.create/review/approve` | Implemented | `WarrantyClaimService.php` |

### B10. Configuration

| No. | Modul | Submodul/Fitur | Halaman/Endpoint | Kegunaan | Role | Permission | Status | Bukti |
|--:|---|---|---|---|---|---|---|---|
| B10.1 | Configuration | Numbering (format penomoran dokumen) | `/app/configuration/numbering` | Format nomor WO/PR/PO/dst per tenant | Admin | `numbering.manage/publish` | Implemented | `DocumentNumberingService.php` |
| B10.2 | Configuration | Document Template | `/app/configuration/document-templates` | Template HTML dokumen cetak (WO print, invoice, dst.) | Admin | `document_template.manage/publish` | Implemented | `DocumentTemplateService.php` |
| B10.3 | Configuration | Workflow | `/app/configuration/workflows` | Desain alur status kustom (mesin status konfigurable) | Admin | `workflow.manage/publish/simulate` | **Partially Implemented** — engine lengkap & bisa disimulasikan, tetapi Work Order/Maintenance Request/Breakdown masih memakai transition service hardcode masing-masing, belum benar-benar memakai mesin ini untuk transisi nyata | `WorkflowEngine.php` |
| B10.4 | Configuration | Notification Rules | `/app/configuration/notifications` | Aturan siapa menerima notifikasi untuk event tertentu | Admin | `notification_rule.manage` | Partially Implemented — lihat B10.5 | `NotificationRuleService.php` |
| B10.5 | Notification | Kotak masuk notifikasi in-app | — | Pesan in-app untuk user | Semua user | — | **Backend Only** — pesan tersimpan di database tetapi **tidak ada halaman/lonceng notifikasi di UI** untuk membacanya | `NotificationInAppMessage` model, `SendNotificationJob.php` |
| B10.6 | Configuration | Configuration History | `/app/configuration/history` | Riwayat versi seluruh jenis konfigurasi di atas | Admin | `configuration_history.view` | Implemented | `ConfigurationController::history` |

### B11. Analytics (Phase 6)

| No. | Modul | Submodul/Fitur | Halaman/Endpoint | Kegunaan | Role | Permission | Status | Bukti |
|--:|---|---|---|---|---|---|---|---|
| B11.1 | Analytics | Overview & KPI Catalog | `/app/analytics/overview` | 18 KPI ringkasan operasional | Manajemen | `analytics.overview.view` | Implemented | `AnalyticsDomainController.php` |
| B11.2 | Analytics | 14 dashboard domain (Fleet/Maintenance/WO/Breakdown/Downtime/Workshop/Mechanic/Inventory/Procurement/Vendor/Cost/Tire/Component/Warranty) | `/app/analytics/{domain}` | Metrik harian per domain dari data warehouse MongoDB | Manajemen | `analytics.<domain>.view` | Implemented (satu halaman generik dipakai ulang untuk semua domain — bukan kekurangan, memang didesain begitu) | `AnalyticsDomainPage.tsx` |
| B11.3 | Analytics | Export CSV | Tombol "Export CSV" di setiap dashboard | Ekspor data metrik sesuai filter & lingkup akses | Manajemen | `analytics.export` | Implemented (hanya CSV, tidak ada XLSX) | `ExportAnalyticsController.php` |

### B12. Maintenance Intelligence (Phase 7)

| No. | Modul | Submodul/Fitur | Halaman/Endpoint | Kegunaan | Role | Permission | Status | Bukti |
|--:|---|---|---|---|---|---|---|---|
| B12.1 | Intelligence | Overview | `/app/intelligence/overview` | Ringkasan kesehatan armada & risiko | Manajemen | `intelligence.overview.view` | Implemented | `IntelligenceOverviewPage.tsx` |
| B12.2 | Intelligence | Vehicle Health & Risk | `/app/intelligence/vehicles`, `/:id` | Skor kesehatan & prediksi risiko gagal per kendaraan | Manajemen | `intelligence.vehicle.view` | Implemented (hanya `vehicle_failure_risk` yang benar-benar ML; lainnya deterministik/statistik) | `PredictionService.php` |
| B12.3 | Intelligence | Component & Tire Intelligence | `/app/intelligence/components`, `/tires` | Reliabilitas komponen & performa ban berbasis data historis | Manajemen | `intelligence.component.view/tire.view` | Implemented | `Domain/Intelligence` |
| B12.4 | Intelligence | Recommendations | `/app/intelligence/recommendations` | Rekomendasi tindak lanjut yang dapat dikonversi ke Maintenance Request | Fleet Manager | `intelligence.recommendation.view/review/accept/reject/convert` | Implemented | `RecommendationReviewService.php` |

---

## Ringkasan Jumlah

- **Modul utama teridentifikasi:** 40 (14 Platform + 26 Tenant, sebelum pemecahan submodul)
- **Baris fitur pada inventaris ini:** 90
- **Status Implemented:** mayoritas (≈70%)
- **Status Partially Implemented:** 13 baris (ditandai eksplisit di atas)
- **Status Backend Only:** 8 baris
- **Status Frontend Only:** 0 baris ditemukan pada cakupan riset ini
- **Potential Defect:** 1 baris (tombol Pass/Fail QC — lihat B7.8)

Rincian bukti file per baris tersedia lebih lengkap pada `OPTIFLEET_TRACEABILITY_MATRIX.md`. Rincian gap/temuan tersedia pada `OPTIFLEET_USER_GUIDE_FINDINGS.md`.
