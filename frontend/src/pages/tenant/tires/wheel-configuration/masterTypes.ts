/** API shapes of the Wheel Configuration master/template endpoints (/app/wheel-configuration-masters). */

export interface PositionDiff {
  unchanged: string[];
  added: string[];
  removed: string[];
}

export interface GeneratedPosition {
  position_code: string;
  group: 'FRONT' | 'REAR' | 'SPARE';
  axle_in_group: number | null;
  label: string;
}

export interface VersionPosition {
  id: string;
  position_code: string;
  position_group: 'FRONT' | 'REAR' | 'SPARE';
  axle_in_group: number | null;
  label: string;
  sequence: number;
}

export interface ConfigurationVersion {
  id: string;
  version_number: number;
  config_code: string;
  front_axles: number[];
  rear_axles: number[];
  spare_tires: number;
  total_axles: number;
  total_wheels: number;
  status: 'ACTIVE' | 'INACTIVE';
  position_diff: PositionDiff;
  activated_at: string | null;
  deactivated_at: string | null;
  created_at: string;
  positions?: VersionPosition[];
}

export interface ConfigurationMaster {
  id: string;
  vehicle_type: string;
  truck_configuration_type: string | null;
  config_code: string;
  status: 'ACTIVE' | 'INACTIVE';
  updated_at: string;
  current_version: ConfigurationVersion | null;
  versions?: ConfigurationVersion[];
  /** active vehicle mappings (any version); returned by the detail endpoint */
  mapped_vehicle_count?: number;
}

/** What Save sends: the axle pattern plus the Config Code the form shows (the server regenerates it). */
export interface SaveRequest {
  vehicle_type: string;
  truck_configuration_type?: string;
  front_axles: number[];
  rear_axles: number[];
  spare_tires: number;
  config_code: string;
}

/** Positions grouped per axle (F1, F2, R1, …, Spare) for display. */
export function groupPositions(positions: { position_code: string; group: string; axle_in_group: number | null }[]): { label: string; codes: string[] }[] {
  const rows = new Map<string, string[]>();
  for (const p of positions) {
    const key = p.group === 'SPARE' ? 'Spare' : `${p.group === 'FRONT' ? 'F' : 'R'}${p.axle_in_group}`;
    rows.set(key, [...(rows.get(key) ?? []), p.position_code]);
  }
  return [...rows.entries()].map(([label, codes]) => ({ label, codes }));
}

/** Active tire installation on a vehicle position (GET /app/vehicles/{id}/wheel-configuration). */
export interface PositionInstallation {
  installation_id: string;
  position_code: string;
  installed_at: string;
  /** in the tenant's timezone */
  installed_date: string;
  installed_time: string;
  installation_date_source: string;
  installation_source: string | null;
  installation_odometer: string | null;
  tread_depth_mm: string | null;
  tire: { id: string; serial_number: string; current_status: string; product: { id: string; name: string; sku: string | null } | null };
}

export interface VehicleWheelConfiguration {
  vehicle: { id: string; registration_number: string; vehicle_type: string | null; axle_count: number | null; wheel_count: number | null };
  mapping: {
    id: string;
    mapped_at: string;
    master: { id: string; vehicle_type: string; truck_configuration_type: string | null; config_code: string; current_version_id: string };
    version: ConfigurationVersion & { positions: VersionPosition[] };
    is_current_version: boolean;
  } | null;
  installations: PositionInstallation[];
  history: { id: string; config_code: string; version_number: number; status: string; mapped_at: string; ended_at: string | null; end_reason: string | null }[];
}
