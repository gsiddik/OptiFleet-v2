/** Questionnaire wording and option labels (codes are the backend's). */

export type Option = { value: string; label: string };
const o = (value: string, label: string): Option => ({ value, label });

export const QUESTIONS: Record<
  string,
  { label: string; options: Option[]; help?: string }
> = {
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
    help: "The tread wear pattern — even, one-sided, center, both sides, cupping / scalloping or flat spot. Abnormal wear helps reveal alignment, suspension, inflation pressure or load problems.",
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
    help: "Check for bulges, deformation or signs that the tire layers are separating. A finding can indicate casing, belt or ply damage.",
    options: [
      o("NONE", "None"),
      o("PRESENT", "Present"),
      o("SUSPECTED", "Suspected"),
      o("NOT_INSPECTED", "Not Inspected"),
    ],
  },
  cord_exposure: {
    label: "Cord / wire exposure",
    help: "Check whether the reinforcing cords / steel wires are visible, broken or corroded. This concerns the tire's structural integrity.",
    options: [
      o("NONE", "None"),
      o("PRESENT", "Present"),
      o("SUSPECTED", "Suspected"),
      o("NOT_INSPECTED", "Not Inspected"),
    ],
  },
  sidewall_condition: {
    label: "Sidewall",
    help: "Condition of the tire's side wall: surface abrasion, cracking, cuts and deeper damage.",
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
    help: "Condition of the bead — the part of the tire that seats on the rim. Check for abrasion, tearing, deformation and an exposed or damaged bead wire.",
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
    help: "Condition of the inner layer that helps hold the air pressure. It can reveal internal damage that is not visible from the outside.",
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
    help: "History or signs that the tire ran with very low pressure, leaking or overheated, which can cause internal casing damage.",
    options: [
      o("NO", "No"),
      o("HISTORY_NO_SIGN", "History Present, No Sign Found"),
      o("PHYSICAL_SIGN", "Physical Sign Found"),
      o("UNKNOWN", "Unknown"),
    ],
  },
  leak_foreign_object: {
    label: "Leak / foreign object",
    help: "Check for leaks or penetration by a foreign object such as a nail, screw or other object.",
    options: [o("NO", "No"), o("YES", "Yes"), o("NOT_TESTED", "Not Tested")],
  },
  previous_repair: {
    label: "Previous repair",
    help: "Check repairs done before and whether they still meet the repair standard.",
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
    help: "Degradation from age or chemical exposure, such as rubber hardening, brittleness, softening, swelling or cracking.",
    options: [
      o("NONE", "None"),
      o("SUSPECTED", "Suspected"),
      o("DEGRADED", "Hardened / Brittle / Softened / Swollen"),
      o("NOT_INSPECTED", "Not Inspected"),
    ],
  },
  casing_compliance: {
    label: "Age / retread / casing compliance",
    help: "Whether the tire's age, retread count and casing condition still meet the limits that apply to this tire category / model (the active Inspection Rules profile).",
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
    help: "The result of a further examination by a specialist / retreader, when the decision cannot be made from the fleet inspection alone.",
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
  {
    value: "INNER_MAIN",
    label: "Main groove inner",
    required: true,
    help: "Tread depth (mm) measured in the main groove on the inner side of the tire. Captures inner-side wear and helps detect uneven wear.",
  },
  {
    value: "OUTER_MAIN",
    label: "Main groove outer",
    required: true,
    help: "Tread depth (mm) measured in the main groove on the outer side of the tire. Compared with the inner groove to read the wear pattern.",
  },
  {
    value: "CENTER",
    label: "Center / most worn",
    required: false,
    help: "Tread depth (mm) at the center of the tread or at the point that visibly looks the most worn. Captures the lowest tread that the inner / outer grooves may miss.",
  },
] as const;

/** Tread zones: measurement areas around the tire's circumference. */
export const ZONE_HELP: Record<number, string> = {
  1: "Zone 1 — the first measurement area around the tire's circumference. Measuring in 3 different areas (not just one spot) makes sure the reading represents the whole tread.",
  2: "Zone 2 — the second measurement area around the tire's circumference, away from Zone 1.",
  3: "Zone 3 — the third measurement area around the tire's circumference, away from Zones 1 and 2.",
};

/** Tread figures (all in mm). D_pull names its real configuration page. */
export const TREAD_HELP = {
  d_new:
    "D_new — the reference tread depth (mm) when the tire was new, or after its last retread. Used as the baseline for the remaining tread percentage when available.",
  d_min:
    "D_min — the lowest tread depth (mm) of all measured points: D_min = min(all tread depth measurements). One of the main inputs of the inspection decision.",
  d_pull:
    "D_pull — the planned removal tread depth (mm): the threshold at which the fleet pulls a tire from service before it reaches the legal / minimum D_service. D_pull must be ≥ D_service. Adjusted per tire category in Tire Management → Inspection Rules (the active rule profile).",
} as const;
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
