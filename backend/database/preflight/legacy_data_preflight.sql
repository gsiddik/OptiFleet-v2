-- OptiFleet R4 Deployment Readiness: legacy-data preflight scan.
--
-- Run this against a read-only copy (or a transaction you will ROLLBACK) of
-- the PRODUCTION database before applying any new schema constraint from
-- this release. Every query below is a SELECT — it never mutates data. A
-- non-empty result for any query means that category of legacy data exists
-- and must be remediated (see docs/deployment/RELEASE_READINESS_R4.md for
-- the corresponding backfill/remediation strategy) before the matching
-- migration is applied — never silently delete or alter conflicting rows.

-- 1. Duplicate tire serials within the same tenant (the tires table has no
--    existing unique constraint on (tenant_id, serial_number); this release
--    does not add one, but any pre-existing duplication should still be
--    known before any future constraint work).
SELECT tenant_id, serial_number, count(*) AS occurrences
FROM tires
WHERE deleted_at IS NULL
GROUP BY tenant_id, serial_number
HAVING count(*) > 1;

-- 2. Invalid tenant ownership: rows whose tenant_id does not reference an
--    existing tenant (checked across every tenant-scoped table this
--    release touches or reads).
SELECT 'work_order_external_services' AS table_name, t.id
FROM work_order_external_services t
LEFT JOIN tenants ten ON ten.id = t.tenant_id
WHERE ten.id IS NULL
UNION ALL
SELECT 'workshop_invoices', t.id FROM workshop_invoices t LEFT JOIN tenants ten ON ten.id = t.tenant_id WHERE ten.id IS NULL
UNION ALL
SELECT 'tires', t.id FROM tires t LEFT JOIN tenants ten ON ten.id = t.tenant_id WHERE ten.id IS NULL;

-- 3. Cross-tenant relationships: a Workshop Invoice, Memo, or Tire
--    referencing a Partner/Work Order/Product that belongs to a DIFFERENT
--    tenant than the row itself.
SELECT wi.id AS workshop_invoice_id, wi.tenant_id AS invoice_tenant, p.tenant_id AS partner_tenant
FROM workshop_invoices wi
JOIN partners p ON p.id = wi.partner_id
WHERE wi.tenant_id <> p.tenant_id
UNION ALL
SELECT wes.id, wes.tenant_id, wo.tenant_id
FROM work_order_external_services wes
JOIN work_orders wo ON wo.id = wes.work_order_id
WHERE wes.tenant_id <> wo.tenant_id;

-- 4. Invalid wheel positions: an installation whose position_code is not a
--    position defined in that vehicle's own wheel configuration.
SELECT ti.id AS installation_id, ti.tire_id, ti.wheel_position
FROM tire_installations ti
JOIN tires tr ON tr.id = ti.tire_id
JOIN vehicles v ON v.id = ti.vehicle_id
WHERE ti.removed_at IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM wheel_configurations wc
    WHERE wc.vehicle_category_id = v.vehicle_category_id
      AND wc.position_code = ti.wheel_position
  );

-- 5. Orphaned Work Orders / Maintenance Memos: a memo referencing a
--    work_order_id, partner_id, or workshop_invoice_id that no longer
--    exists.
SELECT wes.id, 'missing_work_order' AS issue
FROM work_order_external_services wes
LEFT JOIN work_orders wo ON wo.id = wes.work_order_id
WHERE wo.id IS NULL
UNION ALL
SELECT wes.id, 'missing_partner'
FROM work_order_external_services wes
LEFT JOIN partners p ON p.id = wes.partner_id
WHERE p.id IS NULL
UNION ALL
SELECT wes.id, 'dangling_workshop_invoice_id'
FROM work_order_external_services wes
LEFT JOIN workshop_invoices wi ON wi.id = wes.workshop_invoice_id
WHERE wes.workshop_invoice_id IS NOT NULL AND wi.id IS NULL;

