# Product, Seeder, Part Request, Issuance & Return — Improvement Status

Status: **COMPLETE**. Branch `claude/magical-volta-tv4xwl`, baseline `main` @ `932b618`.

## Target architecture (implemented)

```
Product Master -> Part Request -> Approval -> Issuing -> Inventory Ledger
New issued part (not used) -> Return -> Returned Parts Processing -> stock only when accepted
Removed (used) component -> Used Sparepart Processing
```

The three part lifecycles are explicit (`work_order_part_returns.return_source` =
`NEW_PART` / `REMOVED_COMPONENT` / legacy `USED_PART`) and never share a screen or an action.

## Seeders

- `ProductCreationService` is the single Product creation path (POST /app/products and every
  seeder): Item Type ↔ category check, mandatory Component Group + Category, dynamic spec
  validation, server-generated Item Code and SKU.
- Demo/functional seeders create Products through it (idempotency key: tenant + name).
  `PC-SPARE` is no longer created (demo uses the baseline `PC-SPAREPART`). Existing databases
  keep any historical `PC-SPARE` row untouched.
- `OperationsSeeder` no longer duplicates its Work Orders/inspections/breakdowns on rerun;
  `FunctionalTestMasterDataSeeder` no longer writes invalid `usage_type` values.
- Demo seeders create the Vehicle Brand/Model masters their own vehicles use (Hino Ranger FG,
  Mitsubishi Fuso) — required because a Sparepart must have a compatibility Brand/Model.
- Validation: fresh `migrate:fresh --seed` + DevDemoSeeder + FunctionalTestingSeeder run
  twice produce identical row counts; every seeded Product passes the current rules.

## Vehicle masters

- Product compatibility Brand/Model reference `vehicle_brands` / `vehicle_models` by id
  (`vehicle_brand_id`, `vehicle_model_id`); the text columns keep the name snapshot. Backfill
  links only exact, unambiguous name matches (tenant brand first, then platform); anything else
  keeps its text and shows as "legacy text".
- Dependent dropdowns: Create Product rows, Product Detail add rule and edit rule. Changing the
  brand clears the model. Lookups: `/app/product-classification/vehicle-brands|vehicle-models`.
- Vehicle Brand "Brand Of" = many-to-many to active Vehicle Categories
  (`vehicle_brand_categories`), backfilled from the legacy CAR/TRUCK/BUS/HEAVY_EQUIPMENT values.
- Vehicle Category → Component Groups popup has Select All (tri-state, UI only).

## Part Requests (reserve → approve → issue)

- WO Issuance & Return "Reserve" (Product mandatory, searchable; no Description) creates a
  Part Request `REQUESTED`.
- State machine `WorkOrderPartRequest::TRANSITIONS`: REQUESTED → APPROVED | REJECTED |
  CANCELLED; APPROVED → ISSUED. Every transition row-locks and re-checks.
- Issue (`part_request.issue`) posts stock through the existing inventory engine, all lines or
  none, once. Issuing never consumes stock reserved for another Work Order.
- Removed: WO "Part Requests" tab, standalone Inventory → "Issue" menu, and the direct
  planned-part add/reserve/issue endpoints. Legacy un-issued planned lines were wrapped in an
  APPROVED Part Request so they remain issuable.

## Return and Used Sparepart Processing

- Return (Issuance & Return, New Good / New Faulty only) creates a numbered Return
  (`RTN/{YYYY}/{SEQ:6}`, numbering engine) `PENDING_PROCESSING`; no stock at return time.
- Returned Parts Processing (`part_return.process`): actual condition, received quantity,
  MATCH/MISMATCH, inspector. New Good → RESTOCKED (one RETURN movement); New Faulty →
  QUARANTINED (never available stock). Processing twice is rejected.
- Removed Components always create a Used Sparepart Processing record (PENDING_RETURN →
  Receive to Warehouse → PENDING_INSPECTION → existing inspect/propose/approve workflow).

## Migrations

`2026_10_01_000001` compatibility → masters · `000002` vehicle_brand_categories ·
`000003` part request issuing (ISSUED, warehouse/issued_by/issued_at, unique line,
part_request.issue grant, legacy line wrap) · `000004` return processing (source, number,
inspection result, removed-component link, new states, backfill, part_return.* grants).
All additive; no historical row is deleted or rewritten destructively.

## Validation

Backend tests: see the final report. Browser E2E Scenarios 1–14 executed against seeded demo
data (all pass). MongoDB tests NOT RUN (`ext-mongodb` unavailable in this environment).

## Decisions required

1. Returned new parts found faulty (QUARANTINED): next step (supplier warranty claim, repair,
   scrap) is not defined — they stay out of available stock.
2. Approval does not hold stock; availability is checked at Issue. Reserving at approval is
   possible if preferred.
3. Legacy compatibility rows whose brand/model text matched no master (or several) stay
   text-only until cleaned up.
4. Demo seeders now contain Vehicle Brand/Model masters (needed for valid Sparepart
   compatibility), narrowing the earlier "no brands in demo data" answer.
