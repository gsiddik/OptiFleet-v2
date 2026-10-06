# 05 — i18n Audit Coverage

Branch audited: `main` @ 3e55571 (checked out as `claude/magical-volta-tv4xwl`, no content difference). Audit date: 2026-10-05. **No code, i18n library, locale file, migration or seeder was added or changed.**

## Method

1. Repository assessment: frontend `frontend/src` (React 19 + TS), backend `backend/app`, `routes`, `bootstrap`, `config`, `database/seeders`, `resources/views` (Laravel 11).
2. Frontend: TypeScript compiler AST over every `.ts/.tsx` file (JSX text, attributes, props, calls, conditional branches, template literals, mixed JSX children).
3. Backend: PHP tokenizer over every `.php` file (literals, interpolated strings, heredoc/nowdoc templates) with call/array-key/constant context; HTML print templates split into text nodes; workflow labels reconstructed from the seeder generator.
4. Residual pass: every natural-language literal *not* captured was listed and reviewed; the captured patterns were extended until the residue contained only identifiers (key names, CSS, HTTP headers, keyboard key names) and label comparisons — the latter are tab labels used as state, reported in 02 H-3.
5. Consolidation into semantic keys, classification, dynamic-template normalization, terminology and do-not-translate review.

## Coverage counts

| Item | Count |
|---|---|
| Frontend files scanned (.ts/.tsx) | 268 |
| Backend files scanned (.php incl. seeders, config, bootstrap, routes + 1 Blade view) | 791 |
| Files containing user-facing text | 514 |
| Pages scanned (`*Page.tsx`) | 141 |
| React components scanned (named components) | 620 |
| Forms (files using FormField labels) | 78 |
| Validation sources — frontend files with client validation messages | 19 |
| Validation sources — backend files with custom validation messages | 67 |
| Validation sources — FormRequest classes / inline validate() calls (framework defaults) | 75 / 227 |
| API message sources (backend files emitting error/success/validation text) | 212 |
| Document/print templates (14 platform defaults incl. the external WO print section, + RFQ document, + platform invoice PDF) | 16 |
| String literals parsed — frontend | 18690 |
| String literals parsed — backend | 38316 |
| User-facing raw occurrences | 7413 |
| Unique strings (after consolidation) | 5128 |
| Translation candidates (Need = YES) | 4651 |
| Do-not-translate (Need = NO) | 42 |
| Review required (Need = REVIEW) | 435 |
| Dynamic strings (YES + PARTIAL) | 841 |
| Consolidated duplicates (occurrences merged) | 2285 |
| Excluded — demo/test seeder strings | 448 |
| Excluded — artisan CLI output/signatures | 157 |
| Excluded — log messages | 29 |

## Category checklist and breakdown

| # | Category | Checked | Unique | Occurrences | Dynamic | Review |
|---|---|---|---|---|---|---|
| 1 | PAGE_TITLE | YES | 100 | 106 | 4 | 14 |
| 2 | SECTION_TITLE | YES | 288 | 384 | 9 | 12 |
| 3 | MENU | YES | 15 | 18 | 0 | 0 |
| 4 | SUBMENU | YES | 76 | 90 | 0 | 15 |
| 5 | BUTTON | YES | 379 | 863 | 14 | 1 |
| 6 | ACTION | YES | 74 | 133 | 1 | 36 |
| 7 | FORM_LABEL | YES | 1682 | 2995 | 206 | 169 |
| 8 | PLACEHOLDER | YES | 84 | 101 | 1 | 14 |
| 9 | HELPER_TEXT | YES | 574 | 592 | 158 | 57 |
| 10 | TOOLTIP | YES | 69 | 74 | 8 | 0 |
| 11 | VALIDATION | YES | 263 | 289 | 83 | 0 |
| 12 | CONFIRMATION | YES | 46 | 56 | 15 | 0 |
| 13 | ERROR | YES | 516 | 624 | 194 | 0 |
| 14 | SUCCESS | YES | 51 | 57 | 8 | 0 |
| 15 | STATUS | YES | 114 | 166 | 1 | 49 |
| 16 | TABLE_HEADER | YES | 125 | 149 | 2 | 3 |
| 17 | EMPTY_STATE | YES | 172 | 187 | 3 | 0 |
| 18 | FILTER | YES | 34 | 53 | 0 | 1 |
| 19 | SEARCH | YES | 15 | 18 | 0 | 0 |
| 20 | BREADCRUMB | YES | 96 | 96 | 3 | 17 |
| 21 | MODAL | YES | 125 | 128 | 25 | 0 |
| 22 | WARNING | YES | 56 | 57 | 23 | 46 |
| 23 | DOCUMENT_LABEL | YES | 174 | 177 | 83 | 1 |

