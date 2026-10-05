# OptiFleet Final Translation Glossary

Authority values: **APPROVED_BY_PRODUCT_OWNER** (product owner terminology decision, applied), **APPROVED_AUTOMATIC**, **DO_NOT_TRANSLATE**, **STRUCTURAL_PREP_REQUIRED** (translation final, implementation blocked — e.g. status labels needing the status registry), **AWAITING_DECISION** (not covered by the owner decision list). Source of truth for rows: `12-en-id-translation-dataset-final.csv`. Canonical values (status codes, enum values, keys, template variables, numbering tokens) are never translated.

## Core Business Terms

| English Term | Indonesian | Context | Module/Domain | Authority / Status | Note |
|---|---|---|---|---|---|
| Work Order | Work Order | Document / module name | — | APPROVED_BY_PRODUCT_OWNER | Product owner decision (previous rec.: Work Order · Alt: Perintah Kerja) |
| Maintenance | Pemeliharaan | Module / domain | — | APPROVED_BY_PRODUCT_OWNER | Product owner decision (previous rec.: Perawatan · Alt: Pemeliharaan) |
| Workshop | Bengkel | Organization / workshop operations | — | APPROVED_BY_PRODUCT_OWNER | Product owner decision (previous rec.: Bengkel · Alt: Workshop) |
| Spare Part / Part | Suku Cadang | Inventory / item type | — | APPROVED_BY_PRODUCT_OWNER | Product owner decision (previous rec.: Suku Cadang · Alt: Sparepart) |
| Tenant | Penyewa | Platform / SaaS | — | APPROVED_BY_PRODUCT_OWNER | Product owner decision (previous rec.: Tenant · Alt: Perusahaan Pelanggan) |
| Vendor | Vendor | Procurement | — | APPROVED_BY_PRODUCT_OWNER | Product owner decision (previous rec.: Vendor · Alt: Pemasok) |
| Supplier | Pemasok | Partner type | — | APPROVED_BY_PRODUCT_OWNER | Product owner decision (previous rec.: Pemasok · Alt: Supplier) |
| Partner | Mitra | Partner master | — | APPROVED_BY_PRODUCT_OWNER | Product owner decision (previous rec.: Mitra · Alt: Rekanan) |
| Invoice | Faktur | Billing / procurement | — | APPROVED_BY_PRODUCT_OWNER | Product owner decision (previous rec.: Invoice · Alt: Faktur) |
| Work Authorization Letter | Work Authorization Letter | Document | — | APPROVED_BY_PRODUCT_OWNER | Product owner decision (previous rec.: Surat Otorisasi Kerja · Alt: Surat Perintah Kerja (SPK)) |
| Vehicle | Kendaraan | Domain | GLOBAL | APPROVED_AUTOMATIC | Standard |
| Fleet | Armada | Domain | GLOBAL | APPROVED_AUTOMATIC | Standard |
| Branch | Cabang | Organization | GLOBAL | APPROVED_AUTOMATIC | Standard |
| Warehouse | Gudang | Inventory | GLOBAL | APPROVED_AUTOMATIC | Standard |
| Product | Produk | Inventory | GLOBAL | APPROVED_AUTOMATIC | Standard |
| Stock | Stok | Inventory | GLOBAL | APPROVED_AUTOMATIC | Standard |
| Tire / Tyre | Ban | Tire | DOMAIN | APPROVED_AUTOMATIC | Audit REVIEW downgraded with documented correction (owner may veto, see 11) |
| Inspection | Inspeksi | Domain | GLOBAL | APPROVED_AUTOMATIC | Standard in fleet usage |

## Maintenance

