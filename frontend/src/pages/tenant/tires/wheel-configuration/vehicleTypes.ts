/**
 * Vehicle types offered by "Add New Wheels Configuration" — the single source for the dropdown,
 * the form switch and (later) the API value. Only Passenger Car has a detailed form in the
 * prototype; the other types' rules are still to be defined by the owner.
 */
export const VEHICLE_TYPES = [
  { value: 'PASSENGER_CAR', label: 'Passenger Car', hasConfigurationForm: true },
  { value: 'TRUCK', label: 'Truck', hasConfigurationForm: false },
  { value: 'BUS', label: 'Bus', hasConfigurationForm: false },
  { value: 'FORKLIFT', label: 'Forklift', hasConfigurationForm: false },
  { value: 'VAN', label: 'Van', hasConfigurationForm: false },
  { value: 'HEAVY_EQUIPMENT', label: 'Heavy Equipment', hasConfigurationForm: false },
] as const;

export type VehicleType = (typeof VEHICLE_TYPES)[number]['value'];

export function vehicleTypeOption(value: string) {
  return VEHICLE_TYPES.find((t) => t.value === value);
}
