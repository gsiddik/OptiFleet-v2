# Accounting feed (OA6, read-only pull API for OptiEntry)

OptiEntry pulls canonical financial events from OptiFleet on a schedule. OptiFleet keeps no consumer secret, no
webhook and no retry logic; OptiNexus is not involved. The existing outbox relay to OptiNexus is unchanged.

## Endpoint

`GET /api/v1/integration/accounting-feed?after=<cursor>&limit=<n>`

| Parameter | Meaning |
|---|---|
| `after` | Exclusive cursor: the last `sequence` already processed. Digits only, default `0` (from the start). |
| `limit` | 1..200, default 100. Out of range or not an integer: 422. |

Only `GET` exists (other verbs: 405). The call is read-only: no row is inserted, updated or deleted, not even a last-used stamp.

## Authentication and tenant

- Header `Authorization: Bearer ofaf_...`: a read-only machine credential, bound to exactly ONE tenant, scope `accounting.feed.read`.
  Only the SHA-256 of the token is stored (`accounting_feed_credentials`). It is not an OptiFleet user token.
- The tenant is taken from the credential row only. `tenant_id`, `tenant`, `X-Tenant-Id` or anything else in the request is ignored.
- 401 `FEED_UNAUTHENTICATED`: missing, unknown, revoked or expired token (one answer, no probing). 403 `FEED_SCOPE_MISSING`,
  403 `FEED_TENANT_NOT_ACTIVE`, 403 `FEED_SUBSCRIPTION_SUSPENDED`. Rate limit 60 requests per minute (429).
- Issue: `php artisan accounting-feed:issue-credential <tenant-id|code> --name=OptiEntry [--expires-in-days=N]` (token shown once).
  Revoke: `php artisan accounting-feed:revoke-credential <credential-id>` (effective on the next request).

## Response

```json
{ "data": [ { "sequence": 12, "event_id": "uuid", "event_type": "optifleet.workshop_invoice.recorded",
              "schema_version": "1.0", "source_system": "optifleet", "source_tenant_reference": "<OptiFleet tenant id>",
              "occurred_at": "2026-10-11T08:15:30.123Z", "correlation_id": "<work order id>",
              "source": {"type": "workshop_invoice", "id": "uuid", "number": "INV-001"},
              "dimensions": {"vehicle": "uuid", "vehicle_registration_number": "B 1234 XY", "work_order": "uuid",
                             "work_order_number": "WO-...", "branch": "uuid", "branch_code": "JKT"},
              "payload": { } } ],
  "next_cursor": "12", "has_more": false, "high_water": "12" }
```

- Ordered by `sequence`, never by timestamp. `sequence` is a per-tenant counter: starts at 1, no gaps, assigned in commit order
  (a counter row lock is held until the business transaction commits), so a consumer that has seen N never misses an in-flight N-1.
  A rolled-back business change consumes no number.
- `high_water` is the newest committed `sequence` of the tenant, as a string, read after the page (so never behind an event of the page).
  Because the numbering is gap-free and in commit order, a consumer that holds everything up to `high_water` holds every fact committed so far.
  OptiEntry uses it to bound the time it was not listening (pause or disconnect) and to refuse a cursor that is ahead of the source (a restored or reset
  source). Consumers should validate pages strictly: sequences are exactly `after+1, after+2, ...`, `next_cursor` equals the last sequence, an empty page
  does not advance and is not `has_more`, and nothing lies beyond `high_water`.
- `next_cursor` is the last returned `sequence` as a string; with an empty page it echoes `after` (default `"0"`). `has_more` says whether
  another page follows. Delivery is at-least-once: dedupe on `(source_system, event_id)`; `event_id` is the integration outbox row id,
  the same id sent to OptiNexus.
- `occurred_at` is when OptiFleet recorded the fact (UTC, milliseconds), not an accounting date. Accounting dates are in the payload
  (`invoice_date`, `due_date`, `payment.payment_date`).
