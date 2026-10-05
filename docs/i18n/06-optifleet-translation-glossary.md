# 06 — OptiFleet Translation Glossary

English → Indonesian terminology used to build `08-en-id-translation-dataset.csv`. **AUTO_TRANSLATE_SAFE** terms are applied in the dataset. **REVIEW** terms show the recommended option only; they are not final (see 07). Canonical codes, identifiers and template variables never change.

Guiding rules: verbs in imperative for actions (Simpan, Setujui), passive/adjective for statuses (Disimpan, Disetujui); short sentence-case messages; no word-by-word translation — the key, category, module and context decide (e.g. *Issue* = Terbitkan for documents, Keluarkan for stock, Masalah for problems).

## Approved Automatic Terms

| English Term | Indonesian | Context | Module/Domain | Classification | Reason |
|---|---|---|---|---|---|
| Save | Simpan | Button | GLOBAL | AUTO_TRANSLATE_SAFE | Standard |
| Cancel (dialog dismiss) | Batal | Modal / form secondary button | GLOBAL | AUTO_TRANSLATE_SAFE | Dismiss = no action; "Batalkan" is reserved for cancelling a record |
| Cancel <document> | Batalkan <dokumen> | Row / document action | GLOBAL | AUTO_TRANSLATE_SAFE | Action on a record (e.g. Batalkan Permintaan) |
| Edit | Ubah | Button | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline |
| Update | Perbarui | Button | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline |
| Delete | Hapus | Button | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline |
| Remove | Hapus / Lepas | Remove a file/line → Hapus; remove tire/component from vehicle → Lepas | GLOBAL | AUTO_TRANSLATE_SAFE | Context decides; see style.deleteRemove |
| Create | Buat | Button | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline |
| Add | Tambah | Button | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline |
| New (adjective) | Baru | "+ New X" → "+ X Baru" | GLOBAL | AUTO_TRANSLATE_SAFE | Indonesian word order |
| Submit (for approval/review) | Ajukan | Workflow submit | GLOBAL | AUTO_TRANSLATE_SAFE | Deviation from candidate "Kirim": "Dikirim" is already used for Dispatched/Sent (stock & PO); "Ajukan/Diajukan" avoids the collision |
| Submit (payment / inspection data) | Kirim | Non-approval submit | GLOBAL | AUTO_TRANSLATE_SAFE | Sending data, not requesting approval |
| Approve | Setujui | Action | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline |
| Reject | Tolak | Action | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline |
| Close | Tutup | Action | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline |
| Print | Cetak | Action | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline |
| Download | Unduh | Action | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline |
| Upload | Unggah | Action | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline |
| Search | Cari | Search box | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline |
| Back | Kembali | Navigation | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline |
| Next / Previous | Berikutnya / Sebelumnya | Pagination | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline |
| View | Lihat | Action | GLOBAL | AUTO_TRANSLATE_SAFE | Standard |
| Select / Select… | Pilih / Pilih… | Action / placeholder option | GLOBAL | AUTO_TRANSLATE_SAFE | Standard |
| Confirm | Konfirmasi | Button | GLOBAL | AUTO_TRANSLATE_SAFE | Standard |
| Publish | Terbitkan | Configuration publish | GLOBAL | AUTO_TRANSLATE_SAFE | Pairs with status Diterbitkan |
| Issue (document) | Terbitkan | Issue Invoice / RFQ | MODULE | AUTO_TRANSLATE_SAFE | Document issuance |
| Issue (stock) | Keluarkan / Pengeluaran | Inventory issue | MODULE | AUTO_TRANSLATE_SAFE | Stock leaving the warehouse |
| Receive | Terima | Goods / cycle receive | MODULE | AUTO_TRANSLATE_SAFE | Standard |
| Accept | Terima | Recommendation / vendor acceptance | MODULE | AUTO_TRANSLATE_SAFE | Same Indonesian as Receive — context differs (see 11) |
| Assign / Reassign / Unassign | Tugaskan / Tugaskan Ulang / Batalkan Penugasan | Workforce | MODULE | AUTO_TRANSLATE_SAFE | Standard |
| Schedule | Jadwalkan | Action | GLOBAL | AUTO_TRANSLATE_SAFE | Standard |
| Start / Pause / Resume / Finish | Mulai / Jeda / Lanjutkan / Selesai | Labor timer | MODULE | AUTO_TRANSLATE_SAFE | Standard |
| Complete | Selesaikan | Action | GLOBAL | AUTO_TRANSLATE_SAFE | Pairs with status Selesai |
| Import / Export | Impor / Ekspor | Action | GLOBAL | AUTO_TRANSLATE_SAFE | Standard |
| Void | Batalkan (Void) | Invoice void | MODULE | AUTO_TRANSLATE_SAFE | Accounting meaning kept with "Void" in brackets |
| Loading… / Saving… / Creating… | Memuat… / Menyimpan… / Membuat… | Busy state | GLOBAL | AUTO_TRANSLATE_SAFE | Progressive form |
| Yes / No | Ya / Tidak | Boolean | GLOBAL | AUTO_TRANSLATE_SAFE | Standard |
| All / Any | Semua / Apa saja | Filter option | GLOBAL | AUTO_TRANSLATE_SAFE | Standard |
| (optional) / (required) | (opsional) / (wajib) | Field suffix | GLOBAL | AUTO_TRANSLATE_SAFE | Standard |
| No records found. / No X found. | Tidak ada data. / Tidak ada X. | Empty state | GLOBAL | AUTO_TRANSLATE_SAFE | Short form, no "ditemukan" |
| … is required. | … wajib diisi. | Validation | BACKEND | AUTO_TRANSLATE_SAFE | Standard |
| … must be greater than zero | … harus lebih dari nol | Validation | BACKEND | AUTO_TRANSLATE_SAFE | Standard |
| Are you sure …? | Apakah Anda yakin …? | Confirmation | GLOBAL | AUTO_TRANSLATE_SAFE | Standard |
| This cannot be undone. | Tindakan ini tidak dapat dibatalkan. | Warning | GLOBAL | AUTO_TRANSLATE_SAFE | Risk meaning preserved |
| X saved / deleted / removed. | X disimpan / dihapus. | Success | GLOBAL | AUTO_TRANSLATE_SAFE | Short past form |
| … is outside your assigned data scope. | … berada di luar cakupan data Anda. | Error | BACKEND | REVIEW | depends_on glossary.dataScope |
| Name / Code / Status / Type / Category / Description | Nama / Kode / Status / Jenis / Kategori / Deskripsi | Field | GLOBAL | AUTO_TRANSLATE_SAFE | Standard |
| Notes / Reason / Date / Priority | Catatan / Alasan / Tanggal / Prioritas | Field | GLOBAL | AUTO_TRANSLATE_SAFE | Standard |
| Quantity / Qty | Jumlah / Qty | Field | GLOBAL | AUTO_TRANSLATE_SAFE | Qty kept in narrow columns |
| Amount (money) | Nominal | Field | GLOBAL | AUTO_TRANSLATE_SAFE | "Jumlah" is reserved for quantities |
| Unit Price / Discount / Tax / Total / Subtotal | Harga Satuan / Diskon / Pajak / Total / Subtotal | Money | GLOBAL | AUTO_TRANSLATE_SAFE | Standard |
| Vehicle | Kendaraan | Domain | GLOBAL | AUTO_TRANSLATE_SAFE | Standard |
| Fleet | Armada | Domain | GLOBAL | AUTO_TRANSLATE_SAFE | Standard |
| Branch | Cabang | Organization | GLOBAL | AUTO_TRANSLATE_SAFE | Standard |
| Warehouse | Gudang | Inventory | GLOBAL | AUTO_TRANSLATE_SAFE | Standard |
| Product | Produk | Inventory | GLOBAL | AUTO_TRANSLATE_SAFE | Standard |
| Stock | Stok | Inventory | GLOBAL | AUTO_TRANSLATE_SAFE | Standard |
| Tire / Tyre | Ban | Tire | DOMAIN | AUTO_TRANSLATE_SAFE | Audit REVIEW downgraded with documented correction (owner may veto, see 11) |
| Inspection | Inspeksi | Domain | GLOBAL | AUTO_TRANSLATE_SAFE | Standard in fleet usage |
| Template | Templat | Configuration | GLOBAL | AUTO_TRANSLATE_SAFE | KBBI |
| Configuration | Konfigurasi | Configuration | GLOBAL | AUTO_TRANSLATE_SAFE | Standard |
| Workflow | Alur Kerja | Configuration | GLOBAL | AUTO_TRANSLATE_SAFE | Standard |
| Notification | Notifikasi | Configuration | GLOBAL | AUTO_TRANSLATE_SAFE | Standard |
| Permission / Role / User | Izin / Peran / Pengguna | Access | GLOBAL | AUTO_TRANSLATE_SAFE | Standard |
| Contract / Subscription / Billing / Payment / Pricing | Kontrak / Langganan / Penagihan / Pembayaran / Harga | Commercial | MODULE | AUTO_TRANSLATE_SAFE | Standard |
| Warranty / Warranty Claim | Garansi / Klaim Garansi | Warranty | MODULE | AUTO_TRANSLATE_SAFE | Audit REVIEW downgraded with documented correction |
| History / Audit Log | Riwayat / Log Audit | Global | GLOBAL | AUTO_TRANSLATE_SAFE | Standard |
| Overview / Summary / Details | Ringkasan / Ringkasan / Detail | Section | GLOBAL | AUTO_TRANSLATE_SAFE | Overview and Summary both "Ringkasan" (see 11) |
| Recommendation / Risk / Health | Rekomendasi / Risiko / Kesehatan | Intelligence | MODULE | AUTO_TRANSLATE_SAFE | Standard |
| Analytics | Analitik | Module | MODULE | AUTO_TRANSLATE_SAFE | Standard |
| Month names | Januari … Desember (Jan, Feb, Mar, Apr, Mei, Jun, Jul, Agu, Sep, Okt, Nov, Des) | Dates | GLOBAL | STRUCTURAL_PREP_REQUIRED | Prefer Intl.DateTimeFormat (RUNTIME_LABEL_GENERATION) |

