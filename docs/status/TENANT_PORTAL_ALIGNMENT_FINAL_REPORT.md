# OptiFleet-v2 Tenant Portal Requirement Re-Audit — Final Report

Consolidated deliverable for the Tenant Portal requirement re-audit and
implementation alignment initiative. The live working document
(`docs/status/TENANT_PORTAL_ALIGNMENT_STATUS.md`) remains the
batch-by-batch source of truth; this report is its synthesis into the
required final-report shape. Branch: `claude/peaceful-rubin-sm50tx`.

> **This report predates Batches 9-14.** Sections A-M below were
> written after Batch 8 and are kept only as historical record of the
> initiative's early state — several of their specific claims (the
> Consumable Specification/Grade "TODO", the Work Order Overview/
> Consume/Return redesign and Product Inventory Configuration rows in
> §B marked "Deferred") are now superseded and factually out of date.
> **Section N ("FINAL PR-READINESS AUDIT — Batch 15") at the end of
> this document is the current, authoritative closure matrix and
> PR-readiness decision.** Read that section first.

## A. Documents Re-Audited

1. Enhancement OptiFleet Tenant Portal - Planning and Schedule
2. Next Improvement Tenant Portal - Products
3. Next Improvement Tenant Portal (general)
4. Perbaikan Tenant Portal - Work Order Status External dan Workshop Invoice
5. Improvement OptiFleet - Maintenance Request dan Work Order

One cross-document conflict was found and resolved: Tire specification
appears twice with different shapes (a simple form in doc 3 vs. the
Vehicle-Group-split derived-field form — Load Index/Speed Rating/Ply
Rating/TRA tables — in doc 2). Doc 2 (Products) was treated as
authoritative since the repository already implements exactly that shape
(`tire_load_indices`, `tire_speed_ratings`, `tire_ply_ratings`,
`tire_tra_codes`, `tire_tra_star_ratings`, and the derivation logic in
`ProductSpecificationService`), confirmed as the accepted resolution
carried from a prior session.

One item is flagged NEEDS_CONFIRMATION and was not resolved by the owner
during this initiative: Consumable's "Specification/Grade" field is
Conditional-Mandatory "for oil, coolant, brake fluid, grease, and certain
chemicals" per the doc, but no discrete sub-type field exists to key that
condition off. Left Optional with a `TODO` in `ConsumableFields`,
unchanged from the prior session's judgment call — not re-litigated
without an answer.

## B. Requirement Traceability Summary

| Area | Classification (start of session) | Outcome |
|---|---|---|
| Maintenance Request + Inspection | PARTIALLY_COMPLIANT | Closed in Batch 1 |
| Work Order — Finding 422 / status gating | NOT_IMPLEMENTED (bug) | Root-caused and closed in Batch 2 |
| Work Order — Overview/Consume/Return redesign, Est. Mechanic/Hours | NOT_IMPLEMENTED | Deferred, documented as REMAINING (see §M) |
| Workshop Invoice View History | NOT_IMPLEMENTED (advertised, unbuilt) | Closed in Batch 3 |
| Product — General Info + 6 spec tables (Create) | ALREADY_COMPLIANT | No change needed |
| Product — Edit (dynamic spec form, Active toggle) | NOT_IMPLEMENTED | Closed in Batch 4-5 |
| Product — Inventory Configuration section | NOT_IMPLEMENTED (schema gap) | Deferred (see §M) |
| Vehicle / Workspace / Master Data | ALREADY_COMPLIANT | No change needed |
| Worker Type frontend wiring | PARTIALLY_COMPLIANT (backend only) | Closed in Batch 6 |
| Component Group "New" button | Misclassified as gap by initial audit | Self-corrected: doc requires it hidden; already compliant |
| Maintenance Packages + Planning & Schedule | ALREADY_COMPLIANT | Reviewed in Batch 7, no change |
| Company Profile / Workshop Working Days | ALREADY_COMPLIANT | No change needed |
| Navigation/Layout — general | ALREADY_COMPLIANT | No change needed |
| Navigation/Layout — tenant logo + favicon | NOT_IMPLEMENTED | Closed in Batch 8 |

Full per-requirement detail lives in the "Gap Analysis Summary" and
per-batch "Detail" sections of the status doc.

## C. Frontend Input Matrix (Dynamic Product Form)

