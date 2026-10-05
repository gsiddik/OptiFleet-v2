# 07 — Translation Decision List (REVIEW)

All terminology that needs an owner decision, collected in one batch. **No final Indonesian text is set for these terms or for any phrase that contains them** — every dependent entry in `08-en-id-translation-dataset.csv` has `classification = REVIEW`, `decision_status = AWAITING_DECISION`, an empty `translated_text_id`, a `proposed_text_id` written with the **Recommended Option**, and `depends_on = glossary.<term>`. Approving the recommended option turns all dependents into final text in one step; choosing the alternative is a mechanical substitution in `proposed_text_id`.

Parent decisions: **43 terminology** + **5 style** decisions. Dependent dataset entries: **1145**.

## Terminology decisions

| No | English Term | Context | Module/Domain | Recommended Option | Alternative | Reason | Severity | Occurrence | Status |
|---:|---|---|---|---|---|---|---|---:|---|
| 1 | **Work Order** (`glossary.workOrder`) | Document / module name | workOrder, backend:workOrder, backend:workshop, externalWorkOrderInvoice, backend:configurationDefaults | Work Order | Perintah Kerja | Core document across maintenance, workshop, inventory and procurement; "WO" abbreviation is kept everywhere, and Indonesian workshops widely say "Work Order". "Perintah Kerja" collides with "Surat Perintah Kerja (SPK)". Dependent entries: 134. | CRITICAL | 163 | AWAITING_DECISION |
| 2 | **Maintenance** (`glossary.maintenance`) | Module / domain | maintenance, backend:analytics, navigation, backend:workOrder, backend:notification | Perawatan | Pemeliharaan | Module, menu, document and KPI name. "Perawatan" is the common fleet/workshop term; "Pemeliharaan" is more formal/asset-management. Dependent entries: 109. | CRITICAL | 126 | AWAITING_DECISION |
| 3 | **Spare Part / Sparepart / Part** (`glossary.sparePart`) | Inventory / item type | inventory, backend:workOrder, workOrder, navigation, configuration | Suku Cadang | Sparepart | Item type, menu ("Used Spareparts"), sale and request flows; English has two spellings (see 03). Dependent entries: 109. | CRITICAL | 114 | AWAITING_DECISION |
| 4 | **Workshop** (`glossary.workshop`) | Organization / workshop operations | backend:configurationDefaults, externalWorkOrderInvoice, navigation, organization, workshop | Bengkel | Workshop | Organization unit, menu group, external partner type and invoice name. Dependent entries: 70. | CRITICAL | 87 | AWAITING_DECISION |
| 5 | **Invoice** (`glossary.invoice`) | Billing / procurement | backend:workOrder, procurement, externalWorkOrderInvoice, navigation, workOrder | Invoice | Faktur | Platform billing, vendor invoice references, workshop invoices; "Faktur" may be confused with Faktur Pajak, "Tagihan" with billing. Dependent entries: 112. | HIGH | 144 | AWAITING_DECISION |
| 6 | **Tenant** (`glossary.tenant`) | Platform / SaaS | platform.tenants, backend:configuration, backend:http, platform.contracts, platform.dashboard | Tenant | Perusahaan Pelanggan | SaaS account concept used in the platform portal and system-default wording; no natural Indonesian equivalent. Dependent entries: 56. | HIGH | 104 | AWAITING_DECISION |
| 7 | **Vendor** (`glossary.vendor`) | Procurement | procurement, backend:procurement, partner, navigation, backend:configuration | Vendor | Pemasok | Procurement role of a Partner; overlaps with Supplier (03). Dependent entries: 75. | HIGH | 95 | AWAITING_DECISION |
| 8 | **Workspace** (`glossary.workspace`) | Workshop operations | workOrder, backend:workshop, workshop, navigation, dashboard | Area Kerja | Bay Kerja | Workshop bay concept used by scheduler, assignment and WO. Dependent entries: 55. | HIGH | 63 | AWAITING_DECISION |
| 9 | **Retread** (`glossary.retread`) | Tire | tire, backend:tire, common, backend:workOrder | Vulkanisir | Retread | Tire lifecycle process (cycle, history, partner); "vulkanisir" is the common Indonesian word, "retread" is used by fleet tire specialists. Dependent entries: 44. | HIGH | 46 | AWAITING_DECISION |
| 10 | **Purchase Order** (`glossary.purchaseOrder`) | Procurement document | backend:procurement, procurement, navigation, configuration, dashboard | Purchase Order | Pesanan Pembelian | Procurement document; abbreviation PO stays and is common in Indonesian companies. Dependent entries: 36. | HIGH | 42 | AWAITING_DECISION |
| 11 | **Breakdown** (`glossary.breakdown`) | Maintenance | maintenance, backend:analytics, analytics, backend:breakdown, backend:history | Breakdown | Kerusakan Darurat | Operational incident type and module name. Dependent entries: 35. | HIGH | 38 | AWAITING_DECISION |
| 12 | **Quotation / Request for Quotation** (`glossary.quotation`) | Procurement | procurement, backend:procurement, navigation, backend:configuration, backend:vendorQuotation | Penawaran Harga | Quotation | Vendor quotation / RFQ flow; RFQ abbreviation stays. Dependent entries: 35. | HIGH | 38 | AWAITING_DECISION |
| 13 | **Part Request** (`glossary.partRequest`) | Work order / inventory | workOrder, backend:workOrder, backend:tire, navigation, inventory | Permintaan Suku Cadang | Part Request | Work-order part request document; depends on glossary.sparePart. Dependent entries: 27. | HIGH | 28 | AWAITING_DECISION |
| 14 | **Partner** (`glossary.partner`) | Partner master | backend:tire, navigation, workOrder, backend:configurationDefaults, backend:workOrder | Mitra | Rekanan | Master record for vendors, workshops, towing providers. Dependent entries: 17. | HIGH | 25 | AWAITING_DECISION |
| 15 | **Goods Receipt** (`glossary.goodsReceipt`) | Inventory / procurement document | procurement, backend:procurement, navigation, tenantComponents, dashboard | Penerimaan Barang | Goods Receipt | Inventory document; abbreviation GR stays. Dependent entries: 21. | HIGH | 22 | AWAITING_DECISION |
| 16 | **Purchase Request** (`glossary.purchaseRequest`) | Procurement document | procurement, navigation, backend:procurement, dashboard, backend:configuration,configurationDefaults | Permintaan Pembelian | Purchase Request | Procurement document; abbreviation PR stays. Dependent entries: 12. | HIGH | 13 | AWAITING_DECISION |
| 17 | **Work Authorization Letter** (`glossary.workAuthorizationLetter`) | Document | externalWorkOrderInvoice, backend:configurationDefaults, workOrder, backend:configuration,workOrder, backend:externalWorkOrderInvoice | Surat Otorisasi Kerja | Surat Perintah Kerja (SPK) | External workshop document; "SPK" is the common Indonesian name but is also how many companies call a Work Order. Dependent entries: 9. | HIGH | 11 | AWAITING_DECISION |
| 18 | **Supplier** (`glossary.supplier`) | Partner type | partner, navigation, backend:procurement | Pemasok | Supplier | Partner type (Spare Part / Tire Supplier); must not collide with Vendor. Dependent entries: 4. | HIGH | 4 | AWAITING_DECISION |
| 19 | **Tread** (`glossary.tread`) | Tire | tire, backend:tire, inventory, backend:analytics, backend:tireOperation,vehicleWheelConfiguration | Tapak | Tread | Tread depth / tread condition. Dependent entries: 42. | MEDIUM | 47 | AWAITING_DECISION |
| 20 | **Scrap / Scrapped** (`glossary.scrap`) | Tire / inventory | inventory, backend:workOrder, tire, backend:tire, common | Scrap | Afkir | Disposal status/action for tires and used parts (SCRAPPED stays the stored code). Dependent entries: 38. | MEDIUM | 41 | AWAITING_DECISION |
| 21 | **Finding** (`glossary.finding`) | Inspection / work order | workOrder, backend:workOrder, inspection, backend:addExternalWorkOrderPrintSection, backend:correctWorkOrderExternalTransitions | Temuan | Hasil Pemeriksaan | Inspection / work-order findings. Dependent entries: 28. | MEDIUM | 35 | AWAITING_DECISION |
| 22 | **Mechanic / Worker** (`glossary.mechanic`) | Workshop operations | workshop, workOrder, navigation, backend:workshop, access | Mekanik | Teknisi | Menu says Mechanic, API says worker (03). Dependent entries: 33. | MEDIUM | 34 | AWAITING_DECISION |
| 23 | **Reuse / Reusable** (`glossary.reuse`) | Tire / inventory | inventory, tire, backend:tire, backend:workOrder, common | Pakai Ulang | Reuse | Used-tire / used-part disposition. Dependent entries: 28. | MEDIUM | 30 | AWAITING_DECISION |
| 24 | **Intelligence** (`glossary.intelligence`) | Intelligence | intelligence, backend:notificationDefaults, navigation, backend:module, backend:accessControl,module | Intelligence | Analitik Prediktif | Feature/module brand (Maintenance Intelligence). Dependent entries: 20. | MEDIUM | 25 | AWAITING_DECISION |
| 25 | **Inventory** (`glossary.inventory`) | Inventory | inventory, backend:analytics, analytics, dashboard, workOrder | Inventori | Persediaan | Module/menu name; "Persediaan" is the accounting term. Dependent entries: 19. | MEDIUM | 20 | AWAITING_DECISION |
| 26 | **Return Order / Return to Vendor / Purchase Return** (`glossary.returnToVendor`) | Procurement | procurement, backend:procurement, backend:configurationDefaults, tenantComponents, backend:configuration | Retur ke Vendor (flow); document name "Return Order" kept | Retur Pembelian | Three English names for one flow (03); depends on glossary.vendor. Dependent entries: 18. | MEDIUM | 19 | AWAITING_DECISION |
| 27 | **Casing** (`glossary.casing`) | Tire | backend:tire, tire | Casing | Karkas | Tire casing eligibility for retread. Dependent entries: 18. | MEDIUM | 18 | AWAITING_DECISION |
| 28 | **Bundle** (`glossary.bundle`) | Platform commercial | platform.bundles, backend:productCatalog, navigation, backend:contract, platform.pricing | Paket | Bundle | Commercial bundle of modules (platform). Dependent entries: 18. | MEDIUM | 18 | AWAITING_DECISION |
| 29 | **Service Invoice (vs Workshop Invoice)** (`glossary.serviceInvoice`) | Workshop invoice | backend:workOrder, workOrder, navigation, workshopInvoice, backend:accessControl | Invoice Servis | Invoice Bengkel | English canonical is itself undecided (Workshop Invoice vs Service Invoice, 03). Dependent entries: 11. | MEDIUM | 12 | AWAITING_DECISION |
| 30 | **Stock Opname** (`glossary.stockOpname`) | Inventory | inventory, backend:inventory, navigation | Stock Opname | Penghitungan Stok | Already the Indonesian business term used in the English UI. Dependent entries: 12. | MEDIUM | 12 | AWAITING_DECISION |
| 31 | **Load Index / Speed Rating / Ply Rating** (`glossary.tireSpec`) | Tire product | inventory, backend:productMaster, tire, backend:tire | keep English (Load Index / Speed Rating / Ply Rating) | Indeks Beban / Indeks Kecepatan / Peringkat Lapisan | Tire industry standard specification names. Dependent entries: 8. | MEDIUM | 10 | AWAITING_DECISION |
| 32 | **Bead** (`glossary.bead`) | Tire | tire, backend:tire | Bead | Tumit Ban | Tire inspection location. Dependent entries: 7. | MEDIUM | 8 | AWAITING_DECISION |
| 33 | **Inner Liner** (`glossary.innerLiner`) | Tire | tire, backend:tire | Lapisan Dalam | Inner Liner | Tire inspection location. Dependent entries: 7. | MEDIUM | 7 | AWAITING_DECISION |
| 34 | **Sidewall** (`glossary.sidewall`) | Tire | tire, backend:tire | Dinding Samping | Sidewall | Tire inspection location. Dependent entries: 4. | MEDIUM | 5 | AWAITING_DECISION |
| 35 | **Engine Hour / Hour Meter** (`glossary.engineHour`) | Vehicle / maintenance | maintenance | Jam Mesin | Hour Meter (HM) | Usage meter for heavy equipment; "HM" is common in mining fleets. Dependent entries: 3. | MEDIUM | 5 | AWAITING_DECISION |
| 36 | **Entitlement** (`glossary.entitlement`) | Platform commercial | platform.subscriptions, platform.tenants | Hak Akses Modul | Entitlement | Module entitlement per tenant/contract. Dependent entries: 2. | MEDIUM | 2 | AWAITING_DECISION |
| 37 | **Data Scope** (`glossary.dataScope`) | Access management | backend:componentAsset, backend:sparePartSale, backend:workspaceReservation, access, inventory | Cakupan Data | Data Scope | Access-control concept. Dependent entries: 37. | LOW | 71 | AWAITING_DECISION |
| 38 | **Axle** (`glossary.axle`) | Tire | tire, backend:tire, common, backend:masterData, maintenance | Sumbu | As Roda | Wheel configuration axles. Dependent entries: 28. | LOW | 38 | AWAITING_DECISION |
| 39 | **Rim** (`glossary.rim`) | Tire / inventory | tire, inventory, navigation, masterData, backend:productReferenceData | Velg | Pelek | Wheel rim item type. Dependent entries: 17. | LOW | 19 | AWAITING_DECISION |
| 40 | **Odometer** (`glossary.odometer`) | Vehicle | maintenance, tire, workOrder, vehicle, warranty | Odometer | Penunjuk Kilometer | Usage meter label; alternates with "KM" (03). Dependent entries: 14. | LOW | 18 | AWAITING_DECISION |
| 41 | **Road Test** (`glossary.roadTest`) | Work order | workOrder, backend:qualityControl | Uji Jalan | Road Test | Work-order QC step. Dependent entries: 5. | LOW | 7 | AWAITING_DECISION |
| 42 | **Dashboard** (`glossary.dashboard`) | Global | navigation, dashboard, platform.dashboard | Dasbor | Dashboard | Page/menu name; KBBI form is "dasbor", many Indonesian SaaS keep "Dashboard". Dependent entries: 4. | LOW | 5 | AWAITING_DECISION |
| 43 | **Quality Control** (`glossary.qualityControl`) | Work order | workOrder | Quality Control (QC) | Kontrol Kualitas | QC step / module; QC abbreviation stays. Dependent entries: 1. | LOW | 1 | AWAITING_DECISION |