- Money is a decimal string with 4 decimals, never a JSON number. `payload.currency` is the invoice currency.
- Each event is a snapshot taken in the same transaction as the business change and is immutable (database trigger). A later correction
  never rewrites an earlier event.
- `payload.snapshot_basis` is `event_time` for live events and `backfill_current_state` for events created later by
  `accounting-feed:backfill` (see Operations): treat the latter as history of uncertain state.
- Unknown `schema_version` major: do not guess. Additive fields stay in 1.x.

## Event types and payloads

Common payload of the four `workshop_invoice.*` events (the invoice facts at the time of the event):

| Field | Type |
|---|---|
| `vendor` | `{id, code, name, tax_id}` (the workshop partner) or null |
| `invoice_number`, `partner_reference` | string, `partner_reference` nullable |
| `invoice_date`, `due_date` | `YYYY-MM-DD`, `due_date` nullable |
| `currency` | string, e.g. `IDR` |
| `subtotal`, `discount_total`, `tax_total` | decimal string or null (null when the document did not state it) |
| `total_amount` | decimal string |
| `lines` | `[{description, quantity, unit_price, line_total}]`, numbers as decimal strings or null |
| `maintenance_memo` | `{id, number}` |
| `snapshot_basis` | see above |

| `event_type` | `source` | Extra payload | Accounting meaning |
|---|---|---|---|
| `optifleet.workshop_invoice.recorded` | workshop_invoice | none | AP invoice recognised |
| `optifleet.workshop_invoice.corrected` | workshop_invoice | `correction: {id, reason, previous_values, requested_values}`; the two maps hold only the changed fields, named like the fields above (`invoice_number`, `invoice_date`, `due_date`, `currency`, `subtotal`, `discount_total`, `tax_total`, `total_amount`, `partner_reference`, `lines`). The common fields are the corrected state. | reversal of the earlier posting plus a new one |
| `optifleet.workshop_invoice.cancelled` | workshop_invoice | `cancellation: {id, reason, had_payment}`; common fields are the state at cancellation | reversal; a payment, if any, is kept in OptiFleet and arrives as its own event |
| `optifleet.workshop_invoice.payment_recorded` | workshop_invoice | `payment: {id, amount, payment_date, method, reference_number}`; one full payment per invoice | settlement of the AP invoice |
| `optifleet.maintenance_memo.billed` / `.paid` | maintenance_memo (`number` = memo number) | informational only: `{informational: true, memo_number, memo_cost, currency, vendor, workshop_invoice: {id, number} or null, snapshot_basis}` | never post from these; the memo accrual versus invoice policy belongs to OptiEntry |

Not in the feed yet (no OptiFleet outbox event exists): vendor (procurement) invoice and payment, goods receipt, stock issue, spare part sale.
They will be added as new event types, per type, without touching existing ones.

## Operations

- Projection: a listener on `IntegrationOutboxEvent::created` (`AccountingFeedServiceProvider`) writes the feed event inside the business
  transaction. A projection failure is logged and never blocks recording an invoice.
- `php artisan accounting-feed:backfill [--tenant=ID]` adds feed events for outbox rows that have none (rows older than the feed, or a failed
  projection). Idempotent on the outbox row id; sequences continue after the existing ones. Run it once after deployment and, if desired, on a schedule.
- Cutover on the OptiEntry side stays its decision (default: opening balance only, events from the cutover date).

## Files

Migration `2026_10_22_000001_create_accounting_feed_tables` (credentials, per-tenant counters, events; existing tables untouched).
Code: `app/Domain/Integration/AccountingFeed/*`, `AuthenticateAccountingFeed` middleware, `Api/Integration/AccountingFeedController`,
`routes/api/integration.php`, `AccountingFeedServiceProvider` (registered by one line in `bootstrap/providers.php`), three `accounting-feed:*` commands.
Tests: `tests/Feature/AccountingFeedTest.php`.
