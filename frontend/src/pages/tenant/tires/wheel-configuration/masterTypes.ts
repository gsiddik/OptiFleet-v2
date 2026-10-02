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