## Style decisions (do not block translation)

These concern the English canonical wording. The Indonesian mapping already follows the context (see 06), so no dataset entry waits on them.

| No | Decision | Recommended Option | Alternative | Reason | Severity | Status |
|---:|---|---|---|---|---|---|
| 1 | Create / Add / New | Create → Buat (documents), Add → Tambah (lines/children), New → Baru (adjective) | Normalize English first, then translate | Audit 03: English verbs alternate; Indonesian mapping is stable regardless. | LOW | AWAITING_DECISION |
| 2 | Delete / Remove | Delete → Hapus; Remove → Hapus (files/lines) or Lepas (detach) | Remove → Keluarkan | Indonesian collapses both to "Hapus"; keep English distinction. | LOW | AWAITING_DECISION |
| 3 | Cancel (dialog dismiss) vs Cancel (cancel a document) | Dismiss → Batal; cancel document → Batalkan | Batalkan everywhere | One key common.actions.cancel is used for both meanings (see 11 correction proposal). | MEDIUM | AWAITING_DECISION |
| 4 | Approve / Accept | Approve → Setujui; Accept → Terima | — | Different meanings; keep distinct. | LOW | AWAITING_DECISION |
| 5 | Sign In / Login | Masuk | Login | LoginPage mixes both English forms. | LOW | AWAITING_DECISION |

