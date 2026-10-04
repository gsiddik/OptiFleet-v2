# Used Tire Management & Used Stock — Status

Branch `claude/magical-volta-tv4xwl`, baseline `main` @ f76045b.

| Phase | Scope | Commit |
|---|---|---|
| 1 | Used stock lifecycle: REUSE / HOLD statuses, availability, OTR category | a06f85a |
| 2 | Removed tab: Tire History popup, "Inspect" action | 37a2276 |
| 3 | Inspection entity, rule profiles, decision engine, API (+ fix-forward of a staging error) | 7245ea7, 9cc8f0c |
| 4 | Inspection page, result summary, Inspection Rules page | 9145d04 |
| 5 | Lifecycle regression tests, this checkpoint | ba63947 + validation update |
| 6 | Branch scope for tires on no vehicle / in no warehouse (last vehicle's branch) | a87e018 |
| 7 | Used tire warehouse quantity, issued through Part Requests (owner decision 1) | 88e84b8 |
| 8 | Usage-restriction warning at installation (owner decision 2) | c80d555 |

## Status semantics (TireStatus)

| Status | Meaning | Used Stocks | Available for installation |
|---|---|---|---|
| IN_STOCK / RESERVED | new stock, never installed | — (New Stock) | yes |
| REMOVED | taken off a vehicle, awaiting inspection | yes | no |
| REUSE | inspected, fit for reuse | yes (counted in `reusable_qty`) | **yes** (issued through the Part Request from the used tire quantity) |
| HOLD | inspection incomplete / decision pending | yes | no |
| REPAIR / RETREAD | in the repair / retread lifecycle | yes | no |
| SCRAPPED (shown "SCRAP") | terminal; history kept | no | no |

Data migration: used tires that were back IN_STOCK became REUSE (IN_STOCK now means new stock only).
Retread / repair cycle approval RETURN_TO_SERVICE returns the tire to REMOVED (inspected again
before reuse); QUARANTINE maps to HOLD. A REMOVED tire cannot be scrapped directly (SCRAP is an
inspection outcome).

## Inspection

- Questionnaire Q1–Q14 (each condition question has a Not Inspected / Unknown / Cannot Confirm
  option → HOLD), ≥ 6 tread points (3 zones × inner / outer main groove, center optional), damage
  rows (location, type, dimensions, reinforcement, overlap), repair eligibility, specialist /
  retreader result, evidence photos.
- Rule profile per tenant + tire category (Passenger / Light Truck, Truck / Bus, OTR / Heavy
  Equipment) + optional tire product + optional application: D_service, D_pull (≥ D_service),
  A_max, A_retread_max, N_retread_max, repair limits, application limits. Versioned; each
  inspection stores the version and a snapshot of the thresholds.
- Decision engine (C/X/R/P/T/K/F): X → SCRAP; not C → HOLD; T & ¬K → SCRAP; T & R & ¬P → SCRAP;
  T & F → RETREAD (+ CASING_REPAIR); T → HOLD retread candidate; R & P → REPAIR; R & ¬P → SCRAP;
  else REUSE. Remaining tread % is informational only.
- Submit records the inspection (tire unchanged); approve applies the disposition.

## Decisions taken (engineering — please confirm)

1. The approved disposition is always the engine's recommendation (approve or cancel and
   re-inspect); there is no manual override.
2. Inspector and approver may be the same user when they hold both permissions
   (`tire.inspect`, `tire_used_inspection.approve`); no maker-checker rule was specified.
3. New permissions are granted on migration to roles that already approve retread cycles
   (`tire_used_inspection.approve`) and manage scoring configuration (`tire_rule_profile.manage`).
4. Engine rules where the requirement left room: bead torn / deformed / wire damaged, inner liner
   cracked / heat / cord exposed, previous repair not meeting standard and a SEPARATION damage are
   confirmed rejections (X); FLAT_SPOT and CUPPING are "tread not suitable to retain" (T); a leak
   or local inner-liner damage requires a damage row; a repair limit missing from the profile →
   HOLD; exceeding a configured limit → SCRAP.
5. OTR product spec: dual load index and ply rating optional (required only for Truck & Bus).
6. Demo rule profiles for ALPHA are demo values only; the application has no built-in defaults.

## Branch scope (owner request)

A tire on no vehicle and in no warehouse (REMOVED, HOLD, REPAIR / RETREAD at a partner, and
terminal statuses) belongs to the data scope of the branch of the vehicle it was **last installed
on**; a tire in a warehouse follows the warehouse scope; an installed tire follows its vehicle's
branch. One rule (`TireInventoryService::scopeToUser`) serves the tire list, tire detail /
history, Used Stocks and the used tire inspection.

