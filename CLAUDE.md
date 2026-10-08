# OptiFleet — Permanent Development Rules

These are durable engineering rules for every Claude session on this
repository. They are not phase-specific history — phase history and
release status live in `docs/status/*.md`.

## Source of truth

- The repository is the authoritative source of truth. Chat history is
  not authoritative and must never be relied on to reconstruct state.
- `docs/status/*.md` files are the continuation checkpoint between
  sessions. Read the latest relevant status file before resuming work.
- Completed phases must not be reconstructed, redesigned, or reopened
  unless explicitly instructed, and only to the extent instructed.
- Do not start the next phase without explicit owner instruction, even
  if a prior phase's status file says it is complete.

## Branching

- `main` is the baseline of this repository. Start every new piece of work from the latest `main` on a new branch,
  and bring it back to `main` by pull request. Do not keep working on a branch whose pull request is already merged.

## Architecture invariants

- PostgreSQL remains the operational transactional source of truth
  unless the architecture explicitly defines otherwise for a given
  layer.
- MongoDB is an analytical projection only, where already established
  (Phase 6). It must never become authoritative for operational state,
  and must never be written to synchronously as part of an operational
  transaction.
- No direct cross-product/cross-service database access. Integrations
  between distinct systems or bounded contexts must use explicit
  API/event contracts, not shared direct DB reads/writes.
- Tenant isolation is mandatory everywhere: every tenant-scoped query,
  job, and analytical document must be scoped by tenant, and tenant
  context must never be trusted from client input.
- Dynamic RBAC and data scope (branch/workshop/warehouse/etc.) must be
  enforced server-side on every request. Avoid hardcoding role names
  for authorization decisions — check permissions/entitlements, not
  role identifiers.
- Module/commercial entitlement must be enforced server-side.
  Frontend hiding of a feature is never a substitute for a backend
  entitlement check.
- Critical state transitions (approvals, stock movement, payments,
  numbering, workflow transitions) require transactional integrity and
  explicit concurrency protection (row locks, unique constraints,
  idempotency keys) — never assume single-writer conditions.
- Monetary values always use safe decimal handling (no native float
  arithmetic for money).
- Existing tenant-facing behavior must remain backward compatible
  unless explicitly migrated with a documented plan.

## Testing and validation

- Add targeted tests per implementation batch as you go.
- Run a full regression only at release gates, or when a change has
  broad blast radius across existing modules.
- A result may only be reported PASS if the corresponding validation
  was actually executed in this session. Never fabricate test,
  build, or integration results.
- Use NOT RUN (not a guessed PASS/FAIL) when a validation genuinely
  cannot be executed in the current environment, and say why.

## Communication and output

- Keep progress output concise and structured.
- Do not print entire files, large diffs, or long logs in chat unless
  actually needed to make a decision — the repository state is what
  matters, not a transcript of it.
