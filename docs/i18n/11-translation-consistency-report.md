# 11 — Translation Consistency Report

Automated checks over all 5240 dataset rows (`08`), followed by reviewer judgement. "Proposed" text of REVIEW rows is included in the checks.

## Summary

| Check | Result | Verdict |
|---|---:|---|
| Translation key collision | 0 | PASS |
| Missing translation (AUTO / STRUCTURAL without text) | 0 | PASS |
| REVIEW entries with a final translation (review isolation) | 0 | PASS |
| Parameter mismatch ({{x}}, {x}, :attr) | 0 | PASS |
| Do-not-translate / canonical-code loss | 0 | PASS |
| Same English text → different Indonesian | 5 | EXPECTED (context) — see below |
| Terminology rule violations | 3 | ACCEPTED (proper nouns / sample values) |
| Action/status form flags | 30 | SOURCE ISSUE (English itself is status-form) — see below |
| Different English → same Indonesian (same category) | 69 | REVIEWED — 5 need attention, rest are singular/plural |
| Duplicate semantic keys (same text + category, different keys) | 89 | INVENTORY PROPOSAL — consolidate |
| Identical EN = ID (untranslated candidates) | 91 | ACCEPTED (loanwords, numbers, units) |
| Length risk (action / header / menu) | 21 | CHECK IN UI |

## Same English text, different Indonesian (context-dependent, intended)

| English | Indonesian variants | Why |
|---|---|---|
| Return | Kembalikan / Retur | Kembalikan = give back to warehouse (action); Retur = vendor/inventory return (noun) |
| Repair | Perbaikan / Perbaiki | Perbaiki = action button; Perbaikan = status / filter / noun |
| Dispatch | Kirim / Pengiriman | Kirim = action; Pengiriman = field label |
| No | No / Tidak | Tidak = answer button; No = "number" column header |
| Issue | Keluarkan / Pengeluaran | Keluarkan = action; Pengeluaran = field/noun |

## Different English, same Indonesian — items needing attention

| Indonesian | English sources | Risk | Recommendation |
|---|---|---|---|
| Diterbitkan [STATUS] | ISSUED (PO/RFQ/invoice, part request) vs PUBLISHED (configuration) | Same badge text for two lifecycles; ISSUED for part requests means stock issued | Per-domain status labels in the registry: ISSUED → "Diterbitkan" (documents) / "Dikeluarkan" (part requests); PUBLISHED → "Diterbitkan" |
| Terima [BUTTON] | Receive / Accept | Low — different screens | Keep; Accept may become "Setujui Rekomendasi" if owner prefers |
| Lunas [STATUS] | Paid / Settled (fixed: Settled → Diselesaikan) | Resolved in this task | — |
| + Paket Baru / Paket | Bundle (recommended "Paket") vs Maintenance Package | Two concepts share "Paket" | Decision glossary.bundle: consider alternative "Bundle" |
| Ringkasan [SECTION_TITLE] | Overview / Summary | Low | Keep |
| Selesaikan [BUTTON] | Complete / Resolve / Settle | Low — different screens | Keep; Resolve finding may use "Tandai Terselesaikan" |
| Pengiriman [FORM_LABEL] | Delivery / Dispatch | Low | Keep |

The remaining 62 groups are singular/plural or casing variants of the same label (Indonesian has no plural) — e.g. Branch/Branches → Cabang. They confirm the duplicate-key consolidation proposed below.

## Action / status confusion

Workflow default action labels are generated from the *target status* (finding M-5 in 02), so the English button already reads as a status ("Approved", "Cancelled"). The dataset mirrors the source; the recommended fix is verb labels per `action_code` when the workflow labels get their localization model.

| Key | English | Indonesian (mirrors source) | Recommended action label |
|---|---|---|---|
| `workflow.actions.actionLabel7` | Cancelled | Dibatalkan | Batalkan |
| `workflow.actions.approved` | Approved | Disetujui | Setujui |
| `workflow.actions.assessed` | Assessed | Dinilai | Nilai |
| `workflow.actions.assigned` | Assigned | Ditugaskan | Tugaskan |
| `workflow.actions.closed` | Closed | Ditutup | Tutup |
| `workflow.actions.finalized` | Finalized | Difinalisasi | Finalisasi |
| `workflow.actions.inspected` | Inspected | Diinspeksi | Inspeksi |
| `workflow.actions.issued` | Issued | Dikeluarkan | Keluarkan / Terbitkan |
| `workflow.actions.onHold` | On Hold | Ditunda | Tunda |
| `workflow.actions.prepared` | Prepared | Disiapkan | Siapkan |
| `workflow.actions.received` | Received | Diterima | Terima |
| `workflow.actions.rejected` | Rejected | Ditolak | Tolak |
| `workflow.actions.requested` | Requested | Diminta | Ajukan Permintaan |
| `workflow.actions.resolved` | Resolved | Terselesaikan | Selesaikan |
| `workflow.actions.scheduled` | Scheduled | Terjadwal | Jadwalkan |
| `workflow.actions.settled` | Settled | Diselesaikan | — |
| `workflow.actions.submitted` | Submitted | Diajukan | Ajukan |
| `workflow.actions.verified` | Verified | Terverifikasi | Verifikasi |
| `procurement.actions.acceptedByVendor` | Accepted by Vendor | Diterima oleh Vendor | Tandai Diterima Vendor |
| `procurement.actions.rejectedByVendor` | Rejected by Vendor | Ditolak oleh Vendor | Tandai Ditolak Vendor |
| `workshop.actions.reserved` | Reserved | Dipesan | Pesan |

## Terminology rule exceptions (accepted)

| Key | Term | English | Indonesian | Reason |
|---|---|---|---|---|
| `common.placeholders.resourceTypeEGBranch` | Branch | Resource type (e.g. Branch) | Jenis resource (mis. Branch) | Proper noun / sample value / Indonesian KIR document name |
| `masterData.module.analyticsAndDataWarehouse` | Warehouse | Analytics & Data Warehouse | Analitik & Data Warehouse | Proper noun / sample value / Indonesian KIR document name |
| `vehicle.documentType.inspectionCertificate` | Inspection | Inspection Certificate | Sertifikat Uji Kelayakan | Proper noun / sample value / Indonesian KIR document name |

## Length risk (verify in UI; translation kept because it is the natural form)

