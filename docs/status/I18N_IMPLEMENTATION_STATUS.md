# i18n Implementation (EN / ID) — Status

Continuation checkpoint for the EN / ID rollout planned in `docs/i18n/16-i18n-implementation-plan.md`.

- **Entry condition:** `READY_FOR_I18N_IMPLEMENTATION_EXCEPT_MONGODB`
  (`docs/status/I18N_STRUCTURAL_PREPARATION_STATUS.md`).
- **Translation authority:** `docs/i18n/12-en-id-translation-dataset-final.csv` (texts) and
  `13-optifleet-translation-glossary-final.md` (terminology).
- **New strings and plural forms:** only through `docs/i18n/17-i18n-additions.csv`
  (`translation_key, source_text_en, translated_text_id, context, module, reason`).
- **MongoDB exclusion:** the 25 Intelligence rows marked `BLOCKED_BY_MONGODB_TEST_ENVIRONMENT` are not
  generated and their code is not touched (`DEFERRED_MONGODB_I18N_STRUCTURAL_CLOSURE`).

## Phases

| # | Phase | Status |
|---:|---|---|
| 1 | Locale contract + resource generation | DONE |
| 2 | Frontend / backend i18n foundation | — |
| 3 | User / tenant locale preference + language selector | — |
| 4 | Shared / global UI | — |
| 5+ | Business modules | — |
| D | Printed documents | — |
| N | Notifications | — |
| QA | Hard-coded audit, full regression, report | — |

## Phase 1 — Locale contract and resource generation

**Locale contract**
- Supported locales: `en`, `id` (BCP 47 primary tags; `Intl` uses `en-US` / `id-ID`).
- English is the system fallback.
- Locale keys are codes, never display names.
- Frontend: `AppLocale` in `src/i18n/locale.ts`. Backend: `DocumentLocale::SUPPORTED` / `FALLBACK`.

**Generator: `frontend/scripts/i18n/generate.mjs`**
- Commands:
  - `npm run i18n:generate` writes the resources;
  - `npm run i18n:check` validates them and fails if the committed resources are stale.
- **Pipeline:** dataset `12` + additions `17` → generator → generated resources:
  - `frontend/src/i18n/locales/{en,id}/<namespace>.json`, nested by key path; the namespace is the
    first key segment, giving 44 namespace files (e.g. `common`, `nav`, `status`, `workOrder`, `tire`).
  - `backend/lang/{en,id}/catalog.php`: a flat `full.key => text` map for the backend message lookup.
- **Single source of truth:** the CSVs. The resources are generated, never edited by hand, and the
  check keeps them in sync.
- **Validation:**
  - duplicate keys (additions);
  - additions redefining a dataset key (only plural `_one` / `_other` / `_zero` variants may be added);
  - invalid keys or namespaces;
  - empty English or Indonesian text;
  - `{{param}}` mismatch;
  - rich-text tag mismatch (`<link>`, `<position>`);
  - a key colliding with a parent key in the nested layout.
- **Normalization:** legacy single-brace `{param}` becomes `{{param}}`.
- **Excluded (reported each run):**
  - 147 superseded keys (retired fragments);
  - 22 MongoDB-deferred keys (the other 3 blocked rows are also superseded);
  - 5 non-key rows: seeder configuration change summaries, historical metadata stored as is.
- **Result:** 5,281 keys per locale.

**Tests**
- Frontend `tests/unit/i18nResources.test.ts`:
  - the dataset generates cleanly;
  - the resources are up to date;
  - every validation rule fires on fixtures;
  - exclusions and placeholder normalization;
  - the CSV parser.
- Backend `Unit/TranslationCatalogTest`: identical keys and `{{params}}` in `en` / `id`.
- Frontend: type-check, build, 29 unit tests, lint (0 errors, 27 existing warnings).
