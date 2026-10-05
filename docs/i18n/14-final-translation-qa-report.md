# 14 — Final Translation QA Report

Dataset: `12-en-id-translation-dataset-final.csv` — **5275 rows** (5,240 preparation rows + 2 domain status keys + 33 workflow verb proposals). Owner decisions applied: **0** (none provided).

## Result

| Check | Count | Verdict |
|---|---:|---|
| Missing translation | 0 | PASS |
| Remaining awaiting decision | 1162 | OPEN — waits for owner decisions (07) |
| Parameter mismatch | 0 | PASS (100% integrity incl. {{x}}, {x}, :attribute) |
| Terminology inconsistency | 0 | PASS (3 documented proper-noun exceptions excluded) |
| Action/status mismatch | 36 | SOURCE ISSUE — status-form workflow action labels; verb proposals added (workflow.actionVerb.*) |
| Duplicate key collision | 0 | PASS |
| Do-not-translate violation | 0 | PASS |
| Unresolved dependency | 0 | PASS (every REVIEW row names its dependency) |
| Pluralization issue | 0 | PASS (no "(s)" in Indonesian) |
| Near-collision | 0 | RESOLVED / DOCUMENTED (below) |
| Structural blocker | 786 | TRACKED (implementation readiness, see 15) |

## Classification and readiness

| Classification | Rows |
|---|---:|
| AUTO_TRANSLATE_SAFE | 3384 |
| REVIEW | 1162 |
| STRUCTURAL_PREP_REQUIRED | 663 |
| DO_NOT_TRANSLATE | 66 |

| Translation status | Rows |
|---|---:|
| FINAL | 4047 |
| AWAITING_DECISION | 1162 |
| FINAL_UNCHANGED | 66 |

| Implementation status | Rows |
|---|---:|
| READY_AFTER_I18N_INFRASTRUCTURE | 3384 |
| BLOCKED_BY_DECISION | 1039 |
| STRUCTURAL_PREP_REQUIRED | 786 |
| NOT_APPLICABLE | 66 |

Translation readiness and implementation readiness are separate: a STRUCTURAL_PREP_REQUIRED row has a final Indonesian text (`translation_status = FINAL`) but stays `implementation_status = STRUCTURAL_PREP_REQUIRED` until its blocker is removed.

## Remaining awaiting decision by dependency

| Dependency | Rows |
|---|---:|
| `glossary.workOrder` | 135 |
| `glossary.invoice` | 112 |
| `glossary.sparePart` | 110 |
| `glossary.maintenance` | 109 |
| `glossary.vendor` | 75 |
| `glossary.workshop` | 71 |
| `glossary.tenant` | 56 |
| `glossary.workspace` | 55 |
| `glossary.retread` | 44 |
| `glossary.tread` | 42 |
| `glossary.scrap` | 38 |
| `glossary.dataScope` | 37 |
| `glossary.purchaseOrder` | 36 |
| `glossary.breakdown` | 35 |
| `glossary.quotation` | 35 |
| `glossary.mechanic` | 33 |
| `glossary.axle` | 28 |
| `glossary.finding` | 28 |
| `glossary.reuse` | 28 |
| `glossary.partRequest` | 27 |
| `glossary.goodsReceipt` | 21 |
| `glossary.intelligence` | 20 |
| `glossary.inventory` | 19 |
| `glossary.bundle` | 18 |
| `glossary.returnToVendor` | 18 |
| `glossary.casing` | 18 |
| `glossary.partner` | 17 |
| `glossary.rim` | 17 |
| `glossary.odometer` | 14 |
| `glossary.purchaseRequest` | 12 |
| `glossary.stockOpname` | 12 |
| `glossary.serviceInvoice` | 11 |
| `glossary.workAuthorizationLetter` | 9 |
| `glossary.tireSpec` | 8 |
| `correction.tire` | 7 |
| `glossary.bead` | 7 |
| `glossary.innerLiner` | 7 |
| `glossary.roadTest` | 5 |
| `glossary.dashboard` | 4 |
| `glossary.supplier` | 4 |
| `correction.warrantyClaim` | 4 |
| `glossary.sidewall` | 4 |
| `glossary.engineHour` | 3 |
| `glossary.entitlement` | 2 |
| `correction.wheelsConfiguration` | 2 |
| `correction.hold` | 1 |
| `glossary.qualityControl` | 1 |

