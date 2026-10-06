// i18n-audit: canonical-english — English fallbacks beside their keys (o(code, English, key) / label getters).
import { t, translated, translatedRecord, withLabels } from '../../../../i18n/i18n';
/** Questionnaire wording and option labels (codes are the backend's). */

export type Option = { value: string; label: string; labelKey?: string };
/** An option whose label reads in the current language (English when the key is missing). */
const o = (value: string, label: string, key?: string): Option =>
  key ? { value, get label() { return translated(key, label); } } : { value, label };

export const QUESTIONS: Record<
  string,
  { label: string; labelKey?: string; options: Option[]; help?: string }
> = {
  identity_status: {
    get label() { return translated('tire.fields.tireIdentityCategoryManufactureDateVerified', "Can the tire identity, category, and manufacture date be verified?"); },
    options: [
      o("COMPLETE", "Complete", 'common.actions.complete'),
      o("PARTIALLY_UNKNOWN", "Partially Unknown", 'tire.fields.partiallyUnknown'),
      o("CANNOT_VERIFY", "Cannot Be Verified", 'tire.fields.cannotBeVerified'),
    ],
  },
  internal_inspected: {
    get label() { return translated('tire.fields.exteriorInteriorTireBeenInspectedAfter', "Have the exterior and interior of the tire been inspected after removal from the rim?"); },
    options: [o("YES", "Yes", 'common.fields.yes'), o("NOT_YET", "Not Yet", 'tire.fields.notYet')],
  },
  wear_pattern: {
    get label() { return translated('tire.fields.wearPattern', "Wear pattern"); },
    get help() { return t('tire.help.treadWearPatternEvenOneSided'); },
    options: [
      o("EVEN", "Even", 'tire.fields.even'),
      o("ONE_SIDED", "One-Sided Wear", 'tire.fields.oneSidedWear'),
      o("CENTER", "Center Wear", 'tire.fields.centerWear'),
      o("BOTH_SIDES", "Both-Sides Wear", 'tire.fields.bothSidesWear'),
      o("CUPPING_SCALLOPING", "Cupping / Scalloping", 'tire.fields.cuppingScalloping'),
      o("FLAT_SPOT", "Flat Spot", 'tire.fields.flatSpot'),
      o("NOT_INSPECTED", "Not Inspected", 'tire.fields.notInspected'),
    ],
  },
  bulge_separation: {
    get label() { return translated('tire.fields.bulgeDeformationSeparation', "Bulge / deformation / separation"); },
    get help() { return t('tire.help.checkBulgesDeformationSignsTireLayers'); },
    options: [
      o("NONE", "None", 'common.fields.none'),
      o("PRESENT", "Present", 'tire.fields.present'),
      o("SUSPECTED", "Suspected", 'tire.fields.suspected'),
      o("NOT_INSPECTED", "Not Inspected", 'tire.fields.notInspected'),
    ],
  },
  cord_exposure: {
    get label() { return translated('tire.fields.cordWireExposure', "Cord / wire exposure"); },
    get help() { return t('tire.help.checkWhetherReinforcingCordsSteelWires'); },
    options: [
      o("NONE", "None", 'common.fields.none'),
      o("PRESENT", "Present", 'tire.fields.present'),
      o("SUSPECTED", "Suspected", 'tire.fields.suspected'),
      o("NOT_INSPECTED", "Not Inspected", 'tire.fields.notInspected'),
    ],
  },
  sidewall_condition: {
    get label() { return translated('tire.fields.sidewall', "Sidewall"); },
    get help() { return t('tire.help.conditionTireSSideWallSurface'); },
    options: [
      o("NORMAL", "Normal", 'tire.fields.normal'),
      o("SURFACE_ABRASION", "Surface Abrasion", 'tire.fields.surfaceAbrasion'),
      o("SURFACE_CRACKING", "Surface Cracking", 'tire.fields.surfaceCracking'),
      o("DEEP_CUT_CRACK", "Deep Cut / Deep Crack", 'tire.fields.deepCutDeepCrack'),
      o("NOT_INSPECTED", "Not Inspected", 'tire.fields.notInspected'),
    ],
  },
  bead_condition: {
    get label() { return translated('tire.fields.bead', "Bead"); },
    get help() { return t('tire.help.conditionBeadPartTireSeatsRim'); },
    options: [
      o("NORMAL", "Normal", 'tire.fields.normal'),
      o("MINOR_ABRASION", "Minor Abrasion", 'tire.fields.minorAbrasion'),
      o("TORN", "Torn", 'tire.fields.torn'),
      o("DEFORMED", "Deformed", 'tire.fields.deformed'),
      o("BEAD_WIRE_DAMAGED", "Bead Wire Damaged / Exposed", 'tire.fields.beadWireDamagedExposed'),
      o("NOT_INSPECTED", "Not Inspected", 'tire.fields.notInspected'),
    ],
  },
  inner_liner_condition: {
    get label() { return translated('tire.fields.innerLiner', "Inner liner"); },
    get help() { return t('tire.help.conditionInnerLayerHelpsHoldAir'); },
    options: [
      o("NORMAL", "Normal", 'tire.fields.normal'),
      o("LOCAL_DAMAGE", "Local Damage", 'tire.fields.localDamage'),
      o("CRACKED_DELAMINATED", "Cracked / Delaminated", 'tire.fields.crackedDelaminated'),
      o("WRINKLED_HEAT_DAMAGE", "Wrinkled / Heat Damage", 'tire.fields.wrinkledHeatDamage'),
      o("CORD_EXPOSED", "Cord Exposed", 'tire.fields.cordExposed'),
      o("NOT_INSPECTED", "Not Inspected", 'tire.fields.notInspected'),
    ],
  },
  run_flat_overheat: {
    get label() { return translated('tire.fields.runFlatLowPressureOverheat', "Run flat / low pressure / overheat"); },
    get help() { return t('tire.help.historySignsTireRanVeryLow'); },
    options: [
      o("NO", "No", 'common.fields.no'),
      o("HISTORY_NO_SIGN", "History Present, No Sign Found", 'tire.fields.historyPresentNoSignFound'),
      o("PHYSICAL_SIGN", "Physical Sign Found", 'tire.fields.physicalSignFound'),
      o("UNKNOWN", "Unknown", 'tire.fields.unknown'),
    ],
  },
  leak_foreign_object: {
    get label() { return translated('tire.fields.leakForeignObject', "Leak / foreign object"); },
    get help() { return t('tire.help.checkLeaksPenetrationForeignObjectSuch'); },
    options: [o("NO", "No", 'common.fields.no'), o("YES", "Yes", 'common.fields.yes'), o("NOT_TESTED", "Not Tested", 'tire.fields.notTested')],
  },
  previous_repair: {
    get label() { return translated('tire.fields.previousRepair', "Previous repair"); },
    get help() { return t('tire.help.checkRepairsDoneBeforeWhetherThey'); },
    options: [
      o("NONE", "None", 'common.fields.none'),
      o("MEETS_STANDARD", "Meets Standard", 'tire.fields.meetsStandard'),
      o("QUESTIONABLE", "Questionable", 'tire.fields.questionable'),
      o("DOES_NOT_MEET", "Does Not Meet Standard", 'tire.fields.doesNotMeetStandard'),
      o("NOT_INSPECTED", "Not Inspected", 'tire.fields.notInspected'),
    ],
  },
  age_chemical: {
    get label() { return translated('tire.fields.ageChemicalDamage', "Age / chemical damage"); },
    get help() { return t('tire.help.degradationAgeChemicalExposureSuchRubber'); },
    options: [
      o("NONE", "None", 'common.fields.none'),
      o("SUSPECTED", "Suspected", 'tire.fields.suspected'),
      o("DEGRADED", "Hardened / Brittle / Softened / Swollen", 'tire.help.hardenedBrittleSoftenedSwollen'),
      o("NOT_INSPECTED", "Not Inspected", 'tire.fields.notInspected'),
    ],
  },
  casing_compliance: {
    get label() { return translated('tire.fields.ageRetreadCasingCompliance', "Age / retread / casing compliance"); },
    get help() { return t('tire.help.whetherTireSAgeRetreadCount'); },
    options: [
      o("MEETS", "Meets Requirement", 'tire.fields.meetsRequirement'),
      o("DOES_NOT_MEET", "Does Not Meet Requirement", 'tire.fields.doesNotMeetRequirement'),
      o("CANNOT_CONFIRM", "Cannot Yet Be Confirmed", 'tire.fields.cannotYetBeConfirmed'),
    ],
  },
  repair_eligibility: {
    get label() { return translated('tire.fields.doAllDamagesMeetRepairLimits', "Do all damages meet the repair limits applicable to this tire category / model?"); },
    options: [
      o("YES", "Yes", 'common.fields.yes'),
      o("NO", "No", 'common.fields.no'),
      o("SPECIALIST_REQUIRED", "Specialist Required", 'tire.fields.specialistRequired'),
    ],
  },
  specialist_result: {
    get label() { return translated('tire.fields.specialistRetreaderResult', "Specialist / retreader result"); },
    get help() { return t('tire.help.resultFurtherExaminationSpecialistRetreaderWhen'); },
    options: [
      o("NOT_REQUESTED", "Not Requested", 'tire.fields.notRequested'),
      o("PENDING", "Pending", 'tire.fields.pending'),
      o("ACCEPTED", "Accepted (final)", 'tire.fields.acceptedFinal'),
      o("REJECTED", "Rejected (final)", 'tire.fields.rejectedFinal'),
    ],
  },
};

