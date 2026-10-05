# 07 — Translation Decision List

> **Finalization task (latest):** the decision request listed every term as `[ISI KEPUTUSAN]` (placeholder) — **no terminology or style decision was provided**. Per the instruction *"Jangan mengarang keputusan"*, nothing was decided on the owner's behalf: all decisions remain **AWAITING_DECISION**. The propagation mechanism is ready: once decisions are supplied, every dependent entry (`depends_on`) is finalized by substitution in `proposed_text_id` without re-translation.

## Resolved Decisions

| English Term | Final Indonesian / Keep English | Decision Source | Affected Entries |
|---|---|---|---:|
| — | — | No decision provided (placeholders only) | 0 |

## Remaining Decisions

Dependent dataset entries still waiting: **1162** (in `12-en-id-translation-dataset-final.csv`).

### Terminology (43)

| No | English Term | Context | Module/Domain | Recommended Option | Alternative | Reason | Severity | Occurrence | Affected Entries | Status |
|---:|---|---|---|---|---|---|---|---:|---:|---|
| 1 | **Work Order** (`glossary.workOrder`) | Document / module name | workOrder, backend:workOrder, backend:workshop, externalWorkOrderInvoice | Work Order | Perintah Kerja | Core document across maintenance, workshop, inventory and procurement; "WO" abbreviation is kept everywhere, and Indonesian workshops widely say "Work Order". "Perintah Kerja" collides with "Surat Perintah Kerja (SPK)". | CRITICAL | 163 | 135 | AWAITING_DECISION |
| 2 | **Maintenance** (`glossary.maintenance`) | Module / domain | maintenance, backend:analytics, navigation, backend:workOrder | Perawatan | Pemeliharaan | Module, menu, document and KPI name. "Perawatan" is the common fleet/workshop term; "Pemeliharaan" is more formal/asset-management. | CRITICAL | 126 | 109 | AWAITING_DECISION |
| 3 | **Spare Part / Part** (`glossary.sparePart`) | Inventory / item type | inventory, backend:workOrder, workOrder, navigation | Suku Cadang | Sparepart | Item type, menu ("Used Spareparts"), sale and request flows; English has two spellings (see 03). | CRITICAL | 114 | 110 | AWAITING_DECISION |
| 4 | **Workshop** (`glossary.workshop`) | Organization / workshop operations | backend:configurationDefaults, externalWorkOrderInvoice, navigation, organization | Bengkel | Workshop | Organization unit, menu group, external partner type and invoice name. | CRITICAL | 87 | 71 | AWAITING_DECISION |
| 5 | **Invoice** (`glossary.invoice`) | Billing / procurement | backend:workOrder, procurement, externalWorkOrderInvoice, navigation | Invoice | Faktur | Platform billing, vendor invoice references, workshop invoices; "Faktur" may be confused with Faktur Pajak, "Tagihan" with billing. | HIGH | 144 | 112 | AWAITING_DECISION |
| 6 | **Tenant** (`glossary.tenant`) | Platform / SaaS | platform.tenants, backend:configuration, backend:http, platform.contracts | Tenant | Perusahaan Pelanggan | SaaS account concept used in the platform portal and system-default wording; no natural Indonesian equivalent. | HIGH | 104 | 56 | AWAITING_DECISION |
| 7 | **Vendor** (`glossary.vendor`) | Procurement | procurement, backend:procurement, partner, navigation | Vendor | Pemasok | Procurement role of a Partner; overlaps with Supplier (03). | HIGH | 95 | 75 | AWAITING_DECISION |
| 8 | **Workspace** (`glossary.workspace`) | Workshop operations | workOrder, backend:workshop, workshop, navigation | Area Kerja | Bay Kerja | Workshop bay concept used by scheduler, assignment and WO. | HIGH | 63 | 55 | AWAITING_DECISION |
| 9 | **Retread** (`glossary.retread`) | Tire | tire, backend:tire, common, backend:workOrder | Vulkanisir | Retread | Tire lifecycle process (cycle, history, partner); "vulkanisir" is the common Indonesian word, "retread" is used by fleet tire specialists. | HIGH | 46 | 44 | AWAITING_DECISION |
| 10 | **Purchase Order** (`glossary.purchaseOrder`) | Procurement document | backend:procurement, procurement, navigation, configuration | Purchase Order | Pesanan Pembelian | Procurement document; abbreviation PO stays and is common in Indonesian companies. | HIGH | 42 | 36 | AWAITING_DECISION |
| 11 | **Breakdown** (`glossary.breakdown`) | Maintenance | maintenance, backend:analytics, analytics, backend:breakdown | Breakdown | Kerusakan Darurat | Operational incident type and module name. | HIGH | 38 | 35 | AWAITING_DECISION |
| 12 | **Quotation** (`glossary.quotation`) | Procurement | procurement, backend:procurement, navigation, backend:configuration | Penawaran Harga | Quotation | Vendor quotation / RFQ flow; RFQ abbreviation stays. | HIGH | 38 | 35 | AWAITING_DECISION |
| 13 | **Part Request** (`glossary.partRequest`) | Work order / inventory | workOrder, backend:workOrder, backend:tire, navigation | Permintaan Suku Cadang | Part Request | Work-order part request document; depends on glossary.sparePart. | HIGH | 28 | 27 | AWAITING_DECISION |
| 14 | **Partner** (`glossary.partner`) | Partner master | backend:tire, navigation, workOrder, backend:configurationDefaults | Mitra | Rekanan | Master record for vendors, workshops, towing providers. | HIGH | 25 | 17 | AWAITING_DECISION |
| 15 | **Goods Receipt** (`glossary.goodsReceipt`) | Inventory / procurement document | procurement, backend:procurement, navigation, tenantComponents | Penerimaan Barang | Goods Receipt | Inventory document; abbreviation GR stays. | HIGH | 22 | 21 | AWAITING_DECISION |
| 16 | **Purchase Request** (`glossary.purchaseRequest`) | Procurement document | procurement, navigation, backend:procurement, dashboard | Permintaan Pembelian | Purchase Request | Procurement document; abbreviation PR stays. | HIGH | 13 | 12 | AWAITING_DECISION |
| 17 | **Work Authorization Letter** (`glossary.workAuthorizationLetter`) | Document | externalWorkOrderInvoice, backend:configurationDefaults, workOrder, backend:configuration,workOrder | Surat Otorisasi Kerja | Surat Perintah Kerja (SPK) | External workshop document; "SPK" is the common Indonesian name but is also how many companies call a Work Order. | HIGH | 11 | 9 | AWAITING_DECISION |
| 18 | **Supplier** (`glossary.supplier`) | Partner type | partner, navigation, backend:procurement | Pemasok | Supplier | Partner type (Spare Part / Tire Supplier); must not collide with Vendor. | HIGH | 4 | 4 | AWAITING_DECISION |
| 19 | **Tread** (`glossary.tread`) | Tire | tire, backend:tire, inventory, backend:analytics | Tapak | Tread | Tread depth / tread condition. | MEDIUM | 47 | 42 | AWAITING_DECISION |
| 20 | **Scrap** (`glossary.scrap`) | Tire / inventory | inventory, backend:workOrder, tire, backend:tire | Scrap | Afkir | Disposal status/action for tires and used parts (SCRAPPED stays the stored code). | MEDIUM | 41 | 38 | AWAITING_DECISION |
| 21 | **Finding** (`glossary.finding`) | Inspection / work order | workOrder, backend:workOrder, inspection, backend:addExternalWorkOrderPrintSection | Temuan | Hasil Pemeriksaan | Inspection / work-order findings. | MEDIUM | 35 | 28 | AWAITING_DECISION |
| 22 | **Mechanic / Worker** (`glossary.mechanic`) | Workshop operations | workshop, workOrder, navigation, backend:workshop | Mekanik | Teknisi | Menu says Mechanic, API says worker (03). | MEDIUM | 34 | 33 | AWAITING_DECISION |
| 23 | **Reuse** (`glossary.reuse`) | Tire / inventory | inventory, tire, backend:tire, backend:workOrder | Pakai Ulang | Reuse | Used-tire / used-part disposition. | MEDIUM | 30 | 28 | AWAITING_DECISION |
| 24 | **Intelligence** (`glossary.intelligence`) | Intelligence | intelligence, backend:notificationDefaults, navigation, backend:module | Intelligence | Analitik Prediktif | Feature/module brand (Maintenance Intelligence). | MEDIUM | 25 | 20 | AWAITING_DECISION |
| 25 | **Inventory** (`glossary.inventory`) | Inventory | inventory, backend:analytics, analytics, dashboard | Inventori | Persediaan | Module/menu name; "Persediaan" is the accounting term. | MEDIUM | 20 | 19 | AWAITING_DECISION |
| 26 | **Return Order / Return to Vendor** (`glossary.returnToVendor`) | Procurement | procurement, backend:procurement, backend:configurationDefaults, tenantComponents | Retur ke Vendor (flow); document name "Return Order" kept | Retur Pembelian | Three English names for one flow (03); depends on glossary.vendor. | MEDIUM | 19 | 18 | AWAITING_DECISION |
| 27 | **Casing** (`glossary.casing`) | Tire | backend:tire, tire | Casing | Karkas | Tire casing eligibility for retread. | MEDIUM | 18 | 18 | AWAITING_DECISION |
| 28 | **Bundle** (`glossary.bundle`) | Platform commercial | platform.bundles, backend:productCatalog, navigation, backend:contract | Paket | Bundle | Commercial bundle of modules (platform). | MEDIUM | 18 | 18 | AWAITING_DECISION |
| 29 | **Service Invoice** (`glossary.serviceInvoice`) | Workshop invoice | backend:workOrder, workOrder, navigation, workshopInvoice | Invoice Servis | Invoice Bengkel | English canonical is itself undecided (Workshop Invoice vs Service Invoice, 03). | MEDIUM | 12 | 11 | AWAITING_DECISION |
| 30 | **Stock Opname** (`glossary.stockOpname`) | Inventory | inventory, backend:inventory, navigation | Stock Opname | Penghitungan Stok | Already the Indonesian business term used in the English UI. | MEDIUM | 12 | 12 | AWAITING_DECISION |
| 31 | **Load Index / Speed Rating / Ply Rating** (`glossary.tireSpec`) | Tire product | inventory, backend:productMaster, tire, backend:tire | keep English (Load Index / Speed Rating / Ply Rating) | Indeks Beban / Indeks Kecepatan / Peringkat Lapisan | Tire industry standard specification names. | MEDIUM | 10 | 8 | AWAITING_DECISION |
| 32 | **Bead** (`glossary.bead`) | Tire | tire, backend:tire | Bead | Tumit Ban | Tire inspection location. | MEDIUM | 8 | 7 | AWAITING_DECISION |
| 33 | **Inner Liner** (`glossary.innerLiner`) | Tire | tire, backend:tire | Lapisan Dalam | Inner Liner | Tire inspection location. | MEDIUM | 7 | 7 | AWAITING_DECISION |
| 34 | **Sidewall** (`glossary.sidewall`) | Tire | tire, backend:tire | Dinding Samping | Sidewall | Tire inspection location. | MEDIUM | 5 | 4 | AWAITING_DECISION |
| 35 | **Engine Hour** (`glossary.engineHour`) | Vehicle / maintenance | maintenance | Jam Mesin | Hour Meter (HM) | Usage meter for heavy equipment; "HM" is common in mining fleets. | MEDIUM | 5 | 3 | AWAITING_DECISION |
| 36 | **Entitlement** (`glossary.entitlement`) | Platform commercial | platform.subscriptions, platform.tenants | Hak Akses Modul | Entitlement | Module entitlement per tenant/contract. | MEDIUM | 2 | 2 | AWAITING_DECISION |
| 37 | **Data Scope** (`glossary.dataScope`) | Access management | backend:componentAsset, backend:sparePartSale, backend:workspaceReservation, access | Cakupan Data | Data Scope | Access-control concept. | LOW | 71 | 37 | AWAITING_DECISION |
| 38 | **Axle** (`glossary.axle`) | Tire | tire, backend:tire, common, backend:masterData | Sumbu | As Roda | Wheel configuration axles. | LOW | 38 | 28 | AWAITING_DECISION |
| 39 | **Rim** (`glossary.rim`) | Tire / inventory | tire, inventory, navigation, masterData | Velg | Pelek | Wheel rim item type. | LOW | 19 | 17 | AWAITING_DECISION |
| 40 | **Odometer** (`glossary.odometer`) | Vehicle | maintenance, tire, workOrder, vehicle | Odometer | Penunjuk Kilometer | Usage meter label; alternates with "KM" (03). | LOW | 18 | 14 | AWAITING_DECISION |
| 41 | **Road Test** (`glossary.roadTest`) | Work order | workOrder, backend:qualityControl | Uji Jalan | Road Test | Work-order QC step. | LOW | 7 | 5 | AWAITING_DECISION |
| 42 | **Dashboard** (`glossary.dashboard`) | Global | navigation, dashboard, platform.dashboard | Dasbor | Dashboard | Page/menu name; KBBI form is "dasbor", many Indonesian SaaS keep "Dashboard". | LOW | 5 | 4 | AWAITING_DECISION |
| 43 | **Quality Control** (`glossary.qualityControl`) | Work order | workOrder | Quality Control (QC) | Kontrol Kualitas | QC step / module; QC abbreviation stays. | LOW | 1 | 1 | AWAITING_DECISION |

