# Quotation Create PO Guard, Private File Storage, Tire Creation, Wheel Configuration Prototype — Status

Branch `claude/magical-volta-tv4xwl`, baseline `main` @ 4a858d0.

## Phase A1 — Quotation → Create PO (DONE)

Audit:
- Relation: `purchase_orders.vendor_quotation_id` (FK, **partial unique index**
  `purchase_orders_vendor_quotation_unique`) — one PO per quotation. Source of truth for "already
  converted".
- Quotation statuses (DB check constraint): `SUBMITTED`, `SELECTED`, `REJECTED`. `CLOSED` is an
  **RFQ** status (set when a vendor is selected, or by a manual close without selection) —
  a quotation itself can never be `CLOSED`.
- Backend already refused non-selected quotations and a second PO (row lock + unique index).
- Gaps: RFQ Detail showed **Create PO for every SELECTED quotation, even after a PO existed**;
  the Create PO page opened by direct URL showed the form; and `selectVendor` did not check the
  RFQ status, so a quotation of a manually CLOSED / CANCELLED RFQ could still be selected and
  converted.

Implemented:
- `VendorQuotation::purchaseOrder()` (hasOne via `vendor_quotation_id`) and
  `canCreatePurchaseOrder()` = status `SELECTED` and no PO — one rule, used by the API payloads
  (`can_create_purchase_order`, `purchase_order {id, po_number, status}`) on RFQ compare,
  quotation detail and quotation list (eager-loaded, no N+1).
- `PurchaseOrderService::createFromQuotation` (unchanged guard, clearer message
  "This quotation is {STATUS}: only a selected quotation …") → 422; duplicate → 422 "already
  converted" (row lock + unique index backstop).
- `RfqService::selectVendor`: RFQ must be `ISSUED` and the quotation `SUBMITTED` (row-locked) → 422.
- UI: RFQ Detail shows Create PO only when `can_create_purchase_order`, otherwise a link to the PO;
  Select only while the RFQ is ISSUED. Vendor Quotations list gets a "Purchase Order" column
  (PO link / Create PO / —). The Create PO page opened directly for an ineligible quotation shows
  the existing PO (or the reason) instead of the form.

Tests: `PurchaseOrderFromQuotationTest` +2 (eligible → offered in compare/show/list and accepted;
converted → hidden everywhere with PO number, API 422, still 1 PO; RFQ closed without selection →
not offered, API 422, select 422; REJECTED → not offered, 422) — procurement suites 41 passed.
Browser (Alpha): eligible quotation → Create PO on RFQ and list → PO created; afterwards no
Create PO anywhere, PO link shown, direct URL shows "already created … PO/2026/000002", direct API
rejected; manually closed RFQ → no Select / Create PO, select API rejected.

## Phase A2 — Private document storage (DONE)

### Root cause (shared by vendor-invoices and vendor-quotations)
1. **Mixed runtime users in the backend image.** `storage/` was chowned to `www-data` at build
   time and php-fpm workers run as `www-data`, but the entrypoint's artisan steps, `queue-worker`,
   `scheduler` and every `docker compose exec backend php artisan …` ran as **root** — including
   the documented `db:seed --class=DevDemoSeeder`, which writes demo vendor invoices and vendor
   quotation documents.
2. **The `local` (private) disk creates directories as 0700** (Laravel default
   `directory_visibility` = private). A root-created `vendor-invoices/` or `vendor-quotations/`
   folder is therefore not even traversable by `www-data`, so every later upload under it fails
   with `Unable to create a directory at …/<feature>/<tenant>` — for any tenant, existing or new.
   Features nobody seeded as root kept working, which is why only these two broke.
3. Aggravating: the raw Flysystem error (with the server path) reached the user; `storeAs`
   returns `false` silently for some write errors (the External WO file service would then
   save a record pointing at no file); uploads were not on a volume (lost on container
   re-creation); the build context had no effective `.dockerignore` (the one in `backend/` is
   ignored because the context is the repo root), so local `.env` / storage could be baked in.

Reproduced on this host with the real Flysystem adapter and private visibility: root writes
`vendor-invoices/tenant-A/…` → `www-data` upload for tenant-B **and** tenant-A fails with exactly
"Unable to create a directory at …/vendor-invoices/tenant-B. mkdir(): Permission denied".

### Fix (no chmod 777)
- `docker/backend/Dockerfile`: create the writable tree, chown to `www-data`, install `su-exec`,
  **`USER www-data`** — php-fpm, entrypoint artisan steps, queue-worker, scheduler and
  `docker compose exec` all run as the storage owner.