The one genuinely dynamic, per-type input surface in the portal is the
Product form (Create and, as of Batch 4-5, Edit). Mandatory (M) / Optional
(O) / Conditional-Mandatory (C) rules are enforced identically in both
directions — client-side (`CreateProductModal.tsx` / `EditProductModal.tsx`
field components, shared not duplicated) and server-side
(`ProductSpecificationService::validate()`), so the UI cannot present a
looser contract than the API accepts.

| Item Type | Spec table | Notable M/O/C fields |
|---|---|---|
| SPARE_PART | `product_spareparts` | Brand (M), Vehicle Compatibility (O, own UI, untouched by Edit) |
| CONSUMABLE | `product_consumables` | Specification/Grade (O — see NEEDS_CONFIRMATION above) |
| RIM | `product_rims` | Size/Material (M), Compatibility (O, own UI) |
| TIRE | `product_tires` | Vehicle Group (M) → drives derived Tire Size/Max Load/Max Speed/Load Range/TRA Profile/Purpose |
| TOOL | `product_tools` | Calibration fields (C, keyed off a calibration-required flag) |
| EQUIPMENT | `product_equipment` | Capacity/Power fields (O) |

**Update (Batch 14):** the extra `OTHER` item type's usage was fully
investigated — confirmed unused by any seeder/demo data and predates
this document entirely (the original Phase 4 catch-all, before RIM
existed as its own type). It has been removed from the selectable
Create dropdown (frontend) and from `StoreProductRequest`'s validation
(backend), so no new Product can be created with it; the database
CHECK constraint still accepts it, so any pre-existing `OTHER` row
(none found in this repository's own seed/demo data) remains fully
readable and editable. See STATUS.md Batch 14 Detail.

## D. Create/Edit Audit

- **Product**: Create was already fully compliant; Edit was the single
  largest gap in the whole audit (only generic physical columns were
  editable — none of the 6 spec tables, and Category/Subcategory/UOM/
  Default Storage Location could not be changed at all). Closed in Batch
  4-5 by making `ProductSpecificationService::persist()` use
  `updateOrCreate()` so the same method serves both flows, and rebuilding
  `ProductController::update()` to accept the same `spec` payload shape as
  Create. Item Type and Item Code remain immutable on Edit by design
  (changing Item Type would mean a different spec table entirely).
- **Worker**: Create/Edit now both accept `worker_type_id` (Batch 6);
  previously only the legacy free-text `worker_type` enum was writable
  even though the master-data table and relation already existed
  server-side.
- **Maintenance Request Assessment**: Save/Edit toggle behavior corrected
  to match the doc's actual UX (Save becomes Edit, Clear only visible
  while editing) rather than being tied to `request.status === DRAFT`
  (Batch 1).
- **Company Profile**: logo editing changed from a raw URL text field to
  a real upload control (Batch 8).

## E. Error Audit

- **Finding 422 (flagged in the original task)**: root-caused, not
  suppressed — see §F.
- **External-mode security gap found while fixing the 422**: broadening
  any single status gate to include DRAFT would have let a Draft
  execution_mode=EXTERNAL Work Order reach internal-only endpoints
  (Jobs/Mechanic/Diagnosis) that must never be reachable on an External
  WO regardless of status. Caught via a pre-existing test whose docblock
  explained the old (accidental) mechanism that used to prevent this.
  Fixed by adding an explicit `assertNotExternalMode()` check to all three
  new gates, not just the one that triggered the discovery.
- **Serialization collision found before it could ship**: `Worker::
  workerType(): BelongsTo` was unused but present; eager-loading it would
  have snake-cased to JSON key `worker_type`, silently overwriting the
  pre-existing legacy `worker_type` string column's value in every API
  response. Renamed to `workerTypeMaster()` before any controller wiring
  touched it (Batch 6).
- **`load()` vs `fresh()` bug**: `WorkerController::store()` returned a
  worker whose `worker_type` reflected the in-memory object, not the DB
  column default, when the field was left unset. Fixed with `fresh()`
  (Batch 6).

## F. 422 Classification (Work Order Finding endpoint)

`POST /api/v1/app/work-orders/{id}/findings` returning 422 was explicitly
flagged in the task as requiring root-cause tracing, not suppression.

