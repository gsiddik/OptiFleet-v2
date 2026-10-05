# Visual Workflow Builder & Tire Scoring Removal — Status

Branch `claude/magical-volta-tv4xwl`, baseline `main` @ 0e568ce (the branch had no content
difference from `main` at start).

| Phase | Scope | Status | Commit |
|---|---|---|---|
| 1, 5–8, 11–12 (backend) | Workflow audit; catalog, graph analyzer, layout storage, validate / layout / available-transitions API | DONE | 969045a |
| 2–10 | Visual Workflow Builder (canvas, nodes, arrows, reconnect, panels, validation, layout, draft / publish) | DONE | fa2ea1a |
| 11 (frontend) | Module action buttons driven by the published workflow | DONE | dd330fa |
| 13–17 | Tire Scoring dependency audit and removal | DONE | 318e7e7 |
| 18–19 | Workflow and Tire Inspection seeders | DONE | 11b5238 |
| 20–23 | Test matrices, quality gates | DONE | see final report |

No owner decision was needed (see "Decisions" below).

## Workflow audit

- **Source of truth:** the versioned `WORKFLOW` configuration (`configuration_sets` /
  `configuration_versions`, code = resource type), payload `{statuses, transitions}`. Platform
  defaults (`WorkflowDefaultsSeeder`) per resource; a tenant's published version overrides them
  (Workshop → Branch → Tenant → Platform resolution).
- **Runtime:** `WorkflowEngine` — modules call `isTransitionAllowedForVersion(version, from, to)`
  before every status change (Maintenance Request, Work Order, Breakdown, Vehicle / Stock
  Transfer, Purchase Request / Order, Warranty Claim; approval flows for used part disposition
  and spare part sale). `availableTransitions()` filters by required permission and condition.
- **Versioning / history:** Draft → Published → Archived; every document pins the version it was
  created with (`workflow_configuration_version_id`), so publishing never changes in-flight
  documents and history is never reinterpreted.
- **Statuses** are fixed by the domain (DB enum / CHECK columns); module actions are endpoints
  keyed by target status. Before this change the module pages decided their buttons from
  hard-coded per-status lists and the workflow editor was raw JSON.

## Visual Workflow Builder

- Configuration → Workflow lists System Default and Custom workflows per document type (New
  Draft, Edit, Publish, Return to System Default); every workflow opens in the builder
  (`@xyflow/react`, lazy-loaded).
- Status = card (name, code, Start / End markers, error / warning badge); transition = directional
  arrow labelled with its action. Pan, zoom, fit view, drag cards, drag between cards to add a
  transition, drag either end of an arrow to another card (target / source reconnection changes
  the transition's From / To), Delete key or panel button to remove (confirmed).
- Status panel: name, start status, transitions in / out, "Add transition to…" (keyboard
  alternative). Transition panel: From / To, action name and code, required permission, automated
  actions; conditions and approval rules are kept as configured.
- **Add State** offers only the document's own statuses (catalog = the platform default's
  statuses). A transition into a status no module action can execute is refused.
- **Validation** (server, shown live on cards / arrows and in a panel): duplicate status /
  transition / action, unknown status, self transition, missing start, unreachable status (BFS
  from every start status; cycles and backward transitions are valid), status outside the
  catalog, non-executable transition, unknown permission / action / operator, invalid approval
  rule; warnings for unconnected statuses and catalog statuses left out. Publishing enforces the
  same rules.
- **Layout** is stored per version in `workflow_layouts` (positions, viewport), separate from the
  workflow payload and never read by the runtime; a version without one uses a deterministic
  left-to-right auto-layout (BFS column, list order). Auto Layout / Reset Layout. Published custom
  versions open in layout-only mode; System Defaults are read-only.
- Unsaved-change warning on close and page unload.

## Runtime integration