The spec's areas 1–20 map onto these categories (API messages → ERROR/SUCCESS/VALIDATION; enum/status display → STATUS; notifications → HELPER_TEXT/FORM_LABEL with context "notification"; modals → MODAL/CONFIRMATION; print/PDF/reports → DOCUMENT_LABEL; shared components → GLOBAL scope; seed/master labels → DOMAIN scope). All were checked.

## Module coverage matrix

| Module | Scanned (files) | Total Candidates (unique) | Occurrences | Dynamic | Review Required | Notes |
|---|---|---|---|---|---|---|
| BE Domain/AccessControl | 8 | 14 | 15 | 4 | 3 |  |
| BE Domain/Analytics | 32 | 60 | 60 | 2 | 0 | MongoDB projection code: labels audited statically; runtime Mongo documents NOT AUDITED (Mongo unavailable). |
| BE Domain/Audit | 3 | 0 | 0 | 0 | 0 | Audit log actions are stored as codes; the UI renders them (FE audit). |
| BE Domain/Billing | 4 | 1 | 1 | 0 | 0 |  |
| BE Domain/Breakdown | 3 | 2 | 2 | 1 | 0 |  |
| BE Domain/ComponentAsset | 7 | 14 | 14 | 5 | 0 |  |
| BE Domain/Configuration | 21 | 76 | 79 | 17 | 14 |  |
| BE Domain/Contract | 11 | 24 | 24 | 10 | 0 |  |
| BE Domain/Entitlement | 6 | 5 | 5 | 5 | 0 | Messages surface via EntitlementException (422). |
| BE Domain/History | 2 | 14 | 14 | 5 | 2 |  |
| BE Domain/Identity | 3 | 3 | 3 | 0 | 0 |  |
| BE Domain/Inspection | 7 | 6 | 6 | 0 | 1 |  |
| BE Domain/Integration | 2 | 0 | 0 | 0 | 0 | Integration outbox/contracts — no user-facing literals found. |
| BE Domain/Intelligence | 41 | 30 | 30 | 17 | 22 | Reasons/feature-set names are English; persisted predictions NOT AUDITED at runtime. |
| BE Domain/Inventory | 14 | 30 | 32 | 8 | 1 |  |
| BE Domain/Invoice | 7 | 4 | 4 | 1 | 0 |  |
| BE Domain/MaintenancePolicy | 9 | 4 | 5 | 0 | 0 |  |
| BE Domain/MaintenanceRequest | 7 | 4 | 4 | 1 | 0 |  |
| BE Domain/MasterData | 16 | 65 | 65 | 9 | 32 |  |
| BE Domain/Notification | 12 | 52 | 53 | 11 | 0 |  |
| BE Domain/Organization | 6 | 0 | 0 | 0 | 0 | Organization messages are raised from controllers (attributed to BE Http/Controllers). |
| BE Domain/Partner | 4 | 3 | 3 | 0 | 0 |  |
| BE Domain/Payment | 5 | 5 | 5 | 1 | 0 |  |
| BE Domain/Pricing | 7 | 2 | 2 | 2 | 0 |  |
| BE Domain/Procurement | 27 | 81 | 84 | 33 | 0 |  |
| BE Domain/ProductCatalog | 8 | 6 | 6 | 2 | 0 |  |
| BE Domain/ProductMaster | 18 | 22 | 22 | 3 | 0 |  |
| BE Domain/QualityControl | 6 | 8 | 8 | 1 | 0 |  |
| BE Domain/Shared | 7 | 6 | 6 | 5 | 0 | Infrastructure (scopes, concerns, tenant context, private storage); its messages are counted under the file that throws them. |
| BE Domain/Subscription | 5 | 4 | 4 | 1 | 0 |  |
| BE Domain/Tire | 56 | 268 | 273 | 113 | 67 |  |
| BE Domain/Vehicle | 9 | 7 | 8 | 2 | 0 |  |
| BE Domain/VehicleRelease | 3 | 3 | 3 | 0 | 0 |  |
| BE Domain/Warranty | 5 | 1 | 1 | 1 | 0 |  |
| BE Domain/WorkOrder | 47 | 143 | 162 | 60 | 0 |  |
| BE Domain/Workflow | 14 | 29 | 29 | 16 | 0 |  |
| BE Domain/Workshop | 13 | 28 | 29 | 10 | 0 |  |
| BE Http/Controllers | 152 | 225 | 310 | 5 | 8 |  |
| BE Http/Middleware | 6 | 11 | 12 | 2 | 0 |  |
| BE Http/Requests | 75 | 11 | 11 | 2 | 0 |  |
| BE app/Console | 21 | 0 | 0 | 0 | 0 | No user-facing literals found in this area (logic/models only); messages it raises are attributed to the throwing file. |
| BE app/Http | 1 | 0 | 0 | 0 | 0 | No user-facing literals found in this area (logic/models only); messages it raises are attributed to the throwing file. |
| BE app/Jobs | 10 | 5 | 5 | 2 | 1 |  |
| BE app/Models | 1 | 0 | 0 | 0 | 0 | No user-facing literals found in this area (logic/models only); messages it raises are attributed to the throwing file. |
| BE app/Providers | 3 | 0 | 0 | 0 | 0 | No user-facing literals found in this area (logic/models only); messages it raises are attributed to the throwing file. |
| BE app/Support | 4 | 0 | 0 | 0 | 0 | No user-facing literals found in this area (logic/models only); messages it raises are attributed to the throwing file. |
| BE bootstrap/app.php | 1 | 3 | 4 | 0 | 0 |  |
| BE bootstrap/cache | 2 | 0 | 0 | 0 | 0 | No user-facing literals found in this area (logic/models only); messages it raises are attributed to the throwing file. |
| BE bootstrap/providers.php | 1 | 0 | 0 | 0 | 0 | No user-facing literals found in this area (logic/models only); messages it raises are attributed to the throwing file. |
| BE config/analytics.php | 1 | 0 | 0 | 0 | 0 | No user-facing literals found in this area (logic/models only); messages it raises are attributed to the throwing file. |
| BE config/app.php | 1 | 1 | 1 | 0 | 0 |  |
| BE config/auth.php | 1 | 0 | 0 | 0 | 0 | No user-facing literals found in this area (logic/models only); messages it raises are attributed to the throwing file. |
| BE config/cache.php | 1 | 0 | 0 | 0 | 0 | No user-facing literals found in this area (logic/models only); messages it raises are attributed to the throwing file. |
| BE config/cors.php | 1 | 1 | 1 | 0 | 0 |  |
| BE config/database.php | 1 | 0 | 0 | 0 | 0 | No user-facing literals found in this area (logic/models only); messages it raises are attributed to the throwing file. |
| BE config/filesystems.php | 1 | 0 | 0 | 0 | 0 | No user-facing literals found in this area (logic/models only); messages it raises are attributed to the throwing file. |
| BE config/intelligence.php | 1 | 12 | 12 | 12 | 0 |  |
| BE config/logging.php | 1 | 1 | 1 | 0 | 0 |  |
| BE config/mail.php | 1 | 1 | 1 | 0 | 0 |  |
| BE config/procurement.php | 1 | 0 | 0 | 0 | 0 | No user-facing literals found in this area (logic/models only); messages it raises are attributed to the throwing file. |
| BE config/queue.php | 1 | 0 | 0 | 0 | 0 | No user-facing literals found in this area (logic/models only); messages it raises are attributed to the throwing file. |
| BE config/sanctum.php | 1 | 0 | 0 | 0 | 0 | No user-facing literals found in this area (logic/models only); messages it raises are attributed to the throwing file. |
| BE config/services.php | 1 | 0 | 0 | 0 | 0 | No user-facing literals found in this area (logic/models only); messages it raises are attributed to the throwing file. |
| BE config/session.php | 1 | 0 | 0 | 0 | 0 | No user-facing literals found in this area (logic/models only); messages it raises are attributed to the throwing file. |
| BE resources/views | 1 | 21 | 23 | 1 | 0 |  |
| BE routes/api | 2 | 0 | 0 | 0 | 0 | No user-facing literals found in this area (logic/models only); messages it raises are attributed to the throwing file. |
| BE routes/api.php | 1 | 0 | 0 | 0 | 0 | No user-facing literals found in this area (logic/models only); messages it raises are attributed to the throwing file. |
| BE routes/console.php | 1 | 1 | 1 | 0 | 0 |  |
| BE routes/web.php | 1 | 0 | 0 | 0 | 0 | No user-facing literals found in this area (logic/models only); messages it raises are attributed to the throwing file. |
| BE seeders (production) | 17 | 311 | 422 | 87 | 182 | Only BootstrapSeeder chain (+ ComponentGroup/Taxonomy). Demo / functional-test seeders excluded by rule (demo data). |
| FE App.tsx | 1 | 0 | 0 | 0 | 0 | No user-facing literals found in this area (logic/models only); messages it raises are attributed to the throwing file. |
| FE api | 1 | 1 | 1 | 0 | 0 | Fallback API error text only. |
| FE auth | 1 | 1 | 1 | 0 | 0 |  |
| FE auth (LoginPage) | 1 | 7 | 7 | 0 | 0 |  |
| FE components/(shared) | 25 | 122 | 125 | 13 | 1 |  |
| FE components/analytics | 4 | 5 | 5 | 2 | 0 |  |
| FE components/intelligence | 1 | 0 | 0 | 0 | 0 | RiskBadge renders the raw risk/health code (HEALTHY, AT_RISK, CRITICAL, … 11 codes) — no literal label exists; reported in 02 H-2. |
| FE components/masterdata | 3 | 95 | 170 | 5 | 7 |  |
| FE components/tires | 1 | 0 | 0 | 0 | 0 | PositionLabel renders text from utils/tirePosition.ts (audited under FE utils). |
| FE hooks | 3 | 0 | 0 | 0 | 0 | useWorkflowTransitions builds button text from codes (humanize) — reported in 02 H-9; no literal labels. |
| FE layouts | 4 | 119 | 138 | 1 | 16 | Sidebar menu (tenantNav.ts) + platform nav. |
| FE main.tsx | 1 | 0 | 0 | 0 | 0 | No user-facing literals found in this area (logic/models only); messages it raises are attributed to the throwing file. |
| FE navigation | 4 | 93 | 93 | 0 | 16 | Breadcrumb labels. |
| FE platform/(dashboard) | 1 | 16 | 16 | 0 | 0 |  |
| FE platform/access | 2 | 14 | 16 | 0 | 0 |  |
| FE platform/audit | 1 | 1 | 1 | 0 | 0 |  |
| FE platform/billing | 1 | 9 | 9 | 0 | 1 |  |
| FE platform/bundles | 2 | 29 | 31 | 1 | 2 |  |
| FE platform/contracts | 3 | 103 | 123 | 8 | 1 |  |
| FE platform/invoices | 2 | 33 | 33 | 3 | 1 |  |
| FE platform/masterdata | 4 | 26 | 30 | 1 | 0 |  |
| FE platform/modules | 2 | 25 | 31 | 0 | 0 |  |
| FE platform/payments | 2 | 35 | 37 | 2 | 1 |  |
| FE platform/pricing | 1 | 29 | 32 | 4 | 0 |  |
| FE platform/subscriptions | 1 | 30 | 34 | 5 | 1 |  |
| FE platform/tenants | 6 | 55 | 69 | 0 | 1 |  |
| FE tenant/(dashboard) | 1 | 38 | 39 | 0 | 3 |  |
| FE tenant/access | 2 | 39 | 41 | 3 | 3 |  |
| FE tenant/account | 7 | 104 | 117 | 7 | 0 |  |
| FE tenant/analytics | 16 | 116 | 128 | 2 | 5 |  |
| FE tenant/audit | 1 | 1 | 1 | 0 | 0 |  |
| FE tenant/components | 2 | 62 | 73 | 4 | 2 |  |
| FE tenant/configuration | 22 | 292 | 364 | 47 | 2 |  |
| FE tenant/external-work-order-invoices | 1 | 78 | 84 | 5 | 3 |  |
| FE tenant/history | 1 | 15 | 16 | 0 | 0 |  |
| FE tenant/inspections | 3 | 61 | 72 | 5 | 0 |  |
| FE tenant/intelligence | 6 | 75 | 84 | 7 | 2 |  |
| FE tenant/inventory | 18 | 471 | 631 | 43 | 11 |  |
| FE tenant/maintenance | 6 | 171 | 232 | 4 | 9 |  |
| FE tenant/masterdata | 7 | 61 | 116 | 5 | 1 |  |
| FE tenant/organization | 4 | 60 | 94 | 5 | 2 |  |
| FE tenant/partners | 2 | 68 | 101 | 1 | 0 |  |
| FE tenant/procurement | 17 | 263 | 363 | 38 | 9 |  |
| FE tenant/tires | 42 | 785 | 1075 | 124 | 27 |  |
| FE tenant/vehicles | 4 | 133 | 185 | 5 | 5 |  |
| FE tenant/warranty | 4 | 57 | 74 | 2 | 2 |  |
| FE tenant/workorders | 6 | 332 | 427 | 33 | 10 |  |
| FE tenant/workshop | 6 | 97 | 128 | 7 | 3 |  |
| FE tenant/workshop-invoices | 1 | 70 | 76 | 6 | 3 |  |
| FE types | 1 | 0 | 0 | 0 | 0 | No user-facing literals found in this area (logic/models only); messages it raises are attributed to the throwing file. |
| FE utils | 10 | 25 | 25 | 9 | 0 |  |

