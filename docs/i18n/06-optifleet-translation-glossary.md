# 06 — OptiFleet Translation Glossary

> **Update (finalization task):** an *Authority / Status* column was added. Status values: APPROVED_BY_PRODUCT_OWNER (owner decision given — **none yet**: the decision request contained only placeholders), APPROVED_AUTOMATIC, DO_NOT_TRANSLATE, STRUCTURAL_PREP_REQUIRED, AWAITING_DECISION. Final glossary: `13-optifleet-translation-glossary-final.md`.

> **Update (owner decisions applied):** the product owner supplied all 43 terminology and 6 style decisions. This file is kept as the preparation-stage glossary: its Indonesian column still shows the *preparation recommendations*. Rows that were AWAITING_DECISION are now marked **SUPERSEDED_BY_OWNER_DECISION** — the authoritative final Indonesian is in `13-optifleet-translation-glossary-final.md` (APPROVED_BY_PRODUCT_OWNER) and the resolved decision list in `07-translation-decision-list.md`. The only row still AWAITING_DECISION is the Wheels Configuration part (correction item not in the owner list).

English → Indonesian terminology used to build `08-en-id-translation-dataset.csv`. **AUTO_TRANSLATE_SAFE** terms are applied in the dataset. **REVIEW** terms show the recommended option only; they are not final (see 07). Canonical codes, identifiers and template variables never change.

Guiding rules: verbs in imperative for actions (Simpan, Setujui), passive/adjective for statuses (Disimpan, Disetujui); short sentence-case messages; no word-by-word translation — the key, category, module and context decide (e.g. *Issue* = Terbitkan for documents, Keluarkan for stock, Masalah for problems).

## Approved Automatic Terms

