# Comprehensive Improvement from Main — Status

Branch `claude/magical-volta-tv4xwl`, baseline `main` @ a43a3e6.

| Phase | Scope | Commit |
|---|---|---|
| 1–2 | Whitespace / responsive layout; Wheels Configuration list "Number of Vehicle" + nested mapped-vehicle table | 6bb026e |
| 3 | Tire List: Product Name is the link, Action column removed | 55f5ce2 |
| 4–11 | Tire Operations landing + add/edit/cancel, Work Order integration, Part Request for replacements | 9d8dcbf |
| 12 | Seeder overhaul, demo accounts, README credentials, one-command seed | 38a4835 |
| 13–15 | New Stock columns + Excel import (template, preview, outcomes) | 2be457d |
| 16 | Installed tire Serial Detail redesign | 0bf37c3 |
| 17–19 | Contract review, regression, this checkpoint | 756b0b6 + validation update |

## Behaviour summary

- **Tire Operations** (`/app/tire-operations`): Recent table (WO#, Registration, Events, Position
  `1FL1 (Front Left, Axle 1, Pos. 1)`, Usage KM, Usage Time / Hours Meter, Last Tread Depth,
  backend-derived status NEW / IN_PROGRESS / COMPLETED / CANCELLED, Edit / Cancel). Saving creates
  the operation and a Draft Work Order numbered by the central service. Replacement creates a Part
  Request (qty = serial count); Consume installs the serials. Rotation / Inspection reserve no
  parts and apply when the Work Order completes. The old tabbed page stays at
  `/app/tire-operations/legacy` (deprecated, not deleted).
- **Seeding**: `php artisan migrate:fresh --seed` with `APP_ENV=local` seeds bootstrap + demo data
  (`SEED_DEMO_DATA` overrides; production / tests unchanged). Demo accounts, documented list
  exceptions and credentials are in the README "Seed data" section.
- **New Stock import**: Tire Detail → New Stock → Import. Template `.xlsx` ("How To" with 12
  examples, empty "Fill Here" with the exact headers). Uniqueness = existing tenant rule
  (`lower(trim(serial))` per tenant, deleted tires excluded). Every selected row is re-validated on
  import; outcomes SUCCESS / PARTIAL / FAILED (nothing saved).
- **Serial Detail** (installed tire): position, usage, Current / Reference tread with the
  "below standard, NEED TO CHECK" warning, read-only preview with the position highlighted.
  In-Service Actions and Replace Tire are no longer on the page (endpoints kept).

## Owner decisions (confirmed 2026-10-04)

1. Work Order REJECTED → Tire Operation CANCELLED. — agreed.
2. **Replacement (changed by owner):** the old tire goes back as **REMOVED** and waits in Used
   Tire Management (new "Removed" tab). It returns to stock as a reusable Used tire only after
   an inspection held in Used Tire Management. A REMOVED tire is never offered as "Replacing
   With"; "Reuse" now means a previously installed tire that is back IN_STOCK.
3. Rotation swaps and Inspection readings are applied when the Work Order is COMPLETED. — agreed.
4. Cancel is refused once the replacement tires' Part Request is approved. — agreed.
5. KM at Tire Operations may not be below the last recorded reading of the selected tires. — agreed.
6. Legacy tire events stay in History, not in the Recent table. — agreed.
7. Workshop field only when the vehicle has no default workshop. — agreed.
8. Inspection auto-selects free positions; positions without tire data block Save. — agreed.
9. Demo data only when `APP_ENV=local` or `SEED_DEMO_DATA=true` — keep until the owner decides
   to deploy to production.
10. Import: flagged rows may still be selected and are reported as failed. — agreed.

## Usage Time / Hours Meter (owner rule, implemented)

Usage Time is measured like Usage KM, in date/time: a period starts at the tire's installation
date/time — the Last Known Installation Date + Time for the first tire on a position, or the
Tire Operations Date + Time of the replacement / rotation that put the tire there — and every
applied Tire Operation adds the time since the previous one. Displayed in hours ("721.75 h").
To keep this exact, removals, installations and rotations done by a Tire Operation are now dated
at its Tire Operations Date + Time (direct actions still use the current time), and — like the
KM floor — the Tire Operations Date + Time may not be earlier than the last installation or
applied operation of the selected tires.

## Used Tire Management inspection (owner decision 2026-10-04, implemented)

- The Tire Operations date/time floor is confirmed by the owner (kept).
- A removed tire is listed in Tire Detail → Used Stocks with status REMOVED and in Used Tire
  Management → Removed ("Removed tires awaiting inspection").
- Removed tab action **Inspect & return to stock** opens the tire's inspection form
  (`POST /tires/{tire}/inspect-removed`, permission `tire.inspect`): tread depth (required),
  condition, notes, result. PASS → IN_STOCK in the chosen warehouse (tenant's and within the
  user's data scope) — it is then offered as "Reuse" in Tire Operations; FAIL → RETREAD, REPAIR
  or SCRAPPED, where the existing Used Tire Management flows continue. The inspection is always
  recorded as a tire inspection (latest tread depth).
- Not changed: the tire product's warehouse quantity. Serial tires and product stock quantities
  are tracked separately today (as before for any reused tire); returning a used serial to a
  warehouse moves no stock quantity.

## Known follow-ups

- The deprecated legacy Tire Operations page still deep-links to the tire page's removed
  In-Service section (`#in-service`); the link opens the tire page without those actions.
- Seeded lists below 5 records are listed as documented exceptions in the README.

## Validation (executed in this session)

| Check | Result |
|---|---|
| Backend full regression (`php artisan test`, Mongo-only migrations/tests set aside) | PASS — 1051 tests, 6228 assertions (before the 2026-10-04 changes) |
| Targeted: TireOperationTest 12, TireImportTest 12, DemoDatasetSeederTest 3, seeder tests | PASS |
| Frontend `npm run build` (tsc -b + vite build) | PASS |
| oxlint | PASS — 27 warnings (baseline 28, none new) |
| Browser e2e: layout / wheels config 16, tire list 5, tire operations 37, import 20, serial detail 9 | PASS |
| `migrate:fresh --seed` (APP_ENV=local) + re-run idempotency | PASS |
| MongoDB analytics / intelligence tests | NOT RUN — MongoDB unavailable in this environment |
| Docker build | NOT RUN — registry egress blocked in this environment |
| Backend full regression after the 2026-10-04 Usage Time / Removed-tire changes (75b36f9) | PASS — 1051 tests, 6242 assertions |
| Browser e2e after 75b36f9: tire operations 37, serial detail 9, import 20, Used Tire Management tabs | PASS |
| Removed-tire inspection: TireRemovedInspectionTest 4, browser e2e (Removed tab → form → Pass, Used Stocks REMOVED row) | PASS |