## Dependency map (examples per term)

| Term | Dependent entries | Occurrences | Example dependents |
|---|---:|---:|---|
| `glossary.workOrder` | 134 | 163 | This Work Order is outside your assigned data sco…; Work Order; Work Order Workflow; Work Order |
| `glossary.maintenance` | 109 | 126 | Maintenance; Maintenance Type; Maintenance; In Maintenance |
| `glossary.sparePart` | 109 | 114 | Planned Parts; Estimated Parts Cost; Returned part {{return_number}} accepted after in…; A part request must include at least one line ite… |
| `glossary.workshop` | 70 | 87 | Workshop; This workshop is outside your assigned data scope.; Recorded Workshop Invoice; Workshop Initial |
| `glossary.invoice` | 112 | 144 | Invoice Date; Invoice Number; Invoice #; Invoice Amount |
| `glossary.tenant` | 56 | 104 | System master data cannot be modified by a tenant.; Tenant; System master data cannot be deleted by a tenant.; Shared baseline taxonomy visible to every tenant.… |
| `glossary.vendor` | 75 | 95 | Vendor; Vendor Name; Vendor; Select vendor… |
| `glossary.workspace` | 55 | 63 | Workspace; Transfer to Another Workspace; The target workspace was not found.; This Work Order already has a current workspace a… |
| `glossary.retread` | 44 | 46 | Retread; Tires in Retread / Repair Cycle; RETREAD; Retread |
| `glossary.purchaseOrder` | 36 | 42 | Purchase Order; Create Purchase Order; Purchase Order; This Purchase Order already has an open Return Or… |
| `glossary.breakdown` | 35 | 38 | Breakdown; Breakdown; {{breakdown.severity}} breakdown reported for {{v…; Breakdowns |
| `glossary.quotation` | 35 | 38 | ← Back to Quotation; This quotation has already been converted to a Pu…; The quotation document may not be larger than 10 …; Vendor Quotations |
| `glossary.partRequest` | 27 | 28 | A part request must include at least one line ite…; Part Requests; Part Requests; REUSE tires per warehouse, issued through Part Re… |
| `glossary.partner` | 17 | 25 | Partner; A PARTNER sale requires partner_id.; Partners; Partner |
| `glossary.goodsReceipt` | 21 | 22 | Goods Receipt; Goods Receipt; Goods Receipt; Pending Goods Receipt |
| `glossary.purchaseRequest` | 12 | 13 | Purchase Request; Purchase Requests; Purchase Request; Open Purchase Requests |
| `glossary.workAuthorizationLetter` | 9 | 11 | Work Authorization Letter; Confirm that the Work Order and Work Authorizatio…; Upload the signed Work Authorization Letter recei…; Work Authorization Letter |
| `glossary.supplier` | 4 | 4 | Suppliers; No suppliers found.; All Suppliers; Only an active Supplier, Spare Part Supplier or T… |
| `glossary.tread` | 42 | 47 | Current Tread Depth; Last Known Tread Depth; Last Tread Depth; Tread Depth (mm) |
| `glossary.scrap` | 38 | 41 | Scrapped; Scrap Material; Recently Scrapped; SCRAP |
| `glossary.finding` | 28 | 35 | Add Finding; Recorded Findings; No findings recorded.; Finding description |
| `glossary.mechanic` | 33 | 34 | Mechanic; Mechanics; Mechanics; User created, but linking the worker failed: {{me… |
| `glossary.reuse` | 28 | 30 | Reusable; Operational Reuse; REUSE; No used spareparts in Reuse, Quarantine or Repair. |
| `glossary.intelligence` | 20 | 25 | Intelligence; You do not have permission to view vehicle intell…; Maintenance Intelligence; Maintenance Intelligence |
| `glossary.inventory` | 19 | 20 | Inventory; Inventory; Inventory Value; Inventory Analytics |
| `glossary.returnToVendor` | 18 | 19 | This Purchase Order already has an open Return Or…; Return Order; Return Order created; Return Order printed |
| `glossary.casing` | 18 | 18 | Age / retread / casing compliance; + Casing Repair; Check for bulges, deformation or signs that the t…; History or signs that the tire ran with very low … |
| `glossary.bundle` | 18 | 18 | Bundle Management; Bundles; ← Back to Bundle Management; Create Bundle |
| `glossary.serviceInvoice` | 11 | 12 | Record Service Invoice; Service Invoices; View Service Invoice; Record Service Invoice |
| `glossary.stockOpname` | 12 | 12 | Stock Opname; Stock Opname; ← Back to Stock Opname; No stock opnames found. |
| `glossary.tireSpec` | 8 | 10 | Ply Rating; Speed Rating; Dual Load Index; Single Load Index |
| `glossary.bead` | 7 | 8 | Bead; Bead Wire Damaged / Exposed; Condition of the bead — the part of the tire that…; Sidewall / Bead / Inner Liner |
| `glossary.innerLiner` | 7 | 7 | Inner liner; Inner Liner; Sidewall / Bead / Inner Liner; Inner liner / casing failure |
| `glossary.sidewall` | 4 | 5 | Sidewall; Sidewall / Bead / Inner Liner; Deep sidewall cut / crack.; Sidewall not inspected |
| `glossary.engineHour` | 3 | 5 | Engine Hour; · Engine Hour; Engine Hours |
| `glossary.entitlement` | 2 | 2 | Activates the subscription and its module entitle…; Module Entitlements |
| `glossary.dataScope` | 37 | 71 | This vehicle is outside your assigned data scope.; This warehouse is outside your assigned data scop…; This Work Order is outside your assigned data sco…; This branch is outside your assigned data scope. |
| `glossary.axle` | 28 | 38 | Axles; Total Axles; Number of Front Axles; Number of Rear Axles |
| `glossary.rim` | 17 | 19 | Rim Diameter; Rims; Rim; Rim |
| `glossary.odometer` | 14 | 18 | Current Odometer; Odometer; Odometer; Next Due Odometer |
| `glossary.roadTest` | 5 | 7 | Road Test; Record Road Test; No road test recorded.; Road Test is not available for an External Work O… |
| `glossary.dashboard` | 4 | 5 | Dashboard; Dashboard; Dashboard; Platform Dashboard |
| `glossary.qualityControl` | 1 | 1 | Quality Control |

Entries containing several REVIEW terms depend on all of them (e.g. "Work Order Part Request" → `glossary.workOrder; glossary.partRequest; glossary.sparePart`). Known over-match: the `sparePart` rule also matches the plain English word "part" (e.g. "Part of this Tire Operation…"); those entries stay REVIEW until the decision and need no other change.