| English Term | Indonesian | Context | Module/Domain | Classification | Reason | Authority / Status |
|---|---|---|---|---|---|---|
| Save | Simpan | Button | GLOBAL | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Cancel (dialog dismiss) | Batal | Modal / form secondary button | GLOBAL | AUTO_TRANSLATE_SAFE | Dismiss = no action; "Batalkan" is reserved for cancelling a record | APPROVED_AUTOMATIC |
| Cancel <document> | Batalkan <dokumen> | Row / document action | GLOBAL | AUTO_TRANSLATE_SAFE | Action on a record (e.g. Batalkan Permintaan) | APPROVED_AUTOMATIC |
| Edit | Ubah | Button | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline | APPROVED_AUTOMATIC |
| Update | Perbarui | Button | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline | APPROVED_AUTOMATIC |
| Delete | Hapus | Button | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline | APPROVED_AUTOMATIC |
| Remove | Hapus / Lepas | Remove a file/line → Hapus; remove tire/component from vehicle → Lepas | GLOBAL | AUTO_TRANSLATE_SAFE | Context decides; see style.deleteRemove | APPROVED_AUTOMATIC |
| Create | Buat | Button | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline | APPROVED_AUTOMATIC |
| Add | Tambah | Button | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline | APPROVED_AUTOMATIC |
| New (adjective) | Baru | "+ New X" → "+ X Baru" | GLOBAL | AUTO_TRANSLATE_SAFE | Indonesian word order | APPROVED_AUTOMATIC |
| Submit (for approval/review) | Ajukan | Workflow submit | GLOBAL | AUTO_TRANSLATE_SAFE | Deviation from candidate "Kirim": "Dikirim" is already used for Dispatched/Sent (stock & PO); "Ajukan/Diajukan" avoids the collision | APPROVED_AUTOMATIC |
| Submit (payment / inspection data) | Kirim | Non-approval submit | GLOBAL | AUTO_TRANSLATE_SAFE | Sending data, not requesting approval | APPROVED_AUTOMATIC |
| Approve | Setujui | Action | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline | APPROVED_AUTOMATIC |
| Reject | Tolak | Action | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline | APPROVED_AUTOMATIC |
| Close | Tutup | Action | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline | APPROVED_AUTOMATIC |
| Print | Cetak | Action | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline | APPROVED_AUTOMATIC |
| Download | Unduh | Action | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline | APPROVED_AUTOMATIC |
| Upload | Unggah | Action | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline | APPROVED_AUTOMATIC |
| Search | Cari | Search box | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline | APPROVED_AUTOMATIC |
| Back | Kembali | Navigation | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline | APPROVED_AUTOMATIC |
| Next / Previous | Berikutnya / Sebelumnya | Pagination | GLOBAL | AUTO_TRANSLATE_SAFE | Guideline | APPROVED_AUTOMATIC |
| View | Lihat | Action | GLOBAL | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Select / Select… | Pilih / Pilih… | Action / placeholder option | GLOBAL | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Confirm | Konfirmasi | Button | GLOBAL | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Publish | Terbitkan | Configuration publish | GLOBAL | AUTO_TRANSLATE_SAFE | Pairs with status Diterbitkan | APPROVED_AUTOMATIC |
| Issue (document) | Terbitkan | Issue Invoice / RFQ | MODULE | AUTO_TRANSLATE_SAFE | Document issuance | APPROVED_AUTOMATIC |
| Issue (stock) | Keluarkan / Pengeluaran | Inventory issue | MODULE | AUTO_TRANSLATE_SAFE | Stock leaving the warehouse | APPROVED_AUTOMATIC |
| Receive | Terima | Goods / cycle receive | MODULE | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Accept | Terima | Recommendation / vendor acceptance | MODULE | AUTO_TRANSLATE_SAFE | Same Indonesian as Receive — context differs (see 11) | APPROVED_AUTOMATIC |
| Assign / Reassign / Unassign | Tugaskan / Tugaskan Ulang / Batalkan Penugasan | Workforce | MODULE | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Schedule | Jadwalkan | Action | GLOBAL | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Start / Pause / Resume / Finish | Mulai / Jeda / Lanjutkan / Selesai | Labor timer | MODULE | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Complete | Selesaikan | Action | GLOBAL | AUTO_TRANSLATE_SAFE | Pairs with status Selesai | APPROVED_AUTOMATIC |
| Import / Export | Impor / Ekspor | Action | GLOBAL | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Void | Batalkan (Void) | Invoice void | MODULE | AUTO_TRANSLATE_SAFE | Accounting meaning kept with "Void" in brackets | APPROVED_AUTOMATIC |
| Loading… / Saving… / Creating… | Memuat… / Menyimpan… / Membuat… | Busy state | GLOBAL | AUTO_TRANSLATE_SAFE | Progressive form | APPROVED_AUTOMATIC |
| Yes / No | Ya / Tidak | Boolean | GLOBAL | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| All / Any | Semua / Apa saja | Filter option | GLOBAL | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| (optional) / (required) | (opsional) / (wajib) | Field suffix | GLOBAL | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| No records found. / No X found. | Tidak ada data. / Tidak ada X. | Empty state | GLOBAL | AUTO_TRANSLATE_SAFE | Short form, no "ditemukan" | APPROVED_AUTOMATIC |
| … is required. | … wajib diisi. | Validation | BACKEND | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| … must be greater than zero | … harus lebih dari nol | Validation | BACKEND | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Are you sure …? | Apakah Anda yakin …? | Confirmation | GLOBAL | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| This cannot be undone. | Tindakan ini tidak dapat dibatalkan. | Warning | GLOBAL | AUTO_TRANSLATE_SAFE | Risk meaning preserved | APPROVED_AUTOMATIC |
| X saved / deleted / removed. | X disimpan / dihapus. | Success | GLOBAL | AUTO_TRANSLATE_SAFE | Short past form | APPROVED_AUTOMATIC |
| … is outside your assigned data scope. | … berada di luar cakupan data Anda. | Error | BACKEND | REVIEW | depends_on glossary.dataScope | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Name / Code / Status / Type / Category / Description | Nama / Kode / Status / Jenis / Kategori / Deskripsi | Field | GLOBAL | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Notes / Reason / Date / Priority | Catatan / Alasan / Tanggal / Prioritas | Field | GLOBAL | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Quantity / Qty | Jumlah / Qty | Field | GLOBAL | AUTO_TRANSLATE_SAFE | Qty kept in narrow columns | APPROVED_AUTOMATIC |
| Amount (money) | Nominal | Field | GLOBAL | AUTO_TRANSLATE_SAFE | "Jumlah" is reserved for quantities | APPROVED_AUTOMATIC |
| Unit Price / Discount / Tax / Total / Subtotal | Harga Satuan / Diskon / Pajak / Total / Subtotal | Money | GLOBAL | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Vehicle | Kendaraan | Domain | GLOBAL | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Fleet | Armada | Domain | GLOBAL | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Branch | Cabang | Organization | GLOBAL | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Warehouse | Gudang | Inventory | GLOBAL | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Product | Produk | Inventory | GLOBAL | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Stock | Stok | Inventory | GLOBAL | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Tire / Tyre | Ban | Tire | DOMAIN | AUTO_TRANSLATE_SAFE | Audit REVIEW downgraded with documented correction (owner may veto, see 11) | APPROVED_AUTOMATIC |
| Inspection | Inspeksi | Domain | GLOBAL | AUTO_TRANSLATE_SAFE | Standard in fleet usage | APPROVED_AUTOMATIC |
| Template | Templat | Configuration | GLOBAL | AUTO_TRANSLATE_SAFE | KBBI | APPROVED_AUTOMATIC |
| Configuration | Konfigurasi | Configuration | GLOBAL | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Workflow | Alur Kerja | Configuration | GLOBAL | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Notification | Notifikasi | Configuration | GLOBAL | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Permission / Role / User | Izin / Peran / Pengguna | Access | GLOBAL | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Contract / Subscription / Billing / Payment / Pricing | Kontrak / Langganan / Penagihan / Pembayaran / Harga | Commercial | MODULE | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Warranty / Warranty Claim | Garansi / Klaim Garansi | Warranty | MODULE | AUTO_TRANSLATE_SAFE | Audit REVIEW downgraded with documented correction | APPROVED_AUTOMATIC |
| History / Audit Log | Riwayat / Log Audit | Global | GLOBAL | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Overview / Summary / Details | Ringkasan / Ringkasan / Detail | Section | GLOBAL | AUTO_TRANSLATE_SAFE | Overview and Summary both "Ringkasan" (see 11) | APPROVED_AUTOMATIC |
| Recommendation / Risk / Health | Rekomendasi / Risiko / Kesehatan | Intelligence | MODULE | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Analytics | Analitik | Module | MODULE | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Month names | Januari … Desember (Jan, Feb, Mar, Apr, Mei, Jun, Jul, Agu, Sep, Okt, Nov, Des) | Dates | GLOBAL | STRUCTURAL_PREP_REQUIRED | Prefer Intl.DateTimeFormat (RUNTIME_LABEL_GENERATION) | STRUCTURAL_PREP_REQUIRED |

## Technical Terms

