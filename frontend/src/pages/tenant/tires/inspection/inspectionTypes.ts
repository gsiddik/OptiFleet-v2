/** Contract of the Used Tire Management inspection API (backend is the source of truth). */

export type Recommendation = "REUSE" | "REPAIR" | "RETREAD" | "SCRAP" | "HOLD";
export type TireCategory = "PASSENGER_LT" | "TRUCK_BUS" | "OTR";

export interface RuleProfileSnapshot {
  profile_id: string;
  name: string;
  version: number;
  tire_category: TireCategory;
  product_id: string | null;
  application: string | null;
  d_service_mm: string;
  d_pull_mm: string;
  a_max_months: number;
  a_retread_max_months: number;
  n_retread_max: number;
  repair_limits: RepairLimits;
  application_limits: ApplicationLimits;
}

export interface RepairLimits {
  allowed_locations: string[];
  max_puncture_diameter_mm: string | number | null;
  max_cut_length_mm: string | number | null;
  max_cut_width_mm: string | number | null;
  max_cut_depth_mm: string | number | null;
  max_repairs: number | null;
  allow_overlap_previous_repair: boolean;
  allow_reinforcement_damage: boolean;
}

export interface ApplicationLimits {
  positions?: string[];
  max_load_kg?: string | number | null;
  max_speed_kmh?: string | number | null;
  operations?: string[];
  notes?: string | null;
}

export interface TireFacts {
  id: string;
  serial_number: string;
  current_status: string;
  brand: string | null;
  model: string | null;
  product_name: string | null;
  size: string | null;
  construction: string | null;
  category: TireCategory | null;
  category_label: string | null;
  manufacture_date_code: string | null;
  age_months: number | null;
  retread_count: number;
  repair_history: { source: string; label: string; at: string | null }[];
  last_vehicle: string | null;
  last_position: string | null;
  usage_km: string | null;
  removal_reason: string | null;
  inspector: string;
  inspection_at: string;
  d_new_default_mm: string | null;
}

export interface Measurement {
  zone: number;
  groove: "INNER_MAIN" | "OUTER_MAIN" | "CENTER";
  depth_mm: string;
}

export interface Damage {
  id?: string;
  location: string;
  damage_type: string;
  diameter_mm: string | null;
  length_mm: string | null;
  width_mm: string | null;
  depth_mm: string | null;
  reaches_reinforcement: string;
  overlaps_previous_repair: string;
  notes: string | null;
}

export interface Evaluation {
  recommendation: Recommendation;
  recommendation_detail: string | null;
  additional_work: string | null;
  reasons: string[];
  open_items: string[];
  follow_ups: string[];
  variables: Record<"C" | "X" | "R" | "P" | "T" | "K" | "F", boolean | null>;
  d_min_mm: string | null;
  remaining_tread_percent: string | null;
  shows_damage_section: boolean;
  shows_specialist_question: boolean;
  rule_profile: RuleProfileSnapshot | null;
}

export interface InspectionRecord {
  id: string;
  tire_id: string;
  status: "SUBMITTED" | "APPROVED" | "CANCELLED";
  tire_status_before: string;
  inspected_at: string | null;
  inspector: string | null;
  tire_category: TireCategory | null;
  tire_category_label: string | null;
  application: string | null;
  rule_profile_version: number | null;
  thresholds: RuleProfileSnapshot | null;
  tire_snapshot: TireFacts;
  answers: Record<string, string | null>;
  measurements: Measurement[];
  damages: Damage[];
  evidence: {
    id: string;
    kind: string;
    tire_used_inspection_damage_id: string | null;
    original_filename: string;
    mime_type: string;
    size: number;
    notes: string | null;
  }[];
  d_min_mm: string | null;
  d_new_mm: string | null;
  remaining_tread_percent: string | null;
  recommendation: Recommendation;
  recommendation_detail: string | null;
  additional_work: string | null;
  reasons: string[];
  follow_ups: string[];
  variables: Evaluation["variables"];
  notes: string | null;
  result: {
    required_work: string;
    stock_status: string;
    return_requirement: string;
    usage_restrictions: ApplicationLimits | [];
  };
  final_disposition: Recommendation | null;
  return_warehouse_id: string | null;
  approved_by: string | null;
  approved_at: string | null;
  approval_note: string | null;
  cancelled_at: string | null;
}

export interface InspectionContext {
  tire: TireFacts;
  can_inspect: boolean;
  rule_profile: RuleProfileSnapshot | null;
  applications: string[];
  open_inspection: InspectionRecord | null;
  inspections: {
    id: string;
    status: string;
    inspected_at: string | null;
    recommendation: Recommendation;
    recommendation_detail: string | null;
    final_disposition: string | null;
    rule_profile_version: number | null;
  }[];
}