## Technical Terms

| English Term | Indonesian | Context | Module/Domain | Classification | Reason |
|---|---|---|---|---|---|
| Downtime | Downtime | KPI | analytics | AUTO_TRANSLATE_SAFE | Common technical loanword |
| Throughput | Throughput | KPI | analytics | AUTO_TRANSLATE_SAFE | Loanword |
| Utilization | Utilisasi | KPI | analytics | AUTO_TRANSLATE_SAFE | Standard |
| Snapshot | Snapshot | Analytics data | analytics | AUTO_TRANSLATE_SAFE | Loanword |
| ETL / Backfill | ETL / Backfill | Data pipeline | analytics | AUTO_TRANSLATE_SAFE | Technical |
| Machine learning / ML | machine learning / ML | Intelligence | intelligence | AUTO_TRANSLATE_SAFE | Technical |
| Remaining Useful Life (RUL) | Sisa Umur Pakai (RUL) | Intelligence | intelligence | AUTO_TRANSLATE_SAFE | Abbreviation kept |
| Wheel Alignment | Spooring | Tire wear advice | tire | AUTO_TRANSLATE_SAFE | Common workshop word |
| Wheel balance | Balans roda | Tire wear advice | tire | AUTO_TRANSLATE_SAFE | Common workshop word |
| Tubeless / Radial / Bias | Tubeless / Radial / Bias | Tire construction | tire | AUTO_TRANSLATE_SAFE | Industry terms |
| Undercarriage | Undercarriage | Heavy equipment | masterData | AUTO_TRANSLATE_SAFE | Industry term |
| Torque Converter | Torque Converter | Component group | masterData | AUTO_TRANSLATE_SAFE | Industry term |
| Bin / Rack / Zone | Bin / Rak / Zona | Storage layout | inventory | AUTO_TRANSLATE_SAFE | "Bin" kept |
| Asset# | Asset# | Component asset id label | inventory | AUTO_TRANSLATE_SAFE | Identifier label |
| On Hand | Stok di Tangan | Inventory | inventory | AUTO_TRANSLATE_SAFE | Standard |
| Reorder Point | Titik Pemesanan Ulang | Inventory | inventory | AUTO_TRANSLATE_SAFE | Standard |
| Lead Time | Waktu Tunggu (lead time) | Procurement | procurement | AUTO_TRANSLATE_SAFE | Standard |
| Inspection Certificate | Sertifikat Uji Kelayakan | Vehicle document | vehicle | AUTO_TRANSLATE_SAFE | Indonesian KIR document |
| Tax ID | NPWP | Company profile | account | AUTO_TRANSLATE_SAFE | Indonesian tax id |
| Load Index / Speed Rating / Ply Rating | keep English | Tire spec | tire | REVIEW | glossary.tireSpec |
| Engine Hour / HM | Jam Mesin / HM | Meter | vehicle | REVIEW | glossary.engineHour |

