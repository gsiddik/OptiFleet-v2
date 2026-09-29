# Component Group Master — Abbreviation, Management & Production-Safe Seeder

Status: **Phases 1–4, 6, 7, 8 COMPLETE. Phase 5 (SKU integration) NOT
IMPLEMENTED — blocked on owner decision (see bottom).**

## Audit findings (before)

- `component_groups` is tenant-or-platform scoped (`TenantOrPlatformScope`):
  the baseline rows are platform-owned (`tenant_id NULL`, `is_system`),
  tenants may add their own rows. Scope unchanged by this work.
- Baseline was seeded inline in `MasterDataSeeder` via `updateOrCreate`,
  which re-forced name/status on every deploy.
- Tenant UI had Edit/Deactivate but no Create; no platform management UI.
- Delete rode on `component_group.update`.
- FKs to `component_groups` from products/transactions were `CASCADE` or
  `SET NULL`; many `exists:component_groups,id` rules had no tenant or
  soft-delete filter.
- **Product SKU is a free-text, user-entered field** (`required|max:50`,
  unique per tenant, not editable after create). There is no SKU generator.
  The server-generated number is the Item Code (`ITM/{YYYY}/{SEQ:6}` via
  `DocumentNumberingService`). Product ↔ Component Group is many-to-many
  (`product_component_groups`) plus compatibility rows — a Product has no
  single "primary" group. Item Types are enum values with no short code.

## Decisions taken (backward compatible)

- `code` stays the stable identifier: existing `CG-*` codes were NOT renamed
  (seeders, mappings and tenant data key on them). The requested target
  codes map 1:1 (ENGINE → `CG-ENGINE`, …); new groups use `CG-HVAC`,
  `CG-BODY`, `CG-GLASS`, `CG-SRS`, `CG-EV-HV`.
- Existing hierarchy kept (Lubrication/Cooling/Fuel/Exhaust under Engine).
- `abbreviation` is nullable in the DB so legacy tenant groups are never
  given an invented abbreviation; the API requires it for every new group
  and the UI flags "Missing" on legacy rows.
- Abbreviation uniqueness includes soft-deleted rows (never reused).
  Platform rows unique among themselves; tenant rows unique within the
  tenant and may not reuse a platform abbreviation (and vice versa) —
  enforced in `ComponentGroupService` under a `pg_advisory_xact_lock`.
- Abbreviation lock: once a group is referenced by any Product
  (`product_component_groups` or `product_compatibilities`, soft-deleted
  products included). A missing abbreviation may always be filled in.
- Soft-deleted / INACTIVE / other-tenant groups are rejected for new
  references (`ComponentGroup::selectableRule()`); an edit may resend the
  value it already holds. Historical relations use `withTrashed()`.

## Deliverables

- Migrations `2026_09_29_000001..000004`: abbreviation column + CHECK +
  partial unique indexes; controlled backfill by code (renames only
  uncurated legacy names); FKs → `ON DELETE NO ACTION`; `component_group.delete`
  permission created and granted to every role holding `component_group.update`.
- `ComponentGroupSeeder` (called from `MasterDataSeeder`): creates missing
  baseline codes only, fills only NULL abbreviations, never restores,
  renames or re-statuses; vehicle-category default mapping is bootstrap-only.
- Tenant `/app/component-groups` (+ `restore`), platform
  `/platform/component-groups` CRUD + restore. List exposes `abbreviation`,
  `is_used`, `abbreviation_locked`, `is_deleted`; filters: search (incl.
  abbreviation), status, `trashed=with|only`, sort.
- Frontend: shared `ComponentGroupManager` (tenant + platform pages);
  `{ABBR} — {Name}` in every Component Group picker/label.

## Validation (this session)

- `tests/Feature/ComponentGroupMasterTest.php`: 19 tests PASS.
- Upgrade simulation on a disposable DB (old schema + old seeder + curated
  row + tenant legacy row + legacy role → new migrations → `db:seed` ×2):
  PASS, verified via SQL.
- Full non-Mongo backend regression and frontend build/lint: see the final
  report of this initiative (Mongo migrations/tests relocated per the
  established `ext-mongodb`-unavailable precedent and restored afterward).

## OWNER DECISION REQUIRED — Phase 5 (SKU integration)

Using the abbreviation inside SKUs requires all of:
1. SKU changes from manual free text to server-generated (breaking for the
   Create Product API/UI and any integration that supplies SKUs);
2. a single "primary Component Group" on Product (new column; today the
   relation is many-to-many);
3. a short code per Item Type (e.g. SPR/CON/TIR/RIM/TOL/EQP);
4. a numbering sequence (existing `DocumentNumberingService` can host it,
   e.g. a `product_sku` document type keyed per Item Type + group).
Existing SKUs would stay untouched. Not implemented pending approval.