## Pluralization review

31 rows flagged REQUIRES_PLURALIZATION. Indonesian uses no plural suffix, so every translation is a single natural form with the count parameter kept (e.g. `{count} breakdown dalam 90 hari terakhir`). The English side must move to ICU/i18next plural forms (`_one` / `_other`) at implementation; no "(s)" pattern remains in Indonesian.

| Key | English | Indonesian |
|---|---|---|
| `configuration.help.conditionConditionRulesRuleS` | Condition: {{conditionRules}} rule(s). | Kondisi: {{conditionRules}} aturan. |
| `configuration.help.stepsCountStepS` | {{stepsCount}} step(s). | {{stepsCount}} tahap. |
| `inventory.actions.createSellableCountSaleSDraft` | Create {{sellableCount}} Sale(s) (Draft) | Buat {{sellableCount}} Penjualan (Draf) |
| `inventory.help.missingSelectedTireSNoLonger` | {{missing}} selected tire(s) are no longer scrapped or are outside your data scope and were left out. | {{missing}} ban yang dipilih tidak lagi berstatus scrap atau berada di luar cakupan data Anda dan tidak disertakan. |
| `procurement.help.leadDaysDaySAfterPo` | {{leadDays}} day(s) after PO | {{leadDays}} hari setelah PO |
| `tire.help.mappedVehicleCountMappedVehicleS` | {{mapped_vehicle_count}} mapped vehicle(s) stay on their current version until updated in Vehicle Mapping. | {{mapped_vehicle_count}} kendaraan yang terpetakan tetap pada versi saat ini sampai diperbarui di Pemetaan Kendaraan. |
| `tire.help.mappedVehicleCountVehicleSMapped` | {{mapped_vehicle_count}} vehicle(s) are mapped to this configuration. They keep their current version; move them to the new version from Vehicle Mapping when ready. | {{mapped_vehicle_count}} kendaraan dipetakan ke konfigurasi ini. Kendaraan tersebut tetap pada versi saat ini; pindahkan ke versi baru dari Pemetaan Kendaraan saat siap. |
| `tire.help.notListedIncompleteVehicleDataVehicle` | Not listed: {{incomplete_vehicle_data}} vehicle(s) without Vehicle Type, Axles or Wheels on their Vehicle Detail, and {{mapped_to_other_configuration}} vehicle(s) mapped to another configuration (remove them there first). | Tidak ditampilkan: {{incomplete_vehicle_data}} kendaraan tanpa Jenis Kendaraan, Sumbu, atau Roda pada Detail Kendaraan, dan {{mapped_to_other_configuration}} kendaraan yang dipetakan ke konfigurasi lain (hapus dari sana terlebih dahulu). |
| `tire.help.unansweredCountQuestionSStillUnanswered` | {{unansweredCount}} question(s) still unanswered. | {{unansweredCount}} pertanyaan belum dijawab. |
| `tire.warnings.reuseTireRestrictedPositionSValue` | This Reuse tire is restricted to position(s) {{value}}, not {{code}}. You can still save — check the restriction before fitting. | Ban Pakai Ulang ini dibatasi untuk posisi {{value}}, bukan {{code}}. Anda tetap dapat menyimpan — periksa batasan sebelum memasang. |
| `app.labels.countBreakdownSLast30Days` | {count} breakdown(s) in the last 30 days | {count} breakdown dalam 30 hari terakhir |
| `app.labels.countBreakdownSLast90Days` | {count} breakdown(s) in the last 90 days | {count} breakdown dalam 90 hari terakhir |
| `app.labels.countComponentReplacementSLast90` | {count} component replacement(s) in the last 90 days | {count} penggantian komponen dalam 90 hari terakhir |
| `app.labels.countCriticalInspectionFindingSLast` | {count} critical inspection finding(s) in the last 90 days | {count} temuan inspeksi kritis dalam 90 hari terakhir |
| `app.labels.countDaySDowntimeLast90` | {count} day(s) of downtime in the last 90 days | {count} hari downtime dalam 90 hari terakhir |
| `app.labels.countMinuteSDowntimeLast90` | {count} minute(s) of downtime in the last 90 days | {count} menit downtime dalam 90 hari terakhir |
| `app.labels.countOverdueMaintenanceItemS` | {count} overdue maintenance item(s) | {count} item perawatan terlambat |
| `app.labels.countRepeatRepairSSameComponent` | {count} repeat repair(s) on the same component group in the last 90 days | {count} perbaikan berulang pada grup komponen yang sama dalam 90 hari terakhir |
| `app.labels.countTireReplacementSLast90` | {count} tire replacement(s) in the last 90 days | {count} penggantian ban dalam 90 hari terakhir |
| `app.labels.countWarrantyClaimSLast90` | {count} warranty claim(s) in the last 90 days | {count} klaim garansi dalam 90 hari terakhir |
| `app.labels.vehicleAgeCountDayS` | vehicle age: {count} day(s) | usia kendaraan: {count} hari |
| `errors.contract.cannotRemoveModuleModuleCodeStill` | Cannot remove module {{moduleCode}}: still required by active module(s) on this contract | Tidak dapat menghapus modul {{moduleCode}}: masih diwajibkan oleh modul aktif pada kontrak ini |
| `errors.entitlement.cannotDisableModuleWithList` | Cannot disable module {{moduleCode}}: still required by active module(s): {{modules}} | Tidak dapat menonaktifkan modul {{moduleCode}}: masih diwajibkan oleh modul aktif: {{modules}} |
| `errors.entitlement.cannotEnableModuleWithList` | Cannot enable module {{moduleCode}}: missing required module(s): {{modules}} | Tidak dapat mengaktifkan modul {{moduleCode}}: modul yang diwajibkan belum ada: {{modules}} |
| `errors.notification.unknownVariables` | Template references unknown variable(s): {{variables}} | Templat merujuk variabel yang tidak dikenal: {{variables}} |
| `errors.tire.onlyPendingReplacementTireSProduct` | Only {{pending}} replacement tire(s) of this product are still to be installed for the Tire Operation. | Hanya tersisa {{pending}} ban pengganti dari produk ini yang belum dipasang untuk Operasi Ban. |
| `errors.tire.onlyTiresReuseReplacementTireS` | Only {{tires}} REUSE replacement tire(s) of this product are still to be issued for the Tire Operation. | Hanya tersisa {{tires}} ban pengganti REUSE dari produk ini yang belum dikeluarkan untuk Operasi Ban. |
| `errors.workOrder.onlyTiresIssuedUsedTireS` | Only {{tires}} issued used tire(s) of this line are not installed. | Hanya {{tires}} ban bekas yang dikeluarkan dari baris ini yang belum dipasang. |
| `tire.reasons.repairableDamages` | {{count}} repairable damage(s) within the repair limits; tread still usable. | {{count}} kerusakan yang dapat diperbaiki dalam batas perbaikan; tapak masih layak pakai. |
| `tire.reasons.serialSerialNumberRestrictedPositionS` | Serial {{serialNumber}} is restricted to position(s) | Seri {{serialNumber}} dibatasi untuk posisi |
| `validation.accessControl.invalidPermissions` | {{count}} selected permission(s) do not exist or do not belong to the {{scope}} scope. | {{count}} izin yang dipilih tidak ada atau bukan milik cakupan {{scope}}. |