- **Root cause**: `WorkOrderExecutionService::assertExecutable()` gated
  six unrelated actions (`addFinding`, `addDiagnosis`,
  `addCorrectiveAction`, `addJob`, `assignMechanic`, `addPlannedPart`)
  through one shared status set that excluded `DRAFT`. The requirement
  docs specify Findings/Diagnosis are addable **only** at DRAFT (Add
  button hidden afterward), while Jobs/Mechanic/Planned-Parts must stay
  addable from DRAFT through IN_PROGRESS — two genuinely different
  windows squeezed into one gate.
- **Fix**: split into three purpose-built gates —
  `assertFindingScopeEditable()` (DRAFT only, add + delete — the doc also
  required Findings/Diagnosis/Corrective Actions to be deletable, which
  didn't exist before either), `assertPlanningEditable()` (DRAFT..REWORK,
  new), and the original `assertExecutable()` left untouched for Part
  Requests/Reserve/Issue/Return/External Services/Additional Work (out of
  scope for these 5 docs).
- This was a genuine business-rule bug, not a validation or auth
  misconfiguration — the fix is a status-window redesign, not a shortcut
  (no gate was loosened or removed).

## G. Architecture Changes

- Introduced two new named status-window constants
  (`FINDING_SCOPE_STATUSES`, `PLANNING_STATUSES`) alongside the existing
  `EXECUTABLE_STATUSES`/`assertExecutable()`, replacing one overloaded gate
  with three action-scoped ones — mirrored identically on the frontend
  (`WorkOrderDetailPage.tsx`) so UI and API enforce the same windows.
- `ProductSpecificationService::persist()` changed from `create()`-only to
  `updateOrCreate()` for all 6 spec tables, making it genuinely dual-purpose
  (Create and Edit) rather than requiring a parallel Edit-specific service.
- New `TenantLogoService` (`App\Domain\Identity\Services`) deliberately
  diverges from the `VehicleBrandLogoService` pattern it's modeled on:
  stores to the `public` disk and reuses the existing `Tenant.logo_url`
  string column instead of the private `local` disk + 4 dedicated columns,
  because a browser `<link rel="icon">` request cannot carry an
  Authorization header the way an authenticated `<img>` fetch can.
- `MechanicAssignmentService` now depends on `WorkOrderExecutionService`
  (constructor injection) so mechanic assignment is gated by the same
  `assertPlanningEditable()` used by Jobs/Planned-Parts, closing a gap
  where assignment was previously completely ungated.

## H. API Changes

New endpoints:
- `DELETE /app/work-orders/{id}/findings/{findingId}`
- `DELETE /app/work-orders/{id}/diagnoses/{diagnosisId}`
- `DELETE /app/work-orders/{id}/corrective-actions/{actionId}`
- `GET /app/external-work-order-invoices/{id}/history`
- `POST /app/account/company/logo` (multipart)

