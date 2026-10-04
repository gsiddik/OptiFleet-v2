/** API contract of /app/tire-operations (TireOperationController / TireOperationService::present, ::list, ::context). */

import type { ApplicationLimits } from '../inspection/inspectionTypes';

export type TireOperationType = 'REPLACEMENT' | 'ROTATION' | 'INSPECTION';
export type TireOperationStatus = 'NEW' | 'IN_PROGRESS' | 'COMPLETED' | 'CANCELLED';

export const OPERATION_TYPE_LABEL: Record<TireOperationType, string> = { REPLACEMENT: 'Replacement', ROTATION: 'Rotation', INSPECTION: 'Inspection' };
export const OPERATION_TYPES: TireOperationType[] = ['REPLACEMENT', 'ROTATION', 'INSPECTION'];

/** The tire on a position, as shown on an operation card (TireFactsService). */
export interface TireCard {
  id: string;
  serial_number: string;
  current_status: string;
  position_code: string;
  product: { id: string; name: string; sku: string | null } | null;
  last_operation_source: 'TIRE_OPERATION' | 'INSTALLATION' | null;
  last_operation_at: string | null;
  last_operation_date: string | null;
  last_operation_time: string | null;
  last_operation_odometer: string | null;
  usage_km: string | null;
  /** no hours-meter source exists per tire yet → always null */
  usage_hours: string | null;
  last_tread_depth_mm: string | null;
}

export interface OperationContext {
  vehicle: { id: string; registration_number: string; branch_id: string; default_workshop_id: string | null };
  mapping: {
    config_code: string;
    wheel_configuration_version_id: string;
    version_number: number;
    vehicle_type: string;
    truck_configuration_type: string | null;
    front_axles: number[];
    rear_axles: number[];
    spare_tires: number;
  } | null;
  positions: {
    position_code: string;
    position_group: 'FRONT' | 'REAR' | 'SPARE';
    label: string;
    open_operation: { id: string; operation_type: TireOperationType; wo_number: string | null } | null;
    tire: TireCard | null;
  }[];
}

export interface ReplacementCandidate {
  id: string;
  serial_number: string;
  current_status: string;
  source: 'NEW_STOCK' | 'REUSE';
  /** Warehouse holding the serial (a REUSE tire is issued from its used tire quantity). */
  warehouse?: string | null;
  /** From the inspection that returned a REUSE tire to stock (warning only, not enforced). */
  usage_restrictions?: ApplicationLimits | null;
  manufacture_date_code: string | null;
}

export interface TireOperationDetail {
  id: string;
  operation_type: TireOperationType;
  status: TireOperationStatus;
  operated_at: string;
  operated_date: string;
  operated_time: string;
  odometer: string;
  config_code: string;
  wheel_configuration_version_id: string;
  configuration: {
    config_code: string;
    version_number: number;
    vehicle_type: string | null;
    truck_configuration_type: string | null;
    front_axles: number[];
    rear_axles: number[];
    spare_tires: number;
  } | null;
  vehicle: { id: string; registration_number: string | null };
  work_order: { id: string; wo_number: string; status: string } | null;
  part_request: { id: string; status: string } | null;
  cancelled_at: string | null;
  cancellation_reason: string | null;
  applied_at: string | null;
  items: {
    id: string;
    position_code: string;
    pair_number: number | null;
    tread_depth_mm: string | null;
    applied_at: string | null;
    tire: TireCard | null;
    replacement_tire: { id: string; serial_number: string; current_status: string; product: { id: string; name: string; sku: string | null } | null } | null;
  }[];
  /** Non-blocking usage-restriction warnings of REUSE replacements. */
  warnings?: string[];
  can_edit: boolean;
  can_cancel: boolean;
}

export interface TireOperationListItem {
  id: string;
  operation_type: TireOperationType;
  status: TireOperationStatus;
  operated_at: string;
  operated_date: string;
  operated_time: string;
  odometer: string;
  config_code: string;
  vehicle: { id: string; registration_number: string };
  work_order: { id: string; wo_number: string; status: string } | null;
  items: {
    position_code: string;
    pair_number: number | null;
    serial_number: string | null;
    replacement_serial_number: string | null;
    usage_km: string | null;
    usage_hours: string | null;
    last_tread_depth_mm: string | null;
  }[];
  can_edit: boolean;
  can_cancel: boolean;
}

/** Request body of POST/PUT /app/tire-operations. */
export interface TireOperationPayload {
  vehicle_id: string;
  workshop_id?: string;
  operation_type: TireOperationType;
  operated_date: string;
  operated_time: string;
  odometer: string;
  items?: { position_code: string; replacement_tire_id?: string; tread_depth_mm?: string }[];
  rotation_pairs?: { from: string; to: string }[];
}