## Do Not Translate

| English Term | Indonesian | Context | Module/Domain | Classification | Reason |
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

## Review Required

| English Term | Indonesian | Context | Module/Domain | Classification | Reason |
|---|---|---|---|---|---|
| Work Order | Rec: Work Order · Alt: Perintah Kerja | Document / module name | — | REVIEW | CRITICAL — Core document across maintenance, workshop, inventory and procurement; "WO" abbreviation is kept everywhere, and Indonesian workshops widely say "Work Order". "Perintah Kerja" collides with "Surat Perintah Kerja (SPK)". |
| Maintenance | Rec: Perawatan · Alt: Pemeliharaan | Module / domain | — | REVIEW | CRITICAL — Module, menu, document and KPI name. "Perawatan" is the common fleet/workshop term; "Pemeliharaan" is more formal/asset-management. |
| Workshop | Rec: Bengkel · Alt: Workshop | Organization / workshop operations | — | REVIEW | CRITICAL — Organization unit, menu group, external partner type and invoice name. |
| Spare art / Sparepart / art | Rec: Suku Cadang · Alt: Sparepart | Inventory / item type | — | REVIEW | CRITICAL — Item type, menu ("Used Spareparts"), sale and request flows; English has two spellings (see 03). |
| Tenant | Rec: Tenant · Alt: Perusahaan Pelanggan | Platform / SaaS | — | REVIEW | HIGH — SaaS account concept used in the platform portal and system-default wording; no natural Indonesian equivalent. |
| Vendor | Rec: Vendor · Alt: Pemasok | Procurement | — | REVIEW | HIGH — Procurement role of a Partner; overlaps with Supplier (03). |
| Supplier | Rec: Pemasok · Alt: Supplier | Partner type | — | REVIEW | HIGH — Partner type (Spare Part / Tire Supplier); must not collide with Vendor. |
| Partner | Rec: Mitra · Alt: Rekanan | Partner master | — | REVIEW | HIGH — Master record for vendors, workshops, towing providers. |
| Breakdown | Rec: Breakdown · Alt: Kerusakan Darurat | Maintenance | — | REVIEW | HIGH — Operational incident type and module name. |
| Goods Receipt | Rec: Penerimaan Barang · Alt: Goods Receipt | Inventory / procurement document | — | REVIEW | HIGH — Inventory document; abbreviation GR stays. |
| Purchase Order | Rec: Purchase Order · Alt: Pesanan Pembelian | Procurement document | — | REVIEW | HIGH — Procurement document; abbreviation PO stays and is common in Indonesian companies. |
| Purchase Request | Rec: Permintaan Pembelian · Alt: Purchase Request | Procurement document | — | REVIEW | HIGH — Procurement document; abbreviation PR stays. |
| Quotation / Request for Quotation | Rec: Penawaran Harga · Alt: Quotation | Procurement | — | REVIEW | HIGH — Vendor quotation / RFQ flow; RFQ abbreviation stays. |
| Invoice | Rec: Invoice · Alt: Faktur | Billing / procurement | — | REVIEW | HIGH — Platform billing, vendor invoice references, workshop invoices; "Faktur" may be confused with Faktur Pajak, "Tagihan" with billing. |
| Service Invoice | Rec: Invoice Servis · Alt: Invoice Bengkel | Workshop invoice | — | REVIEW | MEDIUM — English canonical is itself undecided (Workshop Invoice vs Service Invoice, 03). |
| Part Request | Rec: Permintaan Suku Cadang · Alt: Part Request | Work order / inventory | — | REVIEW | HIGH — Work-order part request document; depends on glossary.sparePart. |
| Workspace | Rec: Area Kerja · Alt: Bay Kerja | Workshop operations | — | REVIEW | HIGH — Workshop bay concept used by scheduler, assignment and WO. |
| Mechanic / Worker | Rec: Mekanik · Alt: Teknisi | Workshop operations | — | REVIEW | MEDIUM — Menu says Mechanic, API says worker (03). |
| Work Authorization Letter | Rec: Surat Otorisasi Kerja · Alt: Surat Perintah Kerja (SPK) | Document | — | REVIEW | HIGH — External workshop document; "SPK" is the common Indonesian name but is also how many companies call a Work Order. |
| Opname | Rec: Stock Opname · Alt: Penghitungan Stok | Inventory | — | REVIEW | MEDIUM — Already the Indonesian business term used in the English UI. |
| Inventory | Rec: Inventori · Alt: Persediaan | Inventory | — | REVIEW | MEDIUM — Module/menu name; "Persediaan" is the accounting term. |
| Retreadw* | Rec: Vulkanisir · Alt: Retread | Tire | — | REVIEW | HIGH — Tire lifecycle process (cycle, history, partner); "vulkanisir" is the common Indonesian word, "retread" is used by fleet tire specialists. |
| Casing | Rec: Casing · Alt: Karkas | Tire | — | REVIEW | MEDIUM — Tire casing eligibility for retread. |
| Tread | Rec: Tapak · Alt: Tread | Tire | — | REVIEW | MEDIUM — Tread depth / tread condition. |
| Bead | Rec: Bead · Alt: Tumit Ban | Tire | — | REVIEW | MEDIUM — Tire inspection location. |
| Sidewall | Rec: Dinding Samping · Alt: Sidewall | Tire | — | REVIEW | MEDIUM — Tire inspection location. |
| Inner Liner | Rec: Lapisan Dalam · Alt: Inner Liner | Tire | — | REVIEW | MEDIUM — Tire inspection location. |
| Scrap(ped / ping) | Rec: Scrap · Alt: Afkir | Tire / inventory | — | REVIEW | MEDIUM — Disposal status/action for tires and used parts (SCRAPPED stays the stored code). |
| Reuse / Reusable | Rec: Pakai Ulang · Alt: Reuse | Tire / inventory | — | REVIEW | MEDIUM — Used-tire / used-part disposition. |
| Finding | Rec: Temuan · Alt: Hasil Pemeriksaan | Inspection / work order | — | REVIEW | MEDIUM — Inspection / work-order findings. |
| Engine Hour / Hour [Mm]eter | Rec: Jam Mesin · Alt: Hour Meter (HM) | Vehicle / maintenance | — | REVIEW | MEDIUM — Usage meter for heavy equipment; "HM" is common in mining fleets. |
| Odometer | Rec: Odometer · Alt: Penunjuk Kilometer | Vehicle | — | REVIEW | LOW — Usage meter label; alternates with "KM" (03). |
| Bundle | Rec: Paket · Alt: Bundle | Platform commercial | — | REVIEW | MEDIUM — Commercial bundle of modules (platform). |
| Entitlement | Rec: Hak Akses Modul · Alt: Entitlement | Platform commercial | — | REVIEW | MEDIUM — Module entitlement per tenant/contract. |
| Intelligence | Rec: Intelligence · Alt: Analitik Prediktif | Intelligence | — | REVIEW | MEDIUM — Feature/module brand (Maintenance Intelligence). |
| Return Order / Return to Vendor / Purchase Return | Rec: Retur ke Vendor (flow); document name "Return Order" kept · Alt: Retur Pembelian | Procurement | — | REVIEW | MEDIUM — Three English names for one flow (03); depends on glossary.vendor. |
| Road Test | Rec: Uji Jalan · Alt: Road Test | Work order | — | REVIEW | LOW — Work-order QC step. |
| Quality Control | Rec: Quality Control (QC) · Alt: Kontrol Kualitas | Work order | — | REVIEW | LOW — QC step / module; QC abbreviation stays. |
| Rim | Rec: Velg · Alt: Pelek | Tire / inventory | — | REVIEW | LOW — Wheel rim item type. |
| Axle | Rec: Sumbu · Alt: As Roda | Tire | — | REVIEW | LOW — Wheel configuration axles. |
| Load Index / Speed Rating / Ply Rating | Rec: keep English (Load Index / Speed Rating / Ply Rating) · Alt: Indeks Beban / Indeks Kecepatan / Peringkat Lapisan | Tire product | — | REVIEW | MEDIUM — Tire industry standard specification names. |
| Data Scope | Rec: Cakupan Data · Alt: Data Scope | Access management | — | REVIEW | LOW — Access-control concept. |
| Dashboard | Rec: Dasbor · Alt: Dashboard | Global | — | REVIEW | LOW — Page/menu name; KBBI form is "dasbor", many Indonesian SaaS keep "Dashboard". |