| Key | Category | English | Indonesian |
|---|---|---|---|
| `common.actions.moveToReview` | ACTION | Move to Review | Pindahkan ke Peninjauan |
| `nav.items.partRequests` | SUBMENU | Part Requests | Permintaan Suku Cadang |
| `workflow.actions.rework` | ACTION | Rework | Pengerjaan Ulang |
| `configuration.actions.fitView` | BUTTON | Fit View | Sesuaikan Tampilan |
| `configuration.actions.resetLayout` | BUTTON | Reset Layout | Atur Ulang Tata Letak |
| `configuration.actions.zoomIn` | BUTTON | Zoom in | Perbesar tampilan |
| `configuration.actions.zoomOut` | BUTTON | Zoom out | Perkecil tampilan |
| `maintenance.fields.nextDueDate` | TABLE_HEADER | Next Due Date | Tanggal Jatuh Tempo Berikutnya |
| `maintenance.fields.nextDueOdometer` | TABLE_HEADER | Next Due Odometer | Odometer Jatuh Tempo Berikutnya |
| `platform.invoices.actions.void` | BUTTON | Void | Batalkan (Void) |
| `platform.invoices.actions.voidInvoice` | BUTTON | Void Invoice | Batalkan Invoice (Void) |
| `platform.invoices.actions.voiding` | BUTTON | Voiding… | Membatalkan (void)… |
| `procurement.actions.backToRfqs` | BUTTON | ← Back to RFQs | ← Kembali ke Daftar RFQ |
| `tire.actions.deselectAll` | BUTTON | Deselect all | Batalkan semua pilihan |
| `tire.fields.reusableReuse` | TABLE_HEADER | Reusable (REUSE) | Dapat Dipakai Ulang (REUSE) |
| `workOrder.actions.addJob` | BUTTON | Add Job | Tambah Pekerjaan |
| `workOrder.actions.confirmIssue` | BUTTON | Confirm Issue | Konfirmasi Pengeluaran |
| `workOrder.actions.confirmReturn` | BUTTON | Confirm return | Konfirmasi pengembalian |
| `workOrder.actions.confirmReturn2` | BUTTON | Confirm Return | Konfirmasi Pengembalian |
| `workOrder.actions.failRework` | BUTTON | Fail (Rework) | Gagal (Pengerjaan Ulang) |
| `workOrder.actions.unassign` | BUTTON | Unassign | Batalkan Penugasan |

## Identical English and Indonesian (intentional)

`@ est. {{estimated_unit_price}}`, `Apr`, `April`, `Asset#`, `Audit`, `Bank`, `Bias`, `Bin`, `Bin…`, `Breadcrumb`, `Bus`, `Checklist`, `Cupping / Scalloping`, `D new`, `D_min (mm)`, `D_pull (mm)`, `Diagnosis`, `Diameter`, `Diameter (in)`, `Downtime (MTTR/MTBF)`, `Email`, `Email: {{subject}}`, `Est.`, `Event`, `Feb`, `File`, `Forklift`, `Format`, `Internal`, `Item`, `Jan`, `Jul`, `Jun`, `Kilogram`, `Level`, `Level {{l}}`, `Liter`, `Load Range`, `Log`, `MB.`, `Manual`, `Mar`, `Material`, `Min`, `Model`, `Model…`, `No`, `No: {{document_number}}`, `Non Trailer`, `Normal`, `Nov`, `November`, `Offset (mm)`, `PCD (mm)`, `Qty`, `Radial`, `Ref: {{reference_number}}`, `Resource`, `Semi Trailer`, `Sep`, `September`, `Set`, `Status`, `Status {{label}}{{value}}`, `Status: {{workshop_invoice.status}}`, `Subtotal`, `Throughput`, `Total`, `Total: {{currency}} {{toLocaleString}}`, `Total: {{currency}} {{total}}`, `Total: {{invoice.total}}`, `Total: {{purchase_order.total}}`, `Total: {{workshop_invoice.total_amount}} {{workshop_invoice.currency}}`, `Trailer`, `Transfer`, `Tubeless`, `UOM…`, `Undercarriage`, `Universal`, `Van`, `kW`, `psi`, `{{bolt_holes}}x{{value}}mm`, `{{status}} · {{buyerName}} · {{decidedAt}}`, `{{value}} — qty {{quantity}}`, `{{value}} — qty {{quantity}} × {{unit_price}} = {{total_amount}} ({{sale_type}})`, `{{value}} — qty {{quantity}} — {{condition}}`, `{{value}} — qty {{requested_quantity}}`, `· Model v{{model_version}}`, `— qty {{quantity}} ·`, `☰ Menu`

## Duplicate semantic keys (consolidation candidates)