## Near-collision final review

| Pair | Domains | Final handling |
|---|---|---|
| ISSUED vs PUBLISHED | ISSUED: documents (PO/RFQ/invoice) and stock (part request); PUBLISHED: configuration | Separate keys: `status.document.issued` = Diterbitkan, `status.stock.issued` = Dikeluarkan, `status.published` = Diterbitkan (configuration domain). `status.issued` marked superseded. Needs the status registry (per domain). |
| Receive vs Accept | Receive = goods/cycle receipt; Accept = recommendation / vendor acceptance | Kept as separate keys; both read "Terima" literally. Pending style.approveAccept (owner may choose Accept → Setujui). |
| Bundle vs Package | Bundle = commercial module bundle (platform); Package = maintenance package | Separate keys. Package = "Paket" (APPROVED_AUTOMATIC). Bundle is AWAITING_DECISION (recommended "Paket", alternative "Bundle"); choosing "Paket" makes the two literally equal in different domains — choosing "Bundle" avoids it. |
| Paid vs Settled | Payment vs warranty claim | Fixed in preparation (Lunas / Diselesaikan). |

## Workflow action labels (action vs status)

| Key | English (source, status-form) | Indonesian (mirrors source) | Proposed verb key | Proposed verb (EN → ID) |
|---|---|---|---|---|
| `workflow.actions.actionLabel` | External | Eksternal | — | — |
| `workflow.actions.actionLabel2` | Revise | Revisi | — | — |
| `workflow.actions.actionLabel3` | Cancel | Batal | — | — |
| `workflow.actions.actionLabel4` | Close (External Invoice Paid) | Tutup (Invoice Eksternal Lunas) | — | — |
| `workflow.actions.actionLabel5` | In Progress | Dalam Proses | — | — |
| `workflow.actions.actionLabel6` | Qc Pending | Menunggu QC | — | — |
| `workflow.actions.actionLabel7` | Cancelled | Dibatalkan | — | — |
| `workflow.actions.approved` | Approved | Disetujui | `workflow.actionVerb.approved` | Approve → Setujui |
| `workflow.actions.assessed` | Assessed | Dinilai | `workflow.actionVerb.assessed` | Assess → Nilai |
| `workflow.actions.assigned` | Assigned | Ditugaskan | `workflow.actionVerb.assigned` | Assign → Tugaskan |
| `workflow.actions.closed` | Closed | Ditutup | `workflow.actionVerb.closed` | Close → Tutup |
| `workflow.actions.completed` | Completed | Selesai | `workflow.actionVerb.completed` | Complete → Selesaikan |
| `workflow.actions.draft` | Draft | Draf | `workflow.actionVerb.draft` | Return to Draft → Kembalikan ke Draf |
| `workflow.actions.finalized` | Finalized | Difinalisasi | `workflow.actionVerb.finalized` | Finalize → Finalisasi |
| `workflow.actions.inTransit` | In Transit | Dalam Perjalanan | `workflow.actionVerb.inTransit` | Dispatch → Kirim |
| `workflow.actions.inspected` | Inspected | Diinspeksi | `workflow.actionVerb.inspected` | Inspect → Inspeksi |
| `workflow.actions.issued` | Issued | Dikeluarkan | `workflow.actionVerb.issued` | Issue → Terbitkan / Keluarkan |
| `workflow.actions.onHold` | On Hold | Ditunda | `workflow.actionVerb.onHold` | Put on Hold → Tunda |
| `workflow.actions.pendingApproval` | Pending Approval | Menunggu Persetujuan | `workflow.actionVerb.pendingApproval` | Submit for Approval → Ajukan Persetujuan |
| `workflow.actions.prepared` | Prepared | Disiapkan | `workflow.actionVerb.prepared` | Mark Prepared → Tandai Disiapkan |
| `workflow.actions.procurement` | Procurement | Pengadaan | `workflow.actionVerb.procurement` | Send to Procurement → Teruskan ke Pengadaan |
| `workflow.actions.received` | Received | Diterima | `workflow.actionVerb.received` | Receive → Terima |
| `workflow.actions.rejected` | Rejected | Ditolak | `workflow.actionVerb.rejected` | Reject → Tolak |
| `workflow.actions.repair` | Repair | Perbaiki | `workflow.actionVerb.repair` | Repair → Perbaiki |
| `workflow.actions.repairRequired` | Repair Required | Perlu Perbaikan | `workflow.actionVerb.repairRequired` | Mark Repair Required → Tandai Perlu Perbaikan |
| `workflow.actions.replacement` | Replacement | Penggantian | `workflow.actionVerb.replacement` | Replace → Ganti |
| `workflow.actions.requested` | Requested | Diminta | `workflow.actionVerb.requested` | Request → Ajukan Permintaan |
| `workflow.actions.resolved` | Resolved | Terselesaikan | `workflow.actionVerb.resolved` | Resolve → Selesaikan |
| `workflow.actions.rework` | Rework | Pengerjaan Ulang | `workflow.actionVerb.rework` | Send to Rework → Kirim ke Pengerjaan Ulang |
| `workflow.actions.scheduled` | Scheduled | Terjadwal | `workflow.actionVerb.scheduled` | Schedule → Jadwalkan |
| `workflow.actions.settled` | Settled | Diselesaikan | `workflow.actionVerb.settled` | Settle → Selesaikan |
| `workflow.actions.submitted` | Submitted | Diajukan | `workflow.actionVerb.submitted` | Submit → Ajukan |
| `workflow.actions.underReview` | Under Review | Dalam Peninjauan | `workflow.actionVerb.underReview` | Start Review → Mulai Peninjauan |
| `workflow.actions.verified` | Verified | Terverifikasi | `workflow.actionVerb.verified` | Verify → Verifikasi |
| `workflow.actions.waitingPart` | Waiting Part | Menunggu Suku Cadang | `workflow.actionVerb.waitingPart` | Wait for Part → Tunggu Suku Cadang |
| `workflow.actions.workOrderCreated` | Work Order Created | Work Order Dibuat | `workflow.actionVerb.workOrderCreated` | Create Work Order → Buat Work Order |