## Status Terminology

| English Term | Indonesian | Context | Module/Domain | Classification | Reason |
|---|---|---|---|---|---|
| ACTIVE (canonical, unchanged) | Aktif | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| APPROVED (canonical, unchanged) | Disetujui | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| ARCHIVED (canonical, unchanged) | Diarsipkan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| ASSESSED (canonical, unchanged) | Dinilai | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| ASSIGNED (canonical, unchanged) | Ditugaskan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| Add EXTERNAL -> CLOSED transition, triggered when the External Invoice becomes Paid (canonical, unchanged) | Tambah transisi EXTERNAL -> CLOSED, dipicu saat Invoice Eksternal menjadi Lunas | Status display label | DOMAIN | REVIEW | Display only — canonical code never changes; needs status registry |
| Add EXTERNAL status (top-level, parallel to IN_PROGRESS) for work carried out by an external workshop (canonical, unchanged) | Tambah status EXTERNAL (tingkat atas, sejajar dengan IN_PROGRESS) untuk pekerjaan yang dilakukan bengkel eksternal | Status display label | DOMAIN | REVIEW | Display only — canonical code never changes; needs status registry |
| CANCELLED (canonical, unchanged) | Dibatalkan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| CLOSED (canonical, unchanged) | Ditutup | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| COMPLETED (canonical, unchanged) | Selesai | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| Correct EXTERNAL transitions: Findings-only finalization reachable only from DRAFT, exiting only via Revise/Cancel (canonical, unchanged) | Koreksi transisi EXTERNAL: finalisasi khusus Temuan hanya dapat dicapai dari DRAFT, dan keluar hanya melalui Revisi/Batal | Status display label | DOMAIN | REVIEW | Display only — canonical code never changes; needs status registry |
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
| ISSUED (canonical, unchanged) | Diterbitkan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| Initial platform default (migrated from hardcoded transitions) (canonical, unchanged) | Default awal platform (dimigrasikan dari transisi yang sebelumnya hardcoded) | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| LATE (canonical, unchanged) | Terlambat | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| Maintenance Request Workflow (canonical, unchanged) | Alur Kerja Permintaan Perawatan | Status display label | DOMAIN | REVIEW | Display only — canonical code never changes; needs status registry |
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
| PUBLISHED (canonical, unchanged) | Diterbitkan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
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
| RETREAD (canonical, unchanged) | Vulkanisir | Status display label | DOMAIN | REVIEW | Display only — canonical code never changes; needs status registry |
| REUSE (canonical, unchanged) | Pakai Ulang | Status display label | DOMAIN | REVIEW | Display only — canonical code never changes; needs status registry |
| REWORK (canonical, unchanged) | Pengerjaan Ulang | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| Retire Request Info: remove UNDER_REVIEW -> NEED_INFORMATION (app-level only, enum value kept for legacy records) (canonical, unchanged) | Hentikan Request Info: hapus UNDER_REVIEW -> NEED_INFORMATION (hanya di tingkat aplikasi, nilai enum dipertahankan untuk data lama) | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| SCHEDULED (canonical, unchanged) | Terjadwal | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| SCRAP (canonical, unchanged) | Scrap | Status display label | DOMAIN | REVIEW | Display only — canonical code never changes; needs status registry |
| SCRAPPED (canonical, unchanged) | Scrap | Status display label | DOMAIN | REVIEW | Display only — canonical code never changes; needs status registry |
| SETTLED (canonical, unchanged) | Diselesaikan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| SOLD (canonical, unchanged) | Terjual | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| SUBMITTED (canonical, unchanged) | Diajukan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| SUSPENDED (canonical, unchanged) | Ditangguhkan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| TRANSFERRED (canonical, unchanged) | Dipindahkan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| UNDER_REVIEW (canonical, unchanged) | Dalam Peninjauan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| VERIFIED (canonical, unchanged) | Terverifikasi | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| WAITING_PART (canonical, unchanged) | Menunggu Suku Cadang | Status display label | DOMAIN | REVIEW | Display only — canonical code never changes; needs status registry |
| WARRANTY_CLAIM (canonical, unchanged) | Klaim Garansi | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| WORK_ORDER_CREATED (canonical, unchanged) | Work Order Dibuat | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |
| Work Order Workflow (canonical, unchanged) | Alur Kerja Work Order | Status display label | DOMAIN | REVIEW | Display only — canonical code never changes; needs status registry |
| Workflow (canonical, unchanged) | Alur Kerja | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry |

## Action Terminology

| English Term | Indonesian | Context | Module/Domain | Classification | Reason |
|---|---|---|---|---|---|
| Approve → Approved | Setujui → Disetujui | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Reject → Rejected | Tolak → Ditolak | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Cancel → Cancelled | Batalkan → Dibatalkan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Complete → Completed | Selesaikan → Selesai | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Submit → Submitted | Ajukan → Diajukan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Dispatch / Send → Dispatched / Sent | Kirim → Dikirim / Terkirim | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Receive → Received | Terima → Diterima | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Issue (document) → Issued | Terbitkan → Diterbitkan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Issue (stock) → Issued | Keluarkan → Dikeluarkan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Publish → Published | Terbitkan → Diterbitkan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Close → Closed | Tutup → Ditutup | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Assign → Assigned | Tugaskan → Ditugaskan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Schedule → Scheduled | Jadwalkan → Terjadwal | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Verify → Verified | Verifikasi → Terverifikasi | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Archive → Archived | Arsipkan → Diarsipkan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Suspend → Suspended | Tangguhkan → Ditangguhkan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Terminate → Terminated | Akhiri → Diakhiri | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Activate / Deactivate → Active / Inactive | Aktifkan / Nonaktifkan → Aktif / Nonaktif | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Transfer → Transferred | Pindahkan / Transfer → Dipindahkan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Reserve → Reserved | Pesan → Dipesan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Inspect → Inspected | Inspeksi → Diinspeksi | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Resolve → Resolved | Selesaikan → Terselesaikan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Return → Returned | Kembalikan / Retur → Dikembalikan / Diretur | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Settle → Settled | Selesaikan → Diselesaikan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Pay → Paid | Bayar → Lunas | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Request → Requested | Minta / Ajukan → Diminta | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Prepare → Prepared | Siapkan → Disiapkan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Finalize → Finalized | Finalisasi → Difinalisasi | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Void → Voided | Batalkan (Void) → Dibatalkan (Void) | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Hold → On Hold | Tunda → Ditunda | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status |
| Scrap → Scrapped | Scrap → Scrap | Action → resulting status | GLOBAL | REVIEW | Verb (imperative) vs passive/adjective status; glossary.scrap |
| Retread → Retread | Vulkanisir → Vulkanisir | Action → resulting status | GLOBAL | REVIEW | Verb (imperative) vs passive/adjective status; glossary.retread |