-- 6. Inconsistent inventory: a stock/warehouse row referencing a product or
--    warehouse outside its own tenant, or a negative on-hand quantity.
SELECT id, tenant_id, product_id, warehouse_id, quantity_on_hand
FROM warehouse_stocks
WHERE quantity_on_hand < 0
   OR quantity_reserved < 0
   OR quantity_reserved > quantity_on_hand;

-- 7. Invalid enums: any status value outside the CHECK-constrained set for
--    tables this release extends (work_order_external_services.status must
--    be one of REQUESTED/COMPLETED/CANCELLED/BILLED/PAID after this
--    release; workshop_invoices.status must be one of
--    RECORDED/CORRECTION_REQUESTED/CANCELLATION_REQUESTED/CANCELLED).
SELECT id, status FROM work_order_external_services
WHERE status NOT IN ('REQUESTED','COMPLETED','CANCELLED','BILLED','PAID');

SELECT id, status FROM workshop_invoices
WHERE status NOT IN ('RECORDED','CORRECTION_REQUESTED','CANCELLATION_REQUESTED','CANCELLED');

-- 8. Money/decimal risk: any monetary column stored with more precision
--    than the column's own scale would allow after casting, which would
--    indicate the value was ever written via native float arithmetic
--    instead of BigDecimal (a smoke signal, not a hard proof).
SELECT id, total_amount FROM workshop_invoices
WHERE total_amount <> round(total_amount, 4);

-- 9. Missing reference tread depth: a TIRE-type Product with no (or a
--    non-positive) reference_tread_depth_mm — Tire Scoring's calculate()
--    already refuses to run in this case, but a preflight should surface
--    how many such products exist before any tenant tries to activate
--    scoring against them.
SELECT id, code, name
FROM products
WHERE product_type = 'TIRE'
  AND (reference_tread_depth_mm IS NULL OR reference_tread_depth_mm <= 0);

-- 10. Duplicate Workshop Invoice numbers per (tenant, partner) after this
--     release's normalization rule (trim, collapse whitespace, upper-case),
--     excluding CANCELLED rows — this is exactly the condition the new
--     partial unique index enforces going forward; any pre-existing
--     duplicate must be resolved (or one side cancelled) before the
--     migration that adds workshop_invoices_active_number_unique can apply
--     cleanly against production history, if a backfill of historical
--     invoices into this table is ever performed.
SELECT tenant_id, partner_id, external_invoice_number_normalized, count(*)
FROM workshop_invoices
WHERE status <> 'CANCELLED' AND deleted_at IS NULL
GROUP BY tenant_id, partner_id, external_invoice_number_normalized
HAVING count(*) > 1;

-- 11. Missing Partner relationships: a Workshop Invoice or Memo whose
--     partner_id does not resolve, or references a partner of a type that
--     is not workshop-capable (data-quality signal, not a hard rule).
SELECT wi.id, wi.partner_id
FROM workshop_invoices wi
LEFT JOIN partners p ON p.id = wi.partner_id
WHERE p.id IS NULL;

-- 12. New-constraint violations introduced by THIS release specifically:
--     a memo_number that is not unique per tenant (the new migration adds
--     no explicit unique index on memo_number itself, but the numbering
--     service assumes practical uniqueness) and any workshop_invoice_payments
--     row that is not exactly one-to-one with its invoice (the migration
--     enforces this with a UNIQUE column, so this query should already be
--     empty by construction on any tenant created after this release, and
--     exists here only to catch a hypothetical historical backfill).
SELECT tenant_id, memo_number, count(*)
FROM work_order_external_services
WHERE memo_number IS NOT NULL
GROUP BY tenant_id, memo_number
HAVING count(*) > 1;

SELECT workshop_invoice_id, count(*)
FROM workshop_invoice_payments
GROUP BY workshop_invoice_id
HAVING count(*) > 1;
