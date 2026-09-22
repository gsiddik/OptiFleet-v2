# OptiFleet-v2 Tenant Portal Requirement Re-Audit — Final Report

Consolidated deliverable for the Tenant Portal requirement re-audit and
implementation alignment initiative. The live working document
(`docs/status/TENANT_PORTAL_ALIGNMENT_STATUS.md`) remains the
batch-by-batch source of truth; this report is its synthesis into the
required final-report shape. Branch: `claude/peaceful-rubin-sm50tx`.

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

An extra `OTHER` item type exists beyond the 6 documented types; flagged
but not removed since its usage elsewhere in the codebase was not
established.

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

### Explicitly deferred (documented as REMAINING, not silently dropped)

- Work Order Overview tab restructuring (move Complaint section there;
  relocate Est. Labor/Parts Cost fields), Est. Number of Mechanic + Est.
  Total Hours accumulation, Consume popup redesign (Install All +
  Installed Qty), Return popup redesign (split Unused/Used subtables,
  renamed Condition values, real image upload instead of a URL text
  field), and the Planned-Parts-vs-Request-Parts architecture question
  (the doc wants Request Parts visible only from IN_PROGRESS; the current
  app has both as always-visible, architecturally distinct tabs from a
  prior session — reconciling this is a design decision, not a one-line
  fix, and was not attempted without owner sign-off).
- Product "Inventory Configuration" section (Min/Reorder/Max Stock —
  no backing schema today; a schema change, deferred).
- Consumable SDS file upload UI (backend columns already exist).
- Extra `OTHER` Product item type beyond the documented 6 (flagged, not
  removed — usage elsewhere not established).
- The one NEEDS_CONFIRMATION item in §A (Consumable Specification/Grade
  trigger condition).
- Deployment prerequisite for Batch 8: `php artisan storage:link` must be
  run once per environment (not committed code, cannot be verified from
  this sandbox) — without it, uploaded tenant logo URLs 404 even though
  the upload itself succeeds.

No completed phase was reconstructed or redesigned. No phase beyond what
was explicitly scoped in the 5 documents was started.
