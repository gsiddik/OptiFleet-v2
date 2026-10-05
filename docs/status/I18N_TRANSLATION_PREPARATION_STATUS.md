# i18n — Translation Preparation, Glossary & EN–ID Dataset — Status

Input: the user-facing text audit in `docs/i18n/01`–`05` (`main` @ 3e55571; `main` unchanged since).
Scope: documentation only — no source code, i18n library, locale file, enum, workflow, permission,
tab id, error handling, schema or migration change.

| Step | Output | Status |
|---|---|---|
| Classification of all 5,128 inventory entries (4 classes) | `08-en-id-translation-dataset.csv` | DONE |
| Glossary | `06-optifleet-translation-glossary.md` | DONE |
| Terminology decisions (batch) | `07-translation-decision-list.md` — 43 terms + 5 style decisions | AWAITING OWNER DECISION |
| EN–ID dataset | `08-en-id-translation-dataset.csv` — 5,240 rows (5,128 inventory + 76 whole-sentence templates + 36 Laravel validation rules) | DONE |
| Human-readable review | `09-en-id-translation-review.md` | DONE |
| Structural preparation plan | `10-structural-i18n-preparation-required.md` | DONE (not implemented) |
| Consistency / QA + inventory correction proposals | `11-translation-consistency-report.md` | DONE |

Readiness: **READY_FOR_TERMINOLOGY_DECISION**. After the owner decides `07`, the dataset becomes
READY_FOR_FINAL_TRANSLATION_DATASET (substitute `proposed_text_id` → `translated_text_id`).
Not ready for i18n implementation until the structural items in `10` are prepared.
