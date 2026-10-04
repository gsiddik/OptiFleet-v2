/** Questionnaire wording and option labels (codes are the backend's). */

export type Option = { value: string; label: string };
const o = (value: string, label: string): Option => ({ value, label });

export const QUESTIONS: Record<string, { label: string; options: Option[] }> = {
  identity_status: {
    label: "Can the tire identity, category, and manufacture date be verified?",
    options: [
      o("COMPLETE", "Complete"),
      o("PARTIALLY_UNKNOWN", "Partially Unknown"),
      o("CANNOT_VERIFY", "Cannot Be Verified"),
    ],
  },
  internal_inspected: {
    label:
      "Have the exterior and interior of the tire been inspected after removal from the rim?",
    options: [o("YES", "Yes"), o("NOT_YET", "Not Yet")],
  },
  wear_pattern: {
    label: "Wear pattern",
    options: [
      o("EVEN", "Even"),
      o("ONE_SIDED", "One-Sided Wear"),
      o("CENTER", "Center Wear"),
      o("BOTH_SIDES", "Both-Sides Wear"),
      o("CUPPING_SCALLOPING", "Cupping / Scalloping"),
      o("FLAT_SPOT", "Flat Spot"),
      o("NOT_INSPECTED", "Not Inspected"),
    ],
  },
  bulge_separation: {
    label: "Bulge / deformation / separation",
    options: [
      o("NONE", "None"),
      o("PRESENT", "Present"),
      o("SUSPECTED", "Suspected"),
      o("NOT_INSPECTED", "Not Inspected"),
    ],
  },
  cord_exposure: {
    label: "Cord / wire exposure",
    options: [
      o("NONE", "None"),
      o("PRESENT", "Present"),
      o("SUSPECTED", "Suspected"),
      o("NOT_INSPECTED", "Not Inspected"),
    ],
  },
  sidewall_condition: {
    label: "Sidewall",
    options: [
      o("NORMAL", "Normal"),
      o("SURFACE_ABRASION", "Surface Abrasion"),
      o("SURFACE_CRACKING", "Surface Cracking"),
      o("DEEP_CUT_CRACK", "Deep Cut / Deep Crack"),
      o("NOT_INSPECTED", "Not Inspected"),
    ],
  },
  bead_condition: {
    label: "Bead",
    options: [
      o("NORMAL", "Normal"),
      o("MINOR_ABRASION", "Minor Abrasion"),
      o("TORN", "Torn"),
      o("DEFORMED", "Deformed"),
      o("BEAD_WIRE_DAMAGED", "Bead Wire Damaged / Exposed"),
      o("NOT_INSPECTED", "Not Inspected"),
    ],
  },
  inner_liner_condition: {
    label: "Inner liner",
    options: [
      o("NORMAL", "Normal"),
      o("LOCAL_DAMAGE", "Local Damage"),
      o("CRACKED_DELAMINATED", "Cracked / Delaminated"),
      o("WRINKLED_HEAT_DAMAGE", "Wrinkled / Heat Damage"),
      o("CORD_EXPOSED", "Cord Exposed"),
      o("NOT_INSPECTED", "Not Inspected"),
    ],
  },
  run_flat_overheat: {
    label: "Run flat / low pressure / overheat",
    options: [
      o("NO", "No"),
      o("HISTORY_NO_SIGN", "History Present, No Sign Found"),
      o("PHYSICAL_SIGN", "Physical Sign Found"),
      o("UNKNOWN", "Unknown"),
    ],
  },
  leak_foreign_object: {
    label: "Leak / foreign object",
    options: [o("NO", "No"), o("YES", "Yes"), o("NOT_TESTED", "Not Tested")],
  },
  previous_repair: {
    label: "Previous repair",
    options: [
      o("NONE", "None"),
      o("MEETS_STANDARD", "Meets Standard"),
      o("QUESTIONABLE", "Questionable"),
      o("DOES_NOT_MEET", "Does Not Meet Standard"),
      o("NOT_INSPECTED", "Not Inspected"),
    ],
  },
  age_chemical: {
    label: "Age / chemical damage",
    options: [
      o("NONE", "None"),
      o("SUSPECTED", "Suspected"),
      o("DEGRADED", "Hardened / Brittle / Softened / Swollen"),
      o("NOT_INSPECTED", "Not Inspected"),
    ],
  },
  casing_compliance: {
    label: "Age / retread / casing compliance",
    options: [
      o("MEETS", "Meets Requirement"),
      o("DOES_NOT_MEET", "Does Not Meet Requirement"),
      o("CANNOT_CONFIRM", "Cannot Yet Be Confirmed"),
    ],
  },
  repair_eligibility: {
    label:
      "Do all damages meet the repair limits applicable to this tire category / model?",
    options: [
      o("YES", "Yes"),
      o("NO", "No"),
      o("SPECIALIST_REQUIRED", "Specialist Required"),
    ],
  },
  specialist_result: {
    label: "Specialist / retreader result",
    options: [
      o("NOT_REQUESTED", "Not Requested"),
      o("PENDING", "Pending"),
      o("ACCEPTED", "Accepted (final)"),
      o("REJECTED", "Rejected (final)"),
    ],
  },
};

export const LOCATIONS: Option[] = [
  o("TREAD", "Tread"),
  o("SHOULDER", "Shoulder"),
  o("SIDEWALL", "Sidewall"),
  o("BEAD", "Bead"),
  o("INNER_LINER", "Inner Liner"),
];
export const DAMAGE_TYPES: Option[] = [
  o("PUNCTURE", "Puncture"),
  o("CUT", "Cut"),
  o("CRACK", "Crack"),
  o("ABRASION", "Abrasion"),
  o("SEPARATION", "Separation"),
  o("PREVIOUS_REPAIR_DAMAGE", "Previous Repair Damage"),
  o("OTHER", "Other"),
];
export const TRI_STATE: Option[] = [
  o("NO", "No"),
  o("YES", "Yes"),
  o("UNKNOWN", "Unknown"),
];
export const GROOVES = [
  { value: "INNER_MAIN", label: "Main groove inner", required: true },
  { value: "OUTER_MAIN", label: "Main groove outer", required: true },
  { value: "CENTER", label: "Center / most worn", required: false },
] as const;
export const CATEGORY_LABELS: Record<string, string> = {
  PASSENGER_LT: "Passenger / Light Truck",
  TRUCK_BUS: "Truck / Bus",
  OTR: "OTR / Heavy Equipment",
};

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
