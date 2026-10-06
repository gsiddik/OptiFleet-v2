<?php

namespace App\Domain\Shared\Support;

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Lang;
use InvalidArgumentException;

/**
 * Whole-sentence message templates (i18n structural preparation).
 *
 * Each message is one complete sentence keyed by its EN-ID dataset key
 * (docs/i18n/12-en-id-translation-dataset-final.csv) with named {{param}} placeholders, so a language
 * can reorder words freely. Never assemble a sentence from fragments; when a fallback changes the
 * grammar (a missing value, approve vs cancel), add a separate key instead of passing a word as a
 * parameter. Parity with the dataset is enforced by MessagesTest.
 *
 * Locales (i18n rollout): `text()` renders English unless a locale is given — the language of anything
 * stored in a record (notes, default complaints, inspection reasons, seeded names), which stays as
 * stored (D2). `localized()` renders in the request locale for messages returned to the user. Other
 * locales come from the generated catalog (lang/<locale>/catalog.php); a key missing there falls back
 * to English. A message's code (its key) never changes with the language.
 */
final class Messages
{
    /** @var array<string, string> */
    public const EN = [
        'common.fields.front' => 'Front',
        'common.fields.rear' => 'Rear',
        'common.fields.left' => 'Left',
        'common.fields.right' => 'Right',
        'documents.tireImport.howToTitle' => 'How to import New Stock tires — {{productName}}',
        'documents.tireImport.howToTitleWithCode' => 'How to import New Stock tires — {{productName}} ({{productCode}})',
        'documents.tireImport.maxRowsNote' => '7. At most {{maxRows}} rows per file. Rows whose serial already exists, or that repeat a serial in the file, are not imported.',
        'configuration.defaults.numberingSetName' => '{{documentType}} Numbering',
        'configuration.defaults.templateSetName' => '{{documentType}} Template',
        'configuration.help.sectionRepeats' => 'Repeats its content once for every {{section}} row of the document.',
        'contract.notes.amendmentRejected' => 'Rejected: {{note}}',
        'contract.notes.contractTerminated' => 'Terminated: {{reason}}',
        'errors.componentAsset.selectExactAssets' => 'Select exactly {{quantity}} Asset# being returned for this item (selected {{selectedCount}}).',
        'errors.configuration.initialTextInvalid' => '{{field}} must be text of at most 30 characters without braces.',
        'errors.configuration.sequenceDigitRange' => 'Sequential Digit must be a whole number between 1 and {{max}}.',
        'errors.entitlement.cannotDisableModuleWithList' => 'Cannot disable module {{moduleCode}}: still required by active module(s): {{modules}}',
        'errors.entitlement.cannotEnableModuleWithList' => 'Cannot enable module {{moduleCode}}: missing required module(s): {{modules}}',
        'errors.notification.unknownVariables' => 'Template references unknown variable(s): {{variables}}',
        'errors.procurement.paymentMustEqualInvoice' => 'The payment amount must equal the invoice amount ({{amount}}); partial payments are not supported.',
        'errors.procurement.receivingExceedsRemaining' => 'Receiving {{quantity}} exceeds the Remaining Receivable Qty of this line ({{remaining}}).',
        'errors.productCatalog.circularDependency' => 'Circular module dependency detected: {{path}}',
        'errors.productCatalog.unresolvedDependencies' => 'Cannot publish bundle: unresolved module dependencies: {{modules}}',
        'errors.tire.mdcAlreadySet' => 'This tire already has a Manufacture Date Code ({{code}}); it is only filled in here when missing.',
        'errors.tire.remapBlocked' => 'Vehicle cannot be remapped because active tires are installed on positions removed by the target configuration: {{positions}}. Remove or transfer these tires first.',
        'tire.labels.vehicleRegistrationNumberPositionCodeTire' => '{{vehicle_registration_number}} {{position_code}} — Tire {{tire_serial_number}}',
        'errors.workOrder.buyerTypeMustBeOneOf' => 'Buyer type must be one of: {{values}}.',
        'errors.workOrder.conditionMustBeOneOf' => 'Condition must be one of: {{values}}.',
        'errors.workOrder.dispositionMustBeOneOf' => 'Disposition must be one of: {{values}}.',
        'errors.workOrder.saleTypeMustBeOneOf' => 'Sale type must be one of: {{values}}.',
        'errors.workOrder.partRequestOnlyRequestedApprove' => 'Only a requested part request can be approved.',
        'errors.workOrder.partRequestOnlyRequestedReject' => 'Only a requested part request can be rejected.',
        'errors.workOrder.partRequestOnlyRequestedCancel' => 'Only a requested part request can be cancelled.',
        'errors.workOrder.receivedQtyExceedsReturned' => 'Received quantity must be greater than zero and cannot exceed the returned quantity ({{returned}}).',
        'errors.workOrder.returnExceedsReturnable' => 'Cannot return more than the returnable quantity ({{returnable}}): consumed quantity is never returnable.',
        'inspection.defaults.issuesFound' => 'Issues found during inspection {{inspectionId}}',
        'intelligence.reasons.classImbalance' => 'class imbalance too severe: majority class ratio {{ratio}}',
        'intelligence.reasons.dataNotReady' => 'Data readiness NOT_READY: {{reasons}}',
        'intelligence.reasons.featureMissingness' => 'feature missingness too high: {{ratio}}',
        'maintenance.defaults.scheduledMaintenanceDue' => 'Scheduled maintenance due: {{packageName}}',
        'notifications.defaults.setName' => '{{eventName}} Notification',
        'tire.history.inspectionDisposition' => 'Inspection disposition {{disposition}}',
        'tire.history.inspectionDispositionWithWork' => 'Inspection disposition {{disposition}} + {{additionalWork}}',
        'tire.reasons.atOrBelowDPull' => 'D_min {{dMin}} is at or below the planned removal depth D_pull {{dPull}}.',
        'tire.reasons.baselineOnboarding' => 'Baseline reading captured at onboarding; installation date is {{source}}.',
        'tire.reasons.beadDamageDetail' => 'Bead damage: {{condition}}.',
        'tire.reasons.belowDService' => 'D_min {{dMin}} is below the minimum service depth D_service {{dService}}.',
        'tire.reasons.innerLinerFailure' => 'Inner liner / casing failure: {{condition}}.',
        'tire.conditions.torn' => 'torn',
        'tire.conditions.deformed' => 'deformed',
        'tire.conditions.beadWireDamaged' => 'bead wire damaged',
        'tire.conditions.crackedDelaminated' => 'cracked delaminated',
        'tire.conditions.wrinkledHeatDamage' => 'wrinkled heat damage',
        'tire.conditions.cordExposed' => 'cord exposed',
        'tire.reasons.inspectionRecommendation' => 'Used tire inspection — recommendation {{recommendation}}',
        'tire.reasons.inspectionRecommendationWithDetail' => 'Used tire inspection — recommendation {{recommendation}} ({{detail}})',
        'tire.reasons.limitFieldRequired' => '{{label}}: {{field}} is required.',
        'tire.reasons.repairReturnRequirement' => 'Repair complete + final inspection.',
        'tire.reasons.repairReturnRequirementWithLeakTest' => 'Repair complete + final inspection + leak test passed.',
        'tire.reasons.repairableDamages' => '{{count}} repairable damage(s) within the repair limits; tread still usable.',
        'tire.reasons.treadNotSuitable' => 'Tread not suitable to retain: {{wearPattern}}.',
        'tire.reasons.treadPointsMissing' => 'Tread depth: at least 6 points are required (3 zones × inner / outer main groove); missing {{points}}.',
        'tire.wheelConfiguration.positionLabel' => '{{axleGroup}} Axle {{axle}} {{side}} Wheel {{wheel}}',
        'tire.wheelConfiguration.vehicleTypeMismatch' => 'vehicle type {{vehicleType}} does not match the configuration vehicle type.',
        'tire.wheelConfiguration.vehicleTypeNotSetMismatch' => 'vehicle type (not set) does not match the configuration vehicle type.',
        'validation.accessControl.cannotRemoveManagePermission' => 'You cannot remove "{{permission}}" from a role that is your only source of it — you would lose access to role management.',
        'validation.accessControl.invalidPermissions' => '{{count}} selected permission(s) do not exist or do not belong to the {{scope}} scope.',
        'validation.maintenance.assessmentGroupsDuplicated' => 'Each inspection group may only appear once. Duplicated: {{groups}}',
        'validation.maintenance.assessmentGroupsMissing' => 'All 14 inspection groups are required. Missing: {{groups}}',
        'validation.masterData.itemTypesStranded' => 'Existing Products of Item Type {{itemTypes}} use this Subcategory; keep that Item Type allowed or reclassify those Products first.',
        'validation.shared.fileTooLarge' => 'The {{label}} must not be larger than {{maxMb}} MB.',
        'validation.tire.importHeaderMismatch' => 'The header row of "{{sheet}}" must be exactly: {{headers}}.',
        'validation.tire.importMaxRows' => 'At most {{maxRows}} tire rows can be imported per file.',
        'validation.tire.importNoRows' => 'The sheet "{{sheet}}" has no tire rows. Fill one tire per row below the header.',
        'validation.tire.importSerialRepeated' => 'This serial number is repeated in the file (row {{row}}).',
        'validation.tire.importSheetMissing' => 'The workbook has no sheet named "{{sheet}}". Download the template and fill that sheet.',
        'validation.tire.uploadAtMostPhotos' => 'Upload at most {{max}} photos.',
        'validation.tire.uploadPhotoRange' => 'Upload 1 to {{max}} photos.',
        'validation.tire.vehiclesNotFound' => 'Some vehicles were not found: {{vehicles}}.',
        'validation.tire.wheelConfigurationExists' => 'A wheel configuration {{configCode}} already exists for this vehicle type. Edit that configuration instead.',
        'validation.tire.wheelConfigurationExistsForTruckType' => 'A wheel configuration {{configCode}} already exists for this vehicle type and truck configuration type. Edit that configuration instead.',
        'vehicle.notes.vehicleTransfer' => 'Vehicle transfer {{transferId}}',
        'workflow.defaults.setName' => '{{resourceType}} Workflow',
        // Used tire decision engine reasons (I18N-S5: returned and stored as {code, params} next to the text).
        'tire.reasons.tireCategoryUnknownSetVehicleGroup' => 'Tire category is unknown — set the Vehicle Group of the tire product.',
        'tire.reasons.noActiveInspectionRuleProfileTire' => 'No active inspection rule profile for this tire category — thresholds (D_service, D_pull, ages, repair limits) are required.',
        'tire.reasons.tireIdentityCategoryManufactureDateNot' => 'Tire identity / category / manufacture date not fully verified',
        'tire.reasons.interiorNotInspectedAfterRemovalRim' => 'Interior not inspected after removal from the rim',
        'tire.reasons.wearPatternNotInspected' => 'Wear pattern not inspected',
        'tire.reasons.bulgeDeformationSeparationSuspectedNotInspected' => 'Bulge / deformation / separation suspected or not inspected',
        'tire.reasons.cordWireExposureSuspectedNotInspected' => 'Cord / wire exposure suspected or not inspected',
        'tire.reasons.sidewallNotInspected' => 'Sidewall not inspected',
        'tire.reasons.beadNotInspected' => 'Bead not inspected',
        'tire.reasons.innerLinerNotInspected' => 'Inner liner not inspected',
        'tire.reasons.runFlatLowPressureOverheatHistory' => 'Run-flat / low-pressure / overheat history unknown',
        'tire.reasons.leakForeignObjectNotTested' => 'Leak / foreign object not tested',
        'tire.reasons.previousRepairQuestionableNotInspected' => 'Previous repair questionable or not inspected',
        'tire.reasons.ageChemicalDamageSuspectedNotInspected' => 'Age / chemical damage suspected or not inspected',
        'tire.reasons.ageRetreadCasingComplianceCannotYet' => 'Age / retread / casing compliance cannot yet be confirmed',
        'tire.reasons.labelNotAnswered' => '{{label}} (not answered).',
        'tire.reasons.confirmedBulgeDeformationSeparation' => 'Confirmed bulge / deformation / separation.',
        'tire.reasons.cordWireExposed' => 'Cord / wire exposed.',
        'tire.reasons.deepSidewallCutCrack' => 'Deep sidewall cut / crack.',
        'tire.reasons.physicalSignRunFlatLowPressure' => 'Physical sign of run-flat / low-pressure / overheat damage.',
        'tire.reasons.previousRepairDoesNotMeetStandard' => 'Previous repair does not meet the standard.',
        'tire.reasons.permanentChemicalAgeDegradationHardenedBrittle' => 'Permanent chemical / age degradation (hardened, brittle, softened or swollen).',
        'tire.reasons.tireAgeUnknownManufactureDateCode' => 'Tire age unknown — the manufacture date code cannot be read.',
        'tire.reasons.tireAgeAgeMonthsExceedsMaximum' => 'Tire age {{age}} months exceeds the maximum service age A_max ({{a_max_months}} months).',
        'tire.reasons.oneSidedWearCheckWheelAlignment' => 'One-sided wear: check wheel alignment (camber / toe).',
        'tire.reasons.centerWearCheckTirePressureOver' => 'Center wear: check tire pressure (over-inflation).',
        'tire.reasons.bothSidesWearCheckTirePressure' => 'Both-sides wear: check tire pressure (under-inflation) and load.',
        'tire.reasons.cuppingScallopingCheckSuspensionShockAbsorbers' => 'Cupping / scalloping: check suspension (shock absorbers) and wheel balance.',
        'tire.reasons.flatSpotCheckBrakesWheelLock' => 'Flat spot: check brakes / wheel lock-up and suspension.',
        'tire.reasons.leakLocalInnerLinerDamageReported' => 'A leak / local inner liner damage was reported — record the damage details.',
        'tire.reasons.repairEligibilityDamagesNotBeenAnswered' => 'Repair eligibility of the damages has not been answered.',
        'tire.reasons.damageNotRepairableWithinLimitsTire' => 'Damage is not repairable within the limits for this tire category / model.',
        'tire.reasons.specialistRejectedRepair' => 'The specialist rejected the repair.',
        'tire.reasons.specialistDecisionRequiredNoFinalSpecialist' => 'Specialist decision required — no final specialist result yet.',
        'tire.reasons.casingDoesNotMeetAgeRetread' => 'Casing does not meet the age / retread requirement.',
        'tire.reasons.casingAgeAgeMonthsExceedsRetread' => 'Casing age {{age}} months exceeds A_retread_max ({{a_retread_max_months}} months).',
        'tire.reasons.retreadCountRetreadCountReachedN' => 'Retread count {{retread_count}} has reached N_retread_max ({{n_retread_max}}).',
        'tire.reasons.retreaderSpecialistRejectedCasing' => 'The retreader / specialist rejected the casing.',
        'tire.reasons.casingAcceptedRetreadRetreaderSpecialist' => 'Casing accepted for retread by the retreader / specialist.',
        'tire.reasons.casingDamageWithinRepairLimitsRepair' => 'Casing damage within the repair limits: repair it before retreading.',
        'tire.reasons.retreadCandidateCasingAwaitingFinalRetreader' => 'Retread candidate: the casing is awaiting the final retreader inspection.',
        'tire.reasons.inspectionCompleteTreadUsableNoDamage' => 'Inspection complete: tread usable, no damage requiring repair, no rejection condition.',
        'tire.reasons.labelSeparation' => '{{label}}: separation.',
        'tire.reasons.labelUnknownWhetherReachesReinforcingStructure' => '{{label}}: unknown whether it reaches the reinforcing structure.',
        'tire.reasons.labelUnknownWhetherOverlapsPreviousRepair' => '{{label}}: unknown whether it overlaps a previous repair.',
        'tire.reasons.labelRepairsNotPermittedLocation' => '{{label}}: repairs are not permitted in this location.',
        'tire.reasons.labelReachesReinforcingStructureWhichRepair' => '{{label}}: reaches the reinforcing structure, which the repair limits do not permit.',
        'tire.reasons.labelOverlapsPreviousRepairWhichRepair' => '{{label}}: overlaps a previous repair, which the repair limits do not permit.',
        'tire.reasons.labelRepairLimitLimitKeyNot' => '{{label}}: the repair limit {{limitKey}} is not configured in the rule profile.',
        'tire.reasons.repairLimitMaxRepairsNotConfigured' => 'The repair limit max_repairs is not configured in the rule profile.',
        'tire.reasons.openItem' => '{{label}}.',
        'tire.reasons.treadPoint' => 'zone {{zone}} {{groove}}',
        'tire.grooves.innerMain' => 'inner main',
        'tire.grooves.outerMain' => 'outer main',
        'tire.wearPatterns.cuppingScalloping' => 'cupping / scalloping',
        'tire.wearPatterns.flatSpot' => 'flat spot',
        'tire.reasons.damageLabel' => 'Damage {{n}} ({{type}} on {{location}})',
        'tire.damageTypes.puncture' => 'puncture',
        'tire.damageTypes.cut' => 'cut',
        'tire.damageTypes.crack' => 'crack',
        'tire.damageTypes.abrasion' => 'abrasion',
        'tire.damageTypes.separation' => 'separation',
        'tire.damageTypes.previousRepairDamage' => 'previous repair damage',
        'tire.damageTypes.other' => 'other',
        'tire.damageLocations.tread' => 'tread',
        'tire.damageLocations.shoulder' => 'shoulder',
        'tire.damageLocations.sidewall' => 'sidewall',
        'tire.damageLocations.bead' => 'bead',
        'tire.damageLocations.innerLiner' => 'inner liner',
        'tire.damageFields.diameter' => 'diameter',
        'tire.damageFields.length' => 'length',
        'tire.damageFields.width' => 'width',
        'tire.damageFields.depth' => 'depth',
        'tire.reasons.damageSizeExceedsLimit' => '{{label}}: {{field}} {{value}} mm exceeds the limit of {{limit}} mm.',
        'tire.reasons.damageCountExceedsMax' => '{{count}} damages exceed the maximum of {{max}} repairs.',
        // Tire operation position errors (I18N-S5: returned with a machine-readable code)
        'validation.tire.codeNotPositionVehicleSWheels' => '{{code}} is not a position of this vehicle\'s Wheels Configuration ({{config_code}}).',
        'validation.tire.positionCodeNoTireDataYet' => 'Position {{code}} has no tire data yet. Complete it in Vehicle Details → Wheels Configuration first.',
        'validation.tire.positionCodeAlreadyOpenTireOperation' => 'Position {{code}} is already in an open Tire Operation ({{operation_type}}, Work Order {{wo_number}}).',
        'tire.reasons.positionRestrictionWarning' => 'Serial {{serialNumber}} is restricted to position(s) {{positions}} by its used tire inspection, but is planned for {{position}}. Installation is allowed — check the restriction before fitting.',
    ];

