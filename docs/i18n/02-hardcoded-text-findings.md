# 02 — Hard-Coded UI Text Findings

Every finding below is traceable to source. Severity reflects how much it blocks a bilingual (EN/ID) rollout, not code quality in general. **No code was changed.**

## Summary

| Severity | Findings |
|---|---|
| HIGH | 9 |
| MEDIUM | 8 |
| LOW | 4 |

## HIGH

### H-1 — No localization infrastructure on either tier

Every user-facing string (7413 occurrences, 5128 unique) is an English literal. Frontend has no i18n library and a fixed `<html lang="en">`; backend has no `lang/` directory, no `__()` / `trans()` call anywhere in `app/`, `routes/`, `resources/` or seeders, and `APP_LOCALE` defaults to `en`.

| File | Line / Area | Current Text | Suggested Key |
|---|---|---|---|
| `frontend/index.html` | 2 | <html lang="en"> | `—` |
| `backend/config/app.php` | 87 | 'locale' => env('APP_LOCALE', 'en') | `—` |
| `frontend/src (all 268 files)` | — | string literals in JSX / props / calls | `see 01 / CSV` |
| `backend/app (all files)` | — | English literals in exceptions, aborts, validation, responses | `errors.* / validation.* / messages.*` |

**Recommendation (not implemented):** Introduce a locale resolution strategy (user preference → tenant default → `en`) and message catalogs on both tiers before any string replacement.

### H-2 — Statuses are displayed as their canonical code

`StatusBadge` prints the stored code (e.g. `UNDER_REVIEW`, `PENDING_PROCESSING`) uppercased by CSS; its only label map is `{ SCRAPPED: "SCRAP" }`. It is used 154 times in 112 files, and 56 further places render `{row.status|type|kind|category|priority|…}` directly. There is no display value to translate — a label map must exist first. 44 badge codes are listed in the DOMAIN table of 01 with keys `status.<code>`.

| File | Line / Area | Current Text | Suggested Key |
|---|---|---|---|
| `frontend/src/components/StatusBadge.tsx` | 1 | const COLORS = { ACTIVE: …, UNDER_REVIEW: …, … } | `status.<code>` |
| `frontend/src/components/StatusBadge.tsx` | 56 | const LABELS = { SCRAPPED: 'SCRAP' } | `status.scrapped` |
| `frontend/src/components/intelligence/RiskBadge.tsx` | 1 | COLORS = { HEALTHY, GOOD, LOW, WATCH, MEDIUM, AT_RISK, HIGH, CRITICAL, URGENT, DATA_QUALITY, UNKNOWN } — badge prints {level} | `intelligence.level.<code>` |
| `frontend/src/pages/tenant/maintenance/MaintenanceRequestDetailPage.tsx` | 166 | {s.replace(/_/g, ' ')} | `status.<code>` |

**Recommendation (not implemented):** Create one status/enum display registry (code → key) shared by badge, filters and options; keep canonical values untouched.

### H-3 — Tab labels double as state identifiers

Tabs are stored and compared by their English label (`activeTab === "Overview"`, `useState("Overview")`, `?tab=Contract` in a URL). Translating the label would break tab selection, deep links and saved back-navigation.

| File | Line / Area | Current Text | Suggested Key |
|---|---|---|---|
| `frontend/src/pages/tenant/workorders/WorkOrderDetailPage.tsx` | 125 | useState('Overview') … tab === 'Complaint' / 'Diagnosis' / 'External Services' … (16 comparisons) | `workOrder.sections.*` |
| `frontend/src/pages/platform/tenants/TenantDetailPage.tsx` | 23 | useState('Overview'); TABS = ['Overview','Users','Module Entitlements','Capacity Limits','Contract'] | `platform.tenants.sections.*` |
| `frontend/src/pages/platform/contracts/ContractDetailPage.tsx` | 35 | `/platform/tenants/${id}?tab=Contract` | `—` |
| `frontend/src/pages/tenant/vehicles/VehicleDetailPage.tsx` | 19 | TABS = ['Overview','Assignment','Transfer','Documents','Wheels Configuration','History'] used as tab state (6 comparisons) | `vehicle.sections.*` |

**Recommendation (not implemented):** Give each tab a stable id; render the label from the id.

### H-4 — Sentences assembled from fragments

97 unique fragments are concatenated with `+`, inserted inside template literals as conditional pieces, or split across JSX nodes (e.g. `` `${kind === "repair" ? "Repair" : "Retread"} cycle` ``). Word order differs in Indonesian, so these cannot be translated piecewise. All are listed below and in 04.