## Document Terminology

| English Term | Indonesian | Context | Module/Domain | Classification | Reason |
|---|---|---|---|---|---|
| Work Order | Work Order (rec.) | Document | workOrder | REVIEW | glossary.workOrder |
| Work Authorization Letter (WAL) | Surat Otorisasi Kerja (rec.) | Document | workOrder | REVIEW | glossary.workAuthorizationLetter |
| Maintenance Memo / Maintenance Report | Memo Perawatan / Laporan Perawatan (rec.) | Document | maintenance | REVIEW | glossary.maintenance |
| Inspection Report | Laporan Inspeksi | Document | inspection | AUTO_TRANSLATE_SAFE | Standard |
| Purchase Request / Purchase Order | Permintaan Pembelian / Purchase Order (rec.) | Document | procurement | REVIEW | glossary.purchaseRequest / purchaseOrder |
| Request for Quotation | Permintaan Penawaran Harga (rec.) | Document | procurement | REVIEW | glossary.quotation |
| Return Order | Return Order (rec., document name) | Document | procurement | REVIEW | glossary.returnToVendor |
| Goods Receipt | Penerimaan Barang (rec.) | Document | inventory | REVIEW | glossary.goodsReceipt |
| Stock Transfer / Vehicle Transfer | Transfer Stok / Transfer Kendaraan | Document | inventory / vehicle | AUTO_TRANSLATE_SAFE | Standard |
| Warranty Claim | Klaim Garansi | Document | warranty | AUTO_TRANSLATE_SAFE | Standard |
| Invoice | Invoice (rec.) | Document | billing | REVIEW | glossary.invoice |
| Bill To / Due Date / Issue Date | Ditagihkan Kepada / Jatuh Tempo / Tanggal Terbit | Print label | documents | AUTO_TRANSLATE_SAFE | Standard |
| Prepared by / Approved by / Received By / Signature / Position | Disiapkan oleh / Disetujui oleh / Diterima Oleh / Tanda Tangan / Jabatan | Signature block | documents | STRUCTURAL_PREP_REQUIRED | Print templates are DB-stored (DATABASE_LOCALIZATION) |
| Workshop Stamp | Stempel Bengkel (rec.) | Signature block | documents | REVIEW | glossary.workshop |
| Attn: / Dear | Up.: / Kepada Yth. | Letter | documents | STRUCTURAL_PREP_REQUIRED | Indonesian letter conventions |

