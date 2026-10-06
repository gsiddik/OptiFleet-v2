# 03 — Terminology Review

Recommendations only — no code was changed. Counts are user-facing occurrences found by the audit (raw, before consolidation); locations list the files with the most occurrences.

## Terminology inconsistency

| Term A | Term B | Occurrences A / B | Source Locations | Recommended Canonical English Term | Reason |
|---|---|---|---|---|---|
| Tire | Tyre | 180 / 4 | `TireOperationFormPage.tsx`, `TireOperationService.php`, `WorkOrderTireOperationTab.tsx`, `UsedTireInspectionPage.tsx` | Tire | US spelling dominates the UI, API and permission names; "Tyre" survives in seeded component groups ("Wheel & Tyre System", "Tyre and Wheel"). |
| Sparepart | Spare Part / Spare part | 16 / 3 | `tenantNav.ts`, `breadcrumbLabels.ts`, `SparePartSalePage.tsx`, `WarehouseStockListPage.tsx` | Spare Part | Two spellings for one concept (menu "Used Spareparts", item type "Spare Part"). |
| Mechanic | Worker | 16 / 6 | `WorkerListPage.tsx`, `WorkOrderDetailPage.tsx`, `tenantNav.ts`, `breadcrumbLabels.ts` | Mechanic | Menu says Mechanic; routes/API/permissions say worker; some screens say Worker. |
| Vendor | Supplier | 65 / 3 | `ReturnToVendorModal.tsx`, `PartnerListPage.tsx`, `breadcrumbLabels.ts`, `PurchaseOrderDetailPage.tsx` | REVIEW (Vendor for procurement documents, Supplier as partner type) | Used interchangeably for the same partner in procurement screens. |
| Partner | Vendor/Supplier | 17 / 68 | `ReturnToVendorModal.tsx`, `PartnerListPage.tsx`, `ConfigurationDefaultsSeeder.php`, `breadcrumbLabels.ts` | REVIEW | Partner is the master record; Vendor/Supplier are roles of a Partner — define which word each screen uses. |
| Workshop Invoice | Service Invoice | 5 / 12 | `WorkOrderDetailPage.tsx`, `WorkshopInvoiceService.php`, `PermissionCatalog.php`, `ExternalWorkOrderInvoiceListPage.tsx` | REVIEW | PermissionCatalog renames workshop_invoice to "Service Invoice"; menus/pages say Workshop Invoice / Recorded Workshop Invoice. |
| Return Order | Purchase Return / Return to Vendor | 18 / 2 | `PurchaseReturnService.php`, `PurchaseOrderDetailPage.tsx`, `ReturnHistory.tsx`, `DocumentTypeRegistry.php` | Return to Vendor (document: Return Order) | Three names for the PO return flow (print template "Return Order", page "Return to Vendor", code purchase_return). |
| Maintenance Package | Maintenance Policy | 6 / 0 | `MaintenancePackagesPage.tsx`, `tenantNav.ts`, `breadcrumbLabels.ts`, `MaintenanceSchedulePage.tsx` | Maintenance Package | Menu uses Package; permissions/routes/API use policy. |
| Assignment | Workspace Reservation | 10 / 2 | `WorkspaceReservationService.php`, `VehicleDetailPage.tsx`, `WorkOrderWorkspaceTab.tsx`, `tenantNav.ts` | REVIEW | Menu "Assignment" opens /workspace-reservations; scheduler/approval texts use both. |
| KM | Odometer / Kilometer | 26 / 12 | `MaintenancePackagesPage.tsx`, `PositionPanel.tsx`, `TireProductDetailPage.tsx`, `TireHistoryModal.tsx` | Odometer (unit: km) | Field label alternates between "KM", "Odometer" and "KM at …". |
| Delete | Remove | 48 / 28 | `ComponentTaxonomyManagers.tsx`, `WorkOrderDetailPage.tsx`, `ComponentGroupManager.tsx`, `ProductCategoriesPage.tsx` | Delete = destroy record; Remove = detach from parent | Both used for row actions; meaning differs and should stay distinct. |
| Create | Add / New | 40 / 109 | `WorkOrderDetailPage.tsx`, `ContractDetailPage.tsx`, `NotificationRuleModal.tsx`, `ReturnListPage.tsx` | REVIEW (Create for documents, Add for lines) | Primary actions alternate between Create X, Add X and New X. |
| Cancel | Cancelled (as action) | 135 / 19 | `WorkOrderDetailPage.tsx`, `WorkflowDefaultsSeeder.php`, `VehicleDetailPage.tsx`, `ExternalWorkOrderInvoiceListPage.tsx` | Cancel (action) / Cancelled (status) | Workflow defaults label the action with the status name ("Cancelled"). |
| Scrap | Scrapped | 4 / 4 | `ReturnListPage.tsx`, `WarehouseStockListPage.tsx`, `UsedTireManagementPage.tsx`, `TireAnalyticsPage.tsx` | Scrap (display) — SCRAPPED stays the stored code | StatusBadge maps SCRAPPED → SCRAP; other screens show Scrapped. |
| Stock Opname | Stock Count | 9 / 1 | `StockOpnameListPage.tsx`, `StockOpnameService.php`, `tenantNav.ts`, `breadcrumbLabels.ts` | Stock Opname (REVIEW) | Indonesian business term already used in English UI. |
| Email | E-mail | 25 / 0 | `NotificationMessageEditor.tsx`, `ConfigurationController.php`, `WorkerListPage.tsx`, `PlatformUsersPage.tsx` | Email | Spelling variant. |
| Work Order | WO | 161 / 12 | `WorkOrderDetailPage.tsx`, `WorkspaceReservationService.php`, `ExternalWorkOrderInvoiceListPage.tsx`, `ExternalWorkOrderService.php` | Work Order (WO only in narrow columns) | Abbreviation mixed with full term. |
| Goods Receipt | GR | 19 / 3 | `VendorInvoiceReferenceListPage.tsx`, `VendorInvoiceReferenceService.php`, `GoodsReceiptListPage.tsx`, `PurchaseOrderDetailPage.tsx` | Goods Receipt | Abbreviation mixed with full term. |
| Retread | Repair (casing) | 20 / 48 | `UsedTireManagementPage.tsx`, `UsedTireInspectionService.php`, `UsedTireInspectionPage.tsx`, `RetreadCyclePanel.tsx` | Both kept (different processes) | Comments say "repair is a kind of retread" (tenantNav) while the UI treats them as separate cycles. |
| Branch | Location / Site | 42 / 9 | `BranchesPage.tsx`, `ConfigurationController.php`, `VehicleDetailPage.tsx`, `MaintenanceHistoryPage.tsx` | Branch | Organization unit naming. |
| Login | Sign in | 2 / 1 | `LoginPage.tsx`, `WorkerListPage.tsx` | Sign in | LoginPage mixes "Sign In" / "Signing in…" with "Login failed." and "Login Account". |
| Approve | Accept | 30 / 4 | `PurchaseOrderDetailPage.tsx`, `PartRequestListPage.tsx`, `ContractDetailPage.tsx`, `StockTransferDetailPage.tsx` | Approve (workflow) / Accept (vendor/QC result) | Different meanings, keep both. |

