# i18n Structural Preparation — Status

Continuation checkpoint for the structural i18n preparation defined in
`docs/i18n/15-structural-i18n-execution-plan.md`.

- **Entry condition:** terminology fully resolved. `AWAITING_DECISION` = 0 in
  `docs/i18n/12-en-id-translation-dataset-final.csv`.
- **Scope:** make the source structurally translatable. This is **not** the i18n rollout: no i18n
  library is installed, no `t()` replacement and no language selector.
- **Progress tracking:** each phase marks its blocker resolved in `12` (`resolved_blockers` column).
  The per-blocker counts are in `14` → *Structural blocker progress*.

## Phases

| # | Phase | Blocker | Status |
|---:|---|---|---|
| S1 | Stable tab IDs | STABLE_TAB_ID (21 rows) | DONE |
| S2 | Status display label registry | STATUS_DISPLAY_REGISTRY (46 → 150 rows) | DONE |

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

## S2 — Status display label registry

**Change**
- `frontend/src/i18n/statusRegistry.ts`: 148 canonical codes, each mapped to a semantic key
  (`status.underReview`) and an English label.
  - `domain` splits ISSUED into `status.document.issued` (PO / RFQ) and `status.stock.issued`
    (part requests, planned parts).
  - An unknown code is shown as-is (never reformatted), with a one-time dev warning.
  - Lowercase legacy values (`active`) resolve too.
- `StatusBadge` renders `statusLabel(code, domain)`, and the local `SCRAPPED → SCRAP` map is gone. Colours
  stay keyed by the canonical code.
- Converted to the registry:
  - status filter chips on 17 list pages;
  - status `<option>` lists: Tire Operations, PR line status, MR inspection group;
  - 9 raw `{x.status}` renders;
  - the External WO invoice page, whose duplicated label maps were removed. Its badge now receives the
    code instead of the label, so it gets its proper colour.
- Dataset `12`:
  - 104 new `status.*` keys with Indonesian labels;
  - 44 existing `status.*` rows now carry the readable English label;
  - `status.paid` fixed from "LUNAS" to "Lunas".

**Not changed**: canonical status values, API filters (`?status=IN_PROGRESS`), workflow codes, the
database.

**Visible effect (English)**: badges and chips show readable labels instead of raw codes, e.g.
`UNDER_REVIEW` → "Under Review" (badges remain upper-cased by CSS) and `QC_PENDING` → "QC Pending".

**Tests**
- `tests/unit/statusRegistry.test.ts`, 5 tests:
  - every canonical code found in backend constants, workflow/status seeders, migration enums and
    frontend status lists has a registry entry (the scan is guarded against finding nothing);
  - every registry key has a final Indonesian translation in `12`;
  - the ISSUED domain split;
  - labels come from the registry;
  - unknown codes pass through unchanged.
- Browser e2e, 11 checks:
  - Work Order / Maintenance Request chips show labels;
  - list badges carry no raw codes;
  - chip clicks still send `status=IN_PROGRESS` / `status=ISSUED`;
  - PO and part-request ISSUED badges use their own domain;
  - External WO invoice badges.
- `tsc -b` clean; lint 27 pre-existing warnings, 0 errors.

**Remaining risk**: low. Non-status enums shown in badges (severity, event type) still pass through
unchanged and are handled in S3.
