# Work Order Workspace Scheduling & Assignment — Status

Branch `claude/magical-volta-tv4xwl`, baseline `main` @ c8264af (merged into the branch, no
content difference at start).

| Phase | Scope | Status | Commit |
|---|---|---|---|
| Audit | Work Order, Workspace, Workspace Assignment (`workspace_reservations`), Scheduler, permissions, seeders, tests | DONE | — |
| 1–5, 7–9 (backend) | Approval model, Schedule Workspace, SCHEDULED → IN_PROGRESS guard, transfer, QC re-scheduling, capacity, completion from Work Order, permissions | DONE | 9433dc4 |
| 1–6, 10 (frontend) | Schedule Work Order popup, Start guard, Workspace tab, Transfer, assignment list, scheduler occupancy | DONE | 09aff0e |
| 11 | Seeders (service bays, capacity 1/2/3, every scheduling state, transfer history) | DONE | 9433dc4 |
| 12–13 | QA matrix (backend + e2e), quality gates | see below | see git log |

## Owner decisions (DECISION REQUIRED, answered)

1. **Approval** — new APPROVED step: RESERVED (requested) → Approve (`workspace.approve`) →
   APPROVED → COMPLETED (only through Work Order completion); TRANSFERRED and CANCELLED. Legacy
   ACTIVE rows count as approved. Activate / Complete buttons removed.
2. **Transfer** — immediately approved (requires `workspace.approve`); the old assignment becomes
   TRANSFERRED in the same transaction.
3. **QC_PENDING** — one current assignment per Work Order; Schedule Workspace at QC_PENDING
   transfers it (or requests one when none is current).
4. **Capacity unit** — start/end datetime windows; capacity N = up to N assignments overlapping at
   the same moment.

## Engineering decisions

- **Existing domain reused**: Workspace Assignment = `workspace_reservations` (no new table).
- **Schedule Workspace statuses**: DRAFT, SUBMITTED, APPROVED, ASSIGNED and QC_PENDING as
  specified, plus SCHEDULED — otherwise a Work Order that reached SCHEDULED without a workspace
  could never start (dead end). Not offered in IN_PROGRESS / ON_HOLD / WAITING_PART / REWORK
  (transfer is used there), nor in terminal or EXTERNAL statuses.
- **Lifecycle "Schedule" action** (ASSIGNED → SCHEDULED) is relabelled "Mark as Scheduled"; it
  takes workspace and window from the approved assignment (the assignment is the source of truth;
  `work_orders.workspace_id` mirrors it for backward compatibility).
- **Start guard** (`WorkspaceReservationService::assertStartable`, inside the Work Order
  transition transaction): approved assignment (APPROVED / ACTIVE) on a non-deleted workspace with
  `end_at > start_at`. Message: "Cannot start this Work Order because no approved Workspace and
  scheduled work date are assigned." Only SCHEDULED → IN_PROGRESS is guarded (resume paths are
  unchanged).
- **Availability** (`GET /work-orders/{wo}/available-workspaces`): same workshop as the Work
  Order, not BLOCKED / UNDER_MAINTENANCE / INACTIVE, vehicle category compatible (when the
  workspace lists categories), peak occupancy < capacity (null capacity = 1). The workspace row is
  locked before the capacity check on reserve / transfer.
- **Completion**: Work Order COMPLETED → current APPROVED / ACTIVE assignment COMPLETED
  (`completed_at`), a still-pending request CANCELLED; CANCELLED / REJECTED / EXTERNAL release the
  current assignment (CANCELLED). Transferred history is never touched.
- **Cancel**: a requested assignment can always be cancelled; an approved one only before work
  starts (afterwards it must be transferred).
- **Workspace.status** is no longer flipped to OCCUPIED / AVAILABLE by assignments (capacity > 1
  makes a single status meaningless); BLOCKED etc. still make a workspace unavailable.