## Case / punctuation variants of the same label (85)

| Variants | Recommendation |
|---|---|
| ACTIVE · Active | One key; Title Case for labels/headers, sentence case for messages |
| APPROVED · Approved | One key; Title Case for labels/headers, sentence case for messages |
| ARCHIVED · Archived | One key; Title Case for labels/headers, sentence case for messages |
| All Categories · All categories | One key; Title Case for labels/headers, sentence case for messages |
| All Types · All types | One key; Title Case for labels/headers, sentence case for messages |
| Approve · approve | One key; Title Case for labels/headers, sentence case for messages |
| BRANCH · Branch | One key; Title Case for labels/headers, sentence case for messages |
| Buyer Name · Buyer name | One key; Title Case for labels/headers, sentence case for messages |
| Buyer Type · Buyer type | One key; Title Case for labels/headers, sentence case for messages |
| By · by | One key; Title Case for labels/headers, sentence case for messages |
| CANCELLED · Cancelled | One key; Title Case for labels/headers, sentence case for messages |
| CLOSED · Closed | One key; Title Case for labels/headers, sentence case for messages |
| COMPLETED · Completed | One key; Title Case for labels/headers, sentence case for messages |
| Cancellation Reason · Cancellation reason | One key; Title Case for labels/headers, sentence case for messages |
| Change Summary · Change summary | One key; Title Case for labels/headers, sentence case for messages |
| Choose the Document Type. · Choose the document type. | One key; Title Case for labels/headers, sentence case for messages |
| Close-up with scale · close-up with scale | One key; Title Case for labels/headers, sentence case for messages |
| Company Name · Company name | One key; Title Case for labels/headers, sentence case for messages |
| Confirm Return · Confirm return | One key; Title Case for labels/headers, sentence case for messages |
| DRAFT · Draft | One key; Title Case for labels/headers, sentence case for messages |
| Damage photo · damage photo | One key; Title Case for labels/headers, sentence case for messages |
| Delivery Warehouse · Delivery warehouse | One key; Title Case for labels/headers, sentence case for messages |
| Document Type · Document type | One key; Title Case for labels/headers, sentence case for messages |
| Escalation recipient · escalation recipient | One key; Title Case for labels/headers, sentence case for messages |
| Est. Hours · Est. hours | One key; Title Case for labels/headers, sentence case for messages |
| FRONT · Front | One key; Title Case for labels/headers, sentence case for messages |
| Flat Spot · flat spot | One key; Title Case for labels/headers, sentence case for messages |
| HOLD · Hold | One key; Title Case for labels/headers, sentence case for messages |
| INACTIVE · Inactive | One key; Title Case for labels/headers, sentence case for messages |
| INVOICE · Invoice | One key; Title Case for labels/headers, sentence case for messages |
| ISSUED · Issued | One key; Title Case for labels/headers, sentence case for messages |
| In-App Message · In-App message | One key; Title Case for labels/headers, sentence case for messages |
| Inner Liner · Inner liner | One key; Title Case for labels/headers, sentence case for messages |
| Inspection Result · Inspection result | One key; Title Case for labels/headers, sentence case for messages |
| Invoice Document · invoice document | One key; Title Case for labels/headers, sentence case for messages |
| LATE · Late | One key; Title Case for labels/headers, sentence case for messages |
| Manufacture Date Code · Manufacture date code | One key; Title Case for labels/headers, sentence case for messages |
| Mapped Vehicles · Mapped vehicles | One key; Title Case for labels/headers, sentence case for messages |
| Max Speed · Max speed | One key; Title Case for labels/headers, sentence case for messages |
| NEW · New | One key; Title Case for labels/headers, sentence case for messages |
| Order Date · Order date | One key; Title Case for labels/headers, sentence case for messages |
| PAID · Paid | One key; Title Case for labels/headers, sentence case for messages |
| PUBLISHED · Published | One key; Title Case for labels/headers, sentence case for messages |
| Payment Proof · payment proof | One key; Title Case for labels/headers, sentence case for messages |
| Proof of Payment · proof of payment | One key; Title Case for labels/headers, sentence case for messages |
| Purchase Date · Purchase date | One key; Title Case for labels/headers, sentence case for messages |
| QUARANTINED · Quarantined | One key; Title Case for labels/headers, sentence case for messages |
| REJECTED · Rejected | One key; Title Case for labels/headers, sentence case for messages |
| REMOVED · Removed | One key; Title Case for labels/headers, sentence case for messages |
| REPAIR · Repair | One key; Title Case for labels/headers, sentence case for messages |
| REQUESTED · Requested | One key; Title Case for labels/headers, sentence case for messages |
| RESERVED · Reserved | One key; Title Case for labels/headers, sentence case for messages |
| RETREAD · Retread | One key; Title Case for labels/headers, sentence case for messages |
| Receipt History · Receipt history | One key; Title Case for labels/headers, sentence case for messages |
| Recently Scrapped · Recently scrapped | One key; Title Case for labels/headers, sentence case for messages |
| Reject · reject | One key; Title Case for labels/headers, sentence case for messages |
| Remove · remove | One key; Title Case for labels/headers, sentence case for messages |
| Return to Warehouse · Return to warehouse | One key; Title Case for labels/headers, sentence case for messages |
| Root Cause · Root cause | One key; Title Case for labels/headers, sentence case for messages |
| SCRAP · Scrap | One key; Title Case for labels/headers, sentence case for messages |
| SCRAPPED · Scrapped | One key; Title Case for labels/headers, sentence case for messages |
| SUBMITTED · Submitted | One key; Title Case for labels/headers, sentence case for messages |
| SUSPENDED · Suspended | One key; Title Case for labels/headers, sentence case for messages |
| SYSTEM · System | One key; Title Case for labels/headers, sentence case for messages |
| Select · select | One key; Title Case for labels/headers, sentence case for messages |
| Serial Number · Serial number | One key; Title Case for labels/headers, sentence case for messages |
| Service Item · Service item | One key; Title Case for labels/headers, sentence case for messages |
| Start status · start status | One key; Title Case for labels/headers, sentence case for messages |
| TENANT · Tenant | One key; Title Case for labels/headers, sentence case for messages |
| TO · To | One key; Title Case for labels/headers, sentence case for messages |
| TRANSFERRED · Transferred | One key; Title Case for labels/headers, sentence case for messages |
| Terminate · terminate | One key; Title Case for labels/headers, sentence case for messages |
| Tread Depth (mm) · Tread depth (mm) | One key; Title Case for labels/headers, sentence case for messages |
| Unit Price · Unit price · unit price | One key; Title Case for labels/headers, sentence case for messages |
| Unlimited · unlimited | One key; Title Case for labels/headers, sentence case for messages |
| Vehicle Transfer · Vehicle transfer | One key; Title Case for labels/headers, sentence case for messages |
| Vehicle Type · vehicle type | One key; Title Case for labels/headers, sentence case for messages |
| View · view | One key; Title Case for labels/headers, sentence case for messages |
| View Payment Proof · View payment proof | One key; Title Case for labels/headers, sentence case for messages |
| WAREHOUSE · Warehouse | One key; Title Case for labels/headers, sentence case for messages |
| … 5 more (see CSV) | |