## Inventory / Procurement Terminology

| English Term | Indonesian | Context | Module/Domain | Classification | Reason |
|---|---|---|---|---|---|
| Inventory | Inventori (rec.) | Module | inventory | REVIEW | glossary.inventory |
| Stock Opname | Stock Opname (rec.) | Count | inventory | REVIEW | glossary.stockOpname |
| Stock Movement / Ledger / Running Balance | Pergerakan Stok / Buku Besar / Saldo Berjalan | Inventory | inventory | AUTO_TRANSLATE_SAFE | Standard |
| Issuance & Return | Pengeluaran & Pengembalian | WO parts | workOrder | AUTO_TRANSLATE_SAFE | Issue = stock leaves warehouse |
| Consumable | Bahan Habis Pakai | Item type | inventory | AUTO_TRANSLATE_SAFE | Standard |
| Spare Part | Suku Cadang (rec.) | Item type | inventory | REVIEW | glossary.sparePart |
| Quarantine | Karantina | Disposition | inventory | AUTO_TRANSLATE_SAFE | Standard |
| Reuse / Reusable | Pakai Ulang (rec.) | Disposition | inventory | REVIEW | glossary.reuse |
| Scrap | Scrap (rec.) | Disposition | inventory | REVIEW | glossary.scrap |
| Vendor / Supplier / Partner | Vendor / Pemasok / Mitra (rec.) | Procurement | procurement | REVIEW | glossary.vendor / supplier / partner |
| Quotation | Penawaran Harga (rec.) | Procurement | procurement | REVIEW | glossary.quotation |
| Refund / Redelivery | Refund / Pengiriman Ulang | Return to vendor | procurement | AUTO_TRANSLATE_SAFE | "Refund" kept as business loanword |
| Terms of Payment | Termin Pembayaran | Procurement | procurement | AUTO_TRANSLATE_SAFE | Standard |
| Freight | Ongkos Kirim | PO | procurement | AUTO_TRANSLATE_SAFE | Standard |
| Outstanding | Belum Lunas | Payment | procurement | AUTO_TRANSLATE_SAFE | Standard |

## Maintenance Terminology