    /**
     * Renders a message. A parameter may itself be a message (`['code' => key, 'params' => [...]]`, see
     * make()) or a list of values / messages (joined with ", "), so a stored machine-readable message can
     * be rendered again in another language later.
     *
     * @param  array<string, scalar|array|null>  $params
     */
    public static function text(string $key, array $params = [], string $locale = 'en'): string
    {
        $template = self::template($key, $locale);

        return preg_replace_callback('/\{\{(\w+)\}\}/', function (array $m) use ($params, $locale) {
            if (! array_key_exists($m[1], $params)) {
                return $m[0];
            }
            $value = $params[$m[1]];

            if (! is_array($value)) {
                return (string) $value;
            }

            // A list parameter (e.g. missing tread points) renders each item and joins them.
            return array_is_list($value)
                ? implode(', ', array_map(fn ($item) => is_array($item) ? self::render($item, $locale) : (string) $item, $value))
                : self::render($value, $locale);
        }, $template);
    }

    /**
     * Renders a message in the request locale (app()->getLocale()), for text returned to the user.
     *
     * @param  array<string, scalar|array|null>  $params
     */
    public static function localized(string $key, array $params = []): string
    {
        return self::text($key, $params, app()->getLocale());
    }

    /**
     * English text of a key: the catalog above (whole-sentence templates whose English is asserted by
     * MessagesTest), else the generated English catalog (any other dataset key, e.g. auth errors).
     */
    private static function english(string $key): string
    {
        if (isset(self::EN[$key])) {
            return self::EN[$key];
        }
        if (self::hasTranslator() && Lang::has("catalog.{$key}", 'en', false)) {
            return Lang::get("catalog.{$key}", [], 'en', false);
        }

        throw new InvalidArgumentException("Unknown message key [{$key}].");
    }

    /**
     * Outside a booted application (pure unit tests, or after a test's application was flushed) there is
     * no translator: only the catalog above is available.
     */
    private static function hasTranslator(): bool
    {
        return Facade::getFacadeApplication()?->bound('translator') ?? false;
    }

    /** The template in the given locale; English when the locale has no entry for the key. */
    private static function template(string $key, string $locale): string
    {
        $english = self::english($key);
        if ($locale === 'en' || ! self::hasTranslator() || ! Lang::has("catalog.{$key}", $locale, false)) {
            return $english;
        }

        return Lang::get("catalog.{$key}", [], $locale, false);
    }

    /**
     * A machine-readable message: the code is the dataset key, params are scalars or nested messages.
     *
     * @param  array<string, scalar|array|null>  $params
     * @return array{code: string, params: array<string, scalar|array|null>}
     */
    public static function make(string $key, array $params = []): array
    {
        self::english($key);

        return ['code' => $key, 'params' => $params];
    }

    /** @param  array{code: string, params?: array<string, scalar|array|null>}  $message */
    public static function render(array $message, string $locale = 'en'): string
    {
        return self::text($message['code'], $message['params'] ?? [], $locale);
    }
}