- `docker/backend/entrypoint.sh`: started as root (`--user root`) → create the tree, chown only
  entries not owned by `www-data`, then re-exec as `www-data` via `su-exec`; started as
  `www-data` with a foreign-owned entry (older deployment) → refuse to start and print the
  one-time repair command `docker compose run --rm --user root backend true`.
- `docker-compose.yml`: named volume `optifleet_storage` → `storage/app` for backend,
  queue-worker and scheduler (uploads persist and are shared).
- Root `.dockerignore`: no `.env`, `vendor`, tests, runtime storage/caches in the image.
- App: `PrivateDocumentStorage::putFileAs()` is the single write path for private uploads
  (Vendor Invoice via GoodsReceipt / payments, Vendor Quotation, External WO files: signed WAL,
  completed WO, vendor invoice, payment proof). Any storage failure → logged with details, user
  gets **503** "The document could not be saved on the server, so no changes were made…" — no
  path; and `false` results are no longer saved as records.
- Transaction integrity (already in place, now covered by tests): GR + invoice use
  `PrivateDocumentStorage::persist()` (file first → one DB transaction → file deleted if the DB
  fails); a failed file store throws before any DB write. Quotation: file stored first, deleted if
  the DB insert fails. WAL acknowledge: upload inside the transaction before the status change.
- README: run artisan as the image user, never `--user root`, volume and repair notes.

### Verification
- Tests: storage-failure tests (disk throwing the production error) for GR + invoice (503, no
  path, **no GR, no invoice, PO/stock unchanged**, then the same receipt without a document
  posts), quotation (503, nothing recorded, then without document OK) and signed WAL (503,
  status unchanged, no file record). All private-upload suites (20 files): 145 passed.
- Entrypoint verified on this host (su-exec emulated with runuser, php stubbed): incident state
  → www-data start refuses with the fix; `--user root` start repairs ownership and every
  artisan step + final process run as `www-data`; uploads (vendor-invoices, vendor-quotations,
  new tenant) then succeed with the real adapter; re-seeding as `www-data` keeps working.
- Browser (Alpha): GR + vendor invoice PDF → saved, reload, download identical bytes
  (`application/pdf`), "View Invoice" in list; RFQ ISSUED → quotation with PDF + one without →
  both recorded, View/Download identical bytes.
- Dockerfile lint (`docker build --check`): no warnings. **Full image build: NOT RUN** — this
  sandbox's egress policy returns 403 for `dl-cdn.alpinelinux.org`, so `apk add` cannot run
  inside the build (Docker Hub was also rate-limited; the base image was pulled via mirror).
  To verify in a real environment: `docker compose build backend && docker compose up -d`,
  then `docker compose exec backend id -un` → `www-data`.

Audited, unchanged (own `storeAs` with tenant directories on the same disk/foundation):
vehicle documents/photos, product images / SDS, removed components, part-return and used-part
evidence, payment submissions (via PrivateDocumentStorage). They benefit from the deployment fix.

## Phase A3 — Tire creation through Product (DONE)

Audit:
- Product (Item Type = Tire) creates the product master + `product_tires` specification only.
- A **physical tire** (serial-numbered `tires` row — what Tire List, install, rotate, retread,
  scoring work on) could only be created by the Tire List "+ New Tire" modal (`POST /app/tires`),
  which re-entered size/construction/load/speed/ply by hand and accepted **any** product.
- No standalone `/tires/new` or `/tires/create` route exists (only the modal) → no dead links.

Removing the button alone would leave no way to register physical tires, so only the standalone
entry point is removed and registration moves into the Product context:
- Tire List: "+ New Tire" and its modal removed; hint "New tires are created from Products (Item
  Type: Tire)" → `/app/products?product_type=TIRE` (Products list honours the filter param).
- Product Detail (Item Type Tire, `tire.manage`): **Register Tire** → serial number, manufacture
  date code (DOT), purchase date; shows the product's spec.
- `POST /app/tires` → `TireRegistrationService`: product must be Item Type Tire (422 otherwise);
  spec fields not sent are taken from the product spec (size, pattern, construction, tube type,
  width, aspect, rim, load index, speed symbol, ply rating); explicit values still win.
- Tire model, API, list, detail and all lifecycle actions unchanged.

Tests: new `ProductDynamicSpecificationTest` case (create Tire product via product API → register
with serial only → spec inherited, IN_STOCK, override wins, listed in Tire List, non-tire product
422); Tire + Product suites 191 passed. Browser (Alpha): no New Tire button; hint opens Products
filtered to TIRE; Register Tire on "Truck Tire 295/80R22.5" → tire created with 295/80 R22.5 ·
Highway Rib · RADIAL · TUBELESS, opens in Tire Detail, appears in Tire List; existing tires and
their detail pages unchanged.

