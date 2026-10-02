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