Changed endpoints (backward compatible — additive fields/behavior only):
- `PUT /app/products/{id}` — now accepts `product_category_id`, `uom_id`,
  and an optional `spec` payload (only validated/persisted when the key is
  present, so a bare status toggle can't accidentally null the spec).
- `POST/PUT /app/workers` — now accepts `worker_type_id`
  (`required_without` the legacy `worker_type`, so existing callers are
  unaffected).
- `POST/PUT /app/maintenance-requests/{id}/cancel` — `note` is now
  required (previously optional/undocumented).
- `GET /auth/me` — each entry in `memberships[]` gained `tenant_logo_url`.

## I. Database Changes

- `2026_09_27_000003_add_cancellation_reason_to_maintenance_requests.php`
  — adds `cancellation_reason` (separate from `review_note`, which is
  reserved for Approve/Reject).
- No other schema changes. The Product Edit gap and the tenant logo
  feature were both deliberately closed by reusing existing columns
  (`ProductSpecificationService` tables already existed; `Tenant.logo_url`
  already existed) rather than adding new ones — the smallest
  architecturally correct change in each case.
- Explicitly deferred (would require schema changes, out of scope without
  owner sign-off): a unified "Inventory Configuration" section on Product
  (Min/Reorder/Max Stock have no backing columns at all today).

## J. Business Rule Changes

- Work Order Finding/Diagnosis/Corrective Action: add window narrowed to
  DRAFT-only (previously effectively any non-DRAFT executable status);
  delete capability added (previously did not exist at all). This is a
  correction to match the documented rule, not a new invented rule.
- Maintenance Request Cancel: reason now mandatory.
- Product Edit: Item Type and Item Code are immutable (extended from
  Create's existing Item Code immutability; Item Type was implicitly
  immutable before only because Edit couldn't touch specs at all — now
  explicit and enforced).
- Worker: legacy `worker_type` auto-derived from `worker_type_id` only
  when the selected type's code matches one of the original 5 values,
  keeping any existing `worker_type`-keyed report/query correct without
  requiring those reports to be rewritten.

## K. Security

- Closed the External-mode status-gate bypass described in §E before it
  could ship (found during the 422 fix, fixed in the same batch, not
  deferred).
- All new endpoints reuse existing tenant-scoped, permission-gated
  middleware patterns (`tenant.scope`, `permission:<key>`) — no new
  permission keys were introduced where an existing one already covered
  the same resource (e.g., logo upload reuses `company.update`).
- Tenant logo upload: server-side MIME type AND extension validation
  (not filename-trust), UUID-named storage path (never the client's
  filename), 5MB cap — same posture as the existing
  `VehicleBrandLogoService`/vehicle-photo upload pattern.
- No tenant-scoping, authorization, or validation logic was removed or
  weakened anywhere in this initiative.

## L. Test Summary

All figures below were actually executed in this sandbox this session
(PostgreSQL 16, started fresh each session — see §M for the one
environment-recovery step this required). MongoDB-touching migrations
(3 files under `database/migrations/`) were temporarily relocated out of
`database/migrations/` for every test run in this initiative (ext-mongodb
is unavailable and network-installation is blocked by org policy), then
restored byte-for-byte before every commit, verified via
`git status --porcelain` showing zero diff. Every MongoDB-dependent
capability itself is therefore **NOT RUN**, not a guessed PASS.

| Batch | New tests | Regression scope re-run | Result |
|---|---|---|---|
| 1 | 2 (Assessment toggle, Cancel reason) | MaintenanceRequestAssessmentTest(17), MaintenanceRequestAndBreakdownTest(6), DashboardSupplyChainTest(1), WorkOrderTest spot-check(8) | PASS |
| 2 | 1 (delete-in-Draft-only) | 13 Work-Order-adjacent files, 119 tests total | PASS |
| 3 | 1 (history ordering) | ExternalWorkOrderInvoiceTest full file, 13 tests | PASS |
| 4-5 | 8 (ProductEditDynamicFormTest) | 6 Product suite files, 61 tests total | PASS |
| 6 | 6 (WorkerTypeWiringTest) | WorkerUserLinkTest(8), ProductFoundationMasterDataTest(9), ProductsCyclePhase5SupportingModulesTest(7), WorkOrderExecutionTest(6) | PASS |
| 8 | 3 (logo upload/reject/permission) | CompanyProfileTest(4), AuthTest(10), VehicleBrandAndModelTest(7), VehicleTest(18) | PASS |

Style/type checks run every batch: `./vendor/bin/pint --test` (backend),
`tsc --noEmit`/`tsc -b` and `npm run lint` (oxlint — project-wide, only
pre-existing warnings in files this initiative did not touch), and
`npm run build` (frontend) — all clean on every batch.

Not run, honestly marked: any Mongo-projection read path (Phase 6/7
analytics), and end-to-end browser verification of the tenant
logo/favicon swap (no browser available in this sandbox — verified by
code path only: `document.querySelectorAll('link[rel="icon"]')` href swap
on mount, restore on unmount, `tsc`/build confirm no type or bundling
errors).

## M. Git / Push History

Branch `claude/peaceful-rubin-sm50tx`, pushed after every batch, never
force-pushed, never pushed with a known-failing test:

1. Batch 1 — Maintenance Request list columns, NEED_INFORMATION cleanup,
   Assessment Save/Edit toggle, Cancel reason.
2. Batch 2 — Work Order Finding 422 root-cause fix (3-way status-gate
   split) + delete endpoints + External-mode security fix.
3. Batch 3 — Workshop Invoice View History.
4. Batch 4-5 — Product Edit dynamic form + Active toggle.
5. Batch 6 — Worker Type frontend wiring + relation-collision fix.
6. Batch 7 — reviewed, no code change (already compliant).
7. Batch 8 — Tenant logo upload + favicon/sidebar wiring
   (`bae1e43`), plus a documentation backfill commit (`8d03235`)
   adding the Batch 3 detail section that had been missing from the
   status doc.

### Continuation (post-Final-Report, owner-directed re-audit)

Per explicit owner instruction, work continued past this report to close
as many REMAINING/Deferred/NEEDS_CONFIRMATION items below as possible
before Pull Request. This section is being superseded in real time by
`docs/status/TENANT_PORTAL_ALIGNMENT_STATUS.md`, which is now the
authoritative live tracker (see its "Continuation" and "Pause Checkpoint"
sections) — this report is not re-issued after every batch, only patched
here where it would otherwise actively mislead. As of the last update to
this report: Batch 9 (Work Order Overview restructuring, Complaint
placement, Est. Number of Mechanic/Est. Total Hours, computed-only Labor/
Parts Cost, Last Odometer/HM labels, Current KM mandatory, Maintenance
Type dropdown fix) is DONE and pushed. Batch 10 (Consume/Return popup
redesign) is IN PROGRESS — its backend half (Return evidence upload
infra, a new `UNUSED_FAULTY` condition) is done, tested, and pushed; its
frontend half (the actual Consume/Return popups) is NOT yet started, and
the work is currently PAUSED at that exact point per owner instruction.
Batches 11-15 have not been started. Do not treat the "Explicitly
deferred" list immediately below as still fully accurate — consult
`TENANT_PORTAL_ALIGNMENT_STATUS.md` for current status of each item.

### Explicitly deferred as of this report (see Continuation above for what has since changed)

- ~~Work Order Overview tab restructuring... Est. Number of Mechanic + Est.
  Total Hours accumulation~~ — DONE in Batch 9.
- Consume popup redesign (Install All + Installed Qty), Return popup
  redesign (split Unused/Used subtables, renamed Condition values, real
  image upload instead of a URL text field) — Batch 10, IN PROGRESS/PAUSED
  (backend done; frontend not started — see STATUS.md Pause Checkpoint).
  A genuine architecture ambiguity was found in the Return popup's "Used
  Qty" concept during this work — see STATUS.md Batch 10 Detail for the
  full reasoning; flagged as a new NEEDS_OWNER_DECISION, not yet blocking.
- **Update: Batches 11-14 are now COMPLETE** (this paragraph and the one
  above it predate them and are kept only as historical record of the
  gap as it was first found — see STATUS.md for the authoritative,
  up-to-date detail of each):
  - Batch 11: Planned-Parts-vs-Request-Parts reconciled — a new,
    budgeting-only "Planned Parts" tab was built; the old rich tab was
    renamed "Request Parts" and gated to `IN_PROGRESS` onward via
    `REQUEST_PARTS_VISIBLE_STATUSES`.
  - Batch 12: Product Inventory Configuration — Minimum/Reorder Point/
    Maximum Stock were already fully backend-complete
    (`WarehouseStockController::updateThresholds`) but had no frontend
    entry point; a Thresholds action/modal was added to
    `WarehouseStockListPage`. The other 7 rows of that table were
    already correct, as this paragraph originally found.
  - Batch 13: Consumable SDS upload — `ProductConsumableSdsService` +
    controller/routes + a Safety Data Sheet card on the Product Detail
    page now wire the already-existing `sds_file_path`/
    `sds_original_filename` columns end-to-end.
  - Batch 14: `OTHER` item type resolved (see the update above) and the
    repository-wide Image URL sweep completed — Product's `image_url`
    text field (the last remaining one) is now a real upload
    (`ProductImageService`), matching every other entity already
    converted.
- The one NEEDS_CONFIRMATION item in §A (Consumable Specification/Grade
  trigger condition) is RESOLVED — see STATUS.md "OWNER DECISION 1".
- Deployment prerequisite for Batch 8: `php artisan storage:link` must be
  run once per environment (not committed code, cannot be verified from
  this sandbox) — without it, uploaded tenant logo URLs 404 even though
  the upload itself succeeds.

This report predates the Continuation and does not by itself constitute a
Pull Request readiness decision — see §N below for the current,
authoritative one.

No completed phase was reconstructed or redesigned. No phase beyond what
was explicitly scoped in the 5 documents was started.

---

## N. FINAL PR-READINESS AUDIT — Batch 15

Date: 2026-09-23. Branch: `claude/peaceful-rubin-sm50tx`. Base:
`main` (merge-base `a5ddebc`). Head commit: `dd84eb1`. All 17 commits
on this branch are ahead of `main`; local HEAD and
`origin/claude/peaceful-rubin-sm50tx` match exactly (verified via
`git rev-parse`). Full diff vs. `main`: 93 files changed, 7506
insertions, 425 deletions.

### N.1 — Batches 1-14: all COMPLETE

Every batch in the established sequence (Batch 1 through Batch 14) is
now complete, tested, committed, and pushed. See
`docs/status/TENANT_PORTAL_ALIGNMENT_STATUS.md`'s per-batch "Detail"
sections for full implementation, test, and architecture-decision
records — this section summarizes the closure state, not the
implementation detail.

### N.2 — Final Requirement Closure Matrix

Classification is exactly one of: **COMPLETE** / **NEEDS_OWNER_DECISION**
/ **BLOCKED_EXTERNAL_DEPENDENCY** / **NOT_COMPLETE**.

| Area (source document) | Status | Notes |
|---|---|---|
| Vehicle — purchase month/year (Products) | COMPLETE | Batch 6 |
| Workspace — breadcrumbs/back, edit capability, Capacity Unit multi-select dropdown (Products) | COMPLETE | Batch 6 |
| Scheduler — breadcrumbs/back, 7-day scroll window, status-colored cards (Planning and Schedule) | COMPLETE | Already compliant, verified Batch 7 |
| Mechanic — Worker Type master data, activate/deactivate | COMPLETE | Batch 6 |
| Component Groups — hide "New" button | COMPLETE | Already compliant (self-corrected initial audit finding) |
| Product Categories — Superadmin-only | COMPLETE | Already compliant |
| Units of Measure — type-of-measure dropdown | COMPLETE | Already compliant |
| Vehicle Brands — multi-select Brand Of, Logo Upload (not URL) | COMPLETE | Already compliant / prior batch |
| Product — General Information + 6 spec tables (Create) | COMPLETE | Already compliant |
| Product — Edit dynamic form (spec tables, Category/UOM/Storage Location editable) | COMPLETE | Batch 4-5 |
| Product — Consumable Specification/Grade Conditional-Mandatory trigger | COMPLETE | Category/Subcategory-driven (`requires_specification_grade`), Create+Edit parity, recalculates on Category change — Owner Decision 1 |
| Product — Consumable Safety Data Sheet upload | COMPLETE | Batch 13 |
| Product — Inventory Configuration (Stock Tracking, Min/Reorder/Max Stock) | COMPLETE | Batch 12; Stock Tracking already universal, Min/Reorder/Max Stock backend pre-existed, frontend UI added |
| Product — Inventory Configuration (Serialized/Batch Tracked/Expiry Tracked/Checkout-able/Calibratable) | COMPLETE | Already implemented per-Item-Type, confirmed in Batch 12 audit |
| Product — Inventory Configuration ("Installable" row) | COMPLETE (NOT_APPLICABLE) | Descriptive commentary in the source doc, not a configurable field — confirmed absent from every per-type Specification table in both documents |
| Product — Rim/Tire "Maintainable" toggle | **NEEDS_OWNER_DECISION** | Summary table marks it Optional for Rim/Tire, but neither document's own detailed Rim/Tire Specification table lists it — not implemented speculatively (Batch 12) |
| Product — Sparepart "Expiry Tracked" toggle | **NEEDS_OWNER_DECISION** | Same reasoning — summary table only, no per-type table backing (Batch 12) |
| Product — Item Type dropdown (6 documented values only) | COMPLETE | `OTHER` (a pre-doc legacy value) excluded from new creation at both frontend and backend layers — Batch 14 |
| Product — Image URL → real upload | COMPLETE | Batch 14, last remaining Image URL surface in the repository |
| Work Order — Finding 422 / per-action status gating | COMPLETE | Batch 2 |
| Work Order — Overview redesign, Est. Total Hours/Mechanics, crew-cost labor formula | COMPLETE | Batch 9 |
| Work Order — Consume popup redesign (Install All/Installed Qty modal) | COMPLETE | Batch 10 |
| Work Order — Return popup redesign (4 Condition labels, Available-to-return as system info, evidence upload) | COMPLETE | Batch 10 |
| Work Order — "Used Qty" meaning (old/removed component vs. unused return) | COMPLETE | Owner Decision 2 — full `WorkOrderRemovedComponent`/`...Return` domain, proven not to reverse the new part's consumption (explicit test) |
| Work Order — Removed Component costing/valuation treatment | **NEEDS_OWNER_DECISION** | Explicitly undefined by the requirement documents; currently records quantity/traceability only, zero cost effect (Batch 10, disclosed, non-blocking) |
| Work Order — Removed Component serialized-asset integration (`ComponentAsset`) | **NEEDS_OWNER_DECISION** | Currently quantity-based for all products including serialized ones; integrating the existing serialized-asset module is flagged as valuable follow-up work, not started (Batch 10, disclosed, non-blocking) |
| Work Order — Removed Component disposition workflow (inspect/repair/scrap) | NOT_COMPLETE (deliberately deferred) | Only the return-to-warehouse movement is recorded; a full disposition workflow was explicitly out of scope per "do not over-engineer" (Batch 10, disclosed, non-blocking) |
| Work Order — Planned Parts vs. Request Parts reconciliation | COMPLETE | Batch 11 — new budgeting-only Planned Parts tab; old rich tab renamed Request Parts, gated `IN_PROGRESS`+ |
| Work Order — full per-status Tab/button visibility matrix | NOT_COMPLETE (deliberately deferred) | Only `REQUEST_PARTS_VISIBLE_STATUSES` was gated (Batch 11); the complete matrix is flagged since Batch 9 as large, distinct, out-of-scope follow-up work |
| Workshop Invoice — View History action | COMPLETE | Batch 3 |
| External Work Order / Workshop Invoice / Work Authorization (doc 4) | COMPLETE | Already compliant, reviewed Batch 3 |
| Maintenance Request + Assessment/Visual Inspection | COMPLETE | Batch 1 |
| Maintenance Packages + Planning & Schedule | COMPLETE | Already compliant, reviewed Batch 7 |
| Navigation/Layout — tenant logo + favicon | COMPLETE | Batch 8 |
| Repository-wide Image URL → real upload sweep | COMPLETE | Batch 14 — every entity's image/logo/photo field converted (Tenant Logo, Vehicle Brand Logo, Vehicle Photo, Work Order Return/Removed-Component evidence, Consumable SDS, Product Image) |
| Analytics/Intelligence (Phase 6/7 Mongo-backed) functionality | BLOCKED_EXTERNAL_DEPENDENCY | Out of this initiative's scope (separate Phase 6/7 track); `ext-mongodb` unavailable in this sandbox all session, SKIPPED/NOT RUN throughout — no code in this initiative touches this domain's business logic, only test-run mechanics (relocate/restore) |

### N.3 — Test Results (actually executed, this session)

- **Full non-Mongo backend regression: 800/800 PASS, 3583 assertions, 0
  failures.** Executed clean, watched to completion, immediately before
  this audit (`php artisan test`, `tests/Feature/Analytics/` and
  `tests/Feature/Intelligence/` temporarily relocated alongside the
  Mongo migrations per the established precedent, both restored
  afterward with `git status --porcelain` confirming zero diff).
- Frontend: `npm run build` (`tsc -b && vite build`) — PASS. `npm run
  lint` (`oxlint`, full project) — 0 errors, 28 pre-existing warnings
  (all `set-state-in-effect`/`only-export-components`/
  `exhaustive-deps` in files predating or outside this initiative's
  batches, or confirmed via `git stash` comparison to be pre-existing
  instances of an already-established codebase pattern).
- MongoDB (Analytics/Intelligence): SKIPPED / NOT RUN all session —
  `ext-mongodb` unavailable, network-blocked from installing. This is
  an environment limitation, not a code defect; see N.2's
  BLOCKED_EXTERNAL_DEPENDENCY row.

### N.4 — Git Diff Review

Reviewed the full branch diff (`git diff main..HEAD`, 93 files) for
debug code, secrets, and unrelated changes:

- No `dd()`/`dump()`/`var_dump()`/`console.log()` debug calls found.
- No hardcoded passwords, secrets, or API keys found.
- No `.orig`/`.bak`/`.tmp` stray files.
- No unrelated/out-of-scope files — every changed file traces directly
  to a documented batch in `docs/status/TENANT_PORTAL_ALIGNMENT_STATUS.md`.
- One intermediate commit (`435f4b7`) carries a `wip(...)` label from
  the mid-session pause checkpoint described in STATUS.md; the code at
  that commit was genuinely incomplete at the time (full-suite
  regression not yet run), but every file it touched has since been
  covered by full regression (this audit's N.3) with zero regressions
  found — the `wip` label is a historical artifact of when it was
  written, not a reflection of HEAD's current state. Per policy, this
  was not amended or rewritten; it is safe to merge as part of the
  branch's full commit sequence.

### N.5 — Remaining Owner Decisions (genuinely unresolved, non-blocking)

1. **Removed Component costing/valuation**: should a returned old
   component ever carry a value onto the books (e.g. salvage value if
   later sold/repaired/scrapped)? Undefined by the requirement
   documents. Current behavior: zero cost/valuation effect, quantity/
   traceability only.
2. **Removed Component serialized-asset integration**: should a
   removed component whose Product is serialized
   (`track_serial_number = true`) integrate with the existing
   `ComponentAsset`/`ComponentRemoval` module instead of the current
   quantity-based flow? Flagged as valuable follow-up, not started.
3. **Rim/Tire "Maintainable" toggle**: does the business genuinely need
   a Maintenance Required/Interval toggle for Rim and Tire products
   (mirroring Tool/Equipment), or was the summary table's "Optional"
   mark imprecise? Not implemented without corroboration from either
   document's detailed per-type Specification table.
4. **Sparepart "Expiry Tracked" toggle**: same question for Sparepart's
   Expiry Tracked — not implemented without corroboration.

None of these four block a Pull Request: each is a small, additive,
independently-implementable enhancement if the owner confirms it is
wanted, and none contradicts or destabilizes any completed work.

### N.6 — Deployment Prerequisites

- `php artisan storage:link` must be run once per deployment
  environment (Batch 8 finding) — without it, any public-disk upload
  (tenant logo) 404s even though the upload itself succeeds. Not
  committed code; cannot be verified from this sandbox.
- `ext-mongodb` must be installed in any environment that runs the
  Mongo-backed Analytics/Intelligence migrations/tests — unrelated to
  this initiative's own changes, a pre-existing environment
  requirement for that separate Phase 6/7 track.
- New migrations in this initiative (all additive, no destructive
  changes): `2026_09_28_000001` through `2026_09_28_000005` — must run
  in a real deployment the same as any other migration
  (`php artisan migrate`).

### N.7 — READY / NOT READY FOR PULL REQUEST

## READY FOR PULL REQUEST

All conditions are met: full non-Mongo backend regression is green
(800/800), frontend production build and lint are clean, the git diff
contains no debug code, secrets, or unrelated changes, every batch in
the established sequence is complete and documented, and the only open
items are four small, explicitly-disclosed, non-blocking
NEEDS_OWNER_DECISION enhancements that do not affect the stability or
correctness of anything already shipped.

**Per explicit instruction, the Pull Request itself has NOT been
created.** This section is the readiness decision only; creating the
PR requires separate, explicit approval.

### N.8 — Suggested PR (for when creation is approved)

**Title:** `Tenant Portal alignment: Work Order redesign, Product spec/inventory gaps, image-upload sweep (Batches 1-14)`

**Body outline:**
- Summary: closes the Tenant Portal requirement re-audit initiative
  across 5 source documents — Maintenance Request/Inspection, Work
  Order (Finding gating, Overview/Consume/Return redesign, Removed
  Component Return, Planned Parts), Product (Edit dynamic form,
  Consumable Specification/Grade, SDS upload, Inventory Configuration,
  Image upload), Workshop Invoice History, master data (Worker Type,
  Vehicle Brand, UOM, Scheduler), and a repository-wide Image URL →
  upload conversion.
- Link to `docs/status/TENANT_PORTAL_ALIGNMENT_STATUS.md` and this
  Final Report for full detail.
- Test plan: `php artisan test` (800/800, Mongo-dependent tests
  excluded per documented environment limitation), `npm run build`,
  `npm run lint`.
- Remaining owner decisions: the four items in §N.5, called out
  explicitly as non-blocking follow-ups.