| English Term | Indonesian | Context | Module/Domain | Authority / Status | Note |
|---|---|---|---|---|---|
| Maintenance | Pemeliharaan | Module | maintenance | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.maintenance) |
| Maintenance Request | Permintaan Pemeliharaan | Document | maintenance | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.maintenance) |
| Maintenance Package / Schedule | Paket Pemeliharaan / Jadwal Pemeliharaan | Planning | maintenance | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.maintenance) |
| Preventive / Corrective | Preventif / Korektif | Maintenance type | maintenance | APPROVED_AUTOMATIC | Standard |
| Breakdown | Kerusakan | Incident | maintenance | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.breakdown) |
| Complaint / Diagnosis / Finding | Keluhan / Diagnosis / Temuan | Work order | workOrder | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (Finding = glossary.finding) |
| Root Cause / Corrective Action | Akar Masalah / Tindakan Korektif | Work order | workOrder | APPROVED_AUTOMATIC | Standard |
| Road Test / Quality Control | Uji Jalan / Kendali Mutu | QC | workOrder | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.roadTest / qualityControl) |
| Rework | Pengerjaan Ulang | QC | workOrder | APPROVED_AUTOMATIC | Standard |
| Odometer / Engine Hour | Odometer / Engine Hour | Meter | vehicle | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.odometer / engineHour) |
| Interval / Tolerance / Due / Overdue | Interval / Toleransi / Jatuh Tempo / Terlambat | Schedule | maintenance | APPROVED_AUTOMATIC | Standard |

## Work Order

| English Term | Indonesian | Context | Module/Domain | Authority / Status | Note |
|---|---|---|---|---|---|
| Complaint / Diagnosis / Finding | Keluhan / Diagnosis / Temuan | Work order | workOrder | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (Finding = glossary.finding) |
| Root Cause / Corrective Action | Akar Masalah / Tindakan Korektif | Work order | workOrder | APPROVED_AUTOMATIC | Standard |
| Road Test / Quality Control | Uji Jalan / Kendali Mutu | QC | workOrder | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.roadTest / qualityControl) |
| Rework | Pengerjaan Ulang | QC | workOrder | APPROVED_AUTOMATIC | Standard |
| Work Order | Work Order | Document | workOrder | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.workOrder) |
| Work Authorization Letter (WAL) | Work Authorization Letter (WAL) | Document | workOrder | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.workAuthorizationLetter) |

## Inventory

| English Term | Indonesian | Context | Module/Domain | Authority / Status | Note |
|---|---|---|---|---|---|
| Inventory | Inventori | Module | inventory | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.inventory) |
| Stock Opname | Stok Opname | Count | inventory | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.stockOpname) |
| Stock Movement / Ledger / Running Balance | Pergerakan Stok / Buku Besar / Saldo Berjalan | Inventory | inventory | APPROVED_AUTOMATIC | Standard |
| Issuance & Return | Pengeluaran & Pengembalian | WO parts | workOrder | APPROVED_AUTOMATIC | Issue = stock leaves warehouse |
| Consumable | Bahan Habis Pakai | Item type | inventory | APPROVED_AUTOMATIC | Standard |
| Spare Part | Suku Cadang | Item type | inventory | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.sparePart) |
| Quarantine | Karantina | Disposition | inventory | APPROVED_AUTOMATIC | Standard |
| Reuse / Reusable | Guna Ulang | Disposition | inventory | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.reuse) |
| Scrap | Scrap | Disposition | inventory | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.scrap) |

## Procurement

| English Term | Indonesian | Context | Module/Domain | Authority / Status | Note |
|---|---|---|---|---|---|
| Vendor / Supplier / Partner | Vendor / Pemasok / Mitra | Procurement | procurement | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.vendor / supplier / partner) |
| Quotation | Penawaran | Procurement | procurement | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.quotation) |
| Refund / Redelivery | Refund / Pengiriman Ulang | Return to vendor | procurement | APPROVED_AUTOMATIC | "Refund" kept as business loanword |
| Terms of Payment | Termin Pembayaran | Procurement | procurement | APPROVED_AUTOMATIC | Standard |
| Freight | Ongkos Kirim | PO | procurement | APPROVED_AUTOMATIC | Standard |
| Outstanding | Belum Lunas | Payment | procurement | APPROVED_AUTOMATIC | Standard |

## Tire