| English Term | Indonesian | Context | Module/Domain | Classification | Reason | Authority / Status |
|---|---|---|---|---|---|---|
| Downtime | Downtime | KPI | analytics | AUTO_TRANSLATE_SAFE | Common technical loanword | APPROVED_AUTOMATIC |
| Throughput | Throughput | KPI | analytics | AUTO_TRANSLATE_SAFE | Loanword | APPROVED_AUTOMATIC |
| Utilization | Utilisasi | KPI | analytics | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Snapshot | Snapshot | Analytics data | analytics | AUTO_TRANSLATE_SAFE | Loanword | APPROVED_AUTOMATIC |
| ETL / Backfill | ETL / Backfill | Data pipeline | analytics | AUTO_TRANSLATE_SAFE | Technical | APPROVED_AUTOMATIC |
| Machine learning / ML | machine learning / ML | Intelligence | intelligence | AUTO_TRANSLATE_SAFE | Technical | APPROVED_AUTOMATIC |
| Remaining Useful Life (RUL) | Sisa Umur Pakai (RUL) | Intelligence | intelligence | AUTO_TRANSLATE_SAFE | Abbreviation kept | APPROVED_AUTOMATIC |
| Wheel Alignment | Spooring | Tire wear advice | tire | AUTO_TRANSLATE_SAFE | Common workshop word | APPROVED_AUTOMATIC |
| Wheel balance | Balans roda | Tire wear advice | tire | AUTO_TRANSLATE_SAFE | Common workshop word | APPROVED_AUTOMATIC |
| Tubeless / Radial / Bias | Tubeless / Radial / Bias | Tire construction | tire | AUTO_TRANSLATE_SAFE | Industry terms | APPROVED_AUTOMATIC |
| Undercarriage | Undercarriage | Heavy equipment | masterData | AUTO_TRANSLATE_SAFE | Industry term | APPROVED_AUTOMATIC |
| Torque Converter | Torque Converter | Component group | masterData | AUTO_TRANSLATE_SAFE | Industry term | APPROVED_AUTOMATIC |
| Bin / Rack / Zone | Bin / Rak / Zona | Storage layout | inventory | AUTO_TRANSLATE_SAFE | "Bin" kept | APPROVED_AUTOMATIC |
| Asset# | Asset# | Component asset id label | inventory | AUTO_TRANSLATE_SAFE | Identifier label | APPROVED_AUTOMATIC |
| On Hand | Stok di Tangan | Inventory | inventory | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Reorder Point | Titik Pemesanan Ulang | Inventory | inventory | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Lead Time | Waktu Tunggu (lead time) | Procurement | procurement | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Inspection Certificate | Sertifikat Uji Kelayakan | Vehicle document | vehicle | AUTO_TRANSLATE_SAFE | Indonesian KIR document | APPROVED_AUTOMATIC |
| Tax ID | NPWP | Company profile | account | AUTO_TRANSLATE_SAFE | Indonesian tax id | APPROVED_AUTOMATIC |
| Load Index / Speed Rating / Ply Rating | keep English | Tire spec | tire | REVIEW | glossary.tireSpec | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Engine Hour / HM | Jam Mesin / HM | Meter | vehicle | REVIEW | glossary.engineHour | SUPERSEDED_BY_OWNER_DECISION (see 13) |

## Do Not Translate

| English Term | Indonesian | Context | Module/Domain | Classification | Reason | Authority / Status |
|---|---|---|---|---|---|---|
| OptiFleet | OptiFleet | Product name | GLOBAL | DO_NOT_TRANSLATE | Brand | DO_NOT_TRANSLATE |
| VIN | VIN | Vehicle identifier | GLOBAL | DO_NOT_TRANSLATE | Industry abbreviation | DO_NOT_TRANSLATE |
| SKU | SKU | Product code | GLOBAL | DO_NOT_TRANSLATE | Industry abbreviation | DO_NOT_TRANSLATE |
| API / UUID / GPS | API / UUID / GPS | Technical | GLOBAL | DO_NOT_TRANSLATE | Technical | DO_NOT_TRANSLATE |
| PO / GR / RFQ / PR / WO / MR / WAL | PO / GR / RFQ / PR / WO / MR / WAL | Document abbreviations | GLOBAL | DO_NOT_TRANSLATE | Business abbreviation; full names are REVIEW terms | DO_NOT_TRANSLATE |
| QC / KPI / UoM / UOM / PIC / SDS / ETL | QC / KPI / UoM / UOM / PIC / SDS / ETL | Abbreviations | GLOBAL | DO_NOT_TRANSLATE | Business abbreviation | DO_NOT_TRANSLATE |
| MTTR / MTBF / RUL | MTTR / MTBF / RUL | KPI | GLOBAL | DO_NOT_TRANSLATE | Industry abbreviation | DO_NOT_TRANSLATE |
| PDF / CSV / XLSX / JPG / JPEG / PNG / WEBP / DOC / DOCX | PDF / CSV / XLSX / JPG / JPEG / PNG / WEBP / DOC / DOCX | File formats | GLOBAL | DO_NOT_TRANSLATE | Format | DO_NOT_TRANSLATE |
| km / mm / kg / cc / L / kW / psi / % / km/h | km / mm / kg / cc / L / kW / psi / % / km/h | Units | GLOBAL | DO_NOT_TRANSLATE | Unit (km/h shown as "km/jam" inside sentences) | DO_NOT_TRANSLATE |
| {TENANT} {BRANCH} {WORKSHOP} {WAREHOUSE} {DOC} {YYYY} {YY} {MM} {MMMM} {DD} {SEQ:n} | {TENANT} {BRANCH} {WORKSHOP} {WAREHOUSE} {DOC} {YYYY} {YY} {MM} {MMMM} {DD} {SEQ:n} | Numbering tokens | GLOBAL | DO_NOT_TRANSLATE | Functional syntax | DO_NOT_TRANSLATE |
| {{work_order.number}} etc. | {{work_order.number}} etc. | Template variables | GLOBAL | DO_NOT_TRANSLATE | Template engine | DO_NOT_TRANSLATE |
| {{param}} / {param} / :attribute | {{param}} / {param} / :attribute | Message parameters | GLOBAL | DO_NOT_TRANSLATE | Runtime substitution | DO_NOT_TRANSLATE |
| D_new / D_min / D_pull / D_service / A_max / A_retread_max / N_retread_max | D_new / D_min / D_pull / D_service / A_max / A_retread_max / N_retread_max | Tire rule symbols | GLOBAL | DO_NOT_TRANSLATE | Formula symbols | DO_NOT_TRANSLATE |
| TRA Code / OTR / DOT | TRA Code / OTR / DOT | Tire standards | GLOBAL | DO_NOT_TRANSLATE | Standard names | DO_NOT_TRANSLATE |
| Canonical codes (UNDER_REVIEW, SELL_ELIGIBLE, REUSE, PENDING_APPROVAL …) inside messages | Canonical codes (UNDER_REVIEW, SELL_ELIGIBLE, REUSE, PENDING_APPROVAL …) inside messages | Codes | GLOBAL | DO_NOT_TRANSLATE | Identifier; only the display label is translated | DO_NOT_TRANSLATE |
| e.g. example values (ACME, BRAKE_PAD, NET 30, SAE 15W-40, Bosch, Dutro) | e.g. example values (ACME, BRAKE_PAD, NET 30, SAE 15W-40, Bosch, Dutro) | Placeholder samples | GLOBAL | DO_NOT_TRANSLATE | Only "e.g." → "mis." is translated | DO_NOT_TRANSLATE |
| HH:mm / YYYY-MM-DD | HH:mm / YYYY-MM-DD | Format masks | GLOBAL | DO_NOT_TRANSLATE | Format | DO_NOT_TRANSLATE |
| Fill Here (XLSX sheet name), import headers | Fill Here (XLSX sheet name), import headers | Import contract | GLOBAL | DO_NOT_TRANSLATE | Changing them breaks import (ERROR_CODE_DECOUPLING) | DO_NOT_TRANSLATE |

