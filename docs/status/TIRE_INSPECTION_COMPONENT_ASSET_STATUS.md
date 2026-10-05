# Tire Inspection, Retread History, Component Asset Refactor & Seeders — Status

Branch `claude/magical-volta-tv4xwl`, baseline `main` @ 5f197ec (merged into the branch, no content
difference at start).

| Phase | Scope | Status | Commit |
|---|---|---|---|
| Audit | Tire Inspection / identity / age / tread / rule profiles, retread cycles + usage, Component Assets / Component Management, product serial flag, GR, Return to Vendor, Sell, seeders | DONE | — |
| 1–4 | Manufacture Date Code edit, central Tire Age, tread units + tooltips, condition tooltips, D_pull | DONE | 473437c |
| 5 | Recent Retread / Repair Cycles columns (previous vehicle / position, Usage KM / Hours) | DONE | 00fdafa |
| 7–8, 11, 13–14 | GR asset generation, Tire = Asset#, SOLD / RETURNED_TO_VENDOR, invariants, Return Asset# selection, Sell | DONE | d2585d9 |
| 6, 9–10 | Component Assets under Inventory, list columns, no manual create, detail | DONE | 75cb093 |
| 12 | Component Management navigation retired (shared flows kept) | DONE | fa092c1 |
| 16 | Seeders (asset states, GR 3 + 2, sale, assertions) | DONE | e338f74 |
| 17–19 | QA matrix (backend + e2e), quality gates | DONE | see below |

## Owner decisions (DECISION REQUIRED, answered)

1. **Tires as Component Assets** — unified view: the register lists the existing physical Tire
   records with Serial Number = Asset# (no copy rows); tires keep being created by New Stock /
   import with their real serial. Goods Receipt generates assets for non-tire products only.
2. **Return to Vendor** — the Return Order selects the exact Asset# returned; those assets become
   RETURNED_TO_VENDOR (no location, rows and history kept). A redelivery is a new physical unit:
   its Goods Receipt generates new assets.
3. **Selling non-tire assets** — through Sell Sparepart (new COMPONENT_ASSET source, maker-checker
   approval); SCRAPPED or REMOVED assets only (scrapped → scrap material only); approval → SOLD.
4. **Component Management** — remove the menu only; the asset detail keeps install / remove /
   replace / repair actions and the history; APIs and services unchanged.

## Tire Inspection (Phases 1–4)

- Manufacture Date Code is the existing DOT "WWYY" code. `TireAgeService` is the single formula
  (Monday of the ISO week → whole months; rejects week 0 / > 53, a week 53 the year lacks, and
  future weeks). Inspection facts use it; the frontend never computes age.
- Tire Identity shows **Edit** only when the code is empty. `PUT /tires/{tire}/manufacture-date-code`
  (`tire.inspect`, tenant + tire data scope, row lock, fill-only — an existing code is never
  overwritten here) saves on the physical tire; the response carries the refreshed facts and the
  page reloads them (Tire Age updates, the live recommendation re-evaluates).
- Historical integrity: an inspection already recorded keeps its `tire_snapshot` (tested).
- Tread: "Tread depth (mm)" header, "(mm)" on every groove row and an "mm" suffix on every input;
  accessible `InfoTip` tooltips (focus / hover / click, `aria-describedby`, Escape) on Main groove
  inner / outer, Center / most worn, Zone 1 / 2 / 3, D_new, D_min, D_pull and all condition
  questions (Wear pattern … Specialist / retreader result).
- D_pull: value from the active rule profile (with D_service); the tooltip states D_pull ≥ D_service
  and names its real configuration page **Tire Management → Inspection Rules**
  (`/app/tire-inspection-rules`, `tire_rule_profile.manage`). No new configuration was needed.

## Retread history (Phase 5)

Used Tire Management → Retread → Recent Retread / Repair Cycles: When, Event (cycle stage: Sent to
vendor / Received / Re-inspection / Completed → disposition / Rejected), Serial, Product, Vehicle,
Position (`PositionLabel`, e.g. "1RL2 (Rear Left, Axle 1, Pos. 2)"), Usage KM, Usage Hours.
Vehicle / position = the tire's last installation that ended before the cycle opened; usage =
`TireInventoryService::usageBefore` (same period rules as `usage()` / `usageHours()`, periods ended
by the cycle's creation time). Unknown values are null → "—" (never 0); vehicle KM is not shown.

## Component Assets (Phases 6–15)

- **Register** (`GET /component-assets`, `component_asset.view`, module COMPONENT): component assets
  + physical tires (only with the TIRE module and `tire.view`), tenant + data scope, filters
  status / kind / component group / search, server pagination. Columns: Status, Serials / Asset#,
  Component Group (product's groups, else the asset's), Product, Location / Vehicle.
- **Location** is derived: active installation → vehicle registration; SOLD / RETURNED_TO_VENDOR → "—";
  otherwise the asset's warehouse. Removal and repair completion now record the storage warehouse.
- **Generation**: Post Goods Receipt → one asset per unit **accepted** of a product with
  `track_serial_number` (not TIRE / CONSUMABLE), Asset# `AST-YYYY-NNNNNN` (per-tenant counter,
  row-locked), purchase cost = PO unit price, IN_STOCK in the receiving warehouse. Partial receipts
  add up (3 + 2 = 5). Idempotent per receipt line (unique `goods_receipt_item_id, receipt_sequence`;
  re-running generation adds nothing). Fractional quantities of a tracked product are refused.