## Phase A release gate (PASS)

| Check | Result |
|---|---|
| Quotation Create PO | PASS |
| Vendor Invoice Upload | PASS |
| Vendor Quotation Upload | PASS |
| Tire Creation Consolidation | PASS |
| Frontend Build | PASS |
| Frontend Lint | PASS (findings identical to baseline) |
| Backend full regression (PostgreSQL) | PASS — 973 passed (5241 assertions) |
| Seeders (fresh migrate + all seeders twice) | PASS — no errors, identical counts |
| MongoDB Analytics / Intelligence tests | NOT RUN — no MongoDB in this environment |
| Backend Docker image build | NOT RUN — sandbox egress blocks Alpine packages (see A2) |

Commits: 01edbd3 (quotation guard), e36089c (private storage), 591ee61 (tire creation).

## Phase B — Wheel Configuration prototype (PROTOTYPE — awaiting owner review)

Started only after the Phase A gate passed and was pushed. Separate commit. **Not a production
feature**: nothing is persisted; existing wheel-configuration data and API are untouched.

- Page: `/app/wheel-configurations/new` (Tire Management → Wheel Configuration →
  **New Wheels Configuration**, permission `tire.manage`). The old "+ Add Position" is no longer
  the primary action; the single-position modal stays as secondary "Add Single Position" until
  the new flow is approved and persisted.
- Vehicle Type (mandatory): Passenger Car, Truck, Bus, Forklift, Van, Heavy Equipment — one enum
  (`vehicleTypes.ts`). Only Passenger Car has a form; the others show "Configuration form for
  this vehicle type will be added after prototype approval." (no rules invented).
- Passenger Car fields: Number of Front Axles → one "Front Axle n — Wheels / Side" row per axle;
  Number of Rear Axles → "Rear Axle n — Wheels / Side" rows; Spare Tires. All mandatory
  whole-number text inputs (no spinbox, no sign/decimals). Summary (read-only): Total Axles,
  Total Wheels, Config Code — recalculated on every keystroke.
- Calculations (`wheelLayout.ts`, pure, server-portable): Total Axles = front + rear;
  Total Wheels = Σ(front wheels/side × 2) + Σ(rear wheels/side × 2) + spare;
  Config Code = front digits "." rear digits (22.222, 12.221, 12.21); empty group written as "0"
  (0.22 / 22.0). Position code `<axle in group><F|R><L|R><wheel from body>` (1FL1, 2RR2);
  spares S1…Sn. Limits: wheels/side 1–4 (Config Code is one digit per axle), axles per group 0–6
  with ≥1 axle in total, spare 0–4.
- Preview (`wheelPreviewGeometry.ts` + `WheelConfigurationPreview.tsx`, SVG generated from the
  data): top view, FRONT label + arrow, rounded nose, windshield behind the front axles, rear
  window; small axle pitch inside a group, larger labelled wheelbase gap; wheels as rounded
  rectangles, wheel 1 against the body and further wheels outwards, codes inside each tire;
  axle lines drawn under body and wheels, ending at the centre of the outermost tire (never past
  it); spare tires in a separate labelled column outside the body; dashed axle while wheels/side
  is not entered.
- Responsive: desktop form | preview side by side (preview sticky); tablet (820 px) stacked with
  no horizontal scroll.
- Verified: calculation/geometry checks (all scenarios incl. axle-line ends inside outer tire,
  wheelbase gap 150 vs pitch 50, spare outside) and browser at 1440 / 820 px: §52 → 5 / 21 /
  22.222 with 2 + 3 axle lines, 20 wheels, 1 spare, FRONT visible; §53 → 4 / 12 / 12.21 with
  exact wheels per axle; validation (Required, range, 9 axles rejected), Save disabled.

## Phase C — Wheel Configuration: owner decisions applied

### C1 — Final business rules (DONE)
Owner decisions (final): wheels/side 1–4; axles per group 0–6 while editing, ≥1 front AND ≥1 rear
to be valid/saved; spare 0–4; rear axle numbering restarts at 1; spares S1–S4; Config Code
`<prefix><front>.<rear>` with prefix `+` Truck·Trailer, `-` Truck·Semi Trailer, none otherwise —
never an empty group (`0.22` / `22.0` impossible).

- Backend `App\Domain\Tire\Services\WheelConfigurationRules::evaluate()` — validator →
  totals → Config Code → generated position list (front, rear, spares; position_code, group,
  axle_in_group, overall axle_number, side, wheel_index, label, sequence). Final authority for the
  future Save; no persistence yet.
