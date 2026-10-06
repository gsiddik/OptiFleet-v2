# i18n Structural Preparation — Status

Continuation checkpoint for the structural i18n preparation defined in
`docs/i18n/15-structural-i18n-execution-plan.md`.

- **Entry condition:** terminology fully resolved. `AWAITING_DECISION` = 0 in
  `docs/i18n/12-en-id-translation-dataset-final.csv`.
- **Scope:** make the source structurally translatable. This is **not** the i18n rollout: no i18n
  library is installed, no `t()` replacement and no language selector.
- **Progress tracking:** each phase marks its blocker resolved in `12` (`resolved_blockers` column).
  The per-blocker counts are in `14` → *Structural blocker progress*. The S1 marking lands together with
  the S2 dataset update (next commit).

## Phases

| # | Phase | Blocker | Status |
|---:|---|---|---|
| S1 | Stable tab IDs | STABLE_TAB_ID (21 rows) | DONE |

## S1 — Stable tab IDs

**Change**
- `utils/tabs.ts`: `TabDef {id, label}` and `resolveTabId()`. The resolver accepts a stable id, an alias,
  or a legacy English label, ignoring case, spaces and punctuation.
- `hooks/useTabParam.ts`: the active tab is kept by id and mirrored in `?tab=<id>`.
  - Selection replaces the history entry, so it adds no extra Back step.
  - Other query parameters are kept.
  - The default tab removes `?tab=`.
- Pages converted:
  - **Work Order detail**: 16 tab ids. Visibility rules (orphaned, QC step, Issuance & Return,
    Tire Operations position) are keyed by id.
  - **Vehicle detail**: `wheels` keeps the existing `?tab=wheels` links. Permission gating is keyed by id,
    and a deep-linked tab the user may not see now falls back to Overview.
  - **Tenant detail (platform)**.
  - **Contract detail**: back link now uses `?tab=contract`.

**Backward compatibility**
- Old label links (`?tab=Contract`, `?tab=Module%20Entitlements`, `?tab=Issuance%20%26%20Return`)
  still open the right tab.
- Work Order and Vehicle now also accept `?tab=` for every tab. Before, only `?tab=wheels` worked on
  Vehicle and Work Order had no deep links.

**Tests**
- `frontend/tests/unit/tabs.test.ts`: 5 tests for the resolver (stable id, legacy label, alias,
  fallback, label lookup). Run with `npm run test:unit` (Node test runner, no new dependency).
- Browser e2e: 18 checks covering deep link by id, legacy label, click → URL, Overview removes the
  param, hidden/unknown tab falls back, back/forward, reload, other params kept, and platform tenant
  tabs. Existing Work Order tab e2e: 7/7.
- `tsc -b`: clean. Lint: 27 pre-existing warnings, 0 errors (unchanged).

**Remaining risk**: low. Tab labels are still English literals; rendering them through translation keys
is part of the i18n rollout.