Structural correction (not implemented): seed/store `action_label` per `action_code` as a verb; the button renders the verb key, the status badge renders the status key.

## Correction proposals processed (309 from 11)

| Correction type | Count | Handling in final dataset |
|---|---:|---|
| Not user-facing (SQL, class names, dev error, demo names) | 31 | APPLIED — DO_NOT_TRANSLATE, text unchanged |
| Wrong DO_NOT_TRANSLATE (PAID, HOLD, FRONT, TO, OWN, STALE, MB.) | 7 | APPLIED — translated |
| Fragment flag over-propagation | 6 | APPLIED — key not fragment; fragment occurrences use whole templates |
| Audit REVIEW caused by DB storage (no business term) | 231 | APPLIED — STRUCTURAL_PREP_REQUIRED (DATABASE_LOCALIZATION) |
| Example placeholders ("e.g.") | 13 | APPLIED — only "e.g." translated |
| Tire / Warranty Claim / Wheels Configuration / Hold downgrade | 14 | **NOT APPLIED — REVIEW_REQUIRED**; rows back to AWAITING_DECISION (`correction.*`) |
| Cancel semantic split, status.issued split, Select…/month categories, framework rows, whole templates, missing prefix | 7 | Translation-neutral items APPLIED; key split & status split documented REVIEW_REQUIRED for implementation |

## Canonical value integrity

No canonical value was changed: status codes, enum values, permission keys, routes, template variables and numbering tokens appear unchanged in every Indonesian text where they occur in English (checked by the do-not-translate scan). Status display labels are new key values only.