| English Term | Indonesian | Context | Module/Domain | Authority / Status | Note |
|---|---|---|---|---|---|
| Tire | Ban | Domain | tire | APPROVED_AUTOMATIC | Documented correction (owner may veto) |
| Tire Operation (Installation / Rotation / Inspection / Removal / Replacement) | Operasi Ban (Pemasangan / Rotasi / Inspeksi / Pelepasan / Penggantian) | Operations | tire | APPROVED_AUTOMATIC | Standard |
| Wheels Configuration / Position / Axle | Konfigurasi Roda (proposal) / Posisi / Poros | Layout | tire | AWAITING_DECISION | Axle = Poros (owner decision); Wheels Configuration awaits correction decision |
| Spare Tire | Ban Cadangan | Layout | tire | APPROVED_AUTOMATIC | Standard |
| Rim | Velg | Item | tire | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.rim) |
| Retread / Repair (casing) | Vulkanisir / Perbaikan | Lifecycle | tire | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.retread) |
| Casing / Tread / Bead / Sidewall / Inner Liner / Shoulder | Casing / Tapak Ban / Bead / Dinding Samping / Lapisan Dalam / Bahu Ban | Anatomy | tire | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.casing / tread / bead / sidewall / innerLiner) |
| Tread Depth / Wear Pattern | Kedalaman Tapak Ban / Pola Keausan | Inspection | tire | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.tread) |
| Run-flat / Puncture / Cut / Crack / Separation / Bulge | Kempis berjalan / Tusukan / Sayatan / Retak / Pemisahan / Benjolan | Damage | tire | APPROVED_AUTOMATIC | Standard |
| Manufacture Date Code (DOT) | Kode Tanggal Produksi (DOT) | Identity | tire | APPROVED_AUTOMATIC | DOT kept |
| Used Tire Management / Used Stock | Manajemen Ban Bekas / Stok Bekas | Module | tire | APPROVED_AUTOMATIC | Standard |
| New Stock / Installed / Used | Stok Baru / Terpasang / Bekas | Stock state | tire | APPROVED_AUTOMATIC | Standard |

## Workshop

| English Term | Indonesian | Context | Module/Domain | Authority / Status | Note |
|---|---|---|---|---|---|
| Workshop | Bengkel | Organization | workshop | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.workshop) |
| External Workshop | Bengkel Eksternal | Partner type | workshop | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.workshop) |
| Workspace / Service Bay | Workspace / Bay Servis | Bay | workshop | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.workspace) |
| Mechanic / Worker | Mekanik / Pekerja | People | workshop | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.mechanic) |
| Assignment / Reservation | Penugasan / Reservasi | Scheduling | workshop | APPROVED_AUTOMATIC | Standard |
| Scheduler / Workload | Penjadwal / Beban Kerja | Pages | workshop | APPROVED_AUTOMATIC | Standard |
| Labor log / Labor time / Hourly Rate | Log kerja / Waktu kerja / Tarif per Jam | Labor | workshop | APPROVED_AUTOMATIC | Standard |
| Service Invoice / Workshop Invoice | Faktur Layanan / Faktur Bengkel | Billing | workshop | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.serviceInvoice / invoice) |

## Finance / Invoice

| English Term | Indonesian | Context | Module/Domain | Authority / Status | Note |
|---|---|---|---|---|---|
| Invoice | Faktur | Billing / procurement | — | APPROVED_BY_PRODUCT_OWNER | Product owner decision (previous rec.: Invoice · Alt: Faktur) |
| Service Invoice | Faktur Layanan | Workshop invoice | — | APPROVED_BY_PRODUCT_OWNER | Product owner decision (previous rec.: Invoice Servis · Alt: Invoice Bengkel) |
| Void | Batalkan (Void) | Invoice void | MODULE | APPROVED_AUTOMATIC | Accounting meaning kept with "Void" in brackets |
| Amount (money) | Nominal | Field | GLOBAL | APPROVED_AUTOMATIC | "Jumlah" is reserved for quantities |
| Unit Price / Discount / Tax / Total / Subtotal | Harga Satuan / Diskon / Pajak / Total / Subtotal | Money | GLOBAL | APPROVED_AUTOMATIC | Standard |
| Contract / Subscription / Billing / Payment / Pricing | Kontrak / Langganan / Penagihan / Pembayaran / Harga | Commercial | MODULE | APPROVED_AUTOMATIC | Standard |