| English Term | Indonesian | Context | Module/Domain | Classification | Reason |
|---|---|---|---|---|---|
| Maintenance | Perawatan (rec.) | Module | maintenance | REVIEW | glossary.maintenance |
| Maintenance Request | Permintaan Perawatan (rec.) | Document | maintenance | REVIEW | glossary.maintenance |
| Maintenance Package / Schedule | Paket Perawatan / Jadwal Perawatan (rec.) | Planning | maintenance | REVIEW | glossary.maintenance |
| Preventive / Corrective | Preventif / Korektif | Maintenance type | maintenance | AUTO_TRANSLATE_SAFE | Standard |
| Breakdown | Breakdown (rec.) | Incident | maintenance | REVIEW | glossary.breakdown |
| Complaint / Diagnosis / Finding | Keluhan / Diagnosis / Temuan (rec.) | Work order | workOrder | REVIEW | Finding = glossary.finding |
| Root Cause / Corrective Action | Akar Masalah / Tindakan Korektif | Work order | workOrder | AUTO_TRANSLATE_SAFE | Standard |
| Road Test / Quality Control | Uji Jalan / Quality Control (rec.) | QC | workOrder | REVIEW | glossary.roadTest / qualityControl |
| Rework | Pengerjaan Ulang | QC | workOrder | AUTO_TRANSLATE_SAFE | Standard |
| Odometer / Engine Hour | Odometer / Jam Mesin (rec.) | Meter | vehicle | REVIEW | glossary.odometer / engineHour |
| Interval / Tolerance / Due / Overdue | Interval / Toleransi / Jatuh Tempo / Terlambat | Schedule | maintenance | AUTO_TRANSLATE_SAFE | Standard |

## Tire Terminology

| English Term | Indonesian | Context | Module/Domain | Classification | Reason |
|---|---|---|---|---|---|
| Tire | Ban | Domain | tire | AUTO_TRANSLATE_SAFE | Documented correction (owner may veto) |
| Tire Operation (Installation / Rotation / Inspection / Removal / Replacement) | Operasi Ban (Pemasangan / Rotasi / Inspeksi / Pelepasan / Penggantian) | Operations | tire | AUTO_TRANSLATE_SAFE | Standard |
| Wheels Configuration / Position / Axle | Konfigurasi Roda / Posisi / Sumbu (rec.) | Layout | tire | REVIEW | Axle = glossary.axle |
| Spare Tire | Ban Cadangan | Layout | tire | AUTO_TRANSLATE_SAFE | Standard |
| Rim | Velg (rec.) | Item | tire | REVIEW | glossary.rim |
| Retread / Repair (casing) | Vulkanisir (rec.) / Perbaikan | Lifecycle | tire | REVIEW | glossary.retread |
| Casing / Tread / Bead / Sidewall / Inner Liner / Shoulder | Casing / Tapak / Bead / Dinding Samping / Lapisan Dalam (rec.) / Bahu Ban | Anatomy | tire | REVIEW | glossary.casing / tread / bead / sidewall / innerLiner |
| Tread Depth / Wear Pattern | Kedalaman Tapak / Pola Keausan (rec.) | Inspection | tire | REVIEW | glossary.tread |
| Run-flat / Puncture / Cut / Crack / Separation / Bulge | Kempis berjalan / Tusukan / Sayatan / Retak / Pemisahan / Benjolan | Damage | tire | AUTO_TRANSLATE_SAFE | Standard |
| Manufacture Date Code (DOT) | Kode Tanggal Produksi (DOT) | Identity | tire | AUTO_TRANSLATE_SAFE | DOT kept |
| Used Tire Management / Used Stock | Manajemen Ban Bekas / Stok Bekas | Module | tire | AUTO_TRANSLATE_SAFE | Standard |
| New Stock / Installed / Used | Stok Baru / Terpasang / Bekas | Stock state | tire | AUTO_TRANSLATE_SAFE | Standard |

## Workshop Terminology

| English Term | Indonesian | Context | Module/Domain | Classification | Reason |
|---|---|---|---|---|---|
| Workshop | Bengkel (rec.) | Organization | workshop | REVIEW | glossary.workshop |
| External Workshop | Bengkel Eksternal (rec.) | Partner type | workshop | REVIEW | glossary.workshop |
| Workspace / Service Bay | Area Kerja (rec.) / Bay Servis | Bay | workshop | REVIEW | glossary.workspace |
| Mechanic / Worker | Mekanik (rec.) | People | workshop | REVIEW | glossary.mechanic |
| Assignment / Reservation | Penugasan / Reservasi | Scheduling | workshop | AUTO_TRANSLATE_SAFE | Standard |
| Scheduler / Workload | Penjadwal / Beban Kerja | Pages | workshop | AUTO_TRANSLATE_SAFE | Standard |
| Labor log / Labor time / Hourly Rate | Log kerja / Waktu kerja / Tarif per Jam | Labor | workshop | AUTO_TRANSLATE_SAFE | Standard |
| Service Invoice / Workshop Invoice | Invoice Servis (rec.) | Billing | workshop | REVIEW | glossary.serviceInvoice / invoice |

