# Accounting source coverage (OptiFleet facts for OptiEntry): what exists, what is missing, what needs a decision

Audit A01 (source coverage), checked against the code and the migrated schema. Nothing here changes the feed, enables posting or
chooses an accounting treatment: where a treatment is open it is a decision (section 3) for the owner, not a default.
Legend: **Published** = in the accounting feed today; **Data only** = the facts are stored in OptiFleet but no outbox/feed event exists;
**Missing** = a field or fact the consumer would need is not stored; **Decision** = see section 3.

## 1. Matrix

| Fact | OptiFleet source (tables) | State | What is stored | Missing / gap | Decision |
|---|---|---|---|---|---|
| Workshop (external) invoice recorded / corrected / cancelled | `workshop_invoices`, `_corrections`, `_cancellations` | **Published** (`workshop_invoice.*`) | number, dates, currency, subtotal / discount / tax / total, lines, vendor, memo | one `tax_total` (no tax code per line); one currency per invoice, no rate; lines carry no account / category | none (decided: AP via Posting Engine) |
| Workshop invoice payment | `workshop_invoice_payments` | **Published** (`workshop_invoice.payment_recorded`) | date, amount, method, reference | one full payment per invoice; no bank / cash account, no partial payment | cash / bank mapping is on the Entry side (decided) |
| Maintenance memo billed / paid | `work_order_external_services` | **Published**, informational only | memo number, cost, vendor, link to invoice | none (never posted) | none (decided: informational) |
| Work order cost (parts, labor, outside services) | `work_orders.estimated_*`, memo `cost`, `stock_movements` (ISSUE / CONSUME), `work_order_labor_logs` (minutes only) | **Data only**, partly | estimates, memo cost, parts at moving-average unit cost | no actual-cost roll-up per work order, no labor rate, no cost event | D6 |
| Vendor (procurement) invoice | `vendor_invoice_references` (status RECEIVED / VERIFIED / DISPUTED), link to PO and goods receipt | **Data only** | number, date, due date, one `amount`, vendor, PO, receipt | no subtotal / tax / currency columns, no lines; no event | D1, D2 |
| Vendor invoice payment | `vendor_invoice_payments` | **Data only** | date, amount, proof file | no method, reference, bank, currency; no event | D1 |
| Purchase order (commitment) | `purchase_orders`, `_items` | **Data only** | subtotal, tax, freight, total, approval | not a financial fact by itself | D1 (no posting proposed) |
| Goods receipt | `goods_receipts`, `_items`, `stock_movements` (RECEIPT) | **Data only** | accepted / rejected / damaged qty, `unit_cost`, batch, valuation status + basis per movement | no event; receipts at price 0 are `UNVERIFIED`, never "free" | D1, D4 |
| Purchase return / return to vendor | `purchase_returns`, `_items`, movement RETURN_TO_VENDOR | **Data only** | option (refund / redelivery), refunded amount, decisions | no tax / credit-note fields; no event | D5 |
| Stock issue to a work order / installation | `stock_movements` (ISSUE, CONSUME), `installation_stock_exits` | **Data only** | qty, moving-average `unit_cost`, `valuation_status` | no event; cost is only as reliable as the valuation status | D4, D6 |
| Stock adjustment, opname, scrap, reconciliation | `stock_movements` (ADJUSTMENT_*, STOCK_OPNAME, SCRAP), `stock_opnames`, `stock_reconciliation_adjustments` (approved) | **Data only** | qty, cost, reason, approver (reconciliation) | opname movement cannot be joined to its opname (known gap, valuation status doc); no event | D7 |
| Stock transfer between warehouses | `stock_transfers`, movements TRANSFER_OUT / IN | **Data only** | qty, source average cost | internal; relevant only across entities / branches | D8 |
| Spare-part / scrap sale | `spare_part_sales` (OPERATIONAL_REUSE / SCRAP_MATERIAL, tire and component sources), movement SALE | **Data only** | buyer, qty, unit price, total, approval | no tax, no currency column, no payment / receivable state; no event | D9 |
| Vehicle acquisition cost | `vehicles.purchase_month / purchase_year` | **Missing** | month and year only | no amount, vendor or invoice link | D10 |
| Fuel, toll, driver expenses | none | **Missing** | - | OptiFleet has no such module | out of scope |
| OptiFleet's own subscription billing (`billings`, `invoices`, `payments`, `subscriptions`) | platform tables | Not a tenant fact | OptiFleet billing its customer | must never be exported as the tenant's accounting; OptiNexus owns the commercial side | none |