## Platform / Tenant

| English Term | Indonesian | Context | Module/Domain | Authority / Status | Note |
|---|---|---|---|---|---|
| Tenant | Penyewa | Platform / SaaS | — | APPROVED_BY_PRODUCT_OWNER | Product owner decision (previous rec.: Tenant · Alt: Perusahaan Pelanggan) |
| Bundle | Bundel | Platform commercial | — | APPROVED_BY_PRODUCT_OWNER | Product owner decision (previous rec.: Paket · Alt: Bundle) |
| Entitlement | Entitlement | Platform commercial | — | APPROVED_BY_PRODUCT_OWNER | Product owner decision (previous rec.: Hak Akses Modul · Alt: Entitlement) |
| Intelligence | Kecerdasan | Intelligence | — | APPROVED_BY_PRODUCT_OWNER | Product owner decision (previous rec.: Intelligence · Alt: Analitik Prediktif) |
| Data Scope | Lingkup Data | Access management | — | APPROVED_BY_PRODUCT_OWNER | Product owner decision (previous rec.: Cakupan Data · Alt: Data Scope) |
| Dashboard | Dasbor | Global | — | APPROVED_BY_PRODUCT_OWNER | Product owner decision (previous rec.: Dasbor · Alt: Dashboard) |
| Template | Templat | Configuration | GLOBAL | APPROVED_AUTOMATIC | KBBI |
| Configuration | Konfigurasi | Configuration | GLOBAL | APPROVED_AUTOMATIC | Standard |
| Workflow | Alur Kerja | Configuration | GLOBAL | APPROVED_AUTOMATIC | Standard |
| Notification | Notifikasi | Configuration | GLOBAL | APPROVED_AUTOMATIC | Standard |
| Permission / Role / User | Izin / Peran / Pengguna | Access | GLOBAL | APPROVED_AUTOMATIC | Standard |

## Status Terminology