- Frontend `wheelLayout.ts` / `vehicleTypes.ts` mirror the rules for the live preview
  (`configCode()` returns null with an empty group, `saveErrors()`, `configCodePrefix()`).
- Shared cases `backend/tests/fixtures/wheel_configuration_cases.json` (10 valid incl.
  22.222 / 12.221 / 1.1 / +22.222 / -22.222, 10 invalid incl. 0 / 5 wheels, 7 axles, 5 spares,
  empty groups, truck without type) run by `WheelConfigurationRulesTest` (5 tests) and by the
  frontend logic check — both pass.

Audit (for Save / replacement, not implemented yet): positions live in `wheel_configurations`
(vehicle_category_id, free-text position_code, unique per tenant+category+code; tenant_id NULL =
platform default). Tire installation references positions **by code string**, not FK
(`TireService` only validates the code against the category's configured positions when any
exist). Replacing a category's list therefore cannot orphan FKs, but a tire currently installed
on a code that disappears must be handled (block or map) — to be designed with the Save.

### C1b — Manual "Add Single Position" removed (DONE)
Dependency audit: the button + create modal on Wheel Configuration were the only callers of
`POST /app/wheel-configurations`; Tire Detail reads positions (install dropdown) and
`TireService` validates install positions against them. Removed: the user-facing button and the
create modal only. Kept: model, list, edit/delete of existing rows, read API, install
validation, and the store API (backend tests, internal use; the future Save writes generated
positions server-side). Existing historical positions untouched. Interim gap until Save ships:
no new positions can be added from the UI — non-blocking, because a category without configured
positions accepts free position codes at tire install.

### C2 — Bus / Forklift / Van / Heavy Equipment prototypes (PROTOTYPE — awaiting review)
Same form, validation, calculations, Config Code and position rules as Passenger Car (one shared
`AxleConfigurationForm`). Preview architecture: layout engine `buildPreviewGeometry(input,
bodyStyle)` with per-type proportions (`BODY_PROFILES`: body width, nose, tail, axle pitch,
wheelbase, front extension) → shared axle-line / wheel / spare / label renderers → per-type body
renderer (`VehicleBodies.tsx`). Bodies: Bus (long glazed box, roof AC + hatches, side window
strips, rear grille); Van (short hood, windshield, roof rails, split rear doors); Forklift
(mast + forks in front, overhead guard, rear counterweight); Heavy Equipment (generic chamfered
chassis, front hazard bar, central cab, rear engine hood); Passenger Car unchanged.
Checks (browser, every type, 22.222 and 12.221 with 3 spares): summary 5 / 21 / 22.222, wheels per
axle exact, every axle line ends inside its outermost tires (measured on the rendered SVG), no
shape outside the drawing; 1440 / 820 / 390 px: no page overflow, preview inside its card, no
overlap with the form, all 4 spares visible (stacked below the form on tablet/phone).

### C3 — Truck Configuration Type + Non Trailer (PROTOTYPE — awaiting review)
Vehicle Type = Truck shows a mandatory **Truck Configuration Type** (Non Trailer / Trailer / Semi
Trailer); the axle form appears once it is chosen and resets when the type changes. Same form and
rules; the Config Code prefix comes from `configCodePrefix()` (Non Trailer none, Trailer `+`,
Semi Trailer `-`) and only decorates the shared code. Non Trailer body: cab (rounded front,
windshield, mirrors) over the steer axle + separate ribbed cargo box. Browser: 22.222 / +22.222 /
-22.222 and +12.221 / -12.221 (5 axles, 17 wheels with 1 spare), 1.22, axle lines end inside
outer tires for every case.

### C4 — Trailer / Semi Trailer prototypes (PROTOTYPE — awaiting review)
- Trailer (`+` code): box/cargo trailer (front bulkhead, roof ribs, rear doors) with an A-frame
  tow connector triangle attached to the front and pointing forward, ending in a tow eye (drawn
  in the space reserved above the body). Front group = trailer front axles, rear group = rear.
- Semi Trailer (`-` code): cab-over tractor (same cab as Truck) over the steer axle, visible
  chassis behind it, trailer front resting on the tractor's fifth wheel — kingpin marker over the
  last tractor axle, trailer front always ahead of it. Front group = tractor axles (steer → drive
  spacing 64 vs 50 inside groups), rear group = trailer axles, wheelbase 300.
- Layout engine: optional per-profile `frontAxlePitch`; the body renderer map is now complete for
  all 8 bodies (TypeScript requires a body per style).

Regression (browser, rendered SVG measured): every type × 22.222 / 12.221 (+ / − for trucks) /
1.2 → wheels per axle exact, all axle lines end inside their outermost tires, nothing outside the
drawing; 8 types × 1440 / 820 / 390 px → no page overflow, preview inside its card, no overlap
with the form, 4 spares visible. Frontend rules = shared cases; backend wheel + tire suites 101
passed; build PASS, lint = baseline.