- **Analytics**: workspace occupancy (WorkshopMetricsExtractor) counts APPROVED alongside legacy
  ACTIVE and COMPLETED; TRANSFERRED is excluded to avoid double-counting.

## Database (migration `2026_10_08_000001`, additive)

- `workspace_reservations.status` CHECK adds APPROVED, TRANSFERRED.
- New columns: `approved_by`, `approved_at`, `transferred_from_id` (self FK), `transferred_by`,
  `transferred_at`, `completed_at`, `cancelled_at`; index `(work_order_id, status)`.
- Partial unique index `workspace_reservations_one_current_per_wo` (one RESERVED / APPROVED /
  ACTIVE per Work Order). Data plan: older duplicate open reservations of the same Work Order are
  set CANCELLED (rows kept) before the index is created.
- CHECKs: `end_at > start_at`; APPROVED requires `approved_at`.
- Permission `workspace.approve` (granted to roles holding `workspace.reserve`; PermissionSeeder).

## API

| Endpoint | Permission | Notes |
|---|---|---|
| `POST /workspace-reservations` | `workspace.reserve` | creates RESERVED; WO status, tenant, scope, workshop, category, capacity, one current |
| `POST /workspace-reservations/{id}/approve` | `workspace.approve` | RESERVED → APPROVED |
| `POST /workspace-reservations/{id}/transfer` | `workspace.approve` | `workspace_id`, optional `start_at` / `end_at` |
| `POST /workspace-reservations/{id}/cancel` | `workspace.reserve` | |
| `GET /work-orders/{wo}/available-workspaces` | `workspace.view` | `start_at`, `end_at`, `exclude_workspace_id` |
| `POST /workspace-reservations/{id}/activate`, `/complete` | `workspace.reserve` | deprecated → 422 |
| `GET /workshop-scheduler` | `workspace.view` | current assignments + `effective_capacity` |

## Frontend

- `workorders/workspace/ScheduleWorkspaceModal.tsx` (Schedule Work Order: none / pending /
  approved→transfer states; TransferWorkspaceModal), `WorkOrderWorkspaceTab.tsx` (approved
  workspace card, pending request, history), `workspaceAssignment.ts` (status sets, guard).
- WorkOrderDetailPage: Schedule Workspace button, Start disabled with reason, header wraps on
  mobile. WorkspaceReservationListPage: Approve / Transfer / Cancel, no Activate / Complete.
  WorkshopSchedulerPage + `schedulerOccupancy.ts`: capacity, Occupied k / N, free slots.

## Seeders

- DemoDatasetSeeder: a general service bay (capacity 2) per branch workshop; Jakarta bays with
  capacity 1 / 2 / 3 and a QC bay; Work Orders: DRAFT (no workspace), APPROVED, SCHEDULED with and
  without approved workspace, IN_PROGRESS, QC_PENDING moved to the QC bay (TRANSFERRED history),
  COMPLETED (assignment COMPLETED), ASSIGNED with an approved transferable workspace, a pending
  request filling the capacity-2 bay.
- Every seeder that starts a Work Order schedules it through the service
  (`DemoWorkspaceAssignment`: reserve → approve).
- `migrate:fresh --seed` + `db:seed` re-run: PASS, counts unchanged.

## Tests

- `WorkspaceAssignmentWorkflowTest` (9): schedule statuses, one current assignment, cross-tenant,
  start guard (none / pending / deleted workspace / no date refused by DB / approved → allowed),
  Workspace tab data, transfer validation (same, blocked, cross-tenant, other workshop, over
  capacity, not approved, history) and permission, QC_PENDING transfer + completion, capacity
  1 / 2 / 3 / 4th rejected / peak-concurrency, cancel frees a slot, completion & cancellation,
  scheduler capacity.
- `WorkspaceReservationTest` lifecycle updated to the approval model.
- 26 existing call sites that start a Work Order give it an approved workspace first
  (`TestCase::withApprovedWorkspace`).
- e2e (Playwright, freshly seeded DB): 33/33.