## Review Required

| English Term | Indonesian | Context | Module/Domain | Classification | Reason | Authority / Status |
|---|---|---|---|---|---|---|
| Work Order | Rec: Work Order · Alt: Perintah Kerja | Document / module name | — | REVIEW | CRITICAL — Core document across maintenance, workshop, inventory and procurement; "WO" abbreviation is kept everywhere, and Indonesian workshops widely say "Work Order". "Perintah Kerja" collides with "Surat Perintah Kerja (SPK)". | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Maintenance | Rec: Perawatan · Alt: Pemeliharaan | Module / domain | — | REVIEW | CRITICAL — Module, menu, document and KPI name. "Perawatan" is the common fleet/workshop term; "Pemeliharaan" is more formal/asset-management. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Workshop | Rec: Bengkel · Alt: Workshop | Organization / workshop operations | — | REVIEW | CRITICAL — Organization unit, menu group, external partner type and invoice name. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Spare art / Sparepart / art | Rec: Suku Cadang · Alt: Sparepart | Inventory / item type | — | REVIEW | CRITICAL — Item type, menu ("Used Spareparts"), sale and request flows; English has two spellings (see 03). | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Tenant | Rec: Tenant · Alt: Perusahaan Pelanggan | Platform / SaaS | — | REVIEW | HIGH — SaaS account concept used in the platform portal and system-default wording; no natural Indonesian equivalent. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Vendor | Rec: Vendor · Alt: Pemasok | Procurement | — | REVIEW | HIGH — Procurement role of a Partner; overlaps with Supplier (03). | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Supplier | Rec: Pemasok · Alt: Supplier | Partner type | — | REVIEW | HIGH — Partner type (Spare Part / Tire Supplier); must not collide with Vendor. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Partner | Rec: Mitra · Alt: Rekanan | Partner master | — | REVIEW | HIGH — Master record for vendors, workshops, towing providers. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Breakdown | Rec: Breakdown · Alt: Kerusakan Darurat | Maintenance | — | REVIEW | HIGH — Operational incident type and module name. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Goods Receipt | Rec: Penerimaan Barang · Alt: Goods Receipt | Inventory / procurement document | — | REVIEW | HIGH — Inventory document; abbreviation GR stays. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Purchase Order | Rec: Purchase Order · Alt: Pesanan Pembelian | Procurement document | — | REVIEW | HIGH — Procurement document; abbreviation PO stays and is common in Indonesian companies. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Purchase Request | Rec: Permintaan Pembelian · Alt: Purchase Request | Procurement document | — | REVIEW | HIGH — Procurement document; abbreviation PR stays. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Quotation / Request for Quotation | Rec: Penawaran Harga · Alt: Quotation | Procurement | — | REVIEW | HIGH — Vendor quotation / RFQ flow; RFQ abbreviation stays. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Invoice | Rec: Invoice · Alt: Faktur | Billing / procurement | — | REVIEW | HIGH — Platform billing, vendor invoice references, workshop invoices; "Faktur" may be confused with Faktur Pajak, "Tagihan" with billing. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Service Invoice | Rec: Invoice Servis · Alt: Invoice Bengkel | Workshop invoice | — | REVIEW | MEDIUM — English canonical is itself undecided (Workshop Invoice vs Service Invoice, 03). | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Part Request | Rec: Permintaan Suku Cadang · Alt: Part Request | Work order / inventory | — | REVIEW | HIGH — Work-order part request document; depends on glossary.sparePart. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Workspace | Rec: Area Kerja · Alt: Bay Kerja | Workshop operations | — | REVIEW | HIGH — Workshop bay concept used by scheduler, assignment and WO. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Mechanic / Worker | Rec: Mekanik · Alt: Teknisi | Workshop operations | — | REVIEW | MEDIUM — Menu says Mechanic, API says worker (03). | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Work Authorization Letter | Rec: Surat Otorisasi Kerja · Alt: Surat Perintah Kerja (SPK) | Document | — | REVIEW | HIGH — External workshop document; "SPK" is the common Indonesian name but is also how many companies call a Work Order. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Opname | Rec: Stock Opname · Alt: Penghitungan Stok | Inventory | — | REVIEW | MEDIUM — Already the Indonesian business term used in the English UI. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Inventory | Rec: Inventori · Alt: Persediaan | Inventory | — | REVIEW | MEDIUM — Module/menu name; "Persediaan" is the accounting term. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Retreadw* | Rec: Vulkanisir · Alt: Retread | Tire | — | REVIEW | HIGH — Tire lifecycle process (cycle, history, partner); "vulkanisir" is the common Indonesian word, "retread" is used by fleet tire specialists. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Casing | Rec: Casing · Alt: Karkas | Tire | — | REVIEW | MEDIUM — Tire casing eligibility for retread. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Tread | Rec: Tapak · Alt: Tread | Tire | — | REVIEW | MEDIUM — Tread depth / tread condition. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Bead | Rec: Bead · Alt: Tumit Ban | Tire | — | REVIEW | MEDIUM — Tire inspection location. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Sidewall | Rec: Dinding Samping · Alt: Sidewall | Tire | — | REVIEW | MEDIUM — Tire inspection location. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Inner Liner | Rec: Lapisan Dalam · Alt: Inner Liner | Tire | — | REVIEW | MEDIUM — Tire inspection location. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Scrap(ped / ping) | Rec: Scrap · Alt: Afkir | Tire / inventory | — | REVIEW | MEDIUM — Disposal status/action for tires and used parts (SCRAPPED stays the stored code). | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Reuse / Reusable | Rec: Pakai Ulang · Alt: Reuse | Tire / inventory | — | REVIEW | MEDIUM — Used-tire / used-part disposition. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Finding | Rec: Temuan · Alt: Hasil Pemeriksaan | Inspection / work order | — | REVIEW | MEDIUM — Inspection / work-order findings. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Engine Hour / Hour [Mm]eter | Rec: Jam Mesin · Alt: Hour Meter (HM) | Vehicle / maintenance | — | REVIEW | MEDIUM — Usage meter for heavy equipment; "HM" is common in mining fleets. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Odometer | Rec: Odometer · Alt: Penunjuk Kilometer | Vehicle | — | REVIEW | LOW — Usage meter label; alternates with "KM" (03). | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Bundle | Rec: Paket · Alt: Bundle | Platform commercial | — | REVIEW | MEDIUM — Commercial bundle of modules (platform). | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Entitlement | Rec: Hak Akses Modul · Alt: Entitlement | Platform commercial | — | REVIEW | MEDIUM — Module entitlement per tenant/contract. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Intelligence | Rec: Intelligence · Alt: Analitik Prediktif | Intelligence | — | REVIEW | MEDIUM — Feature/module brand (Maintenance Intelligence). | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Return Order / Return to Vendor / Purchase Return | Rec: Retur ke Vendor (flow); document name "Return Order" kept · Alt: Retur Pembelian | Procurement | — | REVIEW | MEDIUM — Three English names for one flow (03); depends on glossary.vendor. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Road Test | Rec: Uji Jalan · Alt: Road Test | Work order | — | REVIEW | LOW — Work-order QC step. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Quality Control | Rec: Quality Control (QC) · Alt: Kontrol Kualitas | Work order | — | REVIEW | LOW — QC step / module; QC abbreviation stays. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Rim | Rec: Velg · Alt: Pelek | Tire / inventory | — | REVIEW | LOW — Wheel rim item type. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Axle | Rec: Sumbu · Alt: As Roda | Tire | — | REVIEW | LOW — Wheel configuration axles. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Load Index / Speed Rating / Ply Rating | Rec: keep English (Load Index / Speed Rating / Ply Rating) · Alt: Indeks Beban / Indeks Kecepatan / Peringkat Lapisan | Tire product | — | REVIEW | MEDIUM — Tire industry standard specification names. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Data Scope | Rec: Cakupan Data · Alt: Data Scope | Access management | — | REVIEW | LOW — Access-control concept. | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Dashboard | Rec: Dasbor · Alt: Dashboard | Global | — | REVIEW | LOW — Page/menu name; KBBI form is "dasbor", many Indonesian SaaS keep "Dashboard". | SUPERSEDED_BY_OWNER_DECISION (see 13) |

