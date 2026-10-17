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

## Authentication, tenant and pairing

- Header `Authorization: Bearer ofaf_...`: a read-only machine credential, bound to exactly ONE tenant, scope `accounting.feed.read`.
  Only the SHA-256 of the token is stored (`accounting_feed_credentials`). It is not an OptiFleet user token.
- The tenant is taken from the credential row only. `tenant_id`, `tenant`, `X-Tenant-Id` or anything else in the request is ignored.
- 401 `FEED_UNAUTHENTICATED`: missing, unknown, revoked or expired token (one answer, no probing). 403 `FEED_SCOPE_MISSING`,
  403 `FEED_TENANT_NOT_ACTIVE`, 403 `FEED_SUBSCRIPTION_SUSPENDED`. Rate limit 60 requests per minute (429).
- **Pairing (audit A19).** While OptiNexus is in use (`OPTINEXUS_ENABLED=true`, or `OPTINEXUS_FEED_PAIRING=required`) a valid credential
  is not enough. The credential records the OptiNexus tenant it was issued for, and on every request the tenant must still be linked to
  that same OptiNexus tenant and OptiNexus (`GET /tenants/{id}/commercial-context`, cached at most 300 s, one call per window) must say:
  tenant ACTIVE, at least one live subscription (ACTIVE, TRIAL, GRACE_PERIOD, PAST_DUE), this application AND the consumer application
  (`OPTINEXUS_FEED_CONSUMER_APPLICATION`, default `optiaccounting`) entitled, and the consumer's connector capabilities held
  (`OPTINEXUS_FEED_CONSUMER_CAPABILITIES`, default `ACCOUNTING_INTEGRATION,OPTIFLEET_CONNECTOR`; optional own capability
  `OPTINEXUS_FEED_OWN_CAPABILITY`). Refusals (403, nothing is served): `FEED_TENANT_NOT_PAIRED`, `FEED_CREDENTIAL_UNPAIRED` (issued
  before pairing existed: rotate it), `FEED_PAIRING_CHANGED`, `FEED_TENANT_NOT_ACTIVE`, `FEED_SUBSCRIPTION_INACTIVE`,
  `FEED_PRODUCER_NOT_ENTITLED`, `FEED_CONSUMER_NOT_ENTITLED`, `FEED_CONNECTOR_NOT_ENTITLED`. OptiNexus unreachable or unusable:
  `503 FEED_GOVERNANCE_UNAVAILABLE` with `Retry-After` (a consumer keeps its cursor; there is no fallback and no stale answer).
  Installations without OptiNexus keep the old credential-only behaviour (legacy); `required` makes pairing mandatory everywhere.
- Only reading the feed is gated. Recording invoices, the outbox and the projection of feed events never look at the pairing: an
  unsubscribed or revoked consumer stops the feed, not OptiFleet's own transactions; the events wait in the feed for the consumer's gap policy.
