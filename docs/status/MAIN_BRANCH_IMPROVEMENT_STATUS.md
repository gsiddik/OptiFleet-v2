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
- Seeded but not enforced anywhere (kept; removing them could break role
  assignments — owner decision): `billing.adjust`, `contract.update`,
  `external_work_order_invoice.cancel`, `inspection.review`,
  `intelligence.model.evaluate`, `intelligence.prediction.run`,
  `invoice.generate`, `invoice.issue`, `subscription.activate`,
  `work_order.update`.
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
  updated `RoleTenantIsolationTest`.
- Full non-Mongo backend regression, frontend build and lint: see the final
  report of this initiative. Mongo tests NOT RUN (`ext-mongodb` unavailable;
  Mongo migrations/tests relocated during the run and restored).