export const LOCATIONS: Option[] = [
  o("TREAD", "Tread", 'tire.fields.tread'),
  o("SHOULDER", "Shoulder", 'tire.fields.shoulder'),
  o("SIDEWALL", "Sidewall", 'tire.fields.sidewall'),
  o("BEAD", "Bead", 'tire.fields.bead'),
  o("INNER_LINER", "Inner Liner", 'tire.fields.innerLiner2'),
];
export const DAMAGE_TYPES: Option[] = [
  o("PUNCTURE", "Puncture", 'tire.fields.puncture'),
  o("CUT", "Cut", 'tire.fields.cut'),
  o("CRACK", "Crack", 'tire.fields.crack'),
  o("ABRASION", "Abrasion", 'tire.fields.abrasion'),
  o("SEPARATION", "Separation", 'tire.fields.separation'),
  o("PREVIOUS_REPAIR_DAMAGE", "Previous Repair Damage", 'tire.fields.previousRepairDamage'),
  o("OTHER", "Other", 'tire.fields.other'),
];
export const TRI_STATE: Option[] = [
  o("NO", "No", 'common.fields.no'),
  o("YES", "Yes", 'common.fields.yes'),
  o("UNKNOWN", "Unknown", 'tire.fields.unknown'),
];
export const GROOVES = withLabels([
  {
    value: "INNER_MAIN",
    get label() { return translated('tire.fields.mainGrooveInner', "Main groove inner"); },
    required: true,
    get help() { return t('tire.help.treadDepthMmMeasuredMainGroove'); },
  },
  {
    value: "OUTER_MAIN",
    get label() { return translated('tire.fields.mainGrooveOuter', "Main groove outer"); },
    required: true,
    get help() { return t('tire.help.treadDepthMmMeasuredMainGroove2'); },
  },
  {
    value: "CENTER",
    get label() { return translated('tire.fields.centerMostWorn', "Center / most worn"); },
    required: false,
    get help() { return t('tire.help.treadDepthMmCenterTreadPoint'); },
  },
] as const);