| English Term | Indonesian | Context | Module/Domain | Authority / Status | Note |
|---|---|---|---|---|---|
| ACTIVE (canonical, unchanged) | Aktif | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| APPROVED (canonical, unchanged) | Disetujui | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| ARCHIVED (canonical, unchanged) | Diarsipkan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| ASSESSED (canonical, unchanged) | Dinilai | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| ASSIGNED (canonical, unchanged) | Ditugaskan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| Add EXTERNAL -> CLOSED transition, triggered when the External Invoice becomes Paid (canonical, unchanged) | Tambah transisi EXTERNAL -> CLOSED, dipicu saat Faktur Eksternal menjadi Lunas | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| Add EXTERNAL status (top-level, parallel to IN_PROGRESS) for work carried out by an external workshop (canonical, unchanged) | Tambah status EXTERNAL (tingkat atas, sejajar dengan IN_PROGRESS) untuk pekerjaan yang dilakukan bengkel eksternal | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| CANCELLED (canonical, unchanged) | Dibatalkan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| CLOSED (canonical, unchanged) | Ditutup | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| COMPLETED (canonical, unchanged) | Selesai | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| Correct EXTERNAL transitions: Findings-only finalization reachable only from DRAFT, exiting only via Revise/Cancel (canonical, unchanged) | Koreksi transisi EXTERNAL: finalisasi khusus Temuan hanya dapat dicapai dari DRAFT, dan keluar hanya melalui Revisi/Batal | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| DISPATCHED (canonical, unchanged) | Dikirim | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| DRAFT (canonical, unchanged) | Draf | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| DUE_SOON (canonical, unchanged) | Segera Jatuh Tempo | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| EXTERNAL (canonical, unchanged) | Eksternal | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| FINALIZED (canonical, unchanged) | Difinalisasi | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| HOLD (canonical, unchanged) | TAHAN | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| INACTIVE (canonical, unchanged) | Nonaktif | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| INSPECTED (canonical, unchanged) | Diinspeksi | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| IN_PROGRESS (canonical, unchanged) | Dalam Proses | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| IN_TRANSIT (canonical, unchanged) | Dalam Perjalanan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| ISSUED (canonical, unchanged) | Diterbitkan (document) / Dikeluarkan (stock) | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Split per domain: status.document.issued / status.stock.issued |
| Initial platform default (migrated from hardcoded transitions) (canonical, unchanged) | Default awal platform (dimigrasikan dari transisi yang sebelumnya hardcoded) | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| LATE (canonical, unchanged) | Terlambat | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| Maintenance Request Workflow (canonical, unchanged) | Alur Kerja Permintaan Pemeliharaan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| NEED_INFORMATION (canonical, unchanged) | Perlu Informasi | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| NEW (canonical, unchanged) | Baru | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| ON_HOLD (canonical, unchanged) | Ditunda | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| PAID (canonical, unchanged) | LUNAS | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| PARTIALLY_RECEIVED (canonical, unchanged) | Diterima Sebagian | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| PENDING_APPROVAL (canonical, unchanged) | Menunggu Persetujuan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| PENDING_INSPECTION (canonical, unchanged) | Menunggu Inspeksi | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| PENDING_PROCESSING (canonical, unchanged) | Menunggu Pemrosesan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| PENDING_RETURN (canonical, unchanged) | Menunggu Pengembalian | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| PREPARED (canonical, unchanged) | Disiapkan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| PROCUREMENT (canonical, unchanged) | Pengadaan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| PUBLISHED (canonical, unchanged) | Diterbitkan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Configuration domain; separate key from ISSUED |
| QC_PENDING (canonical, unchanged) | Menunggu QC | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| QUARANTINED (canonical, unchanged) | Dikarantina | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| RECEIVED (canonical, unchanged) | Diterima | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| REDELIVERY_PENDING (canonical, unchanged) | Menunggu Pengiriman Ulang | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| REDELIVERY_READY (canonical, unchanged) | Siap Dikirim Ulang | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| REDELIVERY_RECEIVED (canonical, unchanged) | Pengiriman Ulang Diterima | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| REDELIVERY_REQUESTED (canonical, unchanged) | Pengiriman Ulang Diminta | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| REFUND_ACCEPTED (canonical, unchanged) | Refund Diterima | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| REFUND_REQUESTED (canonical, unchanged) | Refund Diminta | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| REJECTED (canonical, unchanged) | Ditolak | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| REMOVED (canonical, unchanged) | Dilepas | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| REPAIR (canonical, unchanged) | Perbaikan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| REPAIR_REQUIRED (canonical, unchanged) | Perlu Perbaikan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| REPLACEMENT (canonical, unchanged) | Penggantian | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| REPORTED (canonical, unchanged) | Dilaporkan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| REQUESTED (canonical, unchanged) | Diminta | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| RESERVED (canonical, unchanged) | Dipesan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| RESOLVED (canonical, unchanged) | Terselesaikan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| RESTOCKED (canonical, unchanged) | Dikembalikan ke Stok | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| RETREAD (canonical, unchanged) | Vulkanisir | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| REUSE (canonical, unchanged) | Guna Ulang | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| REWORK (canonical, unchanged) | Pengerjaan Ulang | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| Retire Request Info: remove UNDER_REVIEW -> NEED_INFORMATION (app-level only, enum value kept for legacy records) (canonical, unchanged) | Hentikan Request Info: hapus UNDER_REVIEW -> NEED_INFORMATION (hanya di tingkat aplikasi, nilai enum dipertahankan untuk data lama) | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| SCHEDULED (canonical, unchanged) | Terjadwal | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| SCRAP (canonical, unchanged) | Scrap | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| SCRAPPED (canonical, unchanged) | Scrap | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| SETTLED (canonical, unchanged) | Diselesaikan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| SOLD (canonical, unchanged) | Terjual | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| SUBMITTED (canonical, unchanged) | Diajukan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| SUSPENDED (canonical, unchanged) | Ditangguhkan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| TRANSFERRED (canonical, unchanged) | Dipindahkan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| UNDER_REVIEW (canonical, unchanged) | Dalam Peninjauan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| VERIFIED (canonical, unchanged) | Terverifikasi | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| WAITING_PART (canonical, unchanged) | Menunggu Suku Cadang | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| WARRANTY_CLAIM (canonical, unchanged) | Klaim Garansi | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| WORK_ORDER_CREATED (canonical, unchanged) | Work Order Dibuat | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| Work Order Workflow (canonical, unchanged) | Alur Kerja Work Order | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| Workflow (canonical, unchanged) | Alur Kerja | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |

