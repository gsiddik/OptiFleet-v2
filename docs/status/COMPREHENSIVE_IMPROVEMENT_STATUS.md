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
| 17–19 | Contract review, regression, this checkpoint | (this commit) |

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

## Decisions taken (owner may revisit)

1. Work Order REJECTED → Tire Operation CANCELLED.
2. Old tire on Replacement is removed with disposition REUSE (back to stock as Used).
3. Rotation swaps and Inspection readings are applied when the Work Order is COMPLETED.
4. Cancel is refused once the replacement tires' Part Request is approved.
5. KM at Tire Operations may not be below the last recorded reading of the selected tires.
6. Legacy tire events (installations/rotations before Tire Operations) stay in History; they are
   not merged into the Recent table.
7. Workshop field is shown only when the vehicle has no default workshop.
8. Inspection auto-selects every position except those in another open operation; positions
   without tire data are flagged and block Save.
9. Demo data in `DatabaseSeeder` only when `APP_ENV=local` or `SEED_DEMO_DATA=true`.
10. Import: rows flagged duplicate / invalid in the preview may still be selected; they are
    reported as failed on import (that is how the "all duplicate" outcome is reached).

## DECISION REQUIRED

**Usage Time / Hours Meter**
- Current behavior: shown as "—" everywhere (Tire Operations, Recent table, Serial Detail, Used Stocks).
- Issue: no hours-meter reading is recorded per tire, per installation or per Tire Operation.
- Proposed: add an optional `hour_meter` reading to Tire Operations (and installation), accumulate
  it exactly like Usage KM.
- Alternative: derive from `vehicles.engine_hour` snapshots — not reliable, the vehicle value is
  only the latest reading.
- Affected modules: Tire Operations, tire registration, TireInventoryService usage, Serial Detail.
- Data impact: additive nullable columns; no backfill possible for history.
- Recommendation: proposed option, for vehicle types that run on hours (heavy equipment, forklifts).
- Risks: none for existing data; a value only appears after the first operation that records it.

## Known follow-ups

- The deprecated legacy Tire Operations page still deep-links to the tire page's removed
  In-Service section (`#in-service`); the link opens the tire page without those actions.
- Seeded lists below 5 records are listed as documented exceptions in the README.