- **Manual create**: button and modal removed; `POST /component-assets` returns 410 (model / service
  creation remains for seeders and integrations).
- **Statuses**: existing + SOLD + RETURNED_TO_VENDOR. Sell: `POST /sparepart-sales/component-assets`
  (`sparepart_sale.create`); approval sets SOLD, clears location, stores `sold_at` / sale; an asset in
  an open sale cannot be installed.
- **Status history**: the existing audit trail (Auditable) shown on the detail page.
- **Navigation / permissions**: Inventory → Component Assets (same route / permission / module — no
  access lost). MDC edit = `tire.inspect`; retread table = `tire.view`; asset sale =
  `sparepart_sale.create` / `.approve`. No role names used.

## Database (migration `2026_10_10_000001`, additive)

- `component_assets`: `goods_receipt_item_id` (FK), `receipt_sequence`, `purchase_return_id` (FK),
  `sold_at`, `spare_part_sale_id` (FK); status CHECK + SOLD, RETURNED_TO_VENDOR; unique
  `(goods_receipt_item_id, receipt_sequence)`; unique `(tenant_id, asset_number)` (non-deleted);
  `component_assets_location_check` (INSTALLED / ACTIVE → vehicle; SOLD / RETURNED_TO_VENDOR → no
  location; IN_STOCK → warehouse; never both) — added NOT VALID only if legacy rows violate it.
- `spare_part_sales`: `component_asset_id` (FK), `asset_number`; source CHECK adds COMPONENT_ASSET;
  one active sale per asset (partial unique index).
- Tire serial uniqueness unchanged.

## Seeders

DemoDatasetSeeder `componentAssetRegister()` (through the services, idempotent by PR notes): battery
PO received 3 then 2 → 5 Asset#; IN_STOCK, INSTALLED (H 3201 ALP), REMOVED, UNDER_REPAIR,
RECONDITIONED; second order → SCRAPPED and SOLD (sale approved by the branch admin). Earlier
procurement demos generate further IN_STOCK assets and one RETURNED_TO_VENDOR (returns now name
their Asset#). Existing data already covers: retread cycles with previous vehicle / position /
usage, a REMOVED tire without MDC and a HOLD tire with MDC, active rule profiles with D_pull.
SupplyChainSeeder's battery asset is stored in a warehouse before installation (invariant).

## Tests

- New: `TireManufactureDateCodeTest` (4), `ComponentAssetRegisterTest` (8); extended
  `TireRetreadProcessingTest` (+1) and `DemoDatasetSeederTest` (asset / retread / inspection seeds).
- Updated fixtures: `ComponentAssetTest` (IN_STOCK assets get a warehouse; manual create → 410).
- e2e (Playwright, freshly seeded DB): `e2e_ti` 58/58 (MDC edit + age refresh + persistence, 21
  tooltips by keyboard focus / Escape, D_pull, retread columns + row values, Component Assets nav /
  columns / location per status / tire Asset# / SOLD detail / sell, 410, Return Asset# selection,
  390 px, branch admin access); regression e2e PO return (0 fails, adapted to Asset# selection),
  PO quantity 9/9, retread cycle (0 fails), scrapped tire sale 16/16.

## Quality gates

| Gate | Result |
|---|---|
| Backend targeted (tire suites 176, component / procurement / sale / seeder suites 221, new tests) | PASS |
| Backend full regression (non-Mongo, PostgreSQL) | PASS — 1135 tests, 7378 assertions (affected suites re-run on final HEAD: 50 passed) |
| Mongo-dependent tests (Analytics / Intelligence) | NOT RUN — MongoDB is not available in this environment |
| `npm run lint` | PASS — 0 errors, 27 warnings (unchanged baseline) |
| `npm run build` (includes `tsc -b`) | PASS |
| `npm run typecheck` / `npm test` | not defined in package.json |
| `migrate:fresh --seed` + `db:seed` re-run | PASS — asset counts per status unchanged, no duplicate Asset# / tire serial |

## Remaining risks

- Existing tenants: assets created before this release are not tied to a Goods Receipt, so returns
  of their PO lines stay quantity-only; legacy IN_STOCK assets without a warehouse leave the
  location CHECK NOT VALID until corrected.
- A PO line received partly before and partly after this release requires selecting Asset# for the
  whole returned quantity; only the post-release units can be selected.
- Tire Age for scoring eligibility (`TireDispositionEligibilityService`) still uses the purchase
  date as before — unchanged on purpose (it is a separate configured rule, not the inspection age).