### Style (6)

| No | Decision | Recommended Option | Alternative | Current dataset handling | Status |
|---:|---|---|---|---|---|
| 1 | Create / Add / New | Create → Buat, Add → Tambah, New → Baru | Normalize English first | Each English verb translated literally (no forced merge) | AWAITING_DECISION |
| 2 | Delete / Remove | Delete → Hapus; Remove → Hapus (files/lines) / Lepas (vehicle) | Remove → Keluarkan | Context-based | AWAITING_DECISION |
| 3 | Cancel — close dialog | Batal | Tutup | Batal on dismiss buttons | AWAITING_DECISION |
| 4 | Cancel — cancel transaction/record | Batalkan … | Batal … | Batalkan on record actions | AWAITING_DECISION |
| 5 | Approve / Accept | Approve → Setujui; Accept → Terima | Accept → Setujui | Literal | AWAITING_DECISION |
| 6 | Sign In / Login | Masuk | Login | Masuk / Gagal masuk | AWAITING_DECISION |

Style decisions change English canonical wording; no dataset row is blocked by them (Indonesian already follows context).

### Correction proposals that change terminology authority (REVIEW_REQUIRED)

| Item | Proposal | Affected Entries | Status |
|---|---|---:|---|
| `correction.tire` — Tire / Tires (audit REVIEW) | Ban | 7 | AWAITING_DECISION (proposal not applied) |
| `correction.warrantyClaim` — Warranty Claim(s) (audit REVIEW) | Klaim Garansi | 4 | AWAITING_DECISION (proposal not applied) |
| `correction.wheelsConfiguration` — Wheels Configuration (audit REVIEW) | Konfigurasi Roda | 2 | AWAITING_DECISION (proposal not applied) |
| `correction.hold` — Hold (audit REVIEW) | Tahan | 1 | AWAITING_DECISION (proposal not applied) |
| Workflow verb action labels (`workflow.actionVerb.*`) | English verb wording per action_code (e.g. Approve, Send to QC) | 33 | REVIEW_REQUIRED (English wording) |
| Key split `common.actions.cancel` | dismiss vs record cancel keys | 1 | REVIEW_REQUIRED (implementation) |

## Decision input format

Reply with one line per term (keep English = repeat the term):

```
Work Order = Work Order
Maintenance = Perawatan
Workshop = Bengkel
…
Cancel — close dialog = Batal
```

