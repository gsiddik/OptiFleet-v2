# Main Branch Improvement — Permissions, Role Management, WO Issuance & Return, Form Reliability

Status: **COMPLETE**. Branch `claude/magical-volta-tv4xwl`, started from
`main` @ `3632b31`.

## Permission audit

- Permissions: 325 before (244 tenant / 81 platform), 325 after. No new
  permission was needed; `work_order.reject` already existed but was unused.
- Every permission referenced by a route middleware, backend check or the
  frontend exists in the seeder (0 missing). Every tenant/platform write route
  is behind `auth:sanctum` + a `permission:` (and `module:` where licensed)
  middleware — no unprotected route found.
- Corrected: `POST /app/work-orders/{id}/reject` was gated by
  `work_order.approve`; now `work_order.reject`. Migration
  `2026_09_30_000001` grants it to every role that holds `work_order.approve`
  (idempotent), so no existing user loses the action.
- Ten permissions were seeded but enforced nowhere. Owner decision: wire each
  into a real check (not retire). Every seeded permission is now enforced by a
  route (asserted by `RewiredPermissionsTest`; the configuration permissions
  are resolved per document type inside `ConfigurationController`):

  | Permission | Now gates |
  |---|---|
  | `invoice.generate`, `invoice.issue` | `POST /platform/subscriptions/{id}/generate-billing` (it generates and issues the invoice) — together with `billing.generate`; backfilled to roles holding `billing.generate` |
  | `billing.adjust` | `POST /platform/subscriptions/{id}/adjustment-invoices` — manual extra-charge invoice (amount > 0, issued, `billing_id` NULL, same shape as amendment proration invoices), audited |
  | `subscription.activate` | `POST /platform/subscriptions/{id}/activate` — manual activation of a PENDING subscription (reason required, audited); suspended ones still use Reactivate |
  | `contract.update` | `PUT /platform/contracts/{id}` — edit a DRAFT contract's terms and items (re-priced like create; tenant fixed) |
  | `intelligence.prediction.run` | `POST /platform/intelligence/predictions/run` — queued prediction run for an entitled tenant, audited |
  | `intelligence.model.evaluate` | `POST /platform/intelligence/evaluations/run` — queued outcome evaluation (`EvaluateOutcomesJob`), audited |
  | `work_order.update` | `PUT /app/work-orders/{id}` — edit priority/complaint of a DRAFT Work Order |
  | `inspection.review` | `POST /app/inspections/{id}/review` — one-time supervisor review of a submitted inspection (result unchanged; migration `2026_09_30_000002` adds nullable review columns) |
  | `external_work_order_invoice.cancel` | `POST /app/external-work-order-invoices/{id}/cancel` — together with `work_order.cancel_external` (it cancels the External WO too); backfilled to roles holding `work_order.cancel_external` |

  Backfill migration `2026_09_30_000003` is idempotent. Permissions gating a
  new action are not backfilled (no one could do it before). UI: Subscriptions
  (Activate / Generate Billing / Adjustment Invoice), Contract detail (Edit
  Draft), WO Overview (Edit Details), Inspection detail (Supervisor Review),
  Workshop Invoice (Cancel with reason). The two intelligence runs are API-only,
  like the rest of platform Intelligence administration (no UI exists for it).
- `PermissionSeeder` stays idempotent: it creates missing rows only and never
  touches `role_permissions` (covered by a test).

## Role permission management

- `RolePermissionService` (tenant + platform): scope-checked IDs (unknown or
  cross-scope → 422, never silently dropped), row lock, self lock-out guard
  on `role.assign_permission`, audit `permissions_changed` (added/removed),
  permission cache flushed in-transaction and after commit.
- Existing roles, including seeded system tenant roles, are editable; a
  system role's name is fixed (description/permissions editable). The platform
  superadmin role is locked (`editable: false`).
- `GET /permissions` returns Module → Feature → Action metadata
  (`PermissionCatalog`, module derived from each route's `module:` middleware).
- UI (`RoleManager`): Module → Feature → Action tree, per-module / per-feature
  tri-state checkboxes, Select all / Clear (scoped to the filter), filter;
  the current user's permissions are refreshed after save. Platform roles only
  list platform-scope permissions.

## Work Order — Issuance & Return

- Tab "Request Parts" renamed to "Issuance & Return" (label only; backend
  concepts unchanged).
- `WorkOrderPartService::returnPart` rejects CONSUMED rows and any quantity
  above `returnable_quantity` (issued − consumed − returned) with 422 and no
  inventory movement. `returnable_quantity` is exposed on planned parts; the
  UI shows a disabled Return with an explanation for consumed rows.
- Components removed from the unit are returned via Removed Components
  (unchanged, verified end to end).

## Form reliability / frontend–backend alignment

- All 211 distinct frontend write endpoints (285 call sites) resolve to
  existing backend routes; literal payload keys match backend rules.
- Every tenant and platform Create/Add form was driven in a browser against a
  seeded DB and reached 201 once its data prerequisites existed.
- Fixed: no UI for Warehouse Zone/Rack/Bin (blocked Product creation) → new
  Storage Layout editor on Warehouses; Product compatibility 422s were not
  shown; numbering metadata lacked `{ITEMTYPE}`/`{CG}`; Worker email input
  type; Stock Transfer allowed destination = source.

## Validation

- New tests: `RolePermissionManagementTest`, `WorkOrderConsumedReturnTest`,
  `RewiredPermissionsTest`, updated `RoleTenantIsolationTest`.
- Owner decision: demo data does NOT get vehicle brands, a periodic package or
  workshop working days; tenants set these up themselves before using the
  forms that depend on them.
- Full non-Mongo backend regression, frontend build and lint: see the final
  report of this initiative. Mongo tests NOT RUN (`ext-mongodb` unavailable;
  Mongo migrations/tests relocated during the run and restored).
