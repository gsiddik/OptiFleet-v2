# Used Tire Management & Used Stock — Status

Branch `claude/magical-volta-tv4xwl`, baseline `main` @ f76045b.

| Phase | Scope | Commit |
|---|---|---|
| 1 | Used stock lifecycle: REUSE / HOLD statuses, availability, OTR category | a06f85a |
| 2 | Removed tab: Tire History popup, "Inspect" action | 37a2276 |
| 3 | Inspection entity, rule profiles, decision engine, API (+ fix-forward of a staging error) | 7245ea7, 9cc8f0c |
| 4 | Inspection page, result summary, Inspection Rules page | 9145d04 |
| 5 | Lifecycle regression tests, this checkpoint | (this commit) |

## Status semantics (TireStatus)

| Status | Meaning | Used Stocks | Available for installation |
|---|---|---|---|
| IN_STOCK / RESERVED | new stock, never installed | — (New Stock) | yes |
| REMOVED | taken off a vehicle, awaiting inspection | yes | no |
| REUSE | inspected, fit for reuse | yes (counted in `reusable_qty`) | **yes** |
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

## DECISION REQUIRED

**1. REUSE tire in a Replacement — warehouse quantity**
- Current behavior (interim, implemented): a REUSE serial chosen as "Replacing With" is not
  requested in the Part Request (no new-stock quantity is issued) and is installed when the Work
  Order is completed.
- Issue: serial tires and product warehouse quantity are tracked separately; issuing a used tire
  through the new-stock quantity would deduct stock that did not move.
- Proposed: keep the interim rule.
- Alternatives: track REUSE tires as a separate "used" warehouse quantity and issue them through
  the Part Request like new stock.
- Affected modules: Tire Operations, Part Requests, Warehouse Stock.
- Data impact: none for existing rows. Inventory impact: none (no quantity moves for REUSE).
- Recommendation: keep the interim rule until used-tire warehouse quantities are required.
- Risks: REUSE tires are not visible in warehouse quantity reports (only as serials).

**2. Application limits enforcement**
- Current behavior: stored on the rule profile and shown as "Usage Restrictions" on the result; not
  enforced when the tire is installed.
- Proposed: block / warn on installation into a position outside the allowed positions.
- Affected modules: Tire Operations, installation. Data impact: none.
- Recommendation: warn first; enforce later once positions are standardized.
- Risks: without enforcement the restriction relies on the operator.

## Known limitations

- REMOVED / HOLD tires have no vehicle and (until REUSE) no warehouse, so branch- or
  warehouse-scoped users do not see them (the existing tire data-scope rule).
- The Used Tire Management → Scrap tab still allows scrapping HOLD / QUARANTINED tires directly
  (existing scrap action).