- Lifecycle (`php artisan`, tokens are shown once, only the hash is kept, every action is audited with `--by`):
  `accounting-feed:issue-credential <tenant> [--name] [--expires-in-days] [--by]` (needs a tenant linked to OptiNexus; prints whether the pairing is ready),
  `accounting-feed:rotate-credential <id> [--grace-minutes=60] [--by]` (new credential for the tenant's CURRENT pairing; the old one ends after the grace period),
  `accounting-feed:revoke-credential <id> [--reason] [--by]` (effective on the next request), `accounting-feed:credentials [tenant]` (state and pairing, never a token).
- The consumer should compare `pairing.nexus_tenant_id` of every page with the OptiNexus tenant of its own connection (see Response).

## Response

```json
{ "data": [ { "sequence": 12, "event_id": "uuid", "event_type": "optifleet.workshop_invoice.recorded",
              "schema_version": "1.0", "source_system": "optifleet", "source_tenant_reference": "<OptiFleet tenant id>",
              "occurred_at": "2026-10-11T08:15:30.123Z", "correlation_id": "<work order id>",
              "source": {"type": "workshop_invoice", "id": "uuid", "number": "INV-001"},
              "dimensions": {"vehicle": "uuid", "vehicle_registration_number": "B 1234 XY", "work_order": "uuid",
                             "work_order_number": "WO-...", "branch": "uuid", "branch_code": "JKT"},
              "payload": { } } ],
  "next_cursor": "12", "has_more": false, "high_water": "12",
  "pairing": { "nexus_tenant_id": "<uuid>", "producer_application": "optifleet", "consumer_application": "optiaccounting" } }
```

`pairing` is present when pairing is enforced: the OptiNexus tenant this answer was verified for. It is information for the consumer
(compare it with the OptiNexus tenant of the connection), never an authority for OptiFleet.

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
  `accounting-feed:backfill` (see Operations). A rebuilt event also carries a top-level `review` object:
  `{"required": true, "reason": "backfill_current_state", "state": "QUARANTINED", "note": "...", "repaired_by": "...", "repair_reason": "..."}`.
  It is built from the CURRENT state of its source (a later correction or cancellation is already in it), so it is quarantined: a consumer
  must hold it for a person and never post it automatically. Live events never carry `review`, so the envelope of an event that was
  already delivered never changes (consumers hash envelopes). Rebuilt events from before this field exist only with `snapshot_basis`.
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
They will be added as new event types, per type, without touching existing ones. What exists, what is missing and which accounting
decisions are open: `ACCOUNTING_SOURCE_COVERAGE.md`.

## Operations

- Projection: a listener on `IntegrationOutboxEvent::created` (`AccountingFeedServiceProvider`) writes the feed event inside the business
  transaction, in a savepoint (a failing statement cannot poison the invoice). The outbox row is the durable fact. A projection failure never
  blocks recording an invoice; it is logged and recorded in `accounting_feed_projection_issues` (`projection_exception`, `source_missing`).
- Sequence and cursor (audit A22): `AccountingFeedProjector::append()` allocates the sequence from a per-tenant counter row whose lock is held
  until the writer's transaction ends. Committed sequences are therefore always exactly 1..N in commit order; a rollback or a crash gives the
  number back; writers of different tenants never wait for each other; a long business transaction delays only the later invoices of the
  same tenant. Proved with parallel PHP processes against PostgreSQL (`tests/Feature/AccountingFeedConcurrencyTest.php`: 5 writers x 14
  transactions with random hold times and 25% rollbacks while a consumer polls in pages of 6, a late committer that holds back its
  successors, a writer killed mid-transaction); replacing the allocation by `MAX(sequence)+1` makes those tests fail.
- Monitoring: `php artisan accounting-feed:monitor [--tenant] [--grace=2] [--json]` (scheduled every five minutes) reports outbox facts of a
  feed event type without a feed event, logs `accounting_feed.missed_projections` at critical level per tenant and exits non-zero. `Source rows
  gone` facts keep alerting until someone waives them on purpose.
- Repair (audit A17): `php artisan accounting-feed:backfill [--tenant] [--limit=200] [--dry-run] [--retry-unbuildable] --by=<who> --reason=<10+ chars>`.
  Bounded (at most 1000 facts per tenant and run, oldest first), insert-only (published events are never touched; the repaired fact gets the next
  sequence), idempotent on the outbox id, every added event quarantined (`review`), one audit entry per tenant and run. A fact whose source rows
  no longer exist is not retried unless asked. `php artisan accounting-feed:waive <outbox id> --by --reason` records on purpose that a fact will
  never be published (stops the alert, never repaired, audited; refused when the fact already has an event).
- Run the repair once after deployment (`--dry-run` first) and whenever the monitor reports. It is not meant for a schedule: every run is a decision.
- Cutover on the OptiEntry side stays its decision (default: opening balance only, events from the cutover date).

## Files

Migrations `2026_10_22_000001_create_accounting_feed_tables` (credentials, per-tenant counters, events) and
`2026_10_24_000001_pair_accounting_feed_credentials_and_track_projection_issues` (pairing columns, `review`, projection issues; additive).
Code: `app/Domain/Integration/AccountingFeed/*` (`FeedPairingGate`, `AccountingFeedMonitor`, projector), `AuthenticateAccountingFeed` middleware,
`Api/Integration/AccountingFeedController`, `routes/api/integration.php`, `AccountingFeedServiceProvider`, the `accounting-feed:*` commands.
Tests: `AccountingFeedTest`, `AccountingFeedPairingTest`, `AccountingFeedRepairTest`, `AccountingFeedConcurrencyTest` (workers in `tests/Concurrency/`).