| English [category] | Keys |
|---|---|
| Actual Hours [DOCUMENT_LABEL] | `documents.maintenanceReport.actualHours`, `documents.workOrder.actualHours` |
| Approved [STATUS] | `workOrder.status.approved`, `workflow.status.approved` |
| Brake System [FORM_LABEL] | `maintenance.group.brakeSystem`, `masterData.masterData.brakeSystem` |
| Branch Initial [FORM_LABEL] | `configuration.fields.branchInitial`, `configuration.labels.branchInitial` |
| Branch/Workshop: {{branch.name}} / {{workshop.name}} [DOCUMENT_LABEL] | `documents.maintenanceReport.branchWorkshopBranchNameWorkshopName`, `documents.workOrder.branchWorkshopBranchNameWorkshopName` |
| Bus [FORM_LABEL] | `masterData.masterData.bus`, `tire.fields.bus` |
| Cancelled [STATUS] | `externalWorkOrderInvoice.status.cancelled`, `workflow.status.cancelled` |
| Choose the {{kind}} for each {{what}}. [VALIDATION] | `configuration.validation.chooseKindEachWhat`, `validation.notification.chooseKindEachWhat` |
| Clutch System / Torque Converter [FORM_LABEL] | `maintenance.group.clutchTorqueConverter`, `masterData.masterData.clutchSystemTorqueConverter` |
| Code [DOCUMENT_LABEL] | `documents.purchaseReturn.code`, `documents.rfq.code` |
| Completed [STATUS] | `tire.status.approved`, `workflow.status.completed` |
| Contact: {{partner.contact_name}} ({{partner.contact_phone}}) [DOCUMENT_LABEL] | `documents.maintenanceMemo.contactPartnerContactNamePartnerContact`, `documents.purchaseOrder.contactPartnerContactNamePartnerContact` |
| Cooling System [FORM_LABEL] | `maintenance.group.coolingSystem`, `masterData.masterData.coolingSystem` |
| Date [FORM_LABEL] | `common.fields.date`, `configuration.labels.date` |
| Delivery Warehouse [FORM_LABEL] | `procurement.fields.deliveryWarehouse`, `configuration.labels.deliveryWarehouse` |
| Description [DOCUMENT_LABEL] | `documents.invoice.description`, `documents.platformInvoice.description` |
| Doc Code Name [FORM_LABEL] | `configuration.fields.docCodeName`, `configuration.labels.docCodeName` |
| Electrical System [FORM_LABEL] | `maintenance.group.electricalSystem`, `masterData.masterData.electricalSystem` |
| Email [FORM_LABEL] | `common.fields.email`, `configuration.labels.email` |
| Engine [FORM_LABEL] | `maintenance.group.engine`, `masterData.masterData.engine` |
| Equipment [FORM_LABEL] | `masterData.itemType.equipment`, `masterData.productReferenceData.equipment` |
| Exhaust System [FORM_LABEL] | `maintenance.group.exhaustSystem`, `masterData.masterData.exhaustSystem` |
| Fill Here [FORM_LABEL] | `tire.fields.fillHere`, `tire.labels.fillHere` |
| Forklift [FORM_LABEL] | `masterData.masterData.forklift`, `tire.fields.forklift` |
| From Warehouse [FORM_LABEL] | `inventory.fields.fromWarehouse`, `configuration.labels.fromWarehouse` |
| Front [FORM_LABEL] | `common.fields.front`, `tire.labels.front` |
| Fuel System [FORM_LABEL] | `maintenance.group.fuelSystem`, `masterData.masterData.fuelSystem` |
| Heavy Equipment [FORM_LABEL] | `masterData.masterData.heavyEquipment`, `tire.fields.heavyEquipment` |
| In Progress [STATUS] | `externalWorkOrderInvoice.status.inProgress`, `workflow.status.inProgress` |
| In-App [FORM_LABEL] | `configuration.fields.inApp`, `configuration.labels.inApp` |
| Inspection [FORM_LABEL] | `inventory.fields.inspection`, `inspection.labels.inspection` |
| Invoice [FORM_LABEL] | `account.fields.invoice`, `configuration.labels.invoice` |
| Item [DOCUMENT_LABEL] | `documents.inspectionReport.item`, `documents.rfq.item` |
| Job [DOCUMENT_LABEL] | `documents.maintenanceReport.job`, `documents.workOrder.job` |
| Left [FORM_LABEL] | `common.fields.left`, `tire.labels.left` |
| Line Total [DOCUMENT_LABEL] | `documents.invoice.lineTotal`, `documents.purchaseOrder.lineTotal` |
| Low Stock [FORM_LABEL] | `dashboard.fields.lowStock`, `notifications.lowStock` |
| Lubrication System [FORM_LABEL] | `maintenance.group.lubricationSystem`, `masterData.masterData.lubricationSystem` |
| Maintenance Memo [FORM_LABEL] | `masterData.configurationDefaults.maintenanceMemo`, `workshopInvoice.fields.maintenanceMemo` |
| Maintenance [FORM_LABEL] | `masterData.module.maintenance`, `inventory.fields.maintenance` |
| Manufacture Date Code [FORM_LABEL] | `tire.fields.manufactureDateCode`, `tire.labels.manufactureDateCode` |
| Mechanics [BREADCRUMB] | `breadcrumb.mechanics`, `breadcrumb.workers` |
| Numbering [FORM_LABEL] | `masterData.configurationDefaults.numbering`, `configuration.fields.numbering` |
| OTR / Heavy Equipment [FORM_LABEL] | `inventory.fields.otrHeavyEquipment`, `tire.labels.otrHeavyEquipment` |
| Odometer [FORM_LABEL] | `tire.fields.odometer`, `inspection.labels.odometer` |
| Passenger / Light Truck [FORM_LABEL] | `tire.category.passengerLt`, `tire.labels.passengerLightTruck` |
| Passenger Car [FORM_LABEL] | `masterData.masterData.passengerCar`, `tire.fields.passengerCar` |
| Product [DOCUMENT_LABEL] | `documents.goodsReceipt.product`, `documents.purchaseOrder.product`, `documents.purchaseRequest.product`, `documents.purchaseReturn.product`, `documents.stockTransfer.product` |
| Purchase Date [FORM_LABEL] | `tire.fields.purchaseDate`, `tire.labels.purchaseDate` |
| Qty [DOCUMENT_LABEL] | `documents.invoice.qty`, `documents.platformInvoice.qty`, `documents.purchaseOrder.qty`, `documents.rfq.qty` |
| Rear [FORM_LABEL] | `common.fields.rear`, `tire.labels.rear` |
| Received [STATUS] | `tire.status.received`, `workflow.status.received` |
| Redelivery Request [FORM_LABEL] | `procurement.returnOption.redelivery`, `configuration.labels.redeliveryRequest` |
| Refund Request [FORM_LABEL] | `procurement.returnOption.refund`, `configuration.labels.refundRequest` |
| Rejected [STATUS] | `tire.status.rejected`, `workflow.status.rejected` |
| Reorder Point [FORM_LABEL] | `inventory.fields.reorderPoint`, `notifications.reorderPoint` |
| Repair [STATUS] | `workflow.status.repair`, `inventory.status.repair` |
| Replacement [STATUS] | `tire.status.replacement`, `workflow.status.replacement` |
| Requested [STATUS] | `workOrder.status.reserved`, `workflow.status.requested` |
| Right [FORM_LABEL] | `common.fields.right`, `tire.labels.right` |
| Sequential Digit [FORM_LABEL] | `configuration.fields.sequentialDigit`, `configuration.labels.sequentialDigit` |
| Serial Number [FORM_LABEL] | `common.fields.serialNumber`, `tire.labels.serialNumber` |
| Status [DOCUMENT_LABEL] | `documents.maintenanceReport.status`, `documents.platformInvoice.status`, `documents.workOrder.status` |
| Steering System [FORM_LABEL] | `maintenance.group.steeringSystem`, `masterData.masterData.steeringSystem` |
| Suspension System [FORM_LABEL] | `maintenance.group.suspensionSystem`, `masterData.masterData.suspensionSystem` |
| Template [FORM_LABEL] | `masterData.configurationDefaults.template`, `configuration.fields.template` |
| Tenant Initial [FORM_LABEL] | `configuration.fields.tenantInitial`, `configuration.labels.tenantInitial` |
| Tenant [FORM_LABEL] | `common.fields.tenant`, `configuration.labels.tenant` |
| The selected :attribute is invalid. [VALIDATION] | `validation.in`, `validation.not_in`, `validation.exists` |
| Tires [FORM_LABEL] | `masterData.productReferenceData.tires`, `tenantComponents.fields.tires` |
| To Branch [FORM_LABEL] | `vehicle.fields.toBranch`, `configuration.labels.toBranch` |
| To Warehouse [FORM_LABEL] | `inventory.fields.toWarehouse`, `configuration.labels.toWarehouse` |
| Tools [FORM_LABEL] | `masterData.itemType.tool`, `masterData.productReferenceData.tools` |
| Transmission System [FORM_LABEL] | `maintenance.group.transmissionSystem`, `masterData.masterData.transmissionSystem` |
| Truck / Bus [FORM_LABEL] | `tire.category.truckBus`, `tire.labels.truckBus` |
| Truck [FORM_LABEL] | `masterData.masterData.truck`, `tire.fields.truck` |
| Unit Price [DOCUMENT_LABEL] | `documents.invoice.unitPrice`, `documents.platformInvoice.unitPrice`, `documents.purchaseOrder.unitPrice`, `documents.rfq.unitPrice2` |
| Upload at least one photo. [VALIDATION] | `tire.validation.uploadLeastOnePhoto`, `validation.tireCycle.uploadLeastOnePhoto` |
| Van [FORM_LABEL] | `masterData.masterData.van`, `tire.fields.van` |
| Vehicle: {{vehicle.registration_number}} ({{vehicle.brand}} {{vehicle.model}}) [DOCUMENT_LABEL] | `documents.inspectionReport.vehicleVehicleRegistrationNumberVehicleBrand`, `documents.maintenanceReport.vehicleVehicleRegistrationNumberVehicleBrand`, `documents.vehicleTransfer.vehicleVehicleRegistrationNumberVehicleBrand`, `documents.workOrder.vehicleVehicleRegistrationNumberVehicleBrand` |
| Vendor Name [FORM_LABEL] | `procurement.fields.vendorName`, `notifications.vendorName` |
| Vendor [FORM_LABEL] | `common.fields.vendor`, `configuration.labels.vendor` |
| Warehouse Initial [FORM_LABEL] | `configuration.fields.warehouseInitial`, `configuration.labels.warehouseInitial` |
| Warranty Claim [FORM_LABEL] | `inventory.fields.warrantyClaim`, `configuration.labels.warrantyClaim` |
| Warranty [FORM_LABEL] | `masterData.module.warranty`, `vehicle.documentType.warranty` |
| Work Order [FORM_LABEL] | `common.fields.workOrder`, `common.labels.workOrder` |
| WorkOrder [FORM_LABEL] | `workOrder.fields.workOrder`, `analytics.labels.workOrder` |
| Workshop Initial [FORM_LABEL] | `configuration.fields.workshopInitial`, `configuration.labels.workshopInitial` |
| escalation recipient [FORM_LABEL] | `configuration.fields.escalationRecipient`, `notification.labels.escalationRecipient` |