## Action Terminology

| English Term | Indonesian | Context | Module/Domain | Authority / Status | Note |
|---|---|---|---|---|---|
| Approve → Approved | Setujui → Disetujui | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Reject → Rejected | Tolak → Ditolak | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Cancel → Cancelled | Batalkan → Dibatalkan | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Complete → Completed | Selesaikan → Selesai | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Submit → Submitted | Ajukan → Diajukan | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Dispatch / Send → Dispatched / Sent | Kirim → Dikirim / Terkirim | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Receive → Received | Terima → Diterima | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Issue (document) → Issued | Terbitkan → Diterbitkan | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Issue (stock) → Issued | Keluarkan → Dikeluarkan | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Publish → Published | Terbitkan → Diterbitkan | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Close → Closed | Tutup → Ditutup | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Assign → Assigned | Tugaskan → Ditugaskan | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Schedule → Scheduled | Jadwalkan → Terjadwal | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Verify → Verified | Verifikasi → Terverifikasi | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Archive → Archived | Arsipkan → Diarsipkan | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Suspend → Suspended | Tangguhkan → Ditangguhkan | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Terminate → Terminated | Akhiri → Diakhiri | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Activate / Deactivate → Active / Inactive | Aktifkan / Nonaktifkan → Aktif / Nonaktif | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Transfer → Transferred | Pindahkan / Transfer → Dipindahkan | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Reserve → Reserved | Pesan → Dipesan | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Inspect → Inspected | Inspeksi → Diinspeksi | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Resolve → Resolved | Selesaikan → Terselesaikan | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Return → Returned | Kembalikan / Retur → Dikembalikan / Diretur | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Settle → Settled | Selesaikan → Diselesaikan | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Pay → Paid | Bayar → Lunas | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Request → Requested | Minta / Ajukan → Diminta | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Prepare → Prepared | Siapkan → Disiapkan | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Finalize → Finalized | Finalisasi → Difinalisasi | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Void → Voided | Batalkan (Void) → Dibatalkan (Void) | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Hold → On Hold | Tunda → Ditunda | Action → resulting status | GLOBAL | APPROVED_AUTOMATIC | Verb (imperative) vs passive/adjective status |
| Scrap → Scrapped | Scrap → Scrap | Action → resulting status | GLOBAL | APPROVED_BY_PRODUCT_OWNER | Verb (imperative) vs passive/adjective status; glossary.scrap |
| Retread → Retread | Vulkanisir → Vulkanisir | Action → resulting status | GLOBAL | APPROVED_BY_PRODUCT_OWNER | Verb (imperative) vs passive/adjective status; glossary.retread |

## Document Terminology

