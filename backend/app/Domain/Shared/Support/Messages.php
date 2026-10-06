<?php

namespace App\Domain\Shared\Support;

use InvalidArgumentException;

/**
 * Whole-sentence message templates (i18n structural preparation).
 *
 * Each message is one complete sentence keyed by its EN-ID dataset key
 * (docs/i18n/12-en-id-translation-dataset-final.csv) with named {{param}} placeholders, so a language
 * can reorder words freely. Never assemble a sentence from fragments; when a fallback changes the
 * grammar (a missing value, approve vs cancel), add a separate key instead of passing a word as a
 * parameter. This catalog is the English source; the i18n rollout swaps the lookup for the request /
 * document locale. Parity with the dataset is enforced by MessagesTest.
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
    ];

    /**
     * @param  array<string, scalar|null>  $params
     */
    public static function text(string $key, array $params = []): string
    {
        $template = self::EN[$key] ?? throw new InvalidArgumentException("Unknown message key [{$key}].");

        return preg_replace_callback('/\{\{(\w+)\}\}/', fn (array $m) => array_key_exists($m[1], $params) ? (string) $params[$m[1]] : $m[0], $template);
    }
}