## Status Terminology

| English Term | Indonesian | Context | Module/Domain | Classification | Reason | Authority / Status |
|---|---|---|---|---|---|---|
| ACTIVE (canonical, unchanged) | Aktif | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| APPROVED (canonical, unchanged) | Disetujui | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| ARCHIVED (canonical, unchanged) | Diarsipkan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| ASSESSED (canonical, unchanged) | Dinilai | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| ASSIGNED (canonical, unchanged) | Ditugaskan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| Add EXTERNAL -> CLOSED transition, triggered when the External Invoice becomes Paid (canonical, unchanged) | Tambah transisi EXTERNAL -> CLOSED, dipicu saat Invoice Eksternal menjadi Lunas | Status display label | DOMAIN | REVIEW | Display only — canonical code never changes; needs status registry | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Add EXTERNAL status (top-level, parallel to IN_PROGRESS) for work carried out by an external workshop (canonical, unchanged) | Tambah status EXTERNAL (tingkat atas, sejajar dengan IN_PROGRESS) untuk pekerjaan yang dilakukan bengkel eksternal | Status display label | DOMAIN | REVIEW | Display only — canonical code never changes; needs status registry | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| CANCELLED (canonical, unchanged) | Dibatalkan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| CLOSED (canonical, unchanged) | Ditutup | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| COMPLETED (canonical, unchanged) | Selesai | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| Correct EXTERNAL transitions: Findings-only finalization reachable only from DRAFT, exiting only via Revise/Cancel (canonical, unchanged) | Koreksi transisi EXTERNAL: finalisasi khusus Temuan hanya dapat dicapai dari DRAFT, dan keluar hanya melalui Revisi/Batal | Status display label | DOMAIN | REVIEW | Display only — canonical code never changes; needs status registry | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| DISPATCHED (canonical, unchanged) | Dikirim | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| DRAFT (canonical, unchanged) | Draf | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| DUE_SOON (canonical, unchanged) | Segera Jatuh Tempo | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| EXTERNAL (canonical, unchanged) | Eksternal | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| FINALIZED (canonical, unchanged) | Difinalisasi | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| HOLD (canonical, unchanged) | TAHAN | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| INACTIVE (canonical, unchanged) | Nonaktif | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| INSPECTED (canonical, unchanged) | Diinspeksi | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| IN_PROGRESS (canonical, unchanged) | Dalam Proses | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| IN_TRANSIT (canonical, unchanged) | Dalam Perjalanan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| ISSUED (canonical, unchanged) | Diterbitkan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| Initial platform default (migrated from hardcoded transitions) (canonical, unchanged) | Default awal platform (dimigrasikan dari transisi yang sebelumnya hardcoded) | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| LATE (canonical, unchanged) | Terlambat | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| Maintenance Request Workflow (canonical, unchanged) | Alur Kerja Permintaan Perawatan | Status display label | DOMAIN | REVIEW | Display only — canonical code never changes; needs status registry | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| NEED_INFORMATION (canonical, unchanged) | Perlu Informasi | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| NEW (canonical, unchanged) | Baru | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| ON_HOLD (canonical, unchanged) | Ditunda | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| PAID (canonical, unchanged) | LUNAS | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| PARTIALLY_RECEIVED (canonical, unchanged) | Diterima Sebagian | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| PENDING_APPROVAL (canonical, unchanged) | Menunggu Persetujuan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| PENDING_INSPECTION (canonical, unchanged) | Menunggu Inspeksi | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| PENDING_PROCESSING (canonical, unchanged) | Menunggu Pemrosesan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| PENDING_RETURN (canonical, unchanged) | Menunggu Pengembalian | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| PREPARED (canonical, unchanged) | Disiapkan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| PROCUREMENT (canonical, unchanged) | Pengadaan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| PUBLISHED (canonical, unchanged) | Diterbitkan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| QC_PENDING (canonical, unchanged) | Menunggu QC | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| QUARANTINED (canonical, unchanged) | Dikarantina | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| RECEIVED (canonical, unchanged) | Diterima | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| REDELIVERY_PENDING (canonical, unchanged) | Menunggu Pengiriman Ulang | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| REDELIVERY_READY (canonical, unchanged) | Siap Dikirim Ulang | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| REDELIVERY_RECEIVED (canonical, unchanged) | Pengiriman Ulang Diterima | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| REDELIVERY_REQUESTED (canonical, unchanged) | Pengiriman Ulang Diminta | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| REFUND_ACCEPTED (canonical, unchanged) | Refund Diterima | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| REFUND_REQUESTED (canonical, unchanged) | Refund Diminta | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| REJECTED (canonical, unchanged) | Ditolak | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| REMOVED (canonical, unchanged) | Dilepas | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| REPAIR (canonical, unchanged) | Perbaikan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| REPAIR_REQUIRED (canonical, unchanged) | Perlu Perbaikan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| REPLACEMENT (canonical, unchanged) | Penggantian | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| REPORTED (canonical, unchanged) | Dilaporkan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| REQUESTED (canonical, unchanged) | Diminta | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| RESERVED (canonical, unchanged) | Dipesan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| RESOLVED (canonical, unchanged) | Terselesaikan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| RESTOCKED (canonical, unchanged) | Dikembalikan ke Stok | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| RETREAD (canonical, unchanged) | Vulkanisir | Status display label | DOMAIN | REVIEW | Display only — canonical code never changes; needs status registry | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| REUSE (canonical, unchanged) | Pakai Ulang | Status display label | DOMAIN | REVIEW | Display only — canonical code never changes; needs status registry | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| REWORK (canonical, unchanged) | Pengerjaan Ulang | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| Retire Request Info: remove UNDER_REVIEW -> NEED_INFORMATION (app-level only, enum value kept for legacy records) (canonical, unchanged) | Hentikan Request Info: hapus UNDER_REVIEW -> NEED_INFORMATION (hanya di tingkat aplikasi, nilai enum dipertahankan untuk data lama) | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| SCHEDULED (canonical, unchanged) | Terjadwal | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| SCRAP (canonical, unchanged) | Scrap | Status display label | DOMAIN | REVIEW | Display only — canonical code never changes; needs status registry | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| SCRAPPED (canonical, unchanged) | Scrap | Status display label | DOMAIN | REVIEW | Display only — canonical code never changes; needs status registry | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| SETTLED (canonical, unchanged) | Diselesaikan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| SOLD (canonical, unchanged) | Terjual | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| SUBMITTED (canonical, unchanged) | Diajukan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| SUSPENDED (canonical, unchanged) | Ditangguhkan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| TRANSFERRED (canonical, unchanged) | Dipindahkan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| UNDER_REVIEW (canonical, unchanged) | Dalam Peninjauan | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| VERIFIED (canonical, unchanged) | Terverifikasi | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| WAITING_PART (canonical, unchanged) | Menunggu Suku Cadang | Status display label | DOMAIN | REVIEW | Display only — canonical code never changes; needs status registry | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| WARRANTY_CLAIM (canonical, unchanged) | Klaim Garansi | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| WORK_ORDER_CREATED (canonical, unchanged) | Work Order Dibuat | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |
| Work Order Workflow (canonical, unchanged) | Alur Kerja Work Order | Status display label | DOMAIN | REVIEW | Display only — canonical code never changes; needs status registry | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Workflow (canonical, unchanged) | Alur Kerja | Status display label | DOMAIN | STRUCTURAL_PREP_REQUIRED | Display only — canonical code never changes; needs status registry | STRUCTURAL_PREP_REQUIRED |