| English Term | Indonesian | Context | Module/Domain | Authority / Status | Note |
|---|---|---|---|---|---|
| Work Order | Work Order | Document | workOrder | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.workOrder) |
| Work Authorization Letter (WAL) | Work Authorization Letter (WAL) | Document | workOrder | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.workAuthorizationLetter) |
| Maintenance Memo / Maintenance Report | Memo Pemeliharaan / Laporan Pemeliharaan | Document | maintenance | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.maintenance) |
| Inspection Report | Laporan Inspeksi | Document | inspection | APPROVED_AUTOMATIC | Standard |
| Purchase Request / Purchase Order | Purchase Request / Purchase Order | Document | procurement | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.purchaseRequest / purchaseOrder) |
| Request for Quotation | Permintaan Penawaran | Document | procurement | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.quotation) |
| Return Order | Retur Pemesanan (document) / Retur ke Vendor (flow) | Document | procurement | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.returnToVendor) |
| Goods Receipt | Goods Receipt | Document | inventory | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.goodsReceipt) |
| Stock Transfer / Vehicle Transfer | Transfer Stok / Transfer Kendaraan | Document | inventory / vehicle | APPROVED_AUTOMATIC | Standard |
| Warranty Claim | Klaim Garansi | Document | warranty | APPROVED_AUTOMATIC | Standard |
| Invoice | Faktur | Document | billing | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.invoice) |
| Bill To / Due Date / Issue Date | Ditagihkan Kepada / Jatuh Tempo / Tanggal Terbit | Print label | documents | APPROVED_AUTOMATIC | Standard |
| Prepared by / Approved by / Received By / Signature / Position | Disiapkan oleh / Disetujui oleh / Diterima Oleh / Tanda Tangan / Jabatan | Signature block | documents | STRUCTURAL_PREP_REQUIRED | Print templates are DB-stored (DATABASE_LOCALIZATION) |
| Workshop Stamp | Stempel Bengkel | Signature block | documents | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.workshop) |
| Attn: / Dear | Up.: / Kepada Yth. | Letter | documents | STRUCTURAL_PREP_REQUIRED | Indonesian letter conventions |

## Technical Terms

| English Term | Indonesian | Context | Module/Domain | Authority / Status | Note |
|---|---|---|---|---|---|
| Downtime | Downtime | KPI | analytics | APPROVED_AUTOMATIC | Common technical loanword |
| Throughput | Throughput | KPI | analytics | APPROVED_AUTOMATIC | Loanword |
| Utilization | Utilisasi | KPI | analytics | APPROVED_AUTOMATIC | Standard |
| Snapshot | Snapshot | Analytics data | analytics | APPROVED_AUTOMATIC | Loanword |
| ETL / Backfill | ETL / Backfill | Data pipeline | analytics | APPROVED_AUTOMATIC | Technical |
| Machine learning / ML | machine learning / ML | Intelligence | intelligence | APPROVED_AUTOMATIC | Technical |
| Remaining Useful Life (RUL) | Sisa Umur Pakai (RUL) | Intelligence | intelligence | APPROVED_AUTOMATIC | Abbreviation kept |
| Wheel Alignment | Spooring | Tire wear advice | tire | APPROVED_AUTOMATIC | Common workshop word |
| Wheel balance | Balans roda | Tire wear advice | tire | APPROVED_AUTOMATIC | Common workshop word |
| Tubeless / Radial / Bias | Tubeless / Radial / Bias | Tire construction | tire | APPROVED_AUTOMATIC | Industry terms |
| Undercarriage | Undercarriage | Heavy equipment | masterData | APPROVED_AUTOMATIC | Industry term |
| Torque Converter | Torque Converter | Component group | masterData | APPROVED_AUTOMATIC | Industry term |
| Bin / Rack / Zone | Bin / Rak / Zona | Storage layout | inventory | APPROVED_AUTOMATIC | "Bin" kept |
| Asset# | Asset# | Component asset id label | inventory | APPROVED_AUTOMATIC | Identifier label |
| On Hand | Stok di Tangan | Inventory | inventory | APPROVED_AUTOMATIC | Standard |
| Reorder Point | Titik Pemesanan Ulang | Inventory | inventory | APPROVED_AUTOMATIC | Standard |
| Lead Time | Waktu Tunggu (lead time) | Procurement | procurement | APPROVED_AUTOMATIC | Standard |
| Inspection Certificate | Sertifikat Uji Kelayakan | Vehicle document | vehicle | APPROVED_AUTOMATIC | Indonesian KIR document |
| Tax ID | NPWP | Company profile | account | APPROVED_AUTOMATIC | Indonesian tax id |
| Load Index / Speed Rating / Ply Rating | Indeks Beban / Peringkat Kecepatan / Peringkat Lapisan | Tire spec | tire | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.tireSpec) |
| Engine Hour / HM | Engine Hour / HM | Meter | vehicle | APPROVED_BY_PRODUCT_OWNER | Product owner decision applied (glossary.engineHour) |

## Do Not Translate