### Awaiting owner review (superseded — prototype approved, see C6/C7)
Body proportions and silhouettes (Bus, Van, Forklift, Heavy Equipment, Truck, Trailer, Semi
Trailer), Trailer tow triangle, Semi Trailer tractor/coupling layout, axle spacing and overall
usability. Then: Save (validate → generate → replace the category's position list with
versioning / handling of positions in use), Config Code uniqueness (vehicle type + truck
configuration type + code; `22.222`, `+22.222`, `-22.222` distinct).

### C5 — Owner feedback round 1 applied (PROTOTYPE — awaiting review)
Decisions: Semi Trailer front group = tractor axles, rear group = trailer axles; one reusable
layout engine with type-specific proportions; Trailer = box + centered triangular tow bar;
Semi Trailer = short cab + visible coupling/chassis gap + subtle kingpin; three spacing levels;
phone width uses tap/popover instead of inline codes; no over-design.

- Proportions (`BODY_PROFILES`): body width = per-type multiple of the tire width; length = front
  overhang + front group + wheelbase + rear group + rear overhang (overhangs in tire lengths).
  Spacing: intra-group 50 for every type < wheelbase (Forklift 96, Car 150, Van 170, HE 190,
  Truck 220, Trailer 230, Bus 260) < Semi tractor–trailer 300. Body length (2+3 axles): Forklift
  345 < Car 412 < Van 439 < Bus 553.
- Trailer: framed box (edge rails, corner posts, cross members); A-frame tow bar centered, base ≈
  64% of box width, length 20% of box length (measured 19.2–19.7% for 1+1 … 6+6 axles).
- Semi Trailer: cab 53 vs trailer 470, 22-unit gap showing the chassis, small dashed kingpin
  (r 6, opacity 0.7, no label) on the trailer over the last tractor axle.
- Silhouettes simplified: Bus (no hatches/grille), Van (mirrors + cab/cargo line, merged rear
  body), Heavy Equipment (heavier outline).
- Position codes: printed on tires when the preview is ≥ 420 px wide (desktop/tablet); below that
  (phone) hidden — every tire/spare is a button (tap / Enter) opening a popover with code and
  meaning ("1RL2 · Rear axle 1 · Left · wheel 2"), selected tire highlighted, tap elsewhere /
  Escape closes; collapsible "Position list" under the preview (per axle, mirrors the drawing).
- Verified: all types × 22.222 / 12.221 / 1.2 axle lines inside outer tires; 8 types × 1440 / 820 /
  390 px layout; spacing / tow bar / semi measurements above; popover above the tire inside the
  viewport; rules parity; build PASS, lint = baseline.

### C6 — Owner approval adjustments (DONE, `281abdd`)
Prototype approved with minor adjustments: Trailer tow bar shortened (length 20% → 15% of the box,
measured 14.0–14.6%; width unchanged); Semi Trailer tractor/trailer gap 22 → 32 units (kingpin
unchanged). Phone-width code text not reduced further (tap/popover + Position list kept). Other
silhouettes, spacing, wheel placement and responsive behavior approved as is.

### C7 — Production Save: versioning + position-set diffing (SUPERSEDED by C8 — wrongly vehicle-category coupled)
Never delete-all-and-recreate. One transaction under an exclusive Postgres advisory lock per
(tenant, vehicle category): Validate → Generate Positions → Diff → Check Active Tire Installations
→ Create Version → Apply Position Changes → Activate → Commit.

- Schema (migration `2026_10_02_000004`, additive): `wheel_configuration_versions` (version_number,
  vehicle_type, explicit `truck_configuration_type`, server-generated `config_code`, axles JSON,
  spare/totals, status ACTIVE/SUPERSEDED with one-ACTIVE partial unique index, `position_diff`,
  created_by, activated/superseded_at; category FK RESTRICT so history survives).
  `wheel_configurations` gains `status` ACTIVE/RETIRED (existing rows ACTIVE), `retired_at`,
  `introduced_in_version_id`, `retired_in_version_id`, `position_group`, `axle_in_group`, `side`,
  `wheel_index`.