Producer-side prerequisites that apply to every "Data only" row before a new event type can be published: an outbox event written in the
business transaction (`IntegrationOutboxService`), a snapshot builder branch in `AccountingFeedSnapshotBuilder`, decimal-string money
with a currency, source ids and the dimensions (vehicle, work order, branch, warehouse, product), and a documented schema 1.x
addition. New types are additive (consumers must treat an unknown `event_type` as "review", they already do).

## 2. Feed guarantees that already hold (and are tested)

Gap-free, commit-ordered sequence per tenant, proved with parallel processes against PostgreSQL (late commits, rollbacks, a killed writer);
`high_water`; tenant and OptiNexus pairing with both products subscribed and the connector capability held; credential issue / rotate /
revoke with hashed storage; rebuilt facts are quarantined (`review`) and never rewrite a published event; missed projections are
recorded, monitored and repaired in bounded, audited runs. See `ACCOUNTING_FEED.md`.

## 3. Decisions needed from the owner (nothing is assumed; no posting is enabled for any of these)

| # | Question | Options | Recommendation | Impact |
|---|---|---|---|---|
| D1 | When is a procurement payable recognised? | (a) at the verified vendor invoice only; (b) at goods receipt with a GRNI accrual, cleared by the invoice; (c) at the PO (not recommended) | (b) when stock is valued, (a) for services; needs the three facts below | Fleet must publish receipt, vendor invoice and vendor payment (3 new event types); Entry needs GRNI / AP mapping and a matching view |
| D2 | Three-way match tolerance (PO / receipt / invoice) | exact; fixed tolerance per tenant; percentage | tenant setting, default exact, variance to a review queue, never auto-adjusted | vendor-invoice rows with `DISPUTED` or a variance stay in review; no silent write-off |
| D3 | Money and tax on vendor invoices | add subtotal, tax, currency, lines to `vendor_invoice_references` | add them (additive migration) before publishing | needed for AP, tax and multi-currency; historic rows keep NULL and are reviewed, not guessed |
| D4 | Inventory valuation basis for postings | moving average as stored; verified lines only; manual valuation first | post only `VERIFIED` / `VERIFIED_ZERO` valued movements; `UNVERIFIED`, `NOT_VALUED`, `MIXED` go to review | stock issue / adjustment value depends on valuation review being done first |
| D5 | Purchase return | vendor credit note vs refund receipt vs redelivery (no money) | refund accepted = debit note + cash receipt; redelivery = quantity only | needs credit-note tax fields (D3) |
| D6 | Work order cost | expense per work order; capitalise to the vehicle; WIP until close | expense on parts issue and on the vendor invoice, no WIP, labor out of scope until rates exist | decides whether parts issue is posted; avoids double count with the workshop invoice (memo is informational) |
| D7 | Stock adjustments / opname / scrap | inventory loss account per reason; approval threshold | post only approved reconciliations and posted opnames, reason code to account mapping on the Entry side | needs the opname id on the movement (Fleet fix) |
| D8 | Transfers between warehouses | ignore; inter-branch clearing | ignore unless warehouses belong to different legal entities | none while one entity |
| D9 | Spare-part / scrap sale | cash sale vs receivable; revenue vs gain on disposal; tax | receivable when a buyer partner exists, otherwise cash; other income; tax per buyer type | needs price tax / currency / payment state on the sale (Fleet) |
| D10 | Vehicle acquisition and depreciation | out of scope; capture cost at acquisition | out of scope for OA6; revisit with fixed assets | none now |
| D11 | Backfill of history | none; opening balance only (Entry default); reviewed backfill from a cutover date | keep Entry's default; repaired facts are quarantined and need a person | already enforced: a rebuilt fact never posts by itself |