| English Term | Indonesian | Context | Module/Domain | Authority / Status | Note |
|---|---|---|---|---|---|
| OptiFleet | OptiFleet | Product name | GLOBAL | DO_NOT_TRANSLATE | Brand |
| VIN | VIN | Vehicle identifier | GLOBAL | DO_NOT_TRANSLATE | Industry abbreviation |
| SKU | SKU | Product code | GLOBAL | DO_NOT_TRANSLATE | Industry abbreviation |
| API / UUID / GPS | API / UUID / GPS | Technical | GLOBAL | DO_NOT_TRANSLATE | Technical |
| PO / GR / RFQ / PR / WO / MR / WAL | PO / GR / RFQ / PR / WO / MR / WAL | Document abbreviations | GLOBAL | DO_NOT_TRANSLATE | Business abbreviation; full names are REVIEW terms |
| QC / KPI / UoM / UOM / PIC / SDS / ETL | QC / KPI / UoM / UOM / PIC / SDS / ETL | Abbreviations | GLOBAL | DO_NOT_TRANSLATE | Business abbreviation |
| MTTR / MTBF / RUL | MTTR / MTBF / RUL | KPI | GLOBAL | DO_NOT_TRANSLATE | Industry abbreviation |
| PDF / CSV / XLSX / JPG / JPEG / PNG / WEBP / DOC / DOCX | PDF / CSV / XLSX / JPG / JPEG / PNG / WEBP / DOC / DOCX | File formats | GLOBAL | DO_NOT_TRANSLATE | Format |
| km / mm / kg / cc / L / kW / psi / % / km/h | km / mm / kg / cc / L / kW / psi / % / km/h | Units | GLOBAL | DO_NOT_TRANSLATE | Unit (km/h shown as "km/jam" inside sentences) |
| {TENANT} {BRANCH} {WORKSHOP} {WAREHOUSE} {DOC} {YYYY} {YY} {MM} {MMMM} {DD} {SEQ:n} | {TENANT} {BRANCH} {WORKSHOP} {WAREHOUSE} {DOC} {YYYY} {YY} {MM} {MMMM} {DD} {SEQ:n} | Numbering tokens | GLOBAL | DO_NOT_TRANSLATE | Functional syntax |
| {{work_order.number}} etc. | {{work_order.number}} etc. | Template variables | GLOBAL | DO_NOT_TRANSLATE | Template engine |
| {{param}} / {param} / :attribute | {{param}} / {param} / :attribute | Message parameters | GLOBAL | DO_NOT_TRANSLATE | Runtime substitution |
| D_new / D_min / D_pull / D_service / A_max / A_retread_max / N_retread_max | D_new / D_min / D_pull / D_service / A_max / A_retread_max / N_retread_max | Tire rule symbols | GLOBAL | DO_NOT_TRANSLATE | Formula symbols |
| TRA Code / OTR / DOT | TRA Code / OTR / DOT | Tire standards | GLOBAL | DO_NOT_TRANSLATE | Standard names |
| Canonical codes (UNDER_REVIEW, SELL_ELIGIBLE, REUSE, PENDING_APPROVAL …) inside messages | Canonical codes (UNDER_REVIEW, SELL_ELIGIBLE, REUSE, PENDING_APPROVAL …) inside messages | Codes | GLOBAL | DO_NOT_TRANSLATE | Identifier; only the display label is translated |
| e.g. example values (ACME, BRAKE_PAD, NET 30, SAE 15W-40, Bosch, Dutro) | e.g. example values (ACME, BRAKE_PAD, NET 30, SAE 15W-40, Bosch, Dutro) | Placeholder samples | GLOBAL | DO_NOT_TRANSLATE | Only "e.g." → "mis." is translated |
| HH:mm / YYYY-MM-DD | HH:mm / YYYY-MM-DD | Format masks | GLOBAL | DO_NOT_TRANSLATE | Format |
| Fill Here (XLSX sheet name), import headers | Fill Here (XLSX sheet name), import headers | Import contract | GLOBAL | DO_NOT_TRANSLATE | Changing them breaks import (ERROR_CODE_DECOUPLING) |