## Action Terminology

| English Term | Indonesian | Context | Module/Domain | Classification | Reason | Authority / Status |
|---|---|---|---|---|---|---|
| Approve → Approved | Setujui → Disetujui | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Reject → Rejected | Tolak → Ditolak | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Cancel → Cancelled | Batalkan → Dibatalkan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Complete → Completed | Selesaikan → Selesai | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Submit → Submitted | Ajukan → Diajukan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Dispatch / Send → Dispatched / Sent | Kirim → Dikirim / Terkirim | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Receive → Received | Terima → Diterima | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Issue (document) → Issued | Terbitkan → Diterbitkan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Issue (stock) → Issued | Keluarkan → Dikeluarkan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Publish → Published | Terbitkan → Diterbitkan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Close → Closed | Tutup → Ditutup | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Assign → Assigned | Tugaskan → Ditugaskan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Schedule → Scheduled | Jadwalkan → Terjadwal | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Verify → Verified | Verifikasi → Terverifikasi | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Archive → Archived | Arsipkan → Diarsipkan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Suspend → Suspended | Tangguhkan → Ditangguhkan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Terminate → Terminated | Akhiri → Diakhiri | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Activate / Deactivate → Active / Inactive | Aktifkan / Nonaktifkan → Aktif / Nonaktif | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Transfer → Transferred | Pindahkan / Transfer → Dipindahkan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Reserve → Reserved | Pesan → Dipesan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Inspect → Inspected | Inspeksi → Diinspeksi | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Resolve → Resolved | Selesaikan → Terselesaikan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Return → Returned | Kembalikan / Retur → Dikembalikan / Diretur | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Settle → Settled | Selesaikan → Diselesaikan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Pay → Paid | Bayar → Lunas | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Request → Requested | Minta / Ajukan → Diminta | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Prepare → Prepared | Siapkan → Disiapkan | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Finalize → Finalized | Finalisasi → Difinalisasi | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Void → Voided | Batalkan (Void) → Dibatalkan (Void) | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Hold → On Hold | Tunda → Ditunda | Action → resulting status | GLOBAL | AUTO_TRANSLATE_SAFE | Verb (imperative) vs passive/adjective status | APPROVED_AUTOMATIC |
| Scrap → Scrapped | Scrap → Scrap | Action → resulting status | GLOBAL | REVIEW | Verb (imperative) vs passive/adjective status; glossary.scrap | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Retread → Retread | Vulkanisir → Vulkanisir | Action → resulting status | GLOBAL | REVIEW | Verb (imperative) vs passive/adjective status; glossary.retread | SUPERSEDED_BY_OWNER_DECISION (see 13) |

