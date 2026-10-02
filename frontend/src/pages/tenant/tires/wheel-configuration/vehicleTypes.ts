/**
 * Vehicle types of "Add New Wheels Configuration" — the single source for the dropdowns, the form
 * switch, the preview body and the Config Code prefix (mirrored by the backend
 * WheelConfigurationRules). Every type uses the same axle/wheel form and rules; Truck adds a
 * mandatory Truck Configuration Type that only changes the Config Code prefix and the preview.
 */
export const VEHICLE_TYPES = [
  { value: 'PASSENGER_CAR', label: 'Passenger Car' },
  { value: 'TRUCK', label: 'Truck' },
  { value: 'BUS', label: 'Bus' },
  { value: 'FORKLIFT', label: 'Forklift' },
  { value: 'VAN', label: 'Van' },
  { value: 'HEAVY_EQUIPMENT', label: 'Heavy Equipment' },
] as const;

export type VehicleType = (typeof VEHICLE_TYPES)[number]['value'];

/** Truck Configuration Type → Config Code prefix ("+" Trailer, "-" Semi Trailer, none otherwise). */
export const TRUCK_CONFIGURATION_TYPES = [
  { value: 'NON_TRAILER', label: 'Non Trailer', prefix: '' },
  { value: 'TRAILER', label: 'Trailer', prefix: '+' },
  { value: 'SEMI_TRAILER', label: 'Semi Trailer', prefix: '-' },
] as const;

export type TruckConfigurationType = (typeof TRUCK_CONFIGURATION_TYPES)[number]['value'];

export function vehicleTypeOption(value: string) {
  return VEHICLE_TYPES.find((t) => t.value === value);
}

export function truckConfigurationTypeOption(value: string) {
  return TRUCK_CONFIGURATION_TYPES.find((t) => t.value === value);
}

/** Truck needs its configuration type before the axle form applies; other types do not. */
export function requiresTruckConfigurationType(vehicleType: string): boolean {
  return vehicleType === 'TRUCK';
}

/** The configuration-type indicator in front of the Config Code — never stored as axle data. */
export function configCodePrefix(vehicleType: string, truckConfigurationType: string | null): string {
  return vehicleType === 'TRUCK' ? (truckConfigurationTypeOption(truckConfigurationType ?? '')?.prefix ?? '') : '';
}

/** Which body the preview draws. */
export type BodyStyle = 'PASSENGER_CAR' | 'BUS' | 'VAN' | 'FORKLIFT' | 'HEAVY_EQUIPMENT' | 'TRUCK' | 'TRAILER' | 'SEMI_TRAILER';

export function bodyStyleFor(vehicleType: VehicleType, truckConfigurationType: string | null): BodyStyle {
  if (vehicleType !== 'TRUCK') return vehicleType;
  if (truckConfigurationType === 'TRAILER') return 'TRAILER';
  if (truckConfigurationType === 'SEMI_TRAILER') return 'SEMI_TRAILER';
  return 'TRUCK';
}

/**
 * A vehicle's free-text vehicle_type (legacy values such as "Car", "Truck") → Vehicle Type code.
 * Display/form initialisation only — mirrors the backend VehicleTypeClassifier, which is what
 * decides wheel configuration compatibility.
 */
export function resolveVehicleType(raw: string | null | undefined): VehicleType | null {
  if (!raw) return null;
  const key = raw.trim().toUpperCase().replace(/[^A-Z0-9]+/g, '_').replace(/^_+|_+$/g, '');
  const aliases: Record<string, VehicleType> = { CAR: 'PASSENGER_CAR', PASSENGER: 'PASSENGER_CAR' };
  return VEHICLE_TYPES.find((t) => t.value === key)?.value ?? aliases[key] ?? null;
}