`GET /workflow/available-transitions?resource_type&resource_id` returns what the record's pinned
workflow allows the current user (tenant, permission and data scope checked). Detail pages build
their action buttons from it (Maintenance Request, Breakdown, Purchase Request, Warranty Claim,
Stock Transfer, Vehicle Transfer) or filter their dialog-driven actions by it (Work Order, Purchase
Order). Stock transfer Dispatch / Receive stay their own actions (goods movements that enter their
status directly). The backend keeps enforcing the workflow on every action.

Verified by `WorkflowBuilderTest` and the builder e2e: reconnecting DRAFT → SUBMITTED to
DRAFT → APPROVED, removing DRAFT → CANCELLED and adding backward transitions, then publishing —
a new Maintenance Request offers / accepts exactly the new transitions (submit 422, approve 200,
backward submit from APPROVED and UNDER_REVIEW), the page shows the new buttons, and a request
created before publishing keeps the old workflow.

## Tire Scoring dependency audit

| Class | Items |
|---|---|
| Safe to remove | Tire Scoring configuration page, menu, breadcrumb, route; `POST /tires/{tire}/scoring` and `/finalize`; `TireScoringService`, `TireScoringConfigurationService`, `TireScoringConfigurationValidator`; the TIRE_SCORING configuration type for listing / create / edit / publish / preview; `ConfigurationSetManager` (used only by that page); scoring-only tests |
| Replaced by Tire Inspection | `SELL_FOR_OPERATIONAL_REUSE` gate → latest approved Used Tire Inspection is REUSE and the tire is still REUSE (sale records `tire_used_inspection_id`); retread / repair send eligibility (opt-in scoring config) → the inspection's casing (compliance, A_retread_max, N_retread_max) / repair-limit decision that puts the tire in RETREAD / REPAIR; cycle approval critical-fail scoring check → post-cycle inspection approval + UNSAFE check |
| Historical only (kept) | `tire_scoring_results` table and `TireScoringResult` model (read-only relations on Tire / TireSale), `tire_sales.tire_scoring_result_id`, TIRE_SCORING configuration versions (visible in Configuration History), existing permission rows and role assignments |
| Shared (kept) | `products.reference_tread_depth_mm` (default D_new of a used tire inspection), `ConfigurationSet::TYPE_TIRE_SCORING` constant for legacy rows |
| Decision required | None |

Permissions `tire_scoring.*` / `tire_scoring_configuration.*` are no longer seeded or listed
(`PermissionCatalog::RETIRED_GROUPS`); nothing is deleted. The old `/app/configuration/tire-scoring`
link redirects to Used Tire Management.

## Decisions

- **Statuses are not free-form.** Document statuses are DB enum values used by module code, so the
  builder cannot create arbitrary statuses; Add State re-adds the document's own statuses only.
- **Tire Scoring gates.** The only scoring-only business rules were opt-in (no scoring
  configuration was seeded) or API-only (no UI used scoring calculate / finalize or the tire sell
  endpoint). Tire Inspection already decides retread / repair eligibility and drives cycle
  completion, so no owner decision was required; the operational-reuse sale now relies on the
  inspection.

## Seeders

- `WorkflowDefaultsSeeder` unchanged — all ten platform defaults pass the new analyzer (tested).
- `ConfigurationShowcaseSeeder` (BETA): published Maintenance Request workflow with a backward
  "Return for revision" transition and a saved deterministic layout.
- `DemoDatasetSeeder`: REPAIR and RETREAD inspection outcomes added, so REUSE / REPAIR / RETREAD /
  SCRAP / HOLD all have demo records. No scoring data or permissions are seeded.
- Clean `migrate:fresh --seed` and re-seed: no errors, no duplicates.

## Database

- `workflow_layouts` (new): `configuration_version_id` unique, `tenant_id`, `positions` jsonb,
  `viewport` jsonb, FKs to tenants / configuration_versions.
- `tire_sales.tire_used_inspection_id` (new, nullable FK). No table or column dropped.