## Document Terminology

| English Term | Indonesian | Context | Module/Domain | Classification | Reason | Authority / Status |
|---|---|---|---|---|---|---|
| Work Order | Work Order (rec.) | Document | workOrder | REVIEW | glossary.workOrder | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Work Authorization Letter (WAL) | Surat Otorisasi Kerja (rec.) | Document | workOrder | REVIEW | glossary.workAuthorizationLetter | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Maintenance Memo / Maintenance Report | Memo Perawatan / Laporan Perawatan (rec.) | Document | maintenance | REVIEW | glossary.maintenance | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Inspection Report | Laporan Inspeksi | Document | inspection | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Purchase Request / Purchase Order | Permintaan Pembelian / Purchase Order (rec.) | Document | procurement | REVIEW | glossary.purchaseRequest / purchaseOrder | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Request for Quotation | Permintaan Penawaran Harga (rec.) | Document | procurement | REVIEW | glossary.quotation | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Return Order | Return Order (rec., document name) | Document | procurement | REVIEW | glossary.returnToVendor | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Goods Receipt | Penerimaan Barang (rec.) | Document | inventory | REVIEW | glossary.goodsReceipt | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Stock Transfer / Vehicle Transfer | Transfer Stok / Transfer Kendaraan | Document | inventory / vehicle | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Warranty Claim | Klaim Garansi | Document | warranty | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Invoice | Invoice (rec.) | Document | billing | REVIEW | glossary.invoice | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Bill To / Due Date / Issue Date | Ditagihkan Kepada / Jatuh Tempo / Tanggal Terbit | Print label | documents | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Prepared by / Approved by / Received By / Signature / Position | Disiapkan oleh / Disetujui oleh / Diterima Oleh / Tanda Tangan / Jabatan | Signature block | documents | STRUCTURAL_PREP_REQUIRED | Print templates are DB-stored (DATABASE_LOCALIZATION) | STRUCTURAL_PREP_REQUIRED |
| Workshop Stamp | Stempel Bengkel (rec.) | Signature block | documents | REVIEW | glossary.workshop | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Attn: / Dear | Up.: / Kepada Yth. | Letter | documents | STRUCTURAL_PREP_REQUIRED | Indonesian letter conventions | STRUCTURAL_PREP_REQUIRED |

## Inventory / Procurement Terminology