/** Tread zones: measurement areas around the tire's circumference. */
export const ZONE_HELP: Record<number, string> = translatedRecord({
  1: "Zone 1 — the first measurement area around the tire's circumference. Measuring in 3 different areas (not just one spot) makes sure the reading represents the whole tread.",
  2: "Zone 2 — the second measurement area around the tire's circumference, away from Zone 1.",
  3: "Zone 3 — the third measurement area around the tire's circumference, away from Zones 1 and 2.",
}, { 1: 'tire.help.zone1FirstMeasurementAreaAround', 2: 'tire.help.zone2SecondMeasurementAreaAround', 3: 'tire.help.zone3ThirdMeasurementAreaAround' });

/** Tread figures (all in mm). D_pull names its real configuration page. */
export const TREAD_HELP = translatedRecord({
  get d_new() { return t('tire.help.dNewReferenceTreadDepthMm'); },
  get d_min() { return t('tire.help.dMinLowestTreadDepthMm'); },
  get d_pull() { return t('tire.help.dPullPlannedRemovalTreadDepth'); },
} as const, { d_new: 'tire.help.dNewReferenceTreadDepthMm', d_min: 'tire.help.dMinLowestTreadDepthMm', d_pull: 'tire.help.dPullPlannedRemovalTreadDepth' });
export const CATEGORY_LABELS: Record<string, string> = translatedRecord({
  PASSENGER_LT: "Passenger / Light Truck",
  TRUCK_BUS: "Truck / Bus",
  OTR: "OTR / Heavy Equipment",
}, { PASSENGER_LT: 'tire.category.passengerLt', TRUCK_BUS: 'tire.category.truckBus', OTR: 'inventory.fields.otrHeavyEquipment' });

export const RECOMMENDATION_COLOR: Record<string, string> = {
  REUSE: "#15803d",
  REPAIR: "#a16207",
  RETREAD: "#0f766e",
  HOLD: "#b45309",
  SCRAP: "#b91c1c",
};

/** Answers that make the conditional damage section relevant. */
export function damageSectionRelevant(
  a: Record<string, string | null>,
): boolean {
  return (
    a.leak_foreign_object === "YES" ||
    a.inner_liner_condition === "LOCAL_DAMAGE" ||
    ["SURFACE_CRACKING", "DEEP_CUT_CRACK"].includes(
      a.sidewall_condition ?? "",
    ) ||
    ["MINOR_ABRASION", "TORN", "DEFORMED", "BEAD_WIRE_DAMAGED"].includes(
      a.bead_condition ?? "",
    ) ||
    ["QUESTIONABLE", "DOES_NOT_MEET"].includes(a.previous_repair ?? "") ||
    ["PRESENT", "SUSPECTED"].includes(a.bulge_separation ?? "") ||
    ["PRESENT", "SUSPECTED"].includes(a.cord_exposure ?? "")
  );
}

export function labelOf(
  options: Option[],
  value: string | null | undefined,
): string {
  return options.find((x) => x.value === value)?.label ?? value ?? "—";
}
