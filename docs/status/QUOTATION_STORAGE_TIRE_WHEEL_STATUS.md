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