No module was skipped: every frontend page directory and every backend `app/Domain/*` folder appears above; rows with 0 candidates are explained.

## NOT AUDITED (and why)

| Area | Status | Reason |
|---|---|---|
| MongoDB analytical documents (runtime content) | NOT AUDITED | MongoDB is not available in this environment; projection code was scanned statically, stored documents were not inspected. |
| Laravel framework default validation / auth / pagination messages (vendor/laravel/framework lang/en) | NOT AUDITED line-by-line | Not in the repository; counted as a source (75 FormRequests, 227 validate() calls, 2 messages(), 0 attributes()) — see 02 H-6. |
| Third-party UI text (@xyflow/react controls/attribution, browser-native confirm/alert buttons, file input "Choose file") | NOT AUDITED | Rendered by libraries/browser, not by repository code. |
| Tenant-entered data (names, notes, custom templates, custom workflow labels, custom notification text) | EXCLUDED by rule | User-generated content. |
| Database rows created at runtime beyond production seeders | NOT AUDITED | Static audit only; seeded defaults were audited from seeder source. |
| Email delivery | N/A | No Mailable/Notification classes exist; EMAIL channel uses the same notification templates (audited). |
| Artisan commands output and descriptions | EXCLUDED | Operator/CLI-facing, not tenant UI (157 strings). |
| Automated tests, e2e scripts | EXCLUDED | Not user-facing. |
| Runtime visual verification (rendered screens) | NOT RUN | Audit is static; no browser pass was performed for this task. |