| English Term | Indonesian | Context | Module/Domain | Classification | Reason | Authority / Status |
|---|---|---|---|---|---|---|
| Inventory | Inventori (rec.) | Module | inventory | REVIEW | glossary.inventory | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Stock Opname | Stock Opname (rec.) | Count | inventory | REVIEW | glossary.stockOpname | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Stock Movement / Ledger / Running Balance | Pergerakan Stok / Buku Besar / Saldo Berjalan | Inventory | inventory | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Issuance & Return | Pengeluaran & Pengembalian | WO parts | workOrder | AUTO_TRANSLATE_SAFE | Issue = stock leaves warehouse | APPROVED_AUTOMATIC |
| Consumable | Bahan Habis Pakai | Item type | inventory | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Spare Part | Suku Cadang (rec.) | Item type | inventory | REVIEW | glossary.sparePart | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Quarantine | Karantina | Disposition | inventory | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Reuse / Reusable | Pakai Ulang (rec.) | Disposition | inventory | REVIEW | glossary.reuse | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Scrap | Scrap (rec.) | Disposition | inventory | REVIEW | glossary.scrap | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Vendor / Supplier / Partner | Vendor / Pemasok / Mitra (rec.) | Procurement | procurement | REVIEW | glossary.vendor / supplier / partner | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Quotation | Penawaran Harga (rec.) | Procurement | procurement | REVIEW | glossary.quotation | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Refund / Redelivery | Refund / Pengiriman Ulang | Return to vendor | procurement | AUTO_TRANSLATE_SAFE | "Refund" kept as business loanword | APPROVED_AUTOMATIC |
| Terms of Payment | Termin Pembayaran | Procurement | procurement | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Freight | Ongkos Kirim | PO | procurement | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Outstanding | Belum Lunas | Payment | procurement | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |

## Maintenance Terminology

| English Term | Indonesian | Context | Module/Domain | Classification | Reason | Authority / Status |
|---|---|---|---|---|---|---|
| Maintenance | Perawatan (rec.) | Module | maintenance | REVIEW | glossary.maintenance | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Maintenance Request | Permintaan Perawatan (rec.) | Document | maintenance | REVIEW | glossary.maintenance | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Maintenance Package / Schedule | Paket Perawatan / Jadwal Perawatan (rec.) | Planning | maintenance | REVIEW | glossary.maintenance | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Preventive / Corrective | Preventif / Korektif | Maintenance type | maintenance | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Breakdown | Breakdown (rec.) | Incident | maintenance | REVIEW | glossary.breakdown | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Complaint / Diagnosis / Finding | Keluhan / Diagnosis / Temuan (rec.) | Work order | workOrder | REVIEW | Finding = glossary.finding | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Root Cause / Corrective Action | Akar Masalah / Tindakan Korektif | Work order | workOrder | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Road Test / Quality Control | Uji Jalan / Quality Control (rec.) | QC | workOrder | REVIEW | glossary.roadTest / qualityControl | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Rework | Pengerjaan Ulang | QC | workOrder | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Odometer / Engine Hour | Odometer / Jam Mesin (rec.) | Meter | vehicle | REVIEW | glossary.odometer / engineHour | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Interval / Tolerance / Due / Overdue | Interval / Toleransi / Jatuh Tempo / Terlambat | Schedule | maintenance | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |

## Tire Terminology

| English Term | Indonesian | Context | Module/Domain | Classification | Reason | Authority / Status |
|---|---|---|---|---|---|---|
| Tire | Ban | Domain | tire | AUTO_TRANSLATE_SAFE | Documented correction (owner may veto) | APPROVED_AUTOMATIC |
| Tire Operation (Installation / Rotation / Inspection / Removal / Replacement) | Operasi Ban (Pemasangan / Rotasi / Inspeksi / Pelepasan / Penggantian) | Operations | tire | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Wheels Configuration / Position / Axle | Konfigurasi Roda / Posisi / Sumbu (rec.) | Layout | tire | REVIEW | Axle = glossary.axle | AWAITING_DECISION |
| Spare Tire | Ban Cadangan | Layout | tire | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Rim | Velg (rec.) | Item | tire | REVIEW | glossary.rim | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Retread / Repair (casing) | Vulkanisir (rec.) / Perbaikan | Lifecycle | tire | REVIEW | glossary.retread | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Casing / Tread / Bead / Sidewall / Inner Liner / Shoulder | Casing / Tapak / Bead / Dinding Samping / Lapisan Dalam (rec.) / Bahu Ban | Anatomy | tire | REVIEW | glossary.casing / tread / bead / sidewall / innerLiner | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Tread Depth / Wear Pattern | Kedalaman Tapak / Pola Keausan (rec.) | Inspection | tire | REVIEW | glossary.tread | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Run-flat / Puncture / Cut / Crack / Separation / Bulge | Kempis berjalan / Tusukan / Sayatan / Retak / Pemisahan / Benjolan | Damage | tire | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Manufacture Date Code (DOT) | Kode Tanggal Produksi (DOT) | Identity | tire | AUTO_TRANSLATE_SAFE | DOT kept | APPROVED_AUTOMATIC |
| Used Tire Management / Used Stock | Manajemen Ban Bekas / Stok Bekas | Module | tire | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| New Stock / Installed / Used | Stok Baru / Terpasang / Bekas | Stock state | tire | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |

## Workshop Terminology

| English Term | Indonesian | Context | Module/Domain | Classification | Reason | Authority / Status |
|---|---|---|---|---|---|---|
| Workshop | Bengkel (rec.) | Organization | workshop | REVIEW | glossary.workshop | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| External Workshop | Bengkel Eksternal (rec.) | Partner type | workshop | REVIEW | glossary.workshop | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Workspace / Service Bay | Area Kerja (rec.) / Bay Servis | Bay | workshop | REVIEW | glossary.workspace | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Mechanic / Worker | Mekanik (rec.) | People | workshop | REVIEW | glossary.mechanic | SUPERSEDED_BY_OWNER_DECISION (see 13) |
| Assignment / Reservation | Penugasan / Reservasi | Scheduling | workshop | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Scheduler / Workload | Penjadwal / Beban Kerja | Pages | workshop | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Labor log / Labor time / Hourly Rate | Log kerja / Waktu kerja / Tarif per Jam | Labor | workshop | AUTO_TRANSLATE_SAFE | Standard | APPROVED_AUTOMATIC |
| Service Invoice / Workshop Invoice | Invoice Servis (rec.) | Billing | workshop | REVIEW | glossary.serviceInvoice / invoice | SUPERSEDED_BY_OWNER_DECISION (see 13) |

