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

## Finalization task (terminology decisions)

The decision request contained only `[ISI KEPUTUSAN]` placeholders, so **0 of 48 decisions** were
received. None was invented. The propagation mechanism (`depends_on` → substitution in
`proposed_text_id`) is ready for when decisions arrive.

| Output | Status |
|---|---|
| `06` glossary — authority/status column added | DONE |
| `07` decision list — Resolved (0) / Remaining (43 terminology + 6 style + correction items) | AWAITING OWNER DECISION |
| `12-en-id-translation-dataset-final.csv` — 5,275 rows | DONE; translation readiness and implementation readiness tracked separately |
| `13-optifleet-translation-glossary-final.md` | DONE |
| `14-final-translation-qa-report.md` — 0 missing, 0 parameter mismatch, 0 key collision, 0 DNT violation | DONE |
| `15-structural-i18n-execution-plan.md` — ordered plan + database localization strategy | DONE (not implemented) |

Status: **READY_FOR_REMAINING_TERMINOLOGY_DECISION**. Audit artifacts `01`–`05`, `translation-inventory.csv`,
`08`–`11` are preserved unchanged.

## Owner terminology decisions applied

The product owner supplied all 43 terminology decisions and 6 style decisions. They are recorded in
`07` as APPROVED_BY_PRODUCT_OWNER and were propagated by substitution in `proposed_text_id`; nothing was
re-translated.

- 1,148 dataset rows: AWAITING_DECISION → APPROVED_GLOSSARY.
- Context applications are documented in `07`, for example Mechanic → Mekanik vs Worker → Pekerja,
  Intelligence word order, and physical "Lepas" vs record "Hapus".
- During propagation the `invoice` substitution corrupted 9 `{{…invoice…}}` placeholder names. They were
  restored from the English source, and parameter integrity is back to 100%.

| Output | Status |
|---|---|
| `06` — AWAITING rows marked SUPERSEDED_BY_OWNER_DECISION (preparation recommendations kept as history) | DONE |
| `07` — Resolved: 43 terms + 6 style; Remaining: 4 correction items (14 rows), workflow verb English wording, cancel key split | DONE |
| `12` — 5,275 rows; 14 rows still AWAITING_DECISION (correction.tire / warrantyClaim / wheelsConfiguration / hold) | DONE |
| `13` — decided terms marked APPROVED_BY_PRODUCT_OWNER | DONE |
| `14` — QA rerun: 0 missing, 0 parameter mismatch, 0 key collision, 0 DNT violation, 0 pluralization issue; near-collisions documented (incl. Breakdown vs failure "Kerusakan") | DONE |
| `15` — unchanged (terminology-independent) | DONE (not implemented) |

Status: **READY_FOR_REMAINING_TERMINOLOGY_DECISION**. The remaining decisions are the 4 correction items
that were not in the owner list. Once they are decided the status becomes
READY_FOR_STRUCTURAL_I18N_PREPARATION. `01`–`05`, `translation-inventory.csv`, `08`–`11` and `15` are unchanged.

## Owner architecture decisions D1 / D2 (localization scope)

- **D1, printed document language:** one locale per document, not bilingual. Priority: explicit Print/Export
  choice → user preferred locale → tenant default → `en`. The locale is preserved at generation time.
- **D2, tenant-entered data:** not bilingual. The original value is stored and shown. No auto-translation and
  no EN/ID fields. Only system-controlled text is localized.

Recorded in `07` (Resolved Architecture Decisions) and `15` (§7a, §7b, recommendation table, execution order
step 8, open decisions).

- Dataset `12`: no change needed. All 420 `DATABASE_LOCALIZATION` rows are system/seeded data.
- Finding from code: the 7 print endpoints re-render on demand and persist neither the locale nor the template
  version. Meeting D1's "preserve locale at generation time" needs a document generation record. It is
  recorded as structural item `PRINT_LOCALE_SNAPSHOT` (schema change, not implemented, needs approval).
- New open point (not assumed): whether date/number formatting on printed documents follows the document locale.

Status remains **READY_FOR_REMAINING_TERMINOLOGY_DECISION** (4 correction terms still open).

## Final terminology closure

Owner decisions applied:
- Tire = Ban (display only), Warranty Claim = Klaim Garansi, Wheels Configuration = Konfigurasi Roda.
- Hold: action = Tahan; status HOLD / Held / On Hold = Ditahan.
- Workflow action verbs approved. Approve = Setujui, which supersedes the earlier "Setuju".
- Cancel split: `common.actions.cancel` = Batal (dismiss); new `common.actions.cancelRecord` = Batalkan (record).
- D3: date/number formatting follows the document locale.
- D4: the generation-record schema change is approved.

Results:
- Dataset `12`: 5,276 rows, **AWAITING_DECISION = 0**.
- 34 legacy status-form action labels are marked superseded by verb keys.
- QA rerun: 0 missing, 0 parameter mismatch, 0 key collision, 0 DNT violation.

Status: **READY_FOR_STRUCTURAL_I18N_PREPARATION**. Structural phases continue per `15`; progress is
tracked in `I18N_STRUCTURAL_PREPARATION_STATUS.md`.