## TRANSLATION INVENTORY CORRECTION PROPOSAL

Changes to `01`/`translation-inventory.csv` semantics discovered in this task. Nothing in the audit artifacts was overwritten; the dataset (`08`) applies these proposals and records them in `notes`.

| Current | Proposed | Reason | Impact | Requires Review |
|---|---|---|---|---|
| (summary) | REVIEW → STRUCTURAL_PREP_REQUIRED: 231; DO_NOT_TRANSLATE: 31; REVIEW → AUTO_TRANSLATE_SAFE: 27; DO_NOT_TRANSLATE → translate: 7; dynamic=NO; fragment occurrence(s) move to whole-template key(s): 6 | per-row list below | — | — |
| common.actions.cancel = Cancel (118 occurrences, dialog dismiss + record cancel) | Split: common.actions.cancel (dismiss → "Batal") and <module>.actions.cancel<Document> (→ "Batalkan …") | One key serves two meanings | Key split at implementation | NO |
| Fragment rows (dynamic = PARTIAL) | 76 NEW whole-template keys (08, notes "NEW KEY"); fragment keys marked superseded | Fragments cannot be translated in Indonesian word order | Keys added; fragments retired at implementation | NO |
| componentAsset fragment " Asset# being returned for this item (selected " | Whole template includes missing prefix "Select exactly {{quantity}}" | Audit captured only the literal part after a computed expression | Text completed | NO |
| Laravel framework validation messages (not in inventory) | 36 NEW rows validation.<rule> (08) with FRAMEWORK_LOCALIZATION_REQUIRED | Rules used by 75 FormRequests / 227 validate() calls | Keys added | NO |
| status.issued (StatusBadge, shared) | Per-domain status keys (documents vs part requests) | ISSUED means "issued document" or "issued stock" depending on domain | Status registry design | YES |
| "Select…" categorized FORM_LABEL | PLACEHOLDER | Empty select option text | Category only | NO |
| Month names categorized FORM_LABEL | Remove from catalog; format with Intl.DateTimeFormat(locale) | Locale data, not UI text | Keys removed at implementation | NO |
| breadcrumb.tires = Tires | REVIEW → AUTO_TRANSLATE_SAFE (owner may veto) | Audit REVIEW came from the generic glossary heuristic; the term has one established Indonesian equivalent (Tire → Ban, Warranty Claim → Klaim Garansi, Wheels Configuration → Konfigurasi Roda, Hold → T | Classification only | YES |
| breadcrumb.warrantyClaims = Warranty Claims | REVIEW → AUTO_TRANSLATE_SAFE (owner may veto) | Audit REVIEW came from the generic glossary heuristic; the term has one established Indonesian equivalent (Tire → Ban, Warranty Claim → Klaim Garansi, Wheels Configuration → Konfigurasi Roda, Hold → T | Classification only | YES |
| common.fields.stale = · STALE | DO_NOT_TRANSLATE → translate | Uppercase display word, not an abbreviation (audit regex ^[A-Z]{2,5}$ over-matched). | Classification only | NO |
| common.placeholders.eGBrakePad = e.g. BRAKE_PAD | REVIEW → AUTO_TRANSLATE_SAFE | Example placeholder: only "e.g." is translated, the sample value stays as is. | Classification only | NO |
| common.placeholders.eGCgBrake = e.g. CG-BRAKE | REVIEW → AUTO_TRANSLATE_SAFE | Example placeholder: only "e.g." is translated, the sample value stays as is. | Classification only | NO |
| common.placeholders.eGDiscBrake = e.g. DISC_BRAKE | REVIEW → AUTO_TRANSLATE_SAFE | Example placeholder: only "e.g." is translated, the sample value stays as is. | Classification only | NO |
| nav.items.tire = Tire | REVIEW → AUTO_TRANSLATE_SAFE (owner may veto) | Audit REVIEW came from the generic glossary heuristic; the term has one established Indonesian equivalent (Tire → Ban, Warranty Claim → Klaim Garansi, Wheels Configuration → Konfigurasi Roda, Hold → T | Classification only | YES |
| masterData.configurationDefaults.numbering = Numbering | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.configurationDefaults.template = Template | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.itemType.tire = Tire | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.attachmentAndWorkEquipment = Attachment & Work Equipment | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.attachmentWorkEquipment = Attachment / Work Equipment | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.bodyAndCabin = Body & Cabin | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.brakeSystem = Brake System | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.bus = Bus | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.clutchAndTorqueConverter = Clutch & Torque Converter | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.clutchSystemTorqueConverter = Clutch System / Torque Converter | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.coolingSystem = Cooling System | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.electricalAndElectronicSystem = Electrical & Electronic System | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.electricalSystem = Electrical System | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.engine = Engine | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.evHighVoltageSystem = EV High Voltage System | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.excavator = Excavator | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.exhaustSystem = Exhaust System | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.forklift = Forklift | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.fuelSystem = Fuel System | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.glassAndWasherSystem = Glass & Washer System | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.heavyEquipment = Heavy Equipment | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.hvacAirConditioningSystem = HVAC / Air Conditioning System | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.hydraulicSystem = Hydraulic System | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.lubricationSystem = Lubrication System | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.mainFrameGuardBogie = Main Frame, Guard & Bogie | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.mainFrameGuardBogie2 = Main Frame & Guard / Bogie | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.optionalAccessories = Optional Accessories | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.passengerCar = Passenger Car | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.pneumaticSystem = Pneumatic System | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.safetyAndRestraintSystem = Safety & Restraint System | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.steeringSystem = Steering System | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.suspensionSystem = Suspension System | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.swingSystem = Swing System | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.transmissionSystem = Transmission System | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.truck = Truck | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.tyre = Tyre | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.underCarriage = Under Carriage | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.undercarriage = Undercarriage | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.van = Van | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.masterData.wheelAndTyreSystem = Wheel & Tyre System | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.module.analyticsAndDataWarehouse = Analytics & Data Warehouse | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.module.componentTracking = Component Tracking | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.module.coreFoundation = Core Foundation | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.module.fleet = Fleet | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.module.foundation = Foundation | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.module.historyAndRecords = History & Records | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.module.organizationManagement = Organization Management | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.module.procurement = Procurement | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.module.reporting = Reporting | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.module.supplyChain = Supply Chain | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.module.telematics = Telematics | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.module.tireManagement = Tire Management | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.module.vehicleRegistry = Vehicle Registry | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.module.warranty = Warranty | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.platformSuperadminRole.fullPlatformAccess = Full platform access. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.platformSuperadminRole.platformSuperadmin = Platform Superadmin | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.awayFromFood = Away From Food | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.cleaning = Cleaning | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.compressor = Compressor | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.consumables = Consumables | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.coolStorage = Cool Storage | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.diagnostic = Diagnostic | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.diagnosticTool = Diagnostic Tool | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.dryStorage = Dry Storage | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.equipment = Equipment | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.flammableStorage = Flammable Storage | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.handTool = Hand Tool | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.kilogram = Kilogram | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.lifting = Lifting | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.liftingTool = Lifting Tool | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.liter = Liter | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.lubrication = Lubrication | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.measuringTool = Measuring Tool | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.pair = Pair | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.piece = Piece | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.powerTool = Power Tool | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.set = Set | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.specialServiceTool = Special Service Tool | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.strength = Strength | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.tireService = Tire Service | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.tires = Tires | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.tools = Tools | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.ventilatedArea = Ventilated Area | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.welding = Welding | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| masterData.productReferenceData.wheelAlignment = Wheel Alignment | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.actionLabel = External | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.actionLabel2 = Revise | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.actionLabel3 = Cancel | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.actionLabel5 = In Progress | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.actionLabel6 = Qc Pending | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.actionLabel7 = Cancelled | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.approved = Approved | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.assessed = Assessed | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.assigned = Assigned | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.closed = Closed | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.completed = Completed | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.draft = Draft | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.finalized = Finalized | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.inTransit = In Transit | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.inspected = Inspected | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.issued = Issued | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.onHold = On Hold | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.pendingApproval = Pending Approval | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.prepared = Prepared | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.procurement = Procurement | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.received = Received | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.rejected = Rejected | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.repair = Repair | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.repairRequired = Repair Required | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.replacement = Replacement | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.requested = Requested | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.resolved = Resolved | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.rework = Rework | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.scheduled = Scheduled | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.settled = Settled | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.submitted = Submitted | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.underReview = Under Review | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.actions.verified = Verified | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.approved = Approved | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.assessed = Assessed | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.assigned = Assigned | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.cancelled = Cancelled | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.closed = Closed | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.completed = Completed | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.dispatched = Dispatched | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.displayName = External | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.draft = Draft | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.finalized = Finalized | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.inProgress = In Progress | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.inTransit = In Transit | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.initialPlatformDefault(migratedFromHardcodedTransitions) = Initial platform default (migrated from hardcoded transitions) | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.inspected = Inspected | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.issued = Issued | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.needInformation = Need Information | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.onHold = On Hold | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.partiallyReceived = Partially Received | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.pendingApproval = Pending Approval | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.pendingInspection = Pending Inspection | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.prepared = Prepared | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.procurement = Procurement | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.qcPending = Qc Pending | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.received = Received | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.rejected = Rejected | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.repair = Repair | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.repairRequired = Repair Required | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.replacement = Replacement | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.reported = Reported | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.requested = Requested | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.resolved = Resolved | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.retireRequestInfo:RemoveUnderReview>NeedInformation(appLevelOnly,EnumValueKeptForLegacyRecords) = Retire Request Info: remov | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.rework = Rework | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.scheduled = Scheduled | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.settled = Settled | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.submitted = Submitted | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.underReview = Under Review | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.verified = Verified | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| workflow.status.workflow = Workflow | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| access.fields.own = OWN | DO_NOT_TRANSLATE → translate | Uppercase display word, not an abbreviation (audit regex ^[A-Z]{2,5}$ over-matched). | Classification only | NO |
| configuration.placeholders.eGCriticalImmobilized = e.g. CRITICAL, IMMOBILIZED | REVIEW → AUTO_TRANSLATE_SAFE | Example placeholder: only "e.g." is translated, the sample value stays as is. | Classification only | NO |
| externalWorkOrderInvoice.placeholders.eGNet30 = e.g. NET 30 | REVIEW → AUTO_TRANSLATE_SAFE | Example placeholder: only "e.g." is translated, the sample value stays as is. | Classification only | NO |
| inventory.fields.tireTireSerialNumber = Tire {{tire_serial_number}} · | REVIEW → AUTO_TRANSLATE_SAFE (owner may veto) | Audit REVIEW came from the generic glossary heuristic; the term has one established Indonesian equivalent (Tire → Ban, Warranty Claim → Klaim Garansi, Wheels Configuration → Konfigurasi Roda, Hold → T | Classification only | YES |
| inventory.fields.warrantyClaim = Warranty Claim | REVIEW → AUTO_TRANSLATE_SAFE (owner may veto) | Audit REVIEW came from the generic glossary heuristic; the term has one established Indonesian equivalent (Tire → Ban, Warranty Claim → Klaim Garansi, Wheels Configuration → Konfigurasi Roda, Hold → T | Classification only | YES |
| inventory.placeholders.eGSae15w40Dot = e.g. SAE 15W-40, DOT 4 | REVIEW → AUTO_TRANSLATE_SAFE | Example placeholder: only "e.g." is translated, the sample value stays as is. | Classification only | NO |
| inventory.status.warrantyClaim = Warranty Claim | REVIEW → AUTO_TRANSLATE_SAFE (owner may veto) | Audit REVIEW came from the generic glossary heuristic; the term has one established Indonesian equivalent (Tire → Ban, Warranty Claim → Klaim Garansi, Wheels Configuration → Konfigurasi Roda, Hold → T | Classification only | YES |
| maintenance.placeholders.eGOilFilterReplacement = e.g. Oil filter replacement | REVIEW → AUTO_TRANSLATE_SAFE | Example placeholder: only "e.g." is translated, the sample value stays as is. | Classification only | NO |
| platform.bundles.placeholders.eGOptifleetCustom = e.g. OPTIFLEET_CUSTOM | REVIEW → AUTO_TRANSLATE_SAFE | Example placeholder: only "e.g." is translated, the sample value stays as is. | Classification only | NO |
| platform.tenants.placeholders.eGAcme = e.g. ACME | REVIEW → AUTO_TRANSLATE_SAFE | Example placeholder: only "e.g." is translated, the sample value stays as is. | Classification only | NO |
| procurement.help.paid = PAID | DO_NOT_TRANSLATE → translate | Uppercase display word, not an abbreviation (audit regex ^[A-Z]{2,5}$ over-matched). | Classification only | NO |
| procurement.placeholders.eGBosch = e.g. Bosch | REVIEW → AUTO_TRANSLATE_SAFE | Example placeholder: only "e.g." is translated, the sample value stays as is. | Classification only | NO |
| procurement.placeholders.eGDutro = e.g. Dutro | REVIEW → AUTO_TRANSLATE_SAFE | Example placeholder: only "e.g." is translated, the sample value stays as is. | Classification only | NO |
| tenantComponents.fields.tires = Tires | REVIEW → AUTO_TRANSLATE_SAFE (owner may veto) | Audit REVIEW came from the generic glossary heuristic; the term has one established Indonesian equivalent (Tire → Ban, Warranty Claim → Klaim Garansi, Wheels Configuration → Konfigurasi Roda, Hold → T | Classification only | YES |
| tire.fields.front = FRONT | DO_NOT_TRANSLATE → translate | Uppercase display word, not an abbreviation (audit regex ^[A-Z]{2,5}$ over-matched). | Classification only | NO |
| tire.fields.hold = HOLD | DO_NOT_TRANSLATE → translate | Uppercase display word, not an abbreviation (audit regex ^[A-Z]{2,5}$ over-matched). | Classification only | NO |
| tire.help.vehicleRegistrationNumberPositionCodeTire = {{vehicle_registration_number}} · {{position_code}} — Tire {{tire_serial_number}} | REVIEW → AUTO_TRANSLATE_SAFE (owner may veto) | Audit REVIEW came from the generic glossary heuristic; the term has one established Indonesian equivalent (Tire → Ban, Warranty Claim → Klaim Garansi, Wheels Configuration → Konfigurasi Roda, Hold → T | Classification only | YES |
| tire.placeholders.eGDotWeekYearCode = e.g. DOT week/year code 2326 | REVIEW → AUTO_TRANSLATE_SAFE | Example placeholder: only "e.g." is translated, the sample value stays as is. | Classification only | NO |
| tire.titles.tires = Tires | REVIEW → AUTO_TRANSLATE_SAFE (owner may veto) | Audit REVIEW came from the generic glossary heuristic; the term has one established Indonesian equivalent (Tire → Ban, Warranty Claim → Klaim Garansi, Wheels Configuration → Konfigurasi Roda, Hold → T | Classification only | YES |
| tire.titles.wheelsConfiguration = Wheels Configuration | REVIEW → AUTO_TRANSLATE_SAFE (owner may veto) | Audit REVIEW came from the generic glossary heuristic; the term has one established Indonesian equivalent (Tire → Ban, Warranty Claim → Klaim Garansi, Wheels Configuration → Konfigurasi Roda, Hold → T | Classification only | YES |
| vehicle.fields.wheelsConfiguration = Wheels Configuration | REVIEW → AUTO_TRANSLATE_SAFE (owner may veto) | Audit REVIEW came from the generic glossary heuristic; the term has one established Indonesian equivalent (Tire → Ban, Warranty Claim → Klaim Garansi, Wheels Configuration → Konfigurasi Roda, Hold → T | Classification only | YES |
| vehicle.sections.wheelsConfiguration = Wheels Configuration | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| warranty.titles.warrantyClaims = Warranty Claims | REVIEW → AUTO_TRANSLATE_SAFE (owner may veto) | Audit REVIEW came from the generic glossary heuristic; the term has one established Indonesian equivalent (Tire → Ban, Warranty Claim → Klaim Garansi, Wheels Configuration → Konfigurasi Roda, Hold → T | Classification only | YES |
| workOrder.fields.hold = Hold | REVIEW → AUTO_TRANSLATE_SAFE (owner may veto) | Audit REVIEW came from the generic glossary heuristic; the term has one established Indonesian equivalent (Tire → Ban, Warranty Claim → Klaim Garansi, Wheels Configuration → Konfigurasi Roda, Hold → T | Classification only | YES |
| workshopInvoice.placeholders.eGBankTransfer = e.g. Bank Transfer | REVIEW → AUTO_TRANSLATE_SAFE | Example placeholder: only "e.g." is translated, the sample value stays as is. | Classification only | NO |
| documents.workAuthorizationLetter.to = TO | DO_NOT_TRANSLATE → translate | Uppercase display word, not an abbreviation (audit regex ^[A-Z]{2,5}$ over-matched). | Classification only | NO |
| app.labels.notification = Notification | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| intelligence.reasons.allReadinessCriteriaSatisfied = all readiness criteria satisfied. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| intelligence.reasons.basedAssumedTypicalTireServiceLife = Based on an assumed typical tire service life of {{expectedLifeKm}}km minus {{usag | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| intelligence.reasons.classImbalanceTooSevereMajorityClass = class imbalance too severe: majority class ratio | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| intelligence.reasons.componentAssetsAsCa2 = component_assets as ca2 | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| intelligence.reasons.componentFeatures = Component Features | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| intelligence.reasons.componentInstallationsAsCi2 = component_installations as ci2 | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| intelligence.reasons.dataReadinessNotReady = Data readiness NOT_READY | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| intelligence.reasons.featureMissingnessTooHigh = feature missingness too high | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| intelligence.reasons.insufficientRowsNoLabelVariationAfter = Insufficient rows or no label variation after temporal train/test split. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| intelligence.reasons.metricUnusualRelativeFleetValueFleet = {{metric}} is unusual relative to the fleet (value={{a}}, fleet mean={{fleet_mea | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| intelligence.reasons.negativeValueNotPhysicallyPossible = negative value is not physically possible | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| intelligence.reasons.noDeductionsCleanRecentHistory = No deductions — clean recent history. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| intelligence.reasons.noSignificantContributingFactorsIdentified = No significant contributing factors identified. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| intelligence.reasons.observationPeriodDaysObservationPeriodDays = observation_period_days={{observationPeriodDays}} below minimum {{min_obse | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| intelligence.reasons.occurrencesRepairsSameComponentGroupBetween = {{occurrences}} repairs on the same component group between {{first_at}}  | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| intelligence.reasons.positiveCountPositiveCountBelowTarget = positive_count={{positiveCount}} below target minimum {{min_positive_count}}. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| intelligence.reasons.recommendedBasedSourceTypeRiskLevel = recommended based on {{sourceType}} = {{riskLevel}}. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| intelligence.reasons.sampleSizeSampleSizeBelowMinimum = sample_size={{sampleSize}} below minimum viable {{limited_sample_size}}, or zero pos | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| intelligence.reasons.sampleSizeSampleSizeBelowTarget = sample_size={{sampleSize}} below target minimum {{min_sample_size}}. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| intelligence.reasons.tireFeatures = Tire Features | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| intelligence.reasons.vehicleFeatures = Vehicle Features | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| intelligenceRun.reasons.outcomeEvaluationDispatched = Outcome evaluation dispatched. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| intelligenceRun.reasons.predictionRunDispatched = Prediction run dispatched. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| intelligenceTraining.reasons.trainingDispatched = Training dispatched. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| intelligenceTraining.reasons.unknownModelTarget = Unknown model target. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| notifications.fleetManager = Fleet Manager | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| notifications.initialPlatformDefault = Initial platform default | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| notifications.productNameProductSkuWarehouse = {{product.name}} ({{product.sku}}) at {{warehouse.name}} is low: {{stock.available}} availabl | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.labels.vehicleRegistrationNumberPositionCodeTire = {{vehicle_registration_number}} {{position_code}} — Tire {{tire_serial_number}} | REVIEW → AUTO_TRANSLATE_SAFE (owner may veto) | Audit REVIEW came from the generic glossary heuristic; the term has one established Indonesian equivalent (Tire → Ban, Warranty Claim → Klaim Garansi, Wheels Configuration → Konfigurasi Roda, Hold → T | Classification only | YES |
| tire.reasons.ageChemicalDamageSuspectedNotInspected = Age / chemical damage suspected or not inspected | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.belowMinimumServiceDepthDService = is below the minimum service depth D_service | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.belowPlannedRemovalDepthDPull = is at or below the planned removal depth D_pull | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.bothSidesWearCheckTirePressure = Both-sides wear: check tire pressure (under-inflation) and load. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.bulgeDeformationSeparationSuspectedNotInspected = Bulge / deformation / separation suspected or not inspected | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.centerWearCheckTirePressureOver = Center wear: check tire pressure (over-inflation). | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.confirmedBulgeDeformationSeparation = Confirmed bulge / deformation / separation. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.cordWireExposed = Cord / wire exposed. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.cordWireExposureSuspectedNotInspected = Cord / wire exposure suspected or not inspected | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.cuppingScallopingCheckSuspensionShockAbsorbers = Cupping / scalloping: check suspension (shock absorbers) and wheel balance. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.damageN = Damage {{n}} ( | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.damageNotRepairableWithinLimitsTire = Damage is not repairable within the limits for this tire category / model. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.damagesExceedMaximumMaxRepairs = damages exceed the maximum of {{max}} repairs. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.flatSpot = flat spot | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.flatSpotCheckBrakesWheelLock = Flat spot: check brakes / wheel lock-up and suspension. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.isRequired = is required. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.labelNotAnswered = {{label}} (not answered). | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.labelOverlapsPreviousRepairWhichRepair = {{label}}: overlaps a previous repair, which the repair limits do not permit. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.labelReachesReinforcingStructureWhichRepair = {{label}}: reaches the reinforcing structure, which the repair limits do not perm | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.labelRepairLimitLimitKeyNot = {{label}}: the repair limit {{limitKey}} is not configured in the rule profile. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.labelRepairsNotPermittedLocation = {{label}}: repairs are not permitted in this location. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.labelSeparation = {{label}}: separation. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.labelUnknownWhetherOverlapsPreviousRepair = {{label}}: unknown whether it overlaps a previous repair. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.labelUnknownWhetherReachesReinforcingStructure = {{label}}: unknown whether it reaches the reinforcing structure. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.leakForeignObjectNotTested = Leak / foreign object not tested | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.mmExceedsLimitLimitMm = mm exceeds the limit of {{limit}} mm. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.noActiveInspectionRuleProfileTire = No active inspection rule profile for this tire category — thresholds (D_service, D_pull, a | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.oneSidedWearCheckWheelAlignment = One-sided wear: check wheel alignment (camber / toe). | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.permanentChemicalAgeDegradationHardenedBrittle = Permanent chemical / age degradation (hardened, brittle, softened or swollen). | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.physicalSignRunFlatLowPressure = Physical sign of run-flat / low-pressure / overheat damage. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.previousRepairDoesNotMeetStandard = Previous repair does not meet the standard. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.previousRepairQuestionableNotInspected = Previous repair questionable or not inspected | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.repairEligibilityDamagesNotBeenAnswered = Repair eligibility of the damages has not been answered. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.repairLimitMaxRepairsNotConfigured = The repair limit max_repairs is not configured in the rule profile. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.runFlatLowPressureOverheatHistory = Run-flat / low-pressure / overheat history unknown | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.serialSerialNumberRestrictedPositionS = Serial {{serialNumber}} is restricted to position(s) | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.specialistDecisionRequiredNoFinalSpecialist = Specialist decision required — no final specialist result yet. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.specialistRejectedRepair = The specialist rejected the repair. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.tireAgeAgeMonthsExceedsMaximum = Tire age {{age}} months exceeds the maximum service age A_max ({{a_max_months}} months). | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.tireAgeUnknownManufactureDateCode = Tire age unknown — the manufacture date code cannot be read. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.tireCategoryUnknownSetVehicleGroup = Tire category is unknown — set the Vehicle Group of the tire product. | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.tireIdentityCategoryManufactureDateNot = Tire identity / category / manufacture date not fully verified | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.usedTireInspectionButPlannedPosition = by its used tire inspection, but is planned for {{position}}. Installation is allowed —  | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| tire.reasons.wearPatternNotInspected = Wear pattern not inspected | REVIEW → STRUCTURAL_PREP_REQUIRED | Audit REVIEW reason was data storage (seeded/DB label or persisted reason), not terminology; no glossary term involved. | Classification only | NO |
| validation.shared.mb = MB. | DO_NOT_TRANSLATE → translate | Uppercase display word, not an abbreviation (audit regex ^[A-Z]{2,5}$ over-matched). | Classification only | NO |
| common.fields.approve dynamic=PARTIAL | dynamic=NO; fragment occurrence(s) move to whole-template key(s) | Consolidation propagated PARTIAL from 1 fragment occurrence(s) to all 7 occurrences. | Key becomes AUTO_TRANSLATE_SAFE unless other blockers | NO |
| common.fields.cancel dynamic=PARTIAL | dynamic=NO; fragment occurrence(s) move to whole-template key(s) | Consolidation propagated PARTIAL from 1 fragment occurrence(s) to all 16 occurrences. | Key becomes AUTO_TRANSLATE_SAFE unless other blockers | NO |
| common.fields.partner dynamic=PARTIAL | dynamic=NO; fragment occurrence(s) move to whole-template key(s) | Consolidation propagated PARTIAL from 1 fragment occurrence(s) to all 7 occurrences. | Key becomes AUTO_TRANSLATE_SAFE unless other blockers | NO |
| account.fields.item dynamic=PARTIAL | dynamic=NO; fragment occurrence(s) move to whole-template key(s) | Consolidation propagated PARTIAL from 1 fragment occurrence(s) to all 3 occurrences. | Key becomes AUTO_TRANSLATE_SAFE unless other blockers | NO |
| common.validation.useAuthMustUsedWithinAuth = useAuth must be used within AuthProvider | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| inventory.fields.repair dynamic=PARTIAL | dynamic=NO; fragment occurrence(s) move to whole-template key(s) | Consolidation propagated PARTIAL from 1 fragment occurrence(s) to all 2 occurrences. | Key becomes AUTO_TRANSLATE_SAFE unless other blockers | NO |
| tire.placeholders.hhMm = HH:mm | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| workOrder.fields.workOrder = WorkOrder | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| accessControl.labels.permissionsUserIdTenant = permissions:user:{{id}}:tenant | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| analytics.labels.workOrder = WorkOrder | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| app.labels.contentDisposition = Content-Disposition | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| app.labels.laravel = Laravel | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| app.labels.laravelLog = Laravel Log | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| app.labels.notification dynamic=PARTIAL | dynamic=NO; fragment occurrence(s) move to whole-template key(s) | Consolidation propagated PARTIAL from 1 fragment occurrence(s) to all 2 occurrences. | Key becomes AUTO_TRANSLATE_SAFE unless other blockers | NO |
| componentAsset.labels.componentAsset = ComponentAsset | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| configuration.labels.n123FleetAvenue = 123 Fleet Avenue | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| configuration.labels.optiFleetDemoCorp = OptiFleet Demo Corp | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| configuration.labels.optiFleetDemoCorpLtd = OptiFleet Demo Corp Ltd | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| externalWorkOrderInvoice.labels.workOrderExternalInvoice = WorkOrderExternalInvoice | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| history.labels.castBreakdownsSeverityVarchar = CAST(breakdowns.severity AS varchar) | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| history.labels.castBreakdownsStatusVarchar = CAST(breakdowns.status AS varchar) | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| history.labels.castMaintenanceRequestsRequestNumberVarchar = CAST(maintenance_requests.request_number AS varchar) | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| history.labels.castMaintenanceRequestsStatusVarchar = CAST(maintenance_requests.status AS varchar) | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| history.labels.castNullAsType = CAST(NULL AS {{type}}) | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| history.labels.castQcInspectionsStatusVarchar = CAST(qc_inspections.status AS varchar) | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| history.labels.castWorkOrdersMaintenanceTypeVarchar = CAST(work_orders.maintenance_type AS varchar) | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| history.labels.castWorkOrdersStatusVarchar = CAST(work_orders.status AS varchar) | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| history.labels.castWorkOrdersWoNumberVarchar = CAST(work_orders.wo_number AS varchar) | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| intelligence.reasons.componentAssetsAsCa2 = component_assets as ca2 | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| intelligence.reasons.componentInstallationsAsCi2 = component_installations as ci2 | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| invoice.labels.insertIntoCommercialNumberSequencesSequence = INSERT INTO commercial_number_sequences (sequence_key, last_number) VALUES (?,  | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| masterData.labels.componentSubcategoryItemTypesIt2 = component_subcategory_item_types as it2 | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| tire.labels.cycleACycleNumber = 'Cycle ' \|\| a.cycle_number | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| tire.labels.cycleTCycleNumber = 'Cycle ' \|\| t.cycle_number | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| tire.labels.distinctOiTireIdOiTire = DISTINCT ON (oi.tire_id) oi.tire_id, o.operated_at as at, o.odometer | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| tire.labels.distinctTireIdTireIdTread = DISTINCT ON (tire_id) tire_id, tread_depth_mm | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
| tire.labels.whenWoStatusIn = WHEN {{wo}}.status IN ( | DO_NOT_TRANSLATE (not user-facing) | Technical string captured by the audit heuristics (SQL fragment, header name, class name, developer error, format mask or demo company name). | Remove from translation scope | NO |