- Diff by position code against the positions currently applying (tenant rows; platform defaults
  too until the tenant's first version): UNCHANGED keep their row, ADDED create a row or reactivate
  the same RETIRED row, REMOVED are RETIRED (never hard-deleted). Platform rows are never modified;
  once a tenant has a version they stop applying to that tenant only.
- Blocking: any active tire installation on a vehicle of the category at a position the new
  configuration lacks (REMOVED, or a legacy unconfigured position) → 422 naming position, tire
  serial and vehicle registration; nothing changes; tires are never moved automatically.
- Identical configuration → existing version returned (200, no new version). `22.222` / `+22.222`
  / `-22.222` are distinct versions (same axles → all positions UNCHANGED).
- Backend regenerates Config Code; a client `config_code` that differs → 422.
- Tire install/rotate validate positions inside their transaction under a shared lock (a save
  waits for/blocks them) and ignore RETIRED positions. Legacy position edit/create/delete refused
  once a category is versioned; legacy delete retires a position referenced by history and refuses
  an occupied one.
- API (`module:TIRE`): `GET /app/wheel-configuration-versions` (tire.view),
  `POST /app/wheel-configuration-versions/preview` (dry run) and `POST /app/wheel-configuration-versions`
  (tire.manage). `GET /app/wheel-configurations` hides RETIRED unless `include_retired=1`.
- UI: Vehicle Category select + current version; Save → dialog with Unchanged / Added / Removed
  and a blocker table (position, tire link, vehicle); Confirm only when nothing blocks.

Validation (this session):
- `WheelConfigurationVersionTest` 17 tests PASS (creation, idempotency, versioning, unchanged /
  added / removed, reactivation, installed-tire blocking + atomicity, history integrity, three
  truck types, config code mismatch, invalid input, preview no-write, platform rows, legacy
  unconfigured installs, tenant isolation, permissions, legacy endpoint guards).
- Targeted regression: Tire* + WheelConfiguration* 118 tests PASS; WorkOrderClosureGuardTest PASS.
- Browser e2e (`e2e_wc_save`): first save, identical save, blocked save (dialog + direct API 422),
  save after tire removal (2RL2/2RR2/S1 retired, installation history intact), Trailer and Semi
  Trailer versions, 390 px dialog fit — PASS. Prototype e2e (all types, truck, trailer/semi) PASS.
- Lock contention smoke: install waited ~2.6 s while the exclusive lock was held — PASS.
- Seed check twice (idempotent) PASS; frontend build PASS; oxlint on changed files clean.
- NOT RUN: Mongo analytics/intelligence tests (MongoDB unavailable); Docker image build (egress).

### C8 — Correction: Wheel Configuration is a vehicle-independent master (DONE, `5ec2cb9`)
Owner correction: Wheel Configuration manages only reusable configuration masters/templates. It
does not manage which vehicle (or vehicle category) uses a configuration; that mapping is a
separate future feature. C7 had coupled Save to a vehicle category and to installed tires.

Coupling found (all introduced in C7 on this unmerged branch, not on `main`):
- UI: Vehicle Category select + "Current configuration: … (version N)"; save dialog blocker table
  (position / tire / vehicle) and installed-tire warning.
- API: `/app/wheel-configuration-versions` (index / preview / store) keyed by `vehicle_category_id`.
- DB: `wheel_configuration_versions.vehicle_category_id` (one ACTIVE per tenant+category);
  lifecycle columns on legacy `wheel_configurations` (status/RETIRED, retired_at,
  introduced/retired_in_version_id, position_group, axle_in_group, side, wheel_index).
- Service: `WheelConfigurationVersionService` (category diff, active tire installation check over
  vehicles of the category, retire/reactivate legacy rows), `WheelPositionCatalog` (per-category
  advisory lock), `WheelConfigurationBlockedException` + renderer.
- Other modules: `TireService` install/rotate validation took the category lock and filtered
  RETIRED rows; legacy `WheelConfigurationController` refused edits for versioned categories and
  retired-instead-of-deleted. Test: `WheelConfigurationVersionTest`.

Removed / refactored:
- `TireService`, `WheelConfigurationController`, `WheelConfiguration` model, `bootstrap/app.php`
  restored byte-for-byte to their pre-C7 state (`78ad331`); coupled classes and test deleted.
- Forward migration `2026_10_02_000005` (non-destructive for pre-existing data): drops the C7
  columns/table (only C7 code ever read them) and creates the master model. Legacy
  `wheel_configurations` rows are untouched. Verified up → down → up on the e2e DB (11 legacy rows
  preserved); `down()` restores the 000004 shape.

Final architecture — NO vehicle assignment in this feature:

    Wheel Configuration Master   (tenant, vehicle_type, truck_configuration_type, config_code, status)
            ↓ 1..n
    Configuration Version        (version_number, config code, axles, spare, totals,
                                  ACTIVE/INACTIVE, position_diff vs previous version)
            ↓ 1..n
    Generated Wheel Positions    (position_code, group, axle, side, wheel index, label, sequence)

- Identity: `vehicle_type` + `truck_configuration_type` + `config_code` as explicit fields, partial
  unique index (COALESCE on truck type) over ACTIVE masters → `22.222`, `+22.222`, `-22.222`
  distinct. Vehicle Type / Truck Configuration Type of a master are fixed; edit changes the axle
  pattern only. One ACTIVE version per master (partial unique index).
- Save: Validate → Generate Config Code (server; client value only compared) → Generate Position
  List → Save Configuration Version. Edit with a change → new version, previous INACTIVE with its
  own position list kept; unchanged edit → no version. No installed-tire check.
- API (`module:TIRE`): `GET /app/wheel-configuration-masters` (tire.view), `GET …/{id}` (versions +
  positions, tire.view), `POST …/preview`, `POST …`, `PUT …/{id}` (tire.manage).
- UI: form without any vehicle/category field; edit route `/app/wheel-configurations/:id/edit`;
  save dialog (Vehicle Type, Configuration Type, Config Code, totals, generated positions, diff on
  edit); list of masters (Vehicle Type, Truck Configuration Type, Config Code, Version, Total Axles,
  Total Wheels, Status, Updated At) with a version-history view. Pre-existing per-category position
  rows are hidden from the Wheel Configuration UI (owner decision, C9).

Future scope (not implemented): Vehicle → Assign / Change Wheel Configuration — current vehicle
configuration → new configuration → diff positions → check installed tires → block if removed
positions are occupied.

Validation (this session):
- `WheelConfigurationMasterTest` 14 tests PASS (Passenger Car 22.222 without vehicle, no vehicle
  columns, Truck NON_TRAILER / TRAILER / SEMI_TRAILER 22.222 / +22.222 / -22.222, duplicate
  identity, edit 1.22 → 1.21 with stored diff and history, unchanged edit, fixed type, identity
  clash on edit, preview, server config code, invalid input, save ignores installed tires, tenant
  isolation, permissions).
- Regression: Tire*, Vehicle*, WheelConfiguration* (incl. Rules + legacy), WorkOrderClosureGuard —
  143 tests PASS. Frontend↔backend rules parity (20 cases) PASS.
- Browser e2e: create the 4 identities + 1.22, duplicate blocked, list columns, edit 1.22 → 1.21
  (version 2, diff, v1 positions kept), 390 px dialog; prototype e2e (all types, trailer/semi,
  feedback measurements) PASS; Tire list/detail, Vehicle list/detail load without errors.
- Typecheck PASS, build PASS, oxlint 28 warnings all pre-existing (none in changed files), Pint on
  new files PASS, seed check ×2 PASS.
- NOT RUN: Mongo analytics/intelligence tests (MongoDB unavailable); Docker build (egress).

### C9 — Owner decisions after C8 (DONE)
1. Legacy per-category wheel positions are hidden entirely from the Wheel Configuration page (the
   collapsed "Legacy category wheel positions" section and its edit/delete UI removed). Their data
   and the `/app/wheel-configurations` API are kept unchanged: Tire Detail still reads them for the
   install position dropdown and `TireService` still validates against them, until the future
   Vehicle → Wheel Configuration assignment replaces that.
2. Confirmed: once a configuration exists, its Vehicle Type and Truck Configuration Type cannot be
   changed (already enforced in C8: disabled in the edit form, 422 from the API).

Validation: typecheck PASS, oxlint on changed files clean, build PASS; browser check — list shows 5
masters and no legacy section (API still returns the 11 legacy rows), Tire Detail loads without
errors.

## Phase D — Wheels Configuration list/detail/edit, Vehicle Mapping, Vehicle tab, Tire registration

Architecture: Wheels Configuration Master → Configuration Version → Generated Positions → Vehicle
Mapping (version-specific) → Vehicle Wheels Configuration → Tire Installation → Tire List.
Warehouse inventory stays a separate domain: initial tire registration never touches stock.

### D1 — List / Detail / Edit (DONE, `fa9116a`)
- List paginated: Vehicle Type, Truck Configuration Type (— for non-truck), Config Code, Total
  Wheels, Spare Tire, Version, Status; actions View Detail / Edit (tire.manage) / Vehicle Mapping.
- Detail page: all saved fields per version (front/rear axles + wheels per side, totals, spare),
  preview from the saved version (same renderer), generated positions, diff, mapped vehicle count.
- Edit reuses the existing form; editing creates a new version; mapped vehicles stay on theirs
  (impact note in the form and the save dialog).

### D2 — Vehicle Mapping (DONE, `7727c55`)
- Table `vehicle_wheel_configuration_mappings` (history + active, version-specific; partial
  unique index one ACTIVE per vehicle; FKs RESTRICT to vehicles/masters/versions; end reason
  UNMAPPED / VERSION_UPDATED; previous_mapping_id chain).
- Vehicle attribute audit: `vehicles.vehicle_type` was free text, never editable in the UI
  (seeded "Car"/"Truck"); `axle_count` / `wheel_count` existed (nullable) on Edit Specifications.
  No new vehicle fields: `VehicleTypeClassifier` resolves legacy text (Car → PASSENGER_CAR …), the
  vehicle form gains a Vehicle Type select (stores the type code; legacy text kept if unknown), and
  Wheels is labelled "incl. spare" because configuration Total Wheels includes spares.
- Eligibility (backend): resolved type = configuration type AND axles = Total Axles AND wheels =
  Total Wheels of the current version, in data scope, not disposed, no active mapping anywhere.
  Vehicles with missing data / mapped elsewhere are excluded and counted. Truck: vehicles carry no
  trailer classification, so a Truck vehicle is compatible with any Truck Configuration Type.
- Save (`PUT …/vehicle-mappings`, wheel_configuration.map_vehicle): add / remove / update-to-current-
  version, atomic, rows locked; history rows written; returns the refreshed page.
- Page: VIEW first, Edit → Cancel / Save, click or Enter moves rows (draft), confirm summary
  (Added / Removed / Changed), blocker display, responsive.
- Permission `wheel_configuration.map_vehicle` (migration grants it to roles with tire.manage;
  PermissionSeeder + demo/functional role lists updated).

### D3 — Vehicle Detail → Wheels Configuration tab (DONE, `ccc45a6`)
- `GET /app/vehicles/{vehicle}/wheel-configuration` (tire.view, data scope): mapped VERSION (not the
  latest), positions, active installations per position, mapping history.
- Empty state → "Open Wheels Configuration List" with vehicle context (`?vehicle=`): list filtered
  by the backend (`compatible_vehicle_id`), Vehicle Mapping highlights the vehicle. Mapping stays
  in the single Vehicle Mapping flow.
- Mapped state: summary, newer-version notice, preview with installed markers, position panel.

### D4 — Initial tire registration (DONE, `de62901`)
- `POST /app/vehicles/{vehicle}/wheel-configuration/tires` (tire.install) + `GET …/tire-products`
  (Item Type Tire lookup, tire.install — `/app/products` needs product.view).
- Transaction under the vehicle row lock: position ∈ mapped version and free → product is Tire →
  serial trimmed, case-insensitive match (reuse a loose IN_STOCK/RESERVED tire of the same product
  outside any warehouse; reject installed / other product / other status / warehouse-held) →
  create tire via TireRegistrationService → TireService::install (INSTALLED, baseline tread
  inspection) → `tire_installations.installation_source = INITIAL_REGISTRATION` (new column,
  default STANDARD). Date + HH:mm are read in the tenant timezone. KM / tread: decimal text, ≤ 2
  decimals, ≥ 0 (never truncated). Required: date, time, KM (an estimate is acceptable), product,
  serial; tread depth optional (owner decision).
- No inventory effect: no warehouse_stocks, stock_movements, stock_reservations, stock_transfers or
  goods receipt writes (asserted in tests and e2e).
- TireService install/rotate validate positions against the mapped version when the vehicle is
  mapped (legacy category positions otherwise), inside the transaction with a shared vehicle lock.

### D5 — Mapping safety + regression (DONE)
- Remap rule: any add / version update checks the vehicle's active installations against the
  target positions; a tire on a missing position blocks with vehicle, position and serial; tires
  are never moved; positions that remain keep their installation rows untouched.
- Finding: the seeded truck B 1001 ALP has a tire on the legacy position REAR_LEFT, so it cannot
  be mapped to a generated-position configuration until that tire is moved/removed (by design).
- Unmapping a vehicle with installed tires is allowed; re-mapping is protected by the rule above.

Validation (this session): WheelConfigurationMaster 15, WheelConfigurationVehicleMapping 10,
VehicleWheelConfiguration 4, VehicleTireRegistration 6 PASS; Tire/Wheel/Vehicle regression 164
PASS; full non-Mongo backend regression 1013 PASS; frontend lint 0 errors (28 pre-existing warnings), typecheck + build PASS; browser e2e for every phase
PASS (list/detail/edit, mapping view/edit/cancel/save/remove, vehicle tab empty/mapped/context,
registration form, decimals, duplicate serial, Tire List, warehouse unchanged, remap blocker,
390 px); lock smoke: registration waited ~2.6 s while the vehicle row was locked; seed ×2 PASS.
NOT RUN: Mongo analytics/intelligence tests (MongoDB unavailable).