## Known limitations of the static method

- Category assignment for generic text (e.g. a short span) is heuristic: it uses the JSX element chain, attribute/property names and wording. Every row keeps its context and source file so it can be re-classified without re-scanning.
- Parameter names in normalized templates come from the source expression; `value` marks a computed expression whose final name must be chosen during implementation.
- Labels produced only at runtime from codes (humanizers, `ucwords`) have no literal; they are reported as findings (02 H-2, H-9) and, for workflow defaults, reconstructed.

## Translation readiness

**NOT READY.**

Blockers (all from 02):

1. No locale infrastructure on either tier (H-1).
2. Statuses render canonical codes — no display value exists to translate (H-2).
3. Tab labels are used as state/URL identifiers (H-3).
4. 97 sentence fragments assembled by concatenation (H-4).
5. Backend messages are English literals shown verbatim; framework validation messages and attribute names are implicit (H-5, H-6).
6. User-facing labels stored as data without a localization model — workflow labels, print templates, notification templates, seeded master data (H-7) — needs an owner decision.
7. Logic/contract coupled to English text: message matching, exact-match import headers (H-8); runtime humanizers (H-9).
8. 435 terminology items require an owner glossary decision (03).

The audit itself is complete for repository source within the scope above; the areas marked NOT AUDITED are outside repository source or unavailable in this environment.