| File | Line / Area | Current Text | Suggested Key |
|---|---|---|---|
| `frontend/src/pages/tenant/inventory/StockOpnameDetailPage.tsx` | 17 | Approve | `common.fields.approve` |
| `frontend/src/pages/tenant/inventory/StockTransferDetailPage.tsx` | 20 | Cancel | `common.fields.cancel` |
| `frontend/src/pages/tenant/tires/wheel-configuration/wheelLayout.ts` | 117 | Front | `common.fields.front` |
| `frontend/src/pages/tenant/tires/wheel-configuration/wheelLayout.ts` | 117 | Left | `common.fields.left` |
| `frontend/src/pages/tenant/components/ComponentAssetDetailPage.tsx` | 167 | Partner | `common.fields.partner` |
| `frontend/src/pages/tenant/tires/wheel-configuration/wheelLayout.ts` | 117 | Rear | `common.fields.rear` |
| `frontend/src/pages/tenant/tires/wheel-configuration/wheelLayout.ts` | 117 | Right | `common.fields.right` |
| `backend/database/seeders/ConfigurationDefaultsSeeder.php` | 59 | Numbering | `masterData.configurationDefaults.numbering` |
| `backend/database/seeders/ConfigurationDefaultsSeeder.php` | 64 | Template | `masterData.configurationDefaults.template` |
| `backend/database/seeders/WorkflowDefaultsSeeder.php` | 36 | Workflow | `workflow.status.workflow` |
| `frontend/src/pages/tenant/account/AccountContractPage.tsx` | 45 | Item | `account.fields.item` |
| `frontend/src/pages/tenant/inventory/ReturnListPage.tsx` | 30 | Repair | `inventory.fields.repair` |
| `frontend/src/pages/tenant/inventory/ReturnListPage.tsx` | 228 | the warehouse | `inventory.fields.theWarehouse` |
| `frontend/src/pages/platform/subscriptions/SubscriptionListPage.tsx` | 32 | the subscription | `platform.subscriptions.fields.theSubscription` |
| `frontend/src/pages/tenant/tires/wheel-configuration/wheelLayout.ts` | 117 | (closest to body) | `tire.fields.closestToBody` |
| `frontend/src/pages/tenant/tires/ImportTiresModal.tsx` | 149 | Duplicate | `tire.fields.duplicate` |
| `frontend/src/pages/tenant/tires/ImportTiresModal.tsx` | 149 | Invalid | `tire.fields.invalid` |
| `frontend/src/pages/tenant/tires/retread/RetreadCyclePanel.tsx` | 310 | Retread | `tire.fields.retread` |
| `frontend/src/pages/tenant/tires/operations/TireOperationFormPage.tsx` | 553 | this product | `tire.fields.thisProduct` |
| `frontend/src/pages/tenant/tires/wheel-configuration/VehicleWheelsConfigurationTab.tsx` | 118 | updated to newer version | `tire.help.updatedToNewerVersion` |
| `frontend/src/pages/tenant/workorders/PartRequestListPage.tsx` | 155 | this Work Order | `workOrder.fields.thisWorkOrder` |
| `backend/app/Jobs/SendNotificationJob.php` | 85 | Notification | `app.labels.notification` |
| `backend/app/Domain/Configuration/Services/TemplateVariableRegistry.php` | 255 | Repeats its content once for every | `configuration.labels.repeatsContentOnceEvery` |
| `backend/app/Domain/Configuration/Services/TemplateVariableRegistry.php` | 255 | row of the document. | `configuration.labels.rowOfTheDocument` |
| `backend/app/Domain/Contract/Services/ContractService.php` | 262 | Terminated | `contract.labels.terminated` |
| `backend/app/Domain/ComponentAsset/Services/ComponentAssetRegisterService.php` | 243 | Asset# being returned for this item (selected | `errors.componentAsset.assetNumberBeingReturnedItemSelected` |
| `backend/app/Domain/Entitlement/Services/EntitlementService.php` | 83 | Cannot disable module | `errors.entitlement.cannotDisableModule` |
| `backend/app/Domain/Entitlement/Services/EntitlementService.php` | 57 | Cannot enable module | `errors.entitlement.cannotEnableModule` |
| `backend/app/Domain/Entitlement/Services/EntitlementService.php` | 57 | : missing required module(s) | `errors.entitlement.missingRequiredModuleS` |
| `backend/app/Domain/Entitlement/Services/EntitlementService.php` | 83 | : still required by active module(s) | `errors.entitlement.stillRequiredActiveModuleS` |
| `backend/app/Domain/Configuration/Services/TemplateValidator.php` | 58 | Template references unknown variable(s) | `errors.notification.templateReferencesUnknownVariableS` |
| `backend/app/Domain/Procurement/Services/GoodsReceiptService.php` | 106 | exceeds the Remaining Receivable Qty of this line ( | `errors.procurement.exceedsRemainingReceivableQtyLine` |
| `backend/app/Domain/Procurement/Services/VendorInvoicePaymentService.php` | 40 | ); partial payments are not supported. | `errors.procurement.partialPaymentsNotSupported` |
| `backend/app/Domain/Procurement/Services/VendorInvoicePaymentService.php` | 40 | The payment amount must equal the invoice amount ( | `errors.procurement.paymentAmountMustEqualInvoiceAmount` |
| `backend/app/Domain/Procurement/Services/GoodsReceiptService.php` | 106 | Receiving | `errors.procurement.receiving` |
| `backend/app/Domain/ProductCatalog/Services/BundleService.php` | 110 | Cannot publish bundle: unresolved module dependencies | `errors.productCatalog.cannotPublishBundleUnresolvedModuleDependencies` |
| `backend/app/Domain/ProductCatalog/Services/ModuleDependencyService.php` | 141 | Circular module dependency detected | `errors.productCatalog.circularModuleDependencyDetected` |
| `backend/app/Http/Requests/Tenant/SaveMaintenanceRequestAssessmentRequest.php` | 36 | All 14 inspection groups are required. Missing | `errors.saveMaintenanceAssessment.all14InspectionGroupsRequiredMissing` |
| `backend/app/Domain/Tire/Inspection/UsedTireInspectionService.php` | 199 | ); it is only filled in here when missing. | `errors.tire.onlyFilledHereWhenMissing` |
| `backend/app/Domain/Tire/Inspection/UsedTireInspectionService.php` | 199 | This tire already has a Manufacture Date Code ( | `errors.tire.tireAlreadyManufactureDateCode` |
| `backend/app/Domain/Tire/Services/WheelConfigurationMappingBlockedException.php` | 18 | Vehicle cannot be remapped because active tires are installed on positions removed by the target configuration | `errors.tire.vehicleCannotRemappedBecauseActiveTires` |
| `backend/app/Domain/WorkOrder/Services/WorkOrderPartService.php` | 120 | Cannot return more than the returnable quantity ( | `errors.workOrder.cannotReturnMoreThanReturnableQuantity` |
| `backend/app/Domain/WorkOrder/Services/WorkOrderPartService.php` | 120 | ): consumed quantity is never returnable. | `errors.workOrder.consumedQuantityNeverReturnable` |
| `backend/app/Http/Controllers/Api/Tenant/InspectionController.php` | 151 | Issues found during inspection | `inspection.labels.issuesFoundDuringInspection` |
| `backend/app/Domain/Intelligence/DataReadiness/DataReadinessAssessmentService.php` | 56 | class imbalance too severe: majority class ratio | `intelligence.reasons.classImbalanceTooSevereMajorityClass` |
| `backend/app/Domain/Intelligence/Services/TrainingPipelineService.php` | 61 | Data readiness NOT_READY | `intelligence.reasons.dataReadinessNotReady` |
| `backend/app/Domain/Intelligence/DataReadiness/DataReadinessAssessmentService.php` | 60 | feature missingness too high | `intelligence.reasons.featureMissingnessTooHigh` |
| `backend/app/Domain/Contract/Services/AmendmentService.php` | 182 | Rejected | `messages.contract.rejected` |
| `backend/app/Http/Controllers/Api/Tenant/MaintenanceScheduleController.php` | 128 | Scheduled maintenance due | `messages.maintenanceSchedule.scheduledMaintenanceDue` |
| `backend/app/Http/Requests/Tenant/SaveMaintenanceRequestAssessmentRequest.php` | 39 | Each inspection group may only appear once. Duplicated | `saveMaintenanceAssessment.labels.eachInspectionGroupMayOnlyAppear` |
| `backend/app/Domain/Tire/Services/WheelConfigurationRules.php` | 115 | Axle | `tire.labels.axle` |
| `backend/app/Domain/Tire/Services/TireService.php` | 109 | Baseline reading captured at onboarding; installation date is | `tire.labels.baselineReadingCapturedOnboardingInstallationDate` |
| `backend/app/Domain/Tire/Services/VehicleWheelConfigurationMappingService.php` | 210 | does not match the configuration vehicle type. | `tire.labels.doesNotMatchConfigurationVehicleType` |
| `backend/app/Domain/Tire/Services/TireImportService.php` | 59 | How to import New Stock tires — | `tire.labels.howImportNewStockTires` |
| `backend/app/Domain/Tire/Inspection/UsedTireInspectionService.php` | 332 | Inspection disposition | `tire.labels.inspectionDisposition` |
| `backend/app/Domain/Tire/Services/TireImportService.php` | 67 | 7. At most | `tire.labels.n7AtMost` |
| `backend/app/Domain/Tire/Services/WheelConfigurationMappingBlockedException.php` | 19 | . Remove or transfer these tires first. | `tire.labels.removeTransferTheseTiresFirst` |
| `backend/app/Domain/Tire/Inspection/UsedTireInspectionService.php` | 384 | Repair complete + final inspection | `tire.labels.repairCompleteFinalInspection` |
| `backend/app/Domain/Tire/Services/TireImportService.php` | 67 | rows per file. Rows whose serial already exists, or that repeat a serial in the file, are not imported. | `tire.labels.rowsPerFileRowsWhoseSerial` |
| `backend/app/Domain/Tire/Services/TireImportService.php` | 262 | This serial number is repeated in the file (row | `tire.labels.serialNumberRepeatedFileRow` |

**Recommendation (not implemented):** Rewrite each into one parameterized message (`{{param}}`) per sentence.

### H-5 — Backend messages are English literals shown verbatim in the UI

Domain exceptions (496 occurrences), `abort*()` messages (155), custom validation messages (142), JSON response messages (17) and other API messages (204) are hard-coded. The frontend shows `response.data.message` / `errors` as-is (`extractApiError`), so every one of them reaches the user. Many interpolate values (`"Tire {serial} is …"`).

| File | Line / Area | Current Text | Suggested Key |
|---|---|---|---|
| `backend/app/Domain/WorkOrder/Services (168 `throw new WorkOrderException(`)` | — | e.g. see CSV rows with key errors.workOrder.* | `errors.workOrder.*` |
| `backend/app/Http/Controllers (338 `abort_unless`, 62 `abort_if`)` | — | abort_unless($x, 422, '…') | `errors.<module>.*` |
| `backend/bootstrap/app.php` | 76 | 'Unauthenticated.' / 'Forbidden.' / 'Resource not found.' | `errors.common.*` |
| `backend/app/Http/Middleware/RestrictSuspendedTenant.php` | 40 | Your subscription is suspended due to an outstanding balance… | `errors.http.subscriptionSuspended…` |
| `frontend/src/api/client.ts` | 34 | 'Unexpected error occurred' | `common.errors.unexpectedErrorOccurred` |

**Recommendation (not implemented):** Move messages to Laravel lang files with placeholders, resolve the request locale (e.g. `Accept-Language` / user preference) in middleware, or return stable error codes + params and translate on the client.

### H-6 — Laravel framework validation messages and attribute names are implicit

There are 75 FormRequest classes and 227 `->validate([...])` / `Validator::make` calls, but only 2 `messages()` methods and 0 `attributes()` methods. Every rule without a custom message produces the framework's English default (`The vehicle id field is required.`) with an attribute name derived from the snake_case field. These texts are not in the repository, so they cannot be inventoried string-by-string.

| File | Line / Area | Current Text | Suggested Key |
|---|---|---|---|
| `backend/app/Http/Requests (75 files)` | — | rules() without messages()/attributes() | `validation.* + validation.attributes.*` |
| `backend/app/Http/Controllers/** (227 calls)` | — | $request->validate([...]) | `validation.*` |

**Recommendation (not implemented):** Publish `lang/en/validation.php` + `lang/id/validation.php` and add an `attributes` map; list field display names once.

### H-7 — User-facing labels stored as data (no per-locale columns)

Several labels live in database rows created by seeders or by tenants: workflow status `display_name` and transition `action_label` (generated with `ucwords(code)` and stored per configuration version), 14 platform default print templates + tenant copies (HTML), notification rule names / subject / body, modules, bundles, component groups / categories / subcategories, product reference data, UoM, vehicle categories. Translating them needs a data decision (translatable columns, per-locale template versions, or code-keyed labels) — not a string swap.

| File | Line / Area | Current Text | Suggested Key |
|---|---|---|---|
| `backend/database/seeders/WorkflowDefaultsSeeder.php` | — | 'display_name' => ucwords(strtolower(str_replace('_',' ',$code))) | `workflow.status.<code>` |
| `backend/database/seeders/ConfigurationDefaultsSeeder.php` | 98 | 'work_order' => $wrap('Work Order', <<<HTML … | `documents.<type>.*` |
| `backend/database/seeders/NotificationDefaultsSeeder.php` | 44 | 'body' => '{{product.name}} ({{product.sku}}) at {{warehouse.name}} is low…' | `notifications.*` |
| `backend/database/seeders/ModuleSeeder.php` | 15 | 'name' => 'Organization Management' | `masterData.module.*` |
| `backend/app/Domain/MasterData/Support/ComponentGroupBaseline.php` | 32 | 'name' => 'Fuel System' … | `masterData.componentGroup.*` |

**Recommendation (not implemented):** DECISION REQUIRED (owner): which data labels are translated (system defaults only vs. tenant-entered too) and how (translation table / JSONB per locale / key-based).

### H-8 — Logic depends on English text

UI logic tests the content of a message; the import contract requires English column headers.

| File | Line / Area | Current Text | Suggested Key |
|---|---|---|---|
| `frontend/src/pages/tenant/tires/operations/TireOperationFormPage.tsx` | 422 | notice.includes('no tire data') && … <Link>Open Vehicle Details</Link> | `use an error code` |
| `backend/app/Domain/Tire/Services/TireImportService.php` | 36 | HEADERS = ['Serial Number', 'Manufacture Date Code', 'Purchase Date'] — header row must match exactly | `documents.tireImport.* (translate only with a stable column id)` |

**Recommendation (not implemented):** Branch on codes, not on message text; keep import headers language-independent (or accept both).

### H-9 — Labels generated from codes at runtime

Codes are turned into text by string manipulation, so no literal exists to translate.

| File | Line / Area | Current Text | Suggested Key |
|---|---|---|---|
| `frontend/src/hooks/useWorkflowTransitions.ts` | 51 | const humanize = (code) => code.replace(/_/g, ' ').toLowerCase() | `workflow.actions.<code>` |
| `frontend/src/pages/tenant/configuration/DocumentConfigList.tsx` | 74 | ?.label ?? code.replace(/_/g, ' ') | `documentType.<code>` |
| `frontend/src/pages/tenant/components/ComponentAssetDetailPage.tsx` | 279 | `${from.replace(/_/g,' ')} → ${to.replace(/_/g,' ')}` | `status.<code>` |
| `frontend/src/navigation/breadcrumbLabels.ts` | — | humanizeSegment() fallback for unknown segments | `breadcrumb.<segment>` |
| `backend/app (17 ucwords/ucfirst/Str::headline uses)` | — | e.g. WorkflowDefaultsSeeder set name ucwords(resource) . " Workflow" | `workflow.*` |

**Recommendation (not implemented):** Replace humanizers with registry lookups (code → key) and fail visibly in development when a key is missing.

## MEDIUM

### M-1 — Hard-coded English month names and locale-implicit number/date formatting

`utils/date.ts` builds dates with an English `MONTHS` array; elsewhere 76 `toLocaleString()` and 11 `toLocaleDateString()` calls use the browser locale, so the same screen mixes formats.

| File | Line / Area | Current Text | Suggested Key |
|---|---|---|---|
| `frontend/src/utils/date.ts` | 1 | const MONTHS = ['Jan', 'Feb', …] | `common.date.months.* (or Intl.DateTimeFormat)` |
| `frontend/src (76 places)` | — | value.toLocaleString() | `formatNumber(locale)` |

**Recommendation (not implemented):** Centralize date/number/money formatting on an explicit locale (Intl).

### M-2 — Pluralization by "(s)"

32 unique strings use `item(s)`-style pluralization (Indonesian has no plural suffix; English needs real plural forms).

| File | Line / Area | Current Text | Suggested Key |
|---|---|---|---|
| `frontend/src/components/masterdata/ComponentTaxonomyManagers.tsx` | 390 | Allowed Item Type(s) | `common.fields.allowedItemTypeS` |
| `frontend/src/pages/tenant/configuration/workflow/TransitionConfigurationPanel.tsx` | 193 | Condition: {{conditionRules}} rule(s). | `configuration.help.conditionConditionRulesRuleS` |
| `frontend/src/pages/tenant/configuration/workflow/TransitionConfigurationPanel.tsx` | 197 | {{stepsCount}} step(s). | `configuration.help.stepsCountStepS` |
| `frontend/src/pages/tenant/inventory/ScrappedTireSaleForm.tsx` | 205 | Create {{sellableCount}} Sale(s) (Draft) | `inventory.actions.createSellableCountSaleSDraft` |
| `frontend/src/pages/tenant/inventory/ScrappedTireSaleForm.tsx` | 97 | {{missing}} selected tire(s) are no longer scrapped or are outside your data scope and were left out. | `inventory.help.missingSelectedTireSNoLonger` |
| `frontend/src/pages/tenant/procurement/CreatePurchaseOrderFromQuotationPage.tsx` | 108 | {{leadDays}} day(s) after PO | `procurement.help.leadDaysDaySAfterPo` |
| `frontend/src/pages/tenant/tires/wheel-configuration/SaveConfigurationDialog.tsx` | 131 | {{mapped_vehicle_count}} mapped vehicle(s) stay on their current version until updated in Vehicle Mapping. | `tire.help.mappedVehicleCountMappedVehicleS` |
| `frontend/src/pages/tenant/tires/wheel-configuration/AddWheelConfigurationPage.tsx` | 109 | {{mapped_vehicle_count}} vehicle(s) are mapped to this configuration. They keep their current version; move them to the new version from Ve… | `tire.help.mappedVehicleCountVehicleSMapped` |
| `frontend/src/pages/tenant/tires/wheel-configuration/VehicleMappingPage.tsx` | 250 | Not listed: {{incomplete_vehicle_data}} vehicle(s) without Vehicle Type, Axles or Wheels on their Vehicle Detail, and {{mapped_to_other_con… | `tire.help.notListedIncompleteVehicleDataVehicle` |
| `frontend/src/pages/tenant/tires/inspection/UsedTireInspectionPage.tsx` | 733 | {{unansweredCount}} question(s) still unanswered. | `tire.help.unansweredCountQuestionSStillUnanswered` |
| `frontend/src/pages/tenant/tires/operations/TireOperationFormPage.tsx` | 574 | This Reuse tire is restricted to position(s) {{value}}, not {{code}}. You can still save — check the restriction before fitting. | `tire.warnings.reuseTireRestrictedPositionSValue` |
| `backend/config/intelligence.php` | 260 | {count} breakdown(s) in the last 30 days | `app.labels.countBreakdownSLast30Days` |
| `backend/config/intelligence.php` | 259 | {count} breakdown(s) in the last 90 days | `app.labels.countBreakdownSLast90Days` |
| `backend/config/intelligence.php` | 263 | {count} component replacement(s) in the last 90 days | `app.labels.countComponentReplacementSLast90` |
| `backend/config/intelligence.php` | 262 | {count} critical inspection finding(s) in the last 90 days | `app.labels.countCriticalInspectionFindingSLast` |
| `backend/config/intelligence.php` | 265 | {count} day(s) of downtime in the last 90 days | `app.labels.countDaySDowntimeLast90` |
| `backend/config/intelligence.php` | 266 | {count} minute(s) of downtime in the last 90 days | `app.labels.countMinuteSDowntimeLast90` |
| `backend/config/intelligence.php` | 258 | {count} overdue maintenance item(s) | `app.labels.countOverdueMaintenanceItemS` |
| `backend/config/intelligence.php` | 261 | {count} repeat repair(s) on the same component group in the last 90 days | `app.labels.countRepeatRepairSSameComponent` |
| `backend/config/intelligence.php` | 264 | {count} tire replacement(s) in the last 90 days | `app.labels.countTireReplacementSLast90` |
| `backend/config/intelligence.php` | 267 | {count} warranty claim(s) in the last 90 days | `app.labels.countWarrantyClaimSLast90` |
| `backend/config/intelligence.php` | 268 | vehicle age: {count} day(s) | `app.labels.vehicleAgeCountDayS` |
| `backend/app/Domain/Contract/Services/AmendmentService.php` | 137 | Cannot remove module {{moduleCode}}: still required by active module(s) on this contract | `errors.contract.cannotRemoveModuleModuleCodeStill` |
| `backend/app/Domain/Entitlement/Services/EntitlementService.php` | 57 | : missing required module(s) | `errors.entitlement.missingRequiredModuleS` |
| `backend/app/Domain/Entitlement/Services/EntitlementService.php` | 83 | : still required by active module(s) | `errors.entitlement.stillRequiredActiveModuleS` |

**Recommendation (not implemented):** Use ICU/i18next plural forms with a `count` parameter.

### M-3 — Accessibility text and tooltips are hard-coded

196 unique `aria-label` values and the `title` tooltips / `InfoTip` contents are literals; they are easy to miss in a visual translation pass.

| File | Line / Area | Current Text | Suggested Key |
|---|---|---|---|
| `frontend/src/components/InfoTip.tsx` | 29 | About {{label}} | `common.actions.aboutLabel` |
| `frontend/src/layouts/TenantSidebar.tsx` | 255 | Expand sidebar | `common.actions.expandSidebar` |
| `frontend/src/components/BackButton.tsx` | 7 | Go back | `common.actions.goBack` |
| `frontend/src/layouts/TenantSidebar.tsx` | 255 | Minimize sidebar | `common.actions.minimizeSidebar` |
| `frontend/src/components/ImageUploadField.tsx` | 119 | Remove {{value}} | `common.actions.removeValue` |
| `frontend/src/layouts/TenantSidebar.tsx` | 141 | Search menu | `common.actions.searchMenu` |
| `frontend/src/layouts/TenantLayout.tsx` | 149 | Toggle menu | `common.actions.toggleMenu` |
| `frontend/src/pages/platform/contracts/ContractDetailPage.tsx` | 139 | Amount | `common.fields.amount` |
| `frontend/src/components/SearchableSelect.tsx` | 129 | {{ariaLabel}} search | `common.fields.ariaLabelSearch` |
| `frontend/src/pages/tenant/analytics/BreakdownAnalyticsPage.tsx` | 13 | Branch | `common.fields.branch` |
| `frontend/src/pages/tenant/external-work-order-invoices/ExternalWorkOrderInvoiceListPage.tsx` | 356 | Cancellation reason | `common.fields.cancellationReason` |
| `frontend/src/components/masterdata/ComponentTaxonomyManagers.tsx` | 458 | Category filter | `common.fields.categoryFilter` |
| `frontend/src/components/masterdata/ComponentClassificationFields.tsx` | 85 | Component Group | `common.fields.componentGroup` |
| `frontend/src/components/masterdata/ComponentTaxonomyManagers.tsx` | 190 | Component Group filter | `common.fields.componentGroupFilter` |
| `frontend/src/pages/tenant/configuration/NotificationRulesPage.tsx` | 221 | Event | `common.fields.event` |

**Recommendation (not implemented):** Include aria/title/alt text in the catalogs (same keys as visible labels where the meaning is the same).

### M-4 — Native browser confirm dialogs

14 unique `window.confirm(...)` messages; their OK/Cancel buttons follow the browser language, not the app language.

| File | Line / Area | Current Text | Suggested Key |
|---|---|---|---|
| `frontend/src/pages/tenant/configuration/workflow/WorkflowBuilder.tsx` | 899 | Delete this transition from the draft? | `configuration.confirm.deleteTransitionDraft` |
| `frontend/src/pages/tenant/configuration/workflow/WorkflowBuilder.tsx` | 462 | Leave without saving your changes? | `configuration.confirm.leaveWithoutSavingChanges` |
| `frontend/src/pages/tenant/configuration/workflow/WorkflowBuilder.tsx` | 487 | Publish this workflow? Documents created from now on follow it; documents already in progress keep their current workflow. | `configuration.confirm.publishWorkflowDocumentsCreatedNowFollow` |
| `frontend/src/pages/tenant/configuration/workflow/WorkflowBuilder.tsx` | 409 | Remove {{code}} and its transitions from the draft? The active workflow changes only when the draft is published. | `configuration.confirm.removeCodeTransitionsDraftActiveWorkflow` |
| `frontend/src/pages/tenant/configuration/workflow/WorkflowBuilder.tsx` | 403 | Remove this transition from the draft? The active workflow changes only when the draft is published. | `configuration.confirm.removeTransitionDraftActiveWorkflowChanges` |
| `frontend/src/pages/tenant/configuration/workflow/WorkflowBuilder.tsx` | 883 | Remove {{value}} and its transitions from this draft? | `configuration.confirm.removeValueTransitionsDraft` |
| `frontend/src/pages/tenant/organization/WarehouseStorageLayoutModal.tsx` | 160 | Delete {{code}}? | `organization.confirm.deleteCode` |
| `frontend/src/pages/platform/bundles/BundleDetailPage.tsx` | 81 | Deactivate this bundle? It will no longer be selectable for new contracts, but existing contracts referencing it are unaffected. | `platform.bundles.confirm.deactivateBundleNoLongerSelectableNew` |
| `frontend/src/pages/platform/bundles/BundleDetailPage.tsx` | 99 | Delete this bundle? It will be hidden from Bundle Management and new contracts, but existing contracts that already reference it keep worki… | `platform.bundles.confirm.deleteBundleHiddenBundleManagementNew` |
| `frontend/src/pages/platform/bundles/BundleDetailPage.tsx` | 82 | Reactivate this bundle so it can be selected for new contracts again? | `platform.bundles.confirm.reactivateBundleSoSelectedNewContracts` |
| `frontend/src/pages/platform/pricing/PricingListPage.tsx` | 73 | Deactivate pricing {{priceable_type}} {{priceable_code}}? It will no longer be selectable for new contracts; existing contracts are unaffec… | `platform.pricing.confirm.deactivatePricingPriceableTypePriceableCode` |
| `frontend/src/pages/platform/pricing/PricingListPage.tsx` | 83 | Delete pricing {{priceable_type}} {{priceable_code}}? It will be hidden from selection but existing contracts referencing it keep working —… | `platform.pricing.confirm.deletePricingPriceableTypePriceableCode` |
| `frontend/src/pages/platform/pricing/PricingListPage.tsx` | 74 | Reactivate pricing {{priceable_type}} {{priceable_code}} so it can be selected for new contracts again? | `platform.pricing.confirm.reactivatePricingPriceableTypePriceableCode` |
| `frontend/src/pages/tenant/vehicles/VehicleDetailPage.tsx` | 778 | Delete this document? This cannot be undone. | `vehicle.confirm.deleteDocumentCannotUndone` |

**Recommendation (not implemented):** Use the shared `ConfirmDialog` (title/body/confirm/cancel keys).

### M-5 — Workflow action labels are status names

Default transitions use the target status name as the button text (`Cancelled`, `Qc Pending`, `Work Order Created`), which reads as a state, not an action, in both languages.

| File | Line / Area | Current Text | Suggested Key |
|---|---|---|---|
| `backend/database/seeders/WorkflowDefaultsSeeder.php` | — | 'action_label' => ucwords(strtolower(str_replace('_',' ',$to))) | `workflow.actions.<code>` |
| `backend/database/seeders/AddWorkOrderExternalStatusSeeder.php` | 46 | 'action_label' => 'Qc Pending' | `workflow.actions.qcPending` |

**Recommendation (not implemented):** Define verb-form action labels per action_code (e.g. Cancel, Send to QC) — see 03.

### M-6 — Decision-engine reasons and recommendations are English text

91 unique reasons/follow-ups from `UsedTireDecisionEngine`, `UsedTireUsageRestrictions` and intelligence services are built as English sentences (often concatenated) and may be persisted with the record.

| File | Line / Area | Current Text | Suggested Key |
|---|---|---|---|
| `backend/app/Domain/Intelligence/DataReadiness/DataReadinessAssessmentService.php` | 65 | all readiness criteria satisfied. | `intelligence.reasons.allReadinessCriteriaSatisfied` |
| `backend/app/Domain/Intelligence/Rul/IntervalBasedRulService.php` | 47 | Based on an assumed typical tire service life of {{expectedLifeKm}}km minus {{usageKm}}km already used — not a measured wear reading. | `intelligence.reasons.basedAssumedTypicalTireServiceLife` |
| `backend/app/Domain/Intelligence/Rul/IntervalBasedRulService.php` | 32 | Based on the vehicle's nearest scheduled maintenance due point (odometer/date), not a machine-learned estimate. | `intelligence.reasons.basedVehicleSNearestScheduledMaintenance` |
| `backend/app/Domain/Intelligence/DataReadiness/DataReadinessAssessmentService.php` | 56 | class imbalance too severe: majority class ratio | `intelligence.reasons.classImbalanceTooSevereMajorityClass` |
| `backend/app/Domain/Intelligence/Extractors/ComponentFeatureExtractor.php` | 73 | component_assets as ca2 | `intelligence.reasons.componentAssetsAsCa2` |
| `backend/app/Domain/Intelligence/Extractors/ComponentFeatureExtractor.php` | 34 | Component Features | `intelligence.reasons.componentFeatures` |
| `backend/app/Domain/Intelligence/Extractors/ComponentFeatureExtractor.php` | 72 | component_installations as ci2 | `intelligence.reasons.componentInstallationsAsCi2` |
| `backend/app/Domain/Intelligence/Services/TrainingPipelineService.php` | 61 | Data readiness NOT_READY | `intelligence.reasons.dataReadinessNotReady` |
| `backend/app/Domain/Intelligence/DataReadiness/DataReadinessAssessmentService.php` | 60 | feature missingness too high | `intelligence.reasons.featureMissingnessTooHigh` |
| `backend/app/Domain/Intelligence/Services/TrainingPipelineService.php` | 69 | Insufficient rows or no label variation after temporal train/test split. | `intelligence.reasons.insufficientRowsNoLabelVariationAfter` |
| `backend/app/Domain/Intelligence/Services/DiagnosticsRunService.php` | 106 | {{metric}} is unusual relative to the fleet (value={{a}}, fleet mean={{fleet_mean}}, z={{z_score}}). | `intelligence.reasons.metricUnusualRelativeFleetValueFleet` |
| `backend/app/Domain/Intelligence/Diagnostics/AnomalyDetectionService.php` | 64 | negative value is not physically possible | `intelligence.reasons.negativeValueNotPhysicallyPossible` |
| `backend/app/Domain/Intelligence/Services/HealthScoreRunService.php` | 156 | No deductions — clean recent history. | `intelligence.reasons.noDeductionsCleanRecentHistory` |
| `backend/app/Domain/Intelligence/Services/PredictionService.php` | 156 | No significant contributing factors identified. | `intelligence.reasons.noSignificantContributingFactorsIdentified` |
| `backend/app/Domain/Intelligence/DataReadiness/DataReadinessAssessmentService.php` | 52 | observation_period_days={{observationPeriodDays}} below minimum {{min_observation_period_days}}. | `intelligence.reasons.observationPeriodDaysObservationPeriodDays` |

**Recommendation (not implemented):** Return reason codes + params; translate on display; decide how historical stored text is shown.

### M-7 — Placeholders and help text with sample data

45 unique strings contain `e.g.` examples (plates, codes, formats) — the sample values usually must stay, the wording must translate.

| File | Line / Area | Current Text | Suggested Key |
|---|---|---|---|
| `frontend/src/components/AuditLogTable.tsx` | 49 | Action (e.g. created) | `common.placeholders.actionEGCreated` |
| `frontend/src/components/masterdata/ComponentTaxonomyManagers.tsx` | 621 | e.g. BRAKE_PAD | `common.placeholders.eGBrakePad` |
| `frontend/src/components/masterdata/ComponentGroupManager.tsx` | 320 | e.g. CG-BRAKE | `common.placeholders.eGCgBrake` |
| `frontend/src/components/masterdata/ComponentTaxonomyManagers.tsx` | 315 | e.g. DISC_BRAKE | `common.placeholders.eGDiscBrake` |
| `frontend/src/components/AuditLogTable.tsx` | 40 | Resource type (e.g. Branch) | `common.placeholders.resourceTypeEGBranch` |
| `frontend/src/pages/tenant/configuration/numbering/NumberingBuilderModal.tsx` | 312 | The code the DOC part shows, e.g. PO. | `configuration.help.codeDocPartShowsEG` |
| `frontend/src/pages/tenant/configuration/numbering/NumberingBuilderModal.tsx` | 228 | A name for this numbering configuration, e.g. Purchase Order Jakarta. | `configuration.help.nameNumberingConfigurationEGPurchase` |
| `frontend/src/pages/tenant/configuration/templates/TemplateEditor.tsx` | 499 | A name for this template, e.g. Work Order with company logo text. | `configuration.help.nameTemplateEGWorkOrder` |
| `frontend/src/pages/tenant/configuration/numbering/NumberingBuilderModal.tsx` | 318 | Shown by TENANT, e.g. ALP. Leave empty to use the tenant code. | `configuration.help.shownTenantEGAlpLeave` |
| `frontend/src/pages/tenant/configuration/numbering/NumberingBuilderModal.tsx` | 330 | Shown by WORKSHOP, e.g. WSBDG. Leave empty to use the code of the workshop each document belongs to. | `configuration.help.shownWorkshopEGWsbdgLeave` |
| `frontend/src/pages/tenant/configuration/numbering/NumberingBuilderModal.tsx` | 243 | Type your own text (e.g. RPO-) and add the parts below. Click a card to insert it where the cursor is, or drag it into the Format. A part c… | `configuration.help.typeOwnTextEGRpo` |
| `frontend/src/pages/tenant/configuration/notifications/NotificationRuleModal.tsx` | 245 | e.g. Critical breakdown alert | `configuration.placeholders.eGCriticalBreakdownAlert` |

**Recommendation (not implemented):** Parameterize the example value or mark it do-not-translate inside the message.

### M-8 — Analytics CSV export uses technical field keys as headers

Export headers are `array_keys($flat)` (snake_case keys), not display labels — not translatable as is, and not user-friendly in either language.

| File | Line / Area | Current Text | Suggested Key |
|---|---|---|---|
| `backend/app/Http/Controllers/Api/Tenant/Analytics/ExportAnalyticsController.php` | 115 | fputcsv($out, array_keys($flat)) | `analytics.export.columns.*` |

**Recommendation (not implemented):** Decide whether exports are localized (DECISION REQUIRED); keep a stable machine header row if exports are re-imported.

## LOW

### L-1 — Arrows and separators embedded in labels

35 labels carry `←`/`→`/`·`/`—` inside the text (`← Back to Contract Management`). Direction glyphs and separators should be rendered by the component, not translated.

| File | Line / Area | Current Text | Suggested Key |
|---|---|---|---|
| `frontend/src/components/BackButton.tsx` | 3 | ← Back | `common.fields.back` |
| `frontend/src/pages/tenant/inspections/InspectionDetailPage.tsx` | 104 | ← Back to Inspections | `inspection.actions.backToInspections` |
| `frontend/src/pages/tenant/intelligence/VehicleIntelligenceDetailPage.tsx` | 71 | ← Back to Vehicle Health & Risk | `intelligence.actions.backVehicleHealthRisk` |
| `frontend/src/pages/tenant/inventory/ProductDetailPage.tsx` | 145 | ← Back to Product | `inventory.actions.backToProduct` |
| `frontend/src/pages/tenant/inventory/StockOpnameDetailPage.tsx` | 72 | ← Back to Stock Opname | `inventory.actions.backToStockOpname` |
| `frontend/src/pages/tenant/inventory/StockTransferDetailPage.tsx` | 115 | ← Back to Transfer | `inventory.actions.backToTransfer` |
| `frontend/src/pages/tenant/maintenance/BreakdownDetailPage.tsx` | 85 | ← Back to Breakdown | `maintenance.actions.backToBreakdown` |
| `frontend/src/pages/tenant/maintenance/MaintenancePackagesPage.tsx` | 282 | ← Back to Maintenance Packages | `maintenance.actions.backToMaintenancePackages` |
| `frontend/src/pages/tenant/maintenance/MaintenanceRequestDetailPage.tsx` | 363 | ← Back to Maintenance Request | `maintenance.actions.backToMaintenanceRequest` |
| `frontend/src/pages/tenant/partners/PartnerDetailPage.tsx` | 37 | ← Back to Vendor | `partner.actions.backToVendor` |

**Recommendation (not implemented):** Move glyphs into components.

### L-2 — Units embedded in labels

49 labels include a unit in parentheses (`Length (mm)`, `Engine Capacity (cc)`). Units are mostly language-neutral; the label part translates.

| File | Line / Area | Current Text | Suggested Key |
|---|---|---|---|
| `frontend/src/pages/tenant/analytics/ProcurementAnalyticsPage.tsx` | 15 | Avg Lead Time (days) | `analytics.fields.avgLeadTimeDays` |
| `frontend/src/pages/tenant/inventory/CreateProductModal.tsx` | 952 | Aspect Ratio (%) | `inventory.fields.aspectRatioPercent` |
| `frontend/src/pages/tenant/inventory/CreateProductModal.tsx` | 880 | Center Bore (mm) | `inventory.fields.centerBoreMm` |
| `frontend/src/pages/tenant/inventory/EditProductModal.tsx` | 408 | Height (mm) | `inventory.fields.heightMm` |
| `frontend/src/pages/tenant/inventory/EditProductModal.tsx` | 402 | Length (mm) | `inventory.fields.lengthMm` |
| `frontend/src/pages/tenant/inventory/CreateProductModal.tsx` | 889 | Maximum Load (kg) | `inventory.fields.maximumLoadKg` |
| `frontend/src/pages/tenant/inventory/CreateProductModal.tsx` | 883 | Offset (mm) | `inventory.fields.offsetMm` |
| `frontend/src/pages/tenant/inventory/CreateProductModal.tsx` | 877 | PCD (mm) | `inventory.fields.pcdMm` |
| `frontend/src/pages/tenant/inventory/CreateProductModal.tsx` | 992 | Reference Tread Depth (mm) | `inventory.fields.referenceTreadDepthMm` |
| `frontend/src/pages/tenant/inventory/ProductDetailsSection.tsx` | 164 | Reference Tread Depth (mm) — required before this Tire product's tires can be scored | `inventory.fields.referenceTreadDepthMmRequiredBefore` |

**Recommendation (not implemented):** Keep the unit as a parameter or a separate suffix.

### L-3 — Busy-state button labels duplicated per page

24 unique `…ing…` labels (`Saving…` alone appears 39 times).

| File | Line / Area | Current Text | Suggested Key |
|---|---|---|---|
| `frontend/src/pages/platform/contracts/ContractDetailPage.tsx` | 470 | Adding… | `common.actions.adding` |
| `frontend/src/pages/platform/access/PlatformUsersPage.tsx` | 122 | Creating… | `common.actions.creating` |
| `frontend/src/pages/platform/invoices/InvoiceDetailPage.tsx` | 61 | Loading… | `common.actions.loading` |
| `frontend/src/components/RoleManager.tsx` | 299 | Saving… | `common.actions.saving` |
| `frontend/src/pages/platform/payments/PaymentDetailPage.tsx` | 173 | Submitting… | `common.actions.submitting` |
| `frontend/src/components/ImageUploadField.tsx` | 100 | Uploading… | `common.actions.uploading` |
| `frontend/src/components/States.tsx` | 1 | Loading… | `common.fields.loading` |
| `frontend/src/components/DocumentViewer.tsx` | 46 | Loading… | `common.help.loading` |
| `frontend/src/pages/tenant/account/CompanyProfilePage.tsx` | 189 | Uploading… | `account.help.uploading` |
| `frontend/src/pages/tenant/analytics/AnalyticsDomainPage.tsx` | 118 | Exporting… | `analytics.actions.exporting` |

**Recommendation (not implemented):** Use common.actions.* busy keys.

### L-4 — Same label with and without trailing colon / different casing

145 labels exist both as `X` and `X:`; consolidated under one key (colon is layout, not text). Case variants (`Serial Number` / `Serial number`) are listed in 03.

| File | Line / Area | Current Text | Suggested Key |
|---|---|---|---|
| `—` | — | Type: | `common.fields.type` |
| `—` | — | Notes: | `common.fields.notes` |
| `—` | — | Bank: | `common.fields.bank` |
| `—` | — | Description: | `common.fields.description` |
| `—` | — | Vendor: | `common.fields.vendor` |
| `—` | — | Account Name: | `platform.payments.fields.accountName` |
| `—` | — | Transaction Reference: | `platform.payments.fields.transactionReference` |
| `—` | — | Note: | `platform.payments.fields.note` |
| `—` | — | Category: | `common.fields.category` |
| `—` | — | Manufacturer: | `inventory.fields.manufacturer` |

**Recommendation (not implemented):** Render punctuation in the component.

## Hard-coded text hotspots (files with the most user-facing literals)

| File | User-facing occurrences | Suggested namespace |
|---|---|---|
| `frontend/src/pages/tenant/workorders/WorkOrderDetailPage.tsx` | 305 | `workOrder` |
| `frontend/src/pages/tenant/inventory/CreateProductModal.tsx` | 164 | `inventory` |
| `backend/database/seeders/WorkflowDefaultsSeeder.php` | 152 | `workflow` |
| `backend/database/seeders/ConfigurationDefaultsSeeder.php` | 143 | `configurationDefaults` |
| `frontend/src/pages/tenant/vehicles/VehicleDetailPage.tsx` | 133 | `vehicle` |
| `frontend/src/pages/tenant/tires/inspection/inspectionOptions.ts` | 120 | `tire` |
| `frontend/src/pages/tenant/tires/inspection/UsedTireInspectionPage.tsx` | 119 | `tire` |
| `frontend/src/components/masterdata/ComponentTaxonomyManagers.tsx` | 103 | `masterData` |
| `frontend/src/navigation/breadcrumbLabels.ts` | 93 | `navigation` |
| `backend/app/Http/Controllers/Api/Tenant/ConfigurationController.php` | 90 | `configuration` |
| `frontend/src/layouts/tenantNav.ts` | 88 | `navigation` |
| `frontend/src/pages/tenant/maintenance/MaintenancePackagesPage.tsx` | 87 | `maintenance` |
| `frontend/src/pages/tenant/external-work-order-invoices/ExternalWorkOrderInvoiceListPage.tsx` | 84 | `externalWorkOrderInvoice` |
| `frontend/src/pages/tenant/partners/PartnerDetailPage.tsx` | 80 | `partner` |
| `frontend/src/pages/tenant/workshop-invoices/WorkshopInvoiceDetailPage.tsx` | 76 | `workshopInvoice` |
| `frontend/src/pages/tenant/tires/operations/TireOperationFormPage.tsx` | 75 | `tire` |
| `frontend/src/pages/tenant/inventory/ReturnListPage.tsx` | 72 | `inventory` |
| `frontend/src/pages/platform/contracts/ContractDetailPage.tsx` | 69 | `platform.contracts` |
| `frontend/src/pages/tenant/configuration/templates/TemplateEditor.tsx` | 62 | `configuration` |
| `backend/app/Domain/Tire/Inspection/UsedTireDecisionEngine.php` | 62 | `tire` |
| `frontend/src/pages/tenant/workshop/WorkerListPage.tsx` | 61 | `workshop` |
| `frontend/src/pages/tenant/components/ComponentAssetDetailPage.tsx` | 59 | `tenantComponents` |
| `frontend/src/pages/tenant/maintenance/MaintenanceRequestDetailPage.tsx` | 59 | `maintenance` |
| `frontend/src/pages/tenant/tires/inspection/TireRuleProfilesPage.tsx` | 58 | `tire` |
| `frontend/src/components/masterdata/ComponentGroupManager.tsx` | 55 | `masterData` |
| `frontend/src/pages/tenant/tires/TireDetailPage.tsx` | 53 | `tire` |
| `frontend/src/pages/tenant/procurement/PurchaseOrderDetailPage.tsx` | 50 | `procurement` |
| `frontend/src/pages/tenant/inventory/ProductDetailsSection.tsx` | 48 | `inventory` |
| `backend/resources/views/invoices/pdf.blade.php` | 48 | `app` |
| `frontend/src/pages/tenant/inventory/WarehouseStockListPage.tsx` | 47 | `inventory` |
| `frontend/src/pages/tenant/inventory/StockTransferDetailPage.tsx` | 45 | `inventory` |
| `frontend/src/pages/tenant/configuration/workflow/WorkflowBuilder.tsx` | 44 | `configuration` |
| `frontend/src/components/StatusBadge.tsx` | 44 | `common` |
| `frontend/src/pages/tenant/configuration/notifications/NotificationRuleModal.tsx` | 43 | `configuration` |
| `frontend/src/pages/tenant/procurement/RfqDetailPage.tsx` | 43 | `procurement` |
| `frontend/src/pages/tenant/tires/wheel-configuration/VehicleMappingPage.tsx` | 43 | `tire` |
| `backend/app/Domain/Tire/Services/TireService.php` | 43 | `tire` |
| `backend/app/Domain/Analytics/Kpi/KpiCatalog.php` | 42 | `analytics` |
| `frontend/src/pages/tenant/inventory/UsedPartDispositionPage.tsx` | 41 | `inventory` |
| `frontend/src/pages/tenant/tires/ImportTiresModal.tsx` | 41 | `tire` |

## Existing reusable sources (centralize these first)

| Source | Kind | Entries | Note |
|---|---|---|---|
| `frontend/src/layouts/tenantNav.ts` | NAV_GROUPS (menu + permission/module gating) | — | Single source for sidebar; add a `labelKey` per item |
| `frontend/src/navigation/breadcrumbLabels.ts` | SEGMENT_LABELS + humanizeSegment() | — | Already keyed by segment → maps 1:1 to breadcrumb.* |
| `frontend/src/components/States.tsx` | EmptyState / LoadingState defaults | 2 | common.empty.noRecordsFound, common.status.loading |
| `frontend/src/components/ConfirmDialog.tsx` | confirmLabel default + Cancel | 2 | common.actions.confirm / cancel |
| `frontend/src/components/StatusBadge.tsx` | COLORS (status codes) + LABELS | ~70 | Natural home for the status label registry |
| `frontend/src/components/FormField.tsx / InfoTip.tsx` | label / hint / "About X" aria | — | Accept keys |
| `frontend/src/hooks/useWorkflowTransitions.ts` | workflowButtons() / humanize() | — | Engine-driven buttons; map action_code → key |
| `backend/app/Domain/Configuration/Services/DocumentTypeRegistry.php` | TYPES labels | ~20 | documentType.* |
| `backend/app/Domain/Configuration/Services/TemplateVariableRegistry.php` | SECTION_LABELS, GROUP_LABELS, variable labels | — | templateVariables.* |
| `backend/app/Domain/Notification/Services/NotificationEventCatalog.php` | LABELS, VARIABLE_LABELS | — | notifications.events.* / variables.* |
| `backend/app/Domain/AccessControl/Services/PermissionCatalog.php` | FEATURE_NAMES / ACTION_NAMES + derived names | — | permissions.* (display only; keys unchanged) |
| `backend/app/Http/Controllers/Api/Tenant/ConfigurationController.php` | NUMBERING_TOKENS, recipient types, operators | — | configuration.* |
| `backend/app/Domain/Analytics/Kpi/KpiCatalog.php` | KpiDefinition names / descriptions / formulas | — | analytics.kpi.* |
| `backend/app/Domain/Tire/Inspection/UsedTireInspectionService.php` | CATEGORY_LABELS | 3 | tire.category.* |
| `frontend/src/components/masterdata/ComponentTaxonomyManagers.tsx` | `columns` | 17 | label map / option list |
| `frontend/src/layouts/PlatformLayout.tsx` | `NAV` | 17 | label map / option list |
| `frontend/src/pages/tenant/maintenance/MaintenanceRequestDetailPage.tsx` | `GROUP_LABELS` | 14 | label map / option list |
| `frontend/src/pages/tenant/tires/operations/TireWorkflowTabs.tsx` | `columns` | 14 | label map / option list |
| `frontend/src/pages/tenant/workorders/WorkOrderDetailPage.tsx` | `INTERNAL_TABS` | 13 | label map / option list |
| `frontend/src/pages/tenant/tires/operations/TireOperationsPage.tsx` | `TABS` | 12 | label map / option list |
| `frontend/src/pages/tenant/tires/operations/UsedTireManagementPage.tsx` | `TABS` | 12 | label map / option list |
| `frontend/src/pages/tenant/inventory/UsedSparepartsTab.tsx` | `columns` | 11 | label map / option list |
| `frontend/src/pages/tenant/inventory/WarehouseStockListPage.tsx` | `columns` | 10 | label map / option list |
| `frontend/src/pages/tenant/procurement/VendorInvoiceReferenceListPage.tsx` | `columns` | 10 | label map / option list |
| `frontend/src/pages/tenant/tires/operations/TireOperationsLandingPage.tsx` | `columns` | 10 | label map / option list |
| `frontend/src/components/masterdata/ComponentGroupManager.tsx` | `columns` | 9 | label map / option list |
| `frontend/src/pages/tenant/tires/WheelConfigurationListPage.tsx` | `columns` | 9 | label map / option list |
| `frontend/src/pages/tenant/vehicles/VehicleDetailPage.tsx` | `TRANSFER_ACTIONS` | 9 | label map / option list |
| `frontend/src/pages/platform/contracts/ContractListPage.tsx` | `TABS` | 8 | label map / option list |
| `frontend/src/pages/tenant/inventory/StockMovementListPage.tsx` | `columns` | 8 | label map / option list |
| `frontend/src/pages/tenant/inventory/WarehouseStockListPage.tsx` | `ITEM_GROUPS` | 8 | label map / option list |
| `frontend/src/pages/tenant/maintenance/MaintenanceRequestListPage.tsx` | `columns` | 8 | label map / option list |
| `frontend/src/pages/tenant/tires/operations/TireWorkflowTabs.tsx` | `cycleColumns` | 8 | label map / option list |
| `frontend/src/pages/tenant/warranty/WarrantyClaimDetailPage.tsx` | `ACTIONS_BY_TARGET` | 8 | label map / option list |
| `frontend/src/pages/platform/contracts/ContractListPage.tsx` | `columns` | 7 | label map / option list |
| `frontend/src/pages/platform/invoices/InvoiceListPage.tsx` | `columns` | 7 | label map / option list |
| `frontend/src/pages/tenant/inventory/ProductListPage.tsx` | `columns` | 7 | label map / option list |
| `frontend/src/pages/tenant/inventory/ReturnListPage.tsx` | `STATUSES` | 7 | label map / option list |
| `frontend/src/pages/tenant/inventory/StockTransferDetailPage.tsx` | `ACTIONS_BY_TARGET` | 7 | label map / option list |
| `frontend/src/pages/tenant/tires/TireListPage.tsx` | `columns` | 7 | label map / option list |
| `frontend/src/pages/tenant/tires/operations/TireWorkflowTabs.tsx` | `ACTIVITY_LABELS` | 7 | label map / option list |
| `frontend/src/pages/tenant/vehicles/VehicleDetailPage.tsx` | `TRANSFER_ACTIONS_BY_TARGET` | 7 | label map / option list |
| `frontend/src/pages/tenant/vehicles/VehicleDetailPage.tsx` | `DOCUMENT_TYPE_LABEL` | 7 | label map / option list |
| `frontend/src/pages/tenant/vehicles/VehicleListPage.tsx` | `columns` | 7 | label map / option list |
| `frontend/src/pages/tenant/workshop/WorkerListPage.tsx` | `columns` | 7 | label map / option list |
| `frontend/src/pages/tenant/workshop/WorkspaceListPage.tsx` | `columns` | 7 | label map / option list |
| `frontend/src/pages/tenant/workshop/WorkspaceReservationListPage.tsx` | `columns` | 7 | label map / option list |
| `frontend/src/components/AuditLogTable.tsx` | `columns` | 6 | label map / option list |
| `frontend/src/components/masterdata/ComponentTaxonomyManagers.tsx` | `ITEM_TYPE_LABELS` | 6 | label map / option list |
| `frontend/src/pages/platform/billing/BillingListPage.tsx` | `columns` | 6 | label map / option list |
| `frontend/src/pages/platform/modules/ModuleCatalogPage.tsx` | `columns` | 6 | label map / option list |
| `frontend/src/pages/platform/payments/PaymentListPage.tsx` | `columns` | 6 | label map / option list |
| `frontend/src/pages/platform/pricing/PricingListPage.tsx` | `columns` | 6 | label map / option list |
| `frontend/src/pages/platform/subscriptions/SubscriptionListPage.tsx` | `columns` | 6 | label map / option list |
| `frontend/src/pages/platform/tenants/tabs/TenantContractTab.tsx` | `columns` | 6 | label map / option list |
| `frontend/src/pages/tenant/account/AccountInvoiceListPage.tsx` | `columns` | 6 | label map / option list |
| `frontend/src/pages/tenant/external-work-order-invoices/ExternalWorkOrderInvoiceListPage.tsx` | `STATUS_LABELS` | 6 | label map / option list |
