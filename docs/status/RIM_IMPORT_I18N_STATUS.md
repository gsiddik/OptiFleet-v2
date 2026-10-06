# Rim Management, Excel Imports, Inventory Menu Rename — Status

Continuation checkpoint for the "Rim management, Vehicle/Product/Rim Excel import, inventory menu
renaming, bilingual alignment & MongoDB work isolation" task.

- **Baseline:** `main` @ `6c9349c` (merged into `claude/magical-volta-tv4xwl` with a no-op merge; the
  trees were identical).
- **Branch:** `claude/magical-volta-tv4xwl` (the session's designated branch; delivered to `main` by PR).

## Phases

| Phase | Status | Commit |
|---|---|---|
| Audit + MongoDB classification | DONE | — |
| Rim product + serialized stock + shared Excel engine + Rim serial import | DONE | `806c381` |
| Vehicle Excel template + bulk import | DONE | `5ca0ac8` |
| Item-Type-aware Product Excel template + bulk import | DONE | `afb4d0c` |
| Return → Stock Return, Transfer → Stock Transfer | DONE | `aac91b2` |
| Seeders (rim demo + functional, permissions) | DONE | `cf73911` |
| New Rim serial-tracking fix (found by the browser QA) | DONE | `7152a10` |
| Printed Stock Transfer keeps "Transfer Stok" (found by the regression) | DONE | `04b9cba` |
| Stock Transfer menu uses "Transfer Stok" (owner decision) | DONE | this commit |

## MongoDB isolation

- **Classification:** every change in this task is non-MongoDB (class A).
- **No new MongoDB work** was needed, so there is no deferred MongoDB branch.
- **Existing MongoDB code on `main`** (Analytics / Intelligence) is untouched. The analytics extractors
  read `component_assets`, so rim serials flow into the existing projection without code changes.
- **MongoDB suites:** NOT RUN, because there is no `ext-mongodb` / MongoDB service in this environment.
  The non-MongoDB regression sets the MongoDB migrations and tests aside.

## Design decisions

### Rim

- A physical rim is a **Component Asset** of a Rim Product.
  - Serial numbers are unique per tenant: case-insensitive in the service, with the existing partial
    unique index as the final guard.
  - The fitment is a **Component Installation**: vehicle + `position_location` = the Wheels
    Configuration position code.
  - Remove / replace / repair / sell reuse the component lifecycle.
- **Tires keep their own records**, so a tire and a rim coexist on one position.
- **Register Rim** has two modes:
  - **Installed:** serial + vehicle (tenant, branch scope, active Wheels Configuration) + configured
    position without an active rim. Runs under the vehicle row lock.
  - **New Stock:** serial + warehouse (tenant, warehouse scope). The database requires an IN_STOCK
    asset to have a warehouse, so the warehouse is mandatory.
- **An occupied rim position is rejected** with "remove or replace it first". Nothing is ever
  overwritten silently.
- **New Rim** opens the New Product form in a RIM context.
  - Item Type RIM, Component Group CG-TYRE (WTY — Wheel & Tyre System, the master record) and serial
    tracking are locked.
  - The same locks are enforced by `StoreProductRequest`.
- **Rim List** shows Products of Item Type RIM with the real `product_rims` fields only. The earlier
  standalone `rims` catalog stays readable at `/app/rims/catalog` (API unchanged; no data migration).

### Shared Excel engine

`App\Support\Spreadsheet\Import`:

- **Template:** exact sheets "How To" and "Fill Here". Headers are written in the request language;
  EN or ID headers are accepted on upload.
- **Reading and validation:**
  - only "Fill Here" is read;
  - cell formats are checked;
  - formula cells and `= + - @` prefixes are rejected;
  - upload checks cover type, MIME, size, empty file and malformed workbooks.
- **Preview:** each row is VALID, DUPLICATE or INVALID with localized reasons.
- **Import:** selected rows are validated again and saved one savepoint per row.
- **Template identity:** the optional `IdentifiesTemplate` marker is used by the Product templates.

### Vehicle and Product imports

- **Vehicle import:**
  - Columns are the New Vehicle form fields: Branch, Category, Brand and Model codes plus Registration
    are mandatory.
  - Rows are validated with the StoreVehicleRequest rules and created through the new
    `VehicleCreationService`, which POST /vehicles uses too.
- **Product import:**
  - One template per Item Type: the common fields plus that type's dynamic specification.
  - Rows are checked with `ProductCreationService::check()` and created with `createFromInput()` — the
    same rules as Create Product.
  - A template of another Item Type is rejected.

## Owner decisions / notes

- **Stock Transfer in Indonesian = "Transfer Stok" everywhere** (owner decision): the menu, title,
  breadcrumb and buttons use the glossary term, the same as the printed documents and the other screens.
- **Legacy `rims` catalog records stay as they are** (owner decision): not migrated into Rim serials.
  They stay readable at `/app/rims/catalog`.
- **Scope visibility:** a New Stock rim is in a warehouse, so it is visible to warehouse-scoped users.
  Installed rims follow the vehicle's branch.

## Validation (run in this session)

- **Full non-MongoDB backend regression:** 1200 passed, 1 failed.
  - The failure (`DocumentLocalizationTest`) was caused by the rename: "Stock Transfer" got a second
    Indonesian translation, so the document localizer no longer translated the Stock Transfer print
    template.
  - Fixed with a `documents.labels.stockTransfer` key (printed documents keep the glossary "Transfer
    Stok").
  - After the fix, the document / print / localization suites pass (52).

- **Backend targeted tests:**
  - RimManagementTest 8, VehicleImportTest 4, ProductImportTest 5;
  - related suites: Product / Rim / Tire / Component / Vehicle / Permission / Role (183 + 101 + 30)
    — PASS.
- **Seeders:** on a throwaway database, `migrate:fresh --seed` (with the demo layer) plus a second
  `db:seed` — identical rim counts (ALPHA 42 installed / 4 new / 2 used; FTEST 3 new). No duplicates;
  every installed rim shares its position with a tire.
- **Frontend:** tsc 0 errors, oxlint 0 errors, unit tests 49/49, `i18n:check` up to date, build OK. The
  hard-coded string audit is 0 outside the MongoDB-deferred Intelligence pages.
- **Browser QA (EN + ID): 52/52 checks.**
  - Rim list, New Rim locks, Rim detail and Register Rim.
  - Rim New Stock upload → preview → import.
  - Vehicle and Product import modals.
  - Stock Return / Stock Transfer in the sidebar and titles.
  - No raw translation keys; no page errors.