## Owner decisions (resolved)

**1. REUSE tire in a Replacement — separate used tire warehouse quantity, issued like new stock.**
- `used_tire_stocks` (quantity on hand per warehouse + tire product, never below 0) with an
  append-only per-serial ledger `used_tire_stock_movements` (+1 / −1). New-stock
  `warehouse_stocks` is untouched.
- In: inspection approved as REUSE into a warehouse (INSPECTION_RECEIPT); an issued used tire
  returned unused and accepted New Good (RETURN); REUSE tires already in a warehouse when the
  migration ran (OPENING_BALANCE).
- Out: Part Request issue of a USED line (ISSUE); a counted REUSE tire installed, scrapped or sold
  directly (INSTALL / SCRAP / SALE).
- A Replacement with a REUSE serial creates a **USED** Part Request line (one line per product and
  condition NEW / USED). Approve → planned part with the same `stock_condition`; Issue deducts the
  used quantity — the serial must be counted in the issuing warehouse, all-or-nothing with the
  other lines; Consume installs the serial (as for new stock).
- Return of an issued, not installed, used tire: New Good → back into the used tire quantity;
  New Faulty → the tire goes to HOLD in that warehouse for re-inspection (engineering decision).
- Used tires carry no stock valuation: a USED line is issued at cost 0 (engineering decision).
- Only REUSE tires that are in a warehouse are offered as "Replacing With".
- Operations created before this change (REUSE serial without a USED line) still install the REUSE
  serial at Work Order completion, as planned then.
- UI: Warehouse Stock → **Used Tires** tab (quantity + serials per warehouse / product); Part
  Request and Issuance & Return lines show "(Used)"; the serial picker shows "Reuse · warehouse".

**2. Usage restrictions — warn at installation; block later.**
- The latest approved inspection of a REUSE tire carries its rule profile's application limits
  (snapshot). Installing it on a position outside `positions` returns a warning — Tire Operation
  payload `warnings` (form, Work Order tab) and direct install response `warnings` — and never
  blocks. Comparison is by position code, case-insensitive.
- To do once position codes are standardized: turn the warning into a validation error.

## Known limitations

- A user scoped only by warehouse (no branch) does not see REMOVED / HOLD tires (they are in no
  warehouse); branch-scoped users see them through the last vehicle's branch.
- Load / speed / operation limits are shown with the restriction but not compared (no reliable
  vehicle load / speed data at installation).
- The Used Tire Management → Scrap tab still allows scrapping HOLD / QUARANTINED tires directly
  (existing scrap action).

## Validation (executed in this session)

| Check | Result |
|---|---|
| Backend full regression (`php artisan test`, Mongo-only migrations/tests set aside) | PASS — 1079 tests, 6563 assertions |
| Unit: UsedTireDecisionEngineTest | PASS — 13 tests |
| Feature: TireUsedInspectionTest 8, TireUsedStockLifecycleTest 4, TireHistoryTest 1, TireOperationTest 13, governance / closure / inventory / registration / seeder tests | PASS |
| Frontend `npm run build` (tsc -b + vite build) | PASS |
| oxlint | PASS — 27 warnings (baseline 28, none new) |
| Frontend unit tests / `npm test`, `npm run typecheck` | NOT RUN — no such scripts in the project (typecheck runs inside the build) |
| Browser e2e: inspection flow 20, history popup 4, wheels/tire list 21, tire operations 37, import 20, serial detail 9 | PASS |
| `migrate:fresh --seed` (APP_ENV=local) + re-run idempotency | PASS |
| MongoDB analytics / intelligence tests | NOT RUN — MongoDB unavailable in this environment |

### Validation — phases 6–8 (executed in this session)

| Check | Result |
|---|---|
| Backend full regression (Mongo-only migrations/tests set aside) | PASS — 1085 tests, 6686 assertions |
| Feature: TireRemovedBranchScopeTest, TireOperationTest 17 (USED issue / warehouse / return / direct install / warnings), TireUsedInspectionTest 9 | PASS |
| Related feature set (tire, part request, returns, sale: 46 files) | PASS — 366 tests |
| Data migration on the e2e DB: REUSE tire in a warehouse → OPENING_BALANCE +1; rollback + re-migrate | PASS |
| Frontend `npm run build` | PASS |
| oxlint | PASS — 27 warnings (none new) |
| Browser e2e: used tires + warnings 13, tire operations 37, inspection 20, history 4 | PASS |
| `migrate:fresh --seed` (APP_ENV=local) + re-run | PASS |
| MongoDB analytics / intelligence tests | NOT RUN — MongoDB unavailable in this environment |