## Do Not Translate candidates

| Text | Reason | Context | Recommendation |
|---|---|---|---|
| OptiFleet | Product/brand name | Login, invoice PDF, page titles | NO |
| VIN, SKU, PO, GR, RFQ, PR, WO, MR, QC, KPI, UoM, PIC, SDS | Industry / document abbreviations | Column headers, document numbers | NO (keep; optionally add Indonesian expansion in tooltips) |
| PDF, CSV, XLSX, MIME | File formats | Upload/export buttons | NO |
| km, mm, kg, cc, L, %, km/h, kPa, PSI | Units | Labels in parentheses | NO |
| {TENANT} {BRANCH} {WORKSHOP} {WAREHOUSE} {DOC} {YYYY} {MM} {MMMM} {SEQ:n} | Numbering tokens (functional syntax) | Document Numbering builder | NO (translate only the token descriptions) |
| {{work_order.number}} and other template variables | Template variable paths | Document Template editor / print templates | NO |
| D_new, D_min, D_pull, A_retread_max, N_retread_max | Rule-profile formula symbols | Tire inspection / rule profiles | NO |
| OTR, TRA Code, Ply Rating, Load Index, Speed Rating | Tire industry standard terms | Tire product forms | REVIEW |
| Stock Opname | Already the Indonesian business term | Inventory menu/pages | REVIEW |
| Retread, Casing, Bead, Tread, Sidewall, Inner Liner | Tire technical vocabulary | Tire inspection | REVIEW (often kept in English by Indonesian fleet operators) |
| Work Order, Breakdown, Workshop, Workspace, Part Request, Goods Receipt, Purchase Order, Sparepart | Core business terms | Menus, documents | REVIEW (owner glossary decision) |
| Enum codes shown raw (e.g. UNDER_REVIEW, PENDING_PROCESSING) | Canonical values — must not change | StatusBadge, tables | NO for the code; YES for the new display label |
| Keyboard keys (Enter, Escape, ArrowUp, Delete, Backspace) | Event key names compared in code | Keyboard handlers | NO (not user-facing) |
| Role names seeded as data (e.g. Fleet Manager, Warehouse Manager) | Tenant-editable role names used as notification identifiers | Notification recipients | REVIEW (rename would break identifier match) |

### Inventory rows marked NO / REVIEW

| Need | Count | Examples |
|---|---|---|
| NO | 42 | SKU, ({{value}} KB), OptiFleet, KM, OptiFleet, PDF, · STALE, ENG, RFQ, OWN, OptiFleet, {{code}} — {{max_load_dual_kg}} kg, {{code}} — {{max_load_single_kg}} kg, {{dualMaxLoad}} kg, HP, {{singleMaxLoad}} kg, TRA Code, UOM, · WO, · KM |
| REVIEW | 435 | Tenant, Workshop, Vendor, Rejected, Rejected, Cancelled, Approved, Approved, Draft, Work Order, Cancelled, Workspace, Tire, Submitted, Submitted, Fleet, Foundation, Intelligence, Maintenance, External |
