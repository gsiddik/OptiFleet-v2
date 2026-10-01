export interface Paginated<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export interface TenantMembership {
  tenant_id: string;
  tenant_code: string;
  tenant_name: string;
  tenant_logo_url: string | null;
  status: 'active' | 'inactive';
  roles: string[];
}

export interface CurrentUser {
  id: string;
  name: string;
  email: string;
  scope: 'platform' | 'tenant';
  permissions: string[];
  memberships: TenantMembership[];
}

export interface Tenant {
  id: string;
  code: string;
  name: string;
  legal_name?: string | null;
  industry?: string | null;
  status: 'DRAFT' | 'ACTIVE' | 'INACTIVE' | 'SUSPENDED';
  created_at: string;
}

export interface ModuleCatalogItem {
  id: string;
  code: string;
  name: string;
  description: string | null;
  category: string;
  is_core: boolean;
  is_sellable: boolean;
  status: 'ACTIVE' | 'INACTIVE';
}

export interface ModuleDependency {
  id: string;
  module_id: string;
  module_code: string;
  depends_on_module_id: string;
  depends_on_module_code: string;
}

export interface TenantModuleEntitlement {
  id: string;
  tenant_id: string;
  module_id: string;
  module_code: string;
  module_name: string;
  active: boolean;
  valid_from: string | null;
  valid_until: string | null;
  source: string;
}

export interface CapacityLimit {
  id: string;
  tenant_id: string;
  resource_type: string;
  max_count: number;
  current_count: number;
}

export interface Branch {
  id: string;
  code: string;
  name: string;
  address: string | null;
  province: string | null;
  city: string | null;
  status: 'DRAFT' | 'ACTIVE' | 'INACTIVE' | 'CLOSED';
}

export interface Workshop {
  id: string;
  branch_id: string | null;
  code: string;
  name: string;
  workshop_type: 'INTERNAL' | 'SATELLITE' | 'MOBILE';
  capacity: number | null;
  number_of_service_bays: number | null;
  status: 'DRAFT' | 'ACTIVE' | 'INACTIVE' | 'CLOSED';
}

export interface Warehouse {
  id: string;
  branch_id: string | null;
  workshop_id: string | null;
  code: string;
  name: string;
  warehouse_type: 'CENTRAL' | 'BRANCH' | 'WORKSHOP' | 'TIRE' | 'CONSUMABLE' | 'SCRAP' | 'QUARANTINE';
  status: 'DRAFT' | 'ACTIVE' | 'INACTIVE' | 'CLOSED';
}

export interface VehicleCategory {
  id: string;
  tenant_id: string | null;
  code: string;
  name: string;
  description: string | null;
  is_system: boolean;
  status: 'ACTIVE' | 'INACTIVE';
  component_group_ids?: string[];
}

export interface VehicleBrandItem {
  id: string;
  tenant_id: string | null;
  code: string;
  name: string;
  logo_url: string | null;
  logo_available: boolean;
  usage_type: 'CAR' | 'TRUCK' | 'BUS' | 'HEAVY_EQUIPMENT' | null;
  usage_types: ('CAR' | 'TRUCK' | 'BUS' | 'HEAVY_EQUIPMENT')[] | null;
  /** "Brand Of": linked Vehicle Categories (usage_type / usage_types are legacy, read-only). */
  vehicle_categories?: { id: string; code: string; name: string; status: string }[];
  is_system: boolean;
  status: 'ACTIVE' | 'INACTIVE';
}

export interface VehicleModelItem {
  id: string;
  tenant_id: string | null;
  vehicle_brand_id: string;
  code: string;
  name: string;
  is_system: boolean;
  status: 'ACTIVE' | 'INACTIVE';
  brand?: VehicleBrandItem;
}

export interface ComponentGroup {
  id: string;
  tenant_id: string | null;
  code: string;
  name: string;
  /** Exactly 3 letters A-Z; null only on legacy tenant groups created before abbreviations existed. */
  abbreviation: string | null;
  parent_id: string | null;
  sequence: number;
  description: string | null;
  is_system: boolean;
  status: 'ACTIVE' | 'INACTIVE';
  /** Present on Component Group list/detail endpoints only. */
  is_used?: boolean;
  abbreviation_locked?: boolean;
  is_deleted?: boolean;
  updated_at?: string;
  deleted_at?: string | null;
}

/** L2 Category / Assembly beneath a Component Group (mechanical taxonomy, not the commercial Product Category). */
export interface ComponentCategory {
  id: string;
  tenant_id?: string | null;
  component_group_id: string;
  code: string;
  name: string;
  description?: string | null;
  sequence?: number;
  is_system?: boolean;
  status: 'ACTIVE' | 'INACTIVE';
  deleted_at?: string | null;
  updated_at?: string;
  is_used?: boolean;
  is_deleted?: boolean;
  component_group?: ComponentGroup | null;
}

export type ComponentItemType = 'SPARE_PART' | 'CONSUMABLE' | 'TIRE' | 'RIM' | 'TOOL' | 'EQUIPMENT';

/** L3 Subcategory / Component Family beneath a Category; empty `item_types` = unrestricted. */
export interface ComponentSubcategory {
  id: string;
  tenant_id?: string | null;
  component_category_id: string;
  code: string;
  name: string;
  description?: string | null;
  sequence?: number;
  is_system?: boolean;
  status: 'ACTIVE' | 'INACTIVE';
  deleted_at?: string | null;
  updated_at?: string;
  is_used?: boolean;
  is_deleted?: boolean;
  item_types?: ComponentItemType[];
  /** Product-form lookup only: whether the current Item Type may use it. */
  allowed?: boolean;
  category?: ComponentCategory | null;
}

export interface RoleItem {
  id: string;
  name: string;
  scope: 'platform' | 'tenant';
  is_system: boolean;
  description?: string | null;
  permissions: string[];
  permission_ids?: string[];
  /** False only for the platform superadmin role (always holds every platform permission). */
  editable?: boolean;
}

export interface PermissionItem {
  id: string;
  name: string;
  group: string;
  scope?: 'platform' | 'tenant';
  description?: string | null;
  /** Module -> Feature -> Action metadata (derived server-side from the module-gated routes). */
  module?: string;
  module_name?: string;
  feature?: string;
  feature_name?: string;
  action?: string;
  action_name?: string;
}

// --- Phase 2: Commercial SaaS ---

export interface BundleItem {
  id: string;
  code: string;
  name: string;
  description: string | null;
  status: 'DRAFT' | 'PUBLISHED' | 'ARCHIVED';
  is_active: boolean;
  effective_from: string | null;
  effective_until: string | null;
  modules?: ModuleCatalogItem[];
}

export interface PricingVersionItem {
  id: string;
  pricing_id: string;
  version_number: number;
  amount: string;
  effective_from: string;
  effective_until: string | null;
  status: 'DRAFT' | 'ACTIVE' | 'EXPIRED';
}

export interface PricingItem {
  id: string;
  priceable_type: 'MODULE' | 'BUNDLE' | 'ADD_ON' | 'CAPACITY';
  priceable_code: string;
  pricing_method: string;
  billing_frequency: 'MONTHLY' | 'QUARTERLY' | 'SEMIANNUAL' | 'ANNUAL' | 'CUSTOM';
  currency: string;
  status: 'DRAFT' | 'ACTIVE' | 'ARCHIVED';
  versions?: PricingVersionItem[];
}

export interface ContractItemRow {
  id: string;
  product_type: 'BUNDLE' | 'MODULE' | 'ADD_ON' | 'CAPACITY' | 'SETUP_FEE' | 'OTHER';
  product_reference: string | null;
  description: string;
  quantity: string;
  unit_price: string;
  discount: string;
  tax: string;
  final_amount: string;
  billing_frequency: string;
  valid_from: string;
  valid_until: string | null;
}

export interface ContractItem {
  id: string;
  contract_number: string;
  tenant_id: string;
  start_date: string;
  end_date: string;
  billing_cycle: string;
  payment_terms_days: number;
  grace_period_days: number;
  currency: string;
  subtotal: string;
  discount: string;
  tax: string;
  total: string;
  status: 'DRAFT' | 'PENDING_APPROVAL' | 'APPROVED' | 'ACTIVE' | 'EXPIRING' | 'EXPIRED' | 'REJECTED' | 'CANCELLED' | 'TERMINATED';
  activation_requires_payment: boolean;
  notes: string | null;
  approved_at: string | null;
  activated_at: string | null;
  renewed_from_contract_id?: string | null;
  tenant?: Tenant;
  items?: ContractItemRow[];
  amendments?: ContractAmendmentItem[];
  subscription?: SubscriptionItem | null;
}

export interface ContractAmendmentLineItem {
  id: string;
  action: 'ADD' | 'REMOVE';
  product_type: string;
  product_reference: string | null;
  description: string;
  quantity: string;
  unit_price: string;
  final_amount: string;
  contract_item_id: string | null;
}

export interface ContractAmendmentItem {
  id: string;
  contract_id: string;
  amendment_number: number;
  status: 'DRAFT' | 'PENDING_APPROVAL' | 'APPROVED' | 'REJECTED' | 'APPLIED';
  reason: string | null;
  effective_date: string;
  proration_amount: string | null;
  approved_at: string | null;
  items?: ContractAmendmentLineItem[];
}

export interface SubscriptionItem {
  id: string;
  tenant_id: string;
  contract_id: string;
  start_date: string;
  end_date: string;
  next_billing_date: string;
  grace_period_end: string | null;
  status: 'PENDING' | 'ACTIVE' | 'EXPIRING' | 'PAST_DUE' | 'GRACE_PERIOD' | 'SUSPENDED' | 'EXPIRED' | 'CANCELLED';
  activated_at: string | null;
  suspended_at: string | null;
  contract?: ContractItem;
  tenant?: Tenant;
}

export interface BillingItem {
  id: string;
  subscription_id: string;
  tenant_id: string;
  contract_id: string;
  billing_period_start: string;
  billing_period_end: string;
  invoice_date: string;
  due_date: string;
  subtotal: string;
  discount: string;
  tax: string;
  total: string;
  status: 'DRAFT' | 'GENERATED' | 'INVOICED' | 'PAID' | 'PARTIALLY_PAID' | 'PAST_DUE' | 'CANCELLED';
  tenant?: Tenant;
  contract?: ContractItem;
  items?: InvoiceItemRow[];
}

export interface InvoiceItemRow {
  id: string;
  description: string;
  quantity: string;
  unit_price: string;
  discount: string;
  tax: string;
  amount: string;
}

export interface InvoiceItem {
  id: string;
  invoice_number: string;
  tenant_id: string;
  contract_id: string;
  subscription_id: string;
  invoice_date: string;
  due_date: string;
  currency: string;
  subtotal: string;
  discount: string;
  tax: string;
  total: string;
  paid_amount: string;
  outstanding_amount: string;
  status: 'DRAFT' | 'ISSUED' | 'OUTSTANDING' | 'PARTIALLY_PAID' | 'PAID' | 'OVERDUE' | 'VOID';
  issued_at: string | null;
  paid_at: string | null;
  tenant?: Tenant;
  items?: InvoiceItemRow[];
}

export interface PaymentProofItem {
  id: string;
  original_filename: string;
  mime_type: string;
  size: number;
}

export interface PaymentItem {
  id: string;
  tenant_id: string;
  invoice_id: string;
  payment_date: string;
  amount: string;
  payment_method: string;
  bank_name: string | null;
  account_name: string | null;
  transaction_reference: string | null;
  note: string | null;
  status: 'DRAFT' | 'SUBMITTED' | 'UNDER_REVIEW' | 'VERIFIED' | 'REJECTED' | 'REVERSED';
  verification_note: string | null;
  tenant?: Tenant;
  invoice?: InvoiceItem;
  proofs?: PaymentProofItem[];
}

export interface AuditLogEntry {
  id: string;
  actor_user_id: string | null;
  actor_name: string | null;
  tenant_id: string | null;
  resource_type: string;
  resource_id: string;
  action: string;
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
  ip_address: string | null;
  created_at: string;
}

// --- Phase 3: Core VMS Operations ---

export interface VehicleItem {
  id: string;
  branch_id: string;
  default_workshop_id: string | null;
  vehicle_category_id: string;
  brand: string;
  vehicle_brand_id: string | null;
  model: string;
  vehicle_model_id: string | null;
  vehicle_type: string | null;
  registration_number: string;
  vin: string | null;
  chassis_number: string | null;
  engine_number: string | null;
  year: number | null;
  purchase_month: number | null;
  purchase_year: number | null;
  fuel_type: string | null;
  transmission_type: string | null;
  current_odometer: string;
  engine_hour: string | null;
  status: 'ACTIVE' | 'IN_MAINTENANCE' | 'BREAKDOWN' | 'OUT_OF_SERVICE' | 'INACTIVE' | 'DISPOSED';
  operational_status: 'AVAILABLE' | 'IN_USE' | 'ON_HOLD';
  color: string | null;
  doors: number | null;
  seats: number | null;
  length_mm: string | null;
  width_mm: string | null;
  height_mm: string | null;
  fuel_tank_capacity_liters: string | null;
  engine_capacity_cc: string | null;
  suspension_type: string | null;
  axle_count: number | null;
  empty_weight_kg: string | null;
  load_weight_kg: string | null;
  wheel_count: number | null;
  photo_url: string | null;
  photo_mime_type: string | null;
  photo_size: number | null;
  photo_available: boolean;
  branch?: Branch;
  default_workshop?: Workshop;
  vehicle_category?: VehicleCategory;
  vehicle_brand?: VehicleBrandItem;
  vehicle_model?: VehicleModelItem;
}

export interface VehicleAssignmentItem {
  id: string;
  vehicle_id: string;
  from_branch_id: string | null;
  to_branch_id: string | null;
  from_workshop_id: string | null;
  to_workshop_id: string | null;
  effective_from: string;
  effective_until: string | null;
  notes: string | null;
  from_branch?: Branch | null;
  to_branch?: Branch | null;
}

export interface VehicleTransferItem {
  id: string;
  vehicle_id: string;
  from_branch_id: string;
  to_branch_id: string;
  from_workshop_id: string | null;
  to_workshop_id: string | null;
  status: 'DRAFT' | 'REQUESTED' | 'APPROVED' | 'IN_TRANSIT' | 'RECEIVED' | 'COMPLETED' | 'REJECTED' | 'CANCELLED';
  reason: string | null;
  notes: string | null;
  vehicle?: VehicleItem;
  from_branch?: Branch;
  to_branch?: Branch;
}

export interface VehicleDocumentItem {
  id: string;
  vehicle_id: string;
  document_type: 'REGISTRATION' | 'INSPECTION_CERTIFICATE' | 'INSURANCE' | 'PERMIT' | 'WARRANTY' | 'OTHER';
  document_number: string | null;
  issue_date: string | null;
  expiry_date: string | null;
  original_filename: string;
  mime_type: string;
  size: number;
  notes: string | null;
}

export interface InspectionTemplateItemRow {
  id: string;
  component_group_id: string | null;
  item_text: string;
  input_type: 'CHECKBOX' | 'PASS_FAIL' | 'TEXT' | 'NUMBER' | 'SELECT' | 'PHOTO';
  options: string[] | null;
  required: boolean;
  sequence: number;
  threshold: string | null;
  is_system: boolean;
}

export interface InspectionTemplateItem {
  id: string;
  vehicle_category_id: string | null;
  inspection_type: 'PRE_TRIP' | 'POST_TRIP' | 'PERIODIC' | 'WORKSHOP' | 'MAINTENANCE';
  name: string;
  description: string | null;
  status: 'DRAFT' | 'ACTIVE' | 'ARCHIVED';
  items?: InspectionTemplateItemRow[];
  items_count?: number;
  vehicle_category?: VehicleCategory;
}

export interface InspectionResultItem {
  id: string;
  inspection_template_item_id: string;
  value_text: string | null;
  value_number: string | null;
  value_bool: boolean | null;
  passed: boolean | null;
}

export interface InspectionFindingItem {
  id: string;
  component_group_id: string | null;
  severity: 'INFO' | 'LOW' | 'MEDIUM' | 'HIGH' | 'CRITICAL';
  description: string;
  recommended_action: string | null;
}

export interface InspectionItem {
  id: string;
  branch_id: string;
  workshop_id: string | null;
  vehicle_id: string;
  inspection_template_id: string;
  inspection_type: 'PRE_TRIP' | 'POST_TRIP' | 'PERIODIC' | 'WORKSHOP' | 'MAINTENANCE';
  status: 'CREATED' | 'ASSIGNED' | 'STARTED' | 'SUBMITTED' | 'PASSED' | 'WARNING' | 'FAILED';
  odometer_at_inspection: string | null;
  notes: string | null;
  submitted_at?: string | null;
  reviewed_by?: string | null;
  reviewed_at?: string | null;
  review_notes?: string | null;
  created_at: string;
  template_snapshot: InspectionTemplateSnapshotItem[] | null;
  vehicle?: VehicleItem;
  template?: InspectionTemplateItem;
  results?: InspectionResultItem[];
  findings?: InspectionFindingItem[];
}

export interface InspectionTemplateSnapshotItem {
  id: string;
  item_text: string;
  input_type: 'CHECKBOX' | 'PASS_FAIL' | 'TEXT' | 'NUMBER' | 'SELECT' | 'PHOTO';
  options: string[] | null;
  required: boolean;
  sequence: number;
  is_system: boolean;
}

export interface InspectionLogEntry {
  id: string;
  actor_user_id: string | null;
  actor_name: string | null;
  action: string;
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
  created_at: string;
}

export interface MaintenancePackageItemRow {
  id: string;
  component_group_id: string | null;
  component_group?: ComponentGroup | null;
  service_item: string;
  recommended_part_reference: string | null;
  standard_labor_hours: string | null;
}

export interface MaintenanceIntervalItem {
  id: string;
  trigger_type: 'ODOMETER' | 'ENGINE_HOUR' | 'CALENDAR_DAY' | 'MONTH' | 'COMBINATION' | 'CONDITION_BASED';
  odometer_km: number | null;
  engine_hours: number | null;
  calendar_days: number | null;
  months: number | null;
  tolerance_km: number;
  tolerance_days: number;
  condition_notes: string | null;
}

export interface MaintenancePackageItemType {
  id: string;
  code: string;
  name: string;
  maintenance_type: 'PREVENTIVE' | 'CORRECTIVE' | 'BREAKDOWN' | 'INSPECTION' | 'CAMPAIGN' | 'PERIODIC';
  description: string | null;
  standard_labor_hours: string | null;
  status: 'DRAFT' | 'ACTIVE' | 'ARCHIVED';
  period_by: 'CALENDAR_DAY' | 'MONTH' | 'ODOMETER' | 'ENGINE_HOUR' | null;
  threshold_days: number | null;
  threshold_month: number | null;
  threshold_km: number | null;
  threshold_engine_hour: number | null;
  schedule_period: number | null;
  items?: MaintenancePackageItemRow[];
  intervals?: MaintenanceIntervalItem[];
  component_groups?: ComponentGroup[];
}

export interface MaintenanceScheduleItem {
  id: string;
  vehicle_id: string;
  maintenance_package_id: string;
  schedule_start_date: string | null;
  next_due_date: string | null;
  next_due_odometer: number | null;
  next_due_engine_hour: number | null;
  tolerance_days: number;
  tolerance_odometer: number;
  status: 'UPCOMING' | 'DUE_SOON' | 'DUE' | 'OVERDUE' | 'SCHEDULED' | 'COMPLETED';
  last_completed_at: string | null;
  vehicle?: VehicleItem;
  package?: MaintenancePackageItemType;
}

export interface MaintenanceRequestItem {
  id: string;
  request_number: string;
  branch_id: string;
  workshop_id: string | null;
  vehicle_id: string;
  component_group_id: string | null;
  source_type: 'USER' | 'INSPECTION' | 'SCHEDULE' | 'BREAKDOWN' | 'TELEMATICS' | 'MECHANIC' | 'INTELLIGENCE';
  source_inspection_id: string | null;
  priority: 'LOW' | 'MEDIUM' | 'HIGH' | 'URGENT';
  complaint: string;
  status: 'DRAFT' | 'SUBMITTED' | 'UNDER_REVIEW' | 'APPROVED' | 'WORK_ORDER_CREATED' | 'REJECTED' | 'NEED_INFORMATION' | 'CANCELLED';
  review_note: string | null;
  cancellation_reason: string | null;
  work_order_id: string | null;
  requested_by: string | null;
  created_at: string;
  vehicle?: VehicleItem;
  branch?: Branch;
  workshop?: Workshop;
  component_group?: ComponentGroup;
  requested_by_user?: { id: string; name: string } | null;
}

export const INSPECTION_GROUP_CODES = [
  'ENGINE', 'LUBRICATION_SYSTEM', 'CLUTCH_TORQUE_CONVERTER', 'COOLING_SYSTEM',
  'FUEL_SYSTEM', 'TRANSMISSION_SYSTEM', 'EXHAUST_SYSTEM', 'STEERING_SYSTEM',
  'DRIVE_AXLE_ASSEMBLY', 'FRAME_CHASSIS', 'ELECTRICAL_SYSTEM', 'BRAKE_SYSTEM',
  'SUSPENSION_SYSTEM', 'TYRE_WHEEL',
] as const;

export type InspectionGroupCode = (typeof INSPECTION_GROUP_CODES)[number];
export type InspectionGroupStatus = 'GOOD' | 'ATTENTION' | 'REPAIR_REQUIRED' | 'CRITICAL_UNSAFE' | 'NOT_APPLICABLE';

export interface MaintenanceRequestInspectionGroupItem {
  group_code: InspectionGroupCode;
  status: InspectionGroupStatus;
  notes: string | null;
}

export interface MaintenanceRequestAssessmentItem {
  id: string;
  maintenance_request_id: string;
  assessed_by: string | null;
  assessed_at: string | null;
  notes: string | null;
  groups: MaintenanceRequestInspectionGroupItem[];
}

export interface BreakdownItem {
  id: string;
  branch_id: string;
  vehicle_id: string;
  reported_at: string;
  location: string | null;
  severity: 'MINOR' | 'MAJOR' | 'IMMOBILIZED';
  description: string;
  response_notes: string | null;
  downtime_start_at: string | null;
  status: 'REPORTED' | 'VERIFIED' | 'ASSESSED' | 'REPAIR_REQUIRED' | 'WORK_ORDER_CREATED' | 'RESOLVED';
  maintenance_request_id: string | null;
  work_order_id: string | null;
  resolved_at: string | null;
  vehicle?: VehicleItem;
  branch?: Branch;
}

export interface WorkOrderFindingItem {
  id: string;
  component_group_id: string | null;
  severity: 'INFO' | 'LOW' | 'MEDIUM' | 'HIGH' | 'CRITICAL';
  description: string;
  status: 'OPEN' | 'RESOLVED';
  resolution_notes: string | null;
  resolved_by: string | null;
  resolved_at: string | null;
}

export interface WorkOrderDiagnosisItem {
  id: string;
  work_order_finding_id: string | null;
  root_cause: string;
  notes: string | null;
}

export interface WorkOrderCorrectiveActionItem {
  id: string;
  action_description: string;
  status: 'PLANNED' | 'IN_PROGRESS' | 'DONE';
}

export interface WorkOrderLaborLogItem {
  id: string;
  maintenance_job_id: string;
  worker_id: string;
  status: 'RUNNING' | 'PAUSED' | 'FINISHED';
  started_at: string;
  paused_duration_minutes: number;
  last_paused_at: string | null;
  completed_at: string | null;
  actual_minutes: number | null;
  worker?: WorkerItem;
}

export interface MaintenanceJobItem {
  id: string;
  work_order_id: string;
  component_group_id: string | null;
  service_item: string | null;
  description: string;
  estimated_hours: string | null;
  actual_hours: string | null;
  status: 'PENDING' | 'ASSIGNED' | 'IN_PROGRESS' | 'ON_HOLD' | 'COMPLETED' | 'CANCELLED';
  assigned_mechanic: string | null;
  estimated_labor_cost_computed: string | null;
  labor_logs?: WorkOrderLaborLogItem[];
}

export interface WorkOrderPlannedPartItem {
  id: string;
  maintenance_job_id: string | null;
  product_id: string | null;
  warehouse_id: string | null;
  product_reference: string | null;
  description: string;
  quantity: string;
  notes: string | null;
  status: 'PLANNED' | 'REQUESTED' | 'RESERVED' | 'PARTIALLY_RESERVED' | 'ISSUED' | 'PARTIALLY_ISSUED' | 'CONSUMED' | 'RETURNED' | 'CANCELLED';
  planned_quantity: string;
  reserved_quantity: string;
  issued_quantity: string;
  consumed_quantity: string;
  returned_quantity: string;
  unit_cost_at_issue: string | null;
  /** Issue-time cost snapshot of everything issued (analytics); NOT the line's Total Cost. */
  total_cost: string | null;
  /** Issue cost averaged over the issued quantity (backend-computed). */
  average_unit_cost?: string | null;
  /** Total Cost = consumed quantity × unit cost, 2 decimals (backend-computed; returns never count). */
  consumed_total_cost?: string | null;
  /** issued − consumed − returned; 0 once CONSUMED (backend-computed). */
  returnable_quantity?: string | number;
  /** The Product master is the line's identity; description is its name snapshot (or legacy free text). */
  product?: { id: string; name: string; sku?: string | null; code?: string | null; uom?: UomItem | null } | null;
  warehouse?: { id: string; name: string } | null;
}

/** Doc's true "Planned Parts" tab — pure budgeting, never touches warehouse stock. */
export interface WorkOrderPlannedPartEstimateItem {
  id: string;
  work_order_id: string;
  product_id: string;
  quantity: string;
  notes: string | null;
  product?: ProductItem;
}

export interface WorkOrderPartReturnEvidenceItem {
  id: string;
  work_order_planned_part_id: string;
  work_order_part_return_id: string | null;
  original_filename: string | null;
}

/** Owner decision: an old/removed component taken off the vehicle, distinct from an unused-issued-stock return. */
export interface WorkOrderRemovedComponentItem {
  id: string;
  work_order_id: string;
  maintenance_job_id: string | null;
  replaced_by_planned_part_id: string | null;
  product_id: string;
  quantity: string;
  condition: 'GOOD' | 'FAULTY';
  notes: string | null;
  status: 'PENDING_RETURN' | 'RETURNED';
  removed_at: string;
  product?: ProductItem;
  maintenance_job?: MaintenanceJobItem;
  return?: WorkOrderRemovedComponentReturnItem | null;
  evidence?: WorkOrderRemovedComponentEvidenceItem[];
}

export interface WorkOrderRemovedComponentReturnItem {
  id: string;
  work_order_removed_component_id: string;
  warehouse_id: string;
  quantity: string;
  reason: string | null;
}

export interface WorkOrderRemovedComponentEvidenceItem {
  id: string;
  work_order_removed_component_id: string;
  original_filename: string | null;
}

export interface PartRequestLineItem {
  id: string;
  part_request_id: string;
  product_id: string | null;
  product_reference: string | null;
  description: string;
  quantity_requested: string;
  quantity_approved: string | null;
  planned_part_id: string | null;
  product?: ProductItem;
  planned_part?: WorkOrderPlannedPartItem;
}

export interface PartRequestItem {
  id: string;
  work_order_id: string;
  notes: string | null;
  /** REQUESTED -> APPROVED | REJECTED | CANCELLED; APPROVED -> ISSUED (backend state machine). */
  status: 'REQUESTED' | 'APPROVED' | 'REJECTED' | 'CANCELLED' | 'ISSUED';
  requested_by: string | null;
  requested_at: string | null;
  decided_by: string | null;
  decided_at: string | null;
  decision_note: string | null;
  warehouse_id?: string | null;
  issued_by?: string | null;
  issued_at?: string | null;
  warehouse?: { id: string; code: string; name: string } | null;
  items?: PartRequestLineItem[];
  work_order?: WorkOrderItem;
  created_at?: string;
}

export type ExternalWorkOrderInvoiceAction =
  | 'generate_authorization'
  | 'view_authorization'
  | 'deliver'
  | 'acknowledge'
  | 'view_acknowledgement'
  | 'complete'
  | 'view_bill'
  | 'settle'
  | 'view_settlement'
  | 'cancel'
  | 'view_history';

/** "Perbaikan Tenant Portal - Work Order Status External dan Workshop Invoice" — the tenant-facing
 * "Workshop Invoice" tracking entity, distinct from any WorkshopInvoiceItem (the pre-existing,
 * unrelated R1 externally-issued-invoice-recording feature). */
export interface ExternalWorkOrderInvoiceItem {
  id: string;
  tenant_id: string;
  branch_id: string;
  work_order_id: string;
  status: 'NEW_EXTERNAL_WO' | 'DELIVERED' | 'IN_PROGRESS' | 'CANCELLED' | 'BILLED' | 'PAID';
  work_authorization_status: 'NOT_GENERATED' | 'GENERATED' | 'ACKNOWLEDGED';
  wal_number: string | null;
  wal_issue_date: string | null;
  wal_workshop_partner_id: string | null;
  wal_workshop_name: string | null;
  wal_workshop_address: string | null;
  wal_workshop_pic: string | null;
  wal_workshop_phone: string | null;
  wal_vehicle_unit_number: string | null;
  wal_vehicle_registration_number: string | null;
  wal_vehicle_make_model: string | null;
  wal_vehicle_odometer: string | null;
  wal_company_name: string | null;
  wal_revision: number;
  wal_generated_at: string | null;
  delivered_at: string | null;
  acknowledged_at: string | null;
  vendor_invoice_date: string | null;
  vendor_invoice_amount: string | null;
  payment_term: string | null;
  completed_at: string | null;
  payment_date: string | null;
  paid_amount: string | null;
  settled_at: string | null;
  cancelled_at: string | null;
  cancellation_reason: string | null;
  allowed_actions: ExternalWorkOrderInvoiceAction[];
  work_order?: WorkOrderItem & { vehicle?: VehicleItem; workshop?: Workshop };
}

export interface WorkOrderExternalServiceItem {
  id: string;
  memo_number: string | null;
  work_order_id: string;
  partner_id: string;
  description: string;
  diagnosis: string | null;
  requested_parts_services: string | null;
  photo_evidence: string | null;
  condition_notes: string | null;
  priority: 'LOW' | 'MEDIUM' | 'HIGH' | 'URGENT' | null;
  reference_number: string | null;
  cost: string | null;
  status: 'REQUESTED' | 'COMPLETED' | 'CANCELLED' | 'BILLED' | 'PAID';
  workshop_invoice_id: string | null;
  requested_by: string | null;
  requested_at: string | null;
  completed_by: string | null;
  completed_at: string | null;
  cancelled_by: string | null;
  cancelled_at: string | null;
  partner?: PartnerItem;
}

/** R1: Workshop Invoice is issued EXTERNALLY by the Workshop Partner — OptiFleet records it, never issues it. */
export interface WorkshopInvoiceItem {
  id: string;
  work_order_external_service_id: string;
  work_order_id: string;
  partner_id: string;
  external_invoice_number: string;
  invoice_date: string;
  due_date: string | null;
  currency: string;
  subtotal: string | null;
  tax_total: string | null;
  discount_total: string | null;
  total_amount: string;
  line_items: { description: string; quantity?: string; unit_price?: string; line_total?: string }[] | null;
  partner_reference: string | null;
  returned_memo_attachment_url: string | null;
  invoice_attachment_url: string | null;
  notes: string | null;
  reconciliation_note: string | null;
  status: 'RECORDED' | 'CORRECTION_REQUESTED' | 'CANCELLATION_REQUESTED' | 'CANCELLED';
  received_by: string | null;
  received_at: string | null;
  partner?: PartnerItem;
  work_order?: WorkOrderItem;
  memo?: WorkOrderExternalServiceItem;
  payment?: WorkshopInvoicePaymentItem | null;
  corrections?: WorkshopInvoiceCorrectionItem[];
  cancellations?: WorkshopInvoiceCancellationItem[];
}

export interface WorkshopInvoicePaymentItem {
  id: string;
  workshop_invoice_id: string;
  payment_date: string;
  paid_amount: string;
  payment_method: string | null;
  reference_number: string | null;
  evidence_url: string;
  notes: string | null;
  uploaded_by: string;
  uploaded_at: string;
}

export interface WorkshopInvoiceCorrectionItem {
  id: string;
  workshop_invoice_id: string;
  previous_values: Record<string, unknown>;
  requested_values: Record<string, unknown>;
  reason: string;
  status: 'PENDING' | 'APPROVED' | 'REJECTED';
  requested_by: string;
  requested_at: string;
  decided_by: string | null;
  decided_at: string | null;
  decision_note: string | null;
}

export interface WorkshopInvoiceCancellationItem {
  id: string;
  workshop_invoice_id: string;
  reason: string;
  status: 'PENDING' | 'APPROVED' | 'REJECTED';
  requested_by: string;
  requested_at: string;
  decided_by: string | null;
  decided_at: string | null;
  decision_note: string | null;
}

export interface WorkshopInvoiceReconciliation {
  expected_amount: string | null;
  invoiced_amount: string;
  variance_amount: string | null;
  variance_percent: string | null;
  reconciliation_status: 'MATCHED' | 'VARIANCE' | 'NO_EXPECTED_AMOUNT';
  missing_source_records: string[];
  unmatched_line_items: string[];
  work_order_estimated_total_cost: string | null;
  reconciliation_note: string | null;
}

export interface WorkOrderAdditionalWorkItem {
  id: string;
  description: string;
  status: 'REQUESTED' | 'APPROVED' | 'REJECTED';
  decision_note: string | null;
}

export interface WorkOrderMechanicAssignmentItem {
  id: string;
  work_order_id: string;
  maintenance_job_id: string | null;
  worker_id: string;
  role: 'PRIMARY' | 'ASSISTANT';
  hourly_rate_snapshot: string | null;
  assigned_at: string;
  unassigned_at: string | null;
  worker?: WorkerItem;
}

export interface WorkOrderItem {
  id: string;
  wo_number: string;
  branch_id: string;
  workshop_id: string;
  workspace_id: string | null;
  vehicle_id: string;
  maintenance_request_id: string | null;
  maintenance_type: 'PREVENTIVE' | 'CORRECTIVE' | 'BREAKDOWN' | 'INSPECTION' | 'CAMPAIGN';
  priority: 'LOW' | 'MEDIUM' | 'HIGH' | 'URGENT';
  complaint: string | null;
  current_odometer: string | null;
  estimated_labor_cost: string | null;
  estimated_parts_cost: string | null;
  estimated_total_cost: string | null;
  estimated_by: string | null;
  estimated_at: string | null;
  result_summary: string | null;
  result_recorded_by: string | null;
  result_recorded_at: string | null;
  target_start_at: string | null;
  target_completion_at: string | null;
  started_at: string | null;
  completed_at: string | null;
  closed_at: string | null;
  status: 'DRAFT' | 'SUBMITTED' | 'APPROVED' | 'ASSIGNED' | 'SCHEDULED' | 'IN_PROGRESS' | 'ON_HOLD' | 'WAITING_PART' | 'EXTERNAL' | 'QC_PENDING' | 'REWORK' | 'COMPLETED' | 'CLOSED' | 'REJECTED' | 'CANCELLED';
  execution_mode: 'INTERNAL' | 'EXTERNAL';
  external_finalized_revision: number;
  cancellation_reason: string | null;
  estimated_labor_cost_computed?: string | null;
  estimated_total_hours?: string | null;
  estimated_number_of_mechanics?: number;
  estimated_parts_cost_computed?: string | null;
  vehicle?: VehicleItem;
  branch?: Branch;
  workshop?: Workshop;
  findings?: WorkOrderFindingItem[];
  diagnoses?: WorkOrderDiagnosisItem[];
  corrective_actions?: WorkOrderCorrectiveActionItem[];
  jobs?: MaintenanceJobItem[];
  planned_parts?: WorkOrderPlannedPartItem[];
  planned_part_estimates?: WorkOrderPlannedPartEstimateItem[];
  removed_components?: WorkOrderRemovedComponentItem[];
  additional_works?: WorkOrderAdditionalWorkItem[];
  mechanic_assignments?: WorkOrderMechanicAssignmentItem[];
  road_tests?: RoadTestItem[];
  vehicle_release?: VehicleReleaseItem | null;
  external_services?: WorkOrderExternalServiceItem[];
  workspace_reservations?: WorkspaceReservationItem[];
}

export interface WorkerSkillItem {
  id: string;
  component_group_id: string;
  skill_level: number | null;
  component_group?: ComponentGroup;
}

export interface WorkerItem {
  id: string;
  employee_code: string;
  name: string;
  branch_id: string;
  workshop_id: string | null;
  worker_type: 'LEAD_MECHANIC' | 'MECHANIC' | 'TECHNICIAN' | 'INSPECTOR' | 'QC';
  worker_type_id: string | null;
  status: 'ACTIVE' | 'INACTIVE';
  user_id: string | null;
  phone: string | null;
  email: string | null;
  address: string | null;
  monthly_rate: string | null;
  hourly_rate: string | null;
  photo_url: string | null;
  branch?: Branch;
  workshop?: Workshop;
  skills?: WorkerSkillItem[];
  active_job_count?: number;
  worker_type_master?: WorkerTypeItem;
}

export interface WorkspaceItem {
  id: string;
  workshop_id: string;
  code: string;
  name: string;
  workspace_type: 'GENERAL_SERVICE_BAY' | 'HEAVY_VEHICLE_BAY' | 'INSPECTION_BAY' | 'ELECTRICAL_BAY' | 'TIRE_BAY' | 'QC_BAY' | 'WASHING_BAY' | 'PARKING_LOT' | 'HOLDING_AREA' | 'OTHER';
  capacity: number | null;
  capacity_unit: string | null;
  status: 'AVAILABLE' | 'RESERVED' | 'OCCUPIED' | 'BLOCKED' | 'UNDER_MAINTENANCE' | 'INACTIVE';
  workshop?: Workshop;
  vehicle_categories?: VehicleCategory[];
}

export interface WorkspaceReservationItem {
  id: string;
  workspace_id: string;
  work_order_id: string | null;
  start_at: string;
  end_at: string;
  status: 'RESERVED' | 'ACTIVE' | 'COMPLETED' | 'CANCELLED';
  workspace?: WorkspaceItem;
  work_order?: WorkOrderItem;
}

export interface QcInspectionItem {
  id: string;
  work_order_id: string;
  inspector_worker_id: string | null;
  status: 'QC_PENDING' | 'QC_STARTED' | 'PASS' | 'FAIL' | 'COMPLETED';
  notes: string | null;
  findings?: { id: string; description: string; severity: string; resolved: boolean; resolved_by?: string | null; resolved_at?: string | null }[];
}

export interface RoadTestItem {
  id: string;
  work_order_id: string;
  tester_worker_id: string | null;
  start_odometer: string | null;
  end_odometer: string | null;
  duration_minutes: number | null;
  result: 'PASS' | 'FAIL' | 'NOT_REQUIRED';
  notes: string | null;
}

export interface VehicleReleaseItem {
  id: string;
  work_order_id: string;
  vehicle_id: string;
  released_at: string;
  release_odometer: string | null;
  release_condition: string | null;
  notes: string | null;
}

export interface HistoryEventItem {
  type: 'INSPECTION' | 'MAINTENANCE_REQUEST' | 'BREAKDOWN' | 'WORK_ORDER' | 'QC' | 'VEHICLE_RELEASE';
  id: string;
  at: string;
  summary: string;
  work_order_id?: string;
}

// --- Phase 4: Supply Chain & Asset Lifecycle ---

export type ItemType = 'SPARE_PART' | 'TOOL' | 'TIRE' | 'CONSUMABLE' | 'EQUIPMENT' | 'RIM' | 'OTHER';

export interface ProductCategoryItem {
  id: string;
  code: string;
  name: string;
  parent_id: string | null;
  item_type: ItemType | null;
  description: string | null;
  is_system: boolean;
  status: string;
  requires_specification_grade: boolean;
}

export interface UomItem {
  id: string;
  code: string;
  name: string;
  measure_type: 'LENGTH' | 'PACKAGING' | 'CAPACITY' | 'WEIGHT' | 'PRESSURE' | null;
  /** Measured unit (Liter, Kg…): fractional quantities allowed; counted units take whole numbers. */
  allows_fractional_quantity?: boolean;
  description: string | null;
  is_system: boolean;
  status: string;
}

export interface ProductCompatibilityItem {
  id: string;
  product_id: string;
  component_group_id: string | null;
  vehicle_category_id: string | null;
  /** Vehicle Brand / Model master ids; vehicle_brand / vehicle_model keep the name snapshot (or legacy text). */
  vehicle_brand_id?: string | null;
  vehicle_model_id?: string | null;
  vehicle_brand: string | null;
  vehicle_model: string | null;
  variant: string | null;
  year_from: number | null;
  year_to: number | null;
  position: string | null;
  component_group?: ComponentGroup;
  vehicle_category?: VehicleCategory;
  brand_master?: { id: string; name: string; status: string; deleted_at: string | null } | null;
  model_master?: { id: string; vehicle_brand_id: string; name: string; status: string; deleted_at: string | null } | null;
}

export interface WarehouseZoneItem {
  id: string;
  warehouse_id: string;
  code: string;
  name: string;
  status: string;
}

export interface WarehouseRackItem {
  id: string;
  warehouse_zone_id: string;
  code: string;
  name: string;
  status: string;
}

export interface WarehouseBinItem {
  id: string;
  warehouse_rack_id: string;
  code: string;
  name: string;
  status: string;
}

export interface ToolTypeItem {
  id: string;
  code: string;
  name: string;
  is_system: boolean;
  status: string;
}

export interface EquipmentTypeItem {
  id: string;
  code: string;
  name: string;
  is_system: boolean;
  status: string;
}

export interface StorageRequirementItem {
  id: string;
  code: string;
  name: string;
  is_system: boolean;
  status: string;
}

export interface WorkerTypeItem {
  id: string;
  code: string;
  name: string;
  is_system: boolean;
  status: string;
}

export interface TireLoadIndexItem {
  id: string;
  code: string;
  max_load_single_kg: string | null;
  max_load_dual_kg: string | null;
  status: string;
}

export interface TireSpeedRatingItem {
  id: string;
  code: string;
  max_speed_kmh: string | null;
  status: string;
}

export interface TirePlyRatingItem {
  id: string;
  code: string;
  load_range: string | null;
  status: string;
}

export interface TireTraCodeItem {
  id: string;
  code: string;
  profile: string | null;
  status: string;
}

export interface TireTraStarRatingItem {
  id: string;
  tra_code_id: string;
  star_rating: string;
  purpose: string | null;
}

export interface ProductSparepartSpecItem {
  part_number: string;
  part_type: 'GENUINE' | 'OEM' | 'OES' | 'AFTERMARKET';
  oem_part_number: string | null;
  alternate_part_numbers: string[] | null;
  specification: string | null;
  applicable_position: string[] | null;
  critical_part: boolean | null;
  warranty_period_value: number | null;
  warranty_period_unit: string | null;
  warranty_mileage_km: number | null;
  shelf_life_value: number | null;
  shelf_life_unit: string | null;
}

export interface ProductConsumableSpecItem {
  grade_specification: string | null;
  package_size_value: string | null;
  package_size_uom_id: string | null;
  purchase_uom_id: string | null;
  conversion_to_base_uom: string | null;
  issue_uom_id: string | null;
  track_expiry: boolean;
  shelf_life_value: number | null;
  shelf_life_unit: string | null;
  is_hazardous: boolean;
  sds_file_path: string | null;
  sds_original_filename: string | null;
  storage_requirements?: StorageRequirementItem[];
}

export interface ProductRimSpecItem {
  model: string | null;
  rim_type: 'STEEL' | 'ALLOY' | 'FORGED';
  diameter_inch: string;
  width_inch: string;
  bolt_holes: number;
  pcd_mm: string;
  center_bore_mm: string | null;
  offset_mm: string | null;
  material: string | null;
  max_load_kg: string | null;
  compatible_tire_sizes: string[] | null;
}

export interface ProductTireSpecItem {
  vehicle_group: 'CAR' | 'TRUCK_BUS';
  pattern_name: string;
  width_mm: number;
  aspect_ratio_percent: number;
  construction_type: 'RADIAL' | 'BIAS';
  rim_diameter_inch: string;
  tire_type: 'TUBELESS' | 'TUBE_TYPE';
  single_load_index_id: string;
  speed_rating_id: string;
  dual_load_index_id: string | null;
  ply_rating_id: string | null;
  tra_code_id: string | null;
  tra_star_rating_id: string | null;
  tire_size_computed: string | null;
  single_max_load_kg_computed: string | null;
  max_speed_kmh_computed: string | null;
  dual_max_load_kg_computed: string | null;
  load_range_computed: string | null;
  tra_profile_computed: string | null;
  purpose_computed: string | null;
}

export interface ProductToolSpecItem {
  model: string | null;
  tool_type_id: string;
  specification: string | null;
  checkout_required: boolean;
  calibration_required: boolean;
  calibration_interval_value: number | null;
  calibration_interval_unit: string | null;
  maintenance_required: boolean;
  maintenance_interval_value: number | null;
  maintenance_interval_unit: string | null;
  tool_type?: ToolTypeItem;
}

export interface ProductEquipmentSpecItem {
  model: string;
  equipment_type_id: string;
  specification: string | null;
  capacity_value: string | null;
  capacity_uom_id: string | null;
  power_source: string | null;
  power_rating_value: string | null;
  power_rating_unit: string | null;
  voltage_v: number | null;
  maintenance_required: boolean;
  maintenance_interval_value: number | null;
  maintenance_interval_unit: string | null;
  inspection_required: boolean;
  inspection_interval_value: number | null;
  inspection_interval_unit: string | null;
  calibration_required: boolean;
  calibration_interval_value: number | null;
  calibration_interval_unit: string | null;
  certification_required: boolean | null;
  certification_type: string | null;
  equipment_type?: EquipmentTypeItem;
}

export interface ProductItem {
  id: string;
  code: string;
  sku: string;
  name: string;
  product_category_id: string;
  product_type: ItemType;
  uom_id: string;
  default_storage_bin_id: string | null;
  description: string | null;
  brand: string | null;
  manufacturer: string | null;
  material: string | null;
  production_year: number | null;
  weight_kg: string | null;
  length_mm: string | null;
  width_mm: string | null;
  height_mm: string | null;
  image_url: string | null;
  image_path: string | null;
  image_original_filename: string | null;
  manufacturer_part_number: string | null;
  track_serial_number: boolean;
  track_batch: boolean;
  is_system: boolean;
  status: 'ACTIVE' | 'INACTIVE';
  /** Phase F / BD-3: "KTN" source for TIRE products — required before a tire can be scored. */
  reference_tread_depth_mm: string | null;
  category?: ProductCategoryItem;
  uom?: UomItem;
  default_storage_bin?: WarehouseBinItem;
  component_groups?: ComponentGroup[];
  compatibilities?: ProductCompatibilityItem[];
  /** Mechanical classification (Component Group -> Category -> Subcategory); independent of `category` above. */
  component_group_id?: string | null;
  component_category_id?: string | null;
  component_subcategory_id?: string | null;
  component_group?: ComponentGroup | null;
  component_category?: ComponentCategory | null;
  component_subcategory?: ComponentSubcategory | null;
  sparepart_spec?: ProductSparepartSpecItem;
  consumable_spec?: ProductConsumableSpecItem;
  rim_spec?: ProductRimSpecItem;
  tire_spec?: ProductTireSpecItem;
  tool_spec?: ProductToolSpecItem;
  equipment_spec?: ProductEquipmentSpecItem;
}

export interface WarehouseStockItem {
  id: string;
  tenant_id: string;
  warehouse_id: string;
  product_id: string;
  quantity_on_hand: string;
  quantity_reserved: string;
  quantity_available: number;
  minimum_stock: string;
  maximum_stock: string | null;
  reorder_point: string;
  average_unit_cost: string;
  reorder_status: 'HEALTHY' | 'LOW_STOCK' | 'REORDER_REQUIRED' | 'OUT_OF_STOCK';
  warehouse?: Warehouse;
  product?: ProductItem;
}

export interface StockMovementItem {
  id: string;
  warehouse_id: string;
  product_id: string;
  movement_type: 'OPENING' | 'RECEIPT' | 'RESERVATION' | 'RELEASE_RESERVATION' | 'ISSUE' | 'RETURN' | 'TRANSFER_OUT' | 'TRANSFER_IN' | 'ADJUSTMENT_PLUS' | 'ADJUSTMENT_MINUS' | 'STOCK_OPNAME' | 'SCRAP' | 'CONSUME' | 'SALE';
  quantity: string;
  unit_cost: string | null;
  reference_type: string | null;
  reference_id: string | null;
  occurred_at: string;
  reason: string | null;
  warehouse?: Warehouse;
  product?: ProductItem;
}

/**
 * One record per returned / removed part, in one of three explicit lifecycles (return_source):
 * NEW_PART (Return + Returned Parts Processing), REMOVED_COMPONENT and legacy USED_PART
 * (Used Sparepart Processing).
 */
export interface WorkOrderPartReturnItem {
  id: string;
  return_number: string | null;
  return_source: 'NEW_PART' | 'REMOVED_COMPONENT' | 'USED_PART';
  work_order_id: string | null;
  work_order_planned_part_id: string | null;
  work_order_removed_component_id: string | null;
  warehouse_id: string | null;
  product_id: string;
  quantity: string;
  /** Reported condition (as declared on the Work Order). */
  condition: 'UNUSED_NEW' | 'UNUSED_FAULTY' | 'USED_GOOD' | 'USED_FAULTY';
  /** Returned Parts Processing (NEW_PART): what the inspector found. */
  actual_condition: 'UNUSED_NEW' | 'UNUSED_FAULTY' | null;
  inspection_result: 'MATCH' | 'MISMATCH' | null;
  disposition_status:
    | 'PENDING_PROCESSING' | 'RESTOCKED' | 'QUARANTINED' | 'WARRANTY_CLAIM' | 'REPAIR' | 'SCRAP'
    | 'PENDING_RETURN' | 'PENDING_INSPECTION' | 'INSPECTED' | 'PENDING_APPROVAL' | 'REJECTED' | 'FINALIZED';
  accepted_quantity: string | null;
  returned_by: string | null;
  inspected_by: string | null;
  inspected_at: string | null;
  inspection_notes: string | null;
  inspection_evidence: string | null;
  disposition: 'REPAIR' | 'REUSE' | 'QUARANTINE' | 'SCRAP' | 'SELL_ELIGIBLE' | null;
  disposition_reason: string | null;
  proposed_by: string | null;
  finalized_at: string | null;
  /** Repair → Reuse: set when a finalized REPAIR was completed (item back to INSPECTED). */
  repair_completed_at?: string | null;
  repair_notes?: string | null;
  reason: string | null;
  evidence: string | null;
  created_at: string;
  product?: ProductItem;
  warehouse?: { id: string; code?: string; name: string } | null;
  work_order?: { id: string; wo_number: string; vehicle?: { id: string; registration_number: string } | null } | null;
  planned_part?: { id: string; description: string; work_order?: { id: string; wo_number: string } };
  removed_component?: { id: string; removed_by: string | null; removed_at: string; condition: 'GOOD' | 'FAULTY'; notes: string | null; status: string } | null;
  returner?: { id: string; name: string } | null;
  inspector?: { id: string; name: string } | null;
  /** Faulty (QUARANTINED) new-part return routed to its follow-up disposition. */
  routed_by?: string | null;
  routed_at?: string | null;
  router?: { id: string; name: string } | null;
  remaining_eligible_quantity?: number;
}

export interface SparePartSaleItem {
  id: string;
  work_order_part_return_id: string;
  product_id: string;
  warehouse_id: string;
  quantity: string;
  sale_type: 'OPERATIONAL_REUSE' | 'SCRAP_MATERIAL';
  buyer_type: 'PARTNER' | 'EXTERNAL';
  partner_id: string | null;
  buyer_name: string | null;
  unit_price: string;
  total_amount: string;
  status: 'DRAFT' | 'PENDING_APPROVAL' | 'APPROVED' | 'REJECTED' | 'CANCELLED';
  requested_by: string | null;
  rejection_reason: string | null;
  notes: string | null;
  created_at: string;
  product?: ProductItem;
  warehouse?: Warehouse;
  partner?: { id: string; name: string };
}

export interface StockOpnameItemLine {
  id: string;
  product_id: string;
  system_quantity: string;
  physical_quantity: string | null;
  notes: string | null;
  product?: ProductItem;
}

export interface StockOpnameItem {
  id: string;
  opname_number: string;
  warehouse_id: string;
  status: 'DRAFT' | 'COUNTING' | 'SUBMITTED' | 'APPROVED' | 'POSTED';
  warehouse?: Warehouse;
  items?: StockOpnameItemLine[];
}

export interface StockTransferItemLine {
  id: string;
  product_id: string;
  quantity_sent: string;
  quantity_received: string | null;
  quantity_damaged: string | null;
  quantity_lost: string | null;
  unit_cost: string | null;
  discrepancy_reason: string | null;
  product?: ProductItem;
}

export interface StockTransferItem {
  id: string;
  transfer_number: string;
  from_warehouse_id: string;
  to_warehouse_id: string;
  status: 'DRAFT' | 'REQUESTED' | 'APPROVED' | 'PREPARED' | 'DISPATCHED' | 'IN_TRANSIT' | 'RECEIVED' | 'COMPLETED' | 'REJECTED' | 'CANCELLED';
  dispatched_at: string | null;
  dispatched_by: string | null;
  received_at: string | null;
  received_by: string | null;
  from_warehouse?: Warehouse;
  to_warehouse?: Warehouse;
  items?: StockTransferItemLine[];
}

export interface PartnerPerformanceSummary {
  purchase_orders_issued: number;
  deliveries_on_time: number;
  deliveries_late: number;
  on_time_rate: number | null;
  quantity_accepted: number;
  quantity_rejected: number;
  total_purchase_value: number;
  returns: number;
}

export interface PartnerItem {
  id: string;
  code: string;
  name: string;
  partner_type: 'SUPPLIER' | 'SPARE_PART_SUPPLIER' | 'TIRE_SUPPLIER' | 'EXTERNAL_WORKSHOP' | 'TOWING_PROVIDER' | 'OTHER_SERVICE_PROVIDER';
  contact_name: string | null;
  contact_phone: string | null;
  contact_email: string | null;
  address: string | null;
  province: string | null;
  city: string | null;
  tax_id: string | null;
  payment_terms: string | null;
  bank: string | null;
  account_holder: string | null;
  account_number: string | null;
  description: string | null;
  status: 'ACTIVE' | 'INACTIVE';
  performance?: PartnerPerformanceSummary;
}

export interface PurchaseRequestItemLine {
  id: string;
  product_id: string;
  requested_quantity: string;
  estimated_unit_price: string | null;
  notes: string | null;
  line_status: 'PENDING' | 'APPROVED' | 'ON_HOLD' | 'REJECTED';
  line_reason: string | null;
  product?: ProductItem;
}

export interface PurchaseRequestItem {
  id: string;
  pr_number: string;
  warehouse_id: string;
  source_type: 'MANUAL' | 'WORK_ORDER' | 'REORDER_POINT' | 'STOCK_PLANNING';
  work_order_id: string | null;
  priority: 'LOW' | 'MEDIUM' | 'HIGH' | 'URGENT';
  status: 'DRAFT' | 'SUBMITTED' | 'UNDER_REVIEW' | 'APPROVED' | 'PROCUREMENT' | 'REJECTED' | 'CANCELLED';
  required_date: string | null;
  notes: string | null;
  warehouse?: Warehouse;
  work_order?: WorkOrderItem;
  items?: PurchaseRequestItemLine[];
}

export interface RfqItemLine {
  id: string;
  product_id: string;
  quantity: string;
  product?: ProductItem;
}

export interface RfqItem {
  id: string;
  rfq_number: string;
  warehouse_id: string;
  purchase_request_id: string | null;
  status: 'DRAFT' | 'ISSUED' | 'CLOSED' | 'CANCELLED';
  issue_date: string | null;
  response_deadline: string | null;
  warehouse?: Warehouse;
  items?: RfqItemLine[];
  vendors?: PartnerItem[];
  quotations?: VendorQuotationItem[];
}

export interface VendorQuotationItemLine {
  id: string;
  product_id: string;
  quantity: string;
  unit_price: string;
  discount_percent: string;
  tax_percent: string;
  line_total: string;
  product?: ProductItem;
}

export interface VendorQuotationItem {
  id: string;
  rfq_id: string;
  rfq?: { id: string; rfq_number: string; warehouse_id?: string; status?: string } | null;
  partner_id: string;
  status: 'SUBMITTED' | 'SELECTED' | 'REJECTED';
  lead_time_days: number | null;
  payment_terms: string | null;
  freight_cost: string | null;
  subtotal: string;
  tax_total: string;
  total: string;
  validity_date: string | null;
  partner?: PartnerItem;
  items?: VendorQuotationItemLine[];
}

export interface PurchaseOrderItemLine {
  id: string;
  product_id: string;
  quantity_ordered: string;
  quantity_received: string;
  unit_price: string;
  discount_percent: string;
  tax_percent: string;
  line_total: string;
  product?: ProductItem;
}

export interface WorkflowApprovalStepItem {
  id: string;
  step_number: number;
  approver_type: string;
  approver_identifier: string;
  status: 'PENDING' | 'APPROVED' | 'REJECTED' | 'SKIPPED';
  decided_by: string | null;
  decided_at: string | null;
  note: string | null;
}

export interface WorkflowApprovalRequestItem {
  id: string;
  status: 'PENDING' | 'APPROVED' | 'REJECTED';
  requested_by: string | null;
  steps?: WorkflowApprovalStepItem[];
}

export interface PurchaseOrderItem {
  id: string;
  po_number: string;
  partner_id: string;
  delivery_warehouse_id: string;
  status: 'DRAFT' | 'SUBMITTED' | 'PENDING_APPROVAL' | 'APPROVED' | 'ISSUED' | 'PARTIALLY_RECEIVED' | 'RECEIVED' | 'CLOSED' | 'REJECTED' | 'CANCELLED';
  order_date: string | null;
  expected_delivery_date: string | null;
  subtotal: string;
  tax_total: string;
  freight_cost: string;
  total: string;
  partner?: PartnerItem;
  delivery_warehouse?: Warehouse;
  items?: PurchaseOrderItemLine[];
  goods_receipts?: GoodsReceiptItem[];
  workflow_approval_request?: WorkflowApprovalRequestItem;
}

export interface GoodsReceiptItemLine {
  id: string;
  purchase_order_item_id: string;
  product_id: string;
  quantity_accepted: string;
  quantity_rejected: string;
  quantity_damaged: string;
  batch_number: string | null;
  unit_cost: string;
  product?: ProductItem;
}

export interface GoodsReceiptItem {
  id: string;
  gr_number: string;
  purchase_order_id: string;
  warehouse_id: string;
  partner_id: string;
  status: 'DRAFT' | 'POSTED';
  received_at: string | null;
  notes: string | null;
  vendor_invoice_reference_id: string | null;
  warehouse?: Warehouse;
  partner?: PartnerItem;
  purchase_order?: PurchaseOrderItem;
  items?: GoodsReceiptItemLine[];
  receiver?: { id: string; name: string } | null;
  vendor_invoice_reference?: VendorInvoiceReferenceItem | null;
}

export interface VendorInvoiceReferenceItem {
  id: string;
  partner_id: string;
  purchase_order_id: string | null;
  goods_receipt_id: string | null;
  vendor_invoice_number: string;
  vendor_invoice_date: string | null;
  amount: string | null;
  /** Working days. */
  terms_of_payment_days: number | null;
  due_date: string | null;
  origin: 'MANUAL' | 'GOODS_RECEIPT';
  status: 'RECEIVED' | 'VERIFIED' | 'DISPUTED';
  notes: string | null;
  has_document: boolean;
  attachment_original_name: string | null;
  partner?: PartnerItem;
  purchase_order?: PurchaseOrderItem;
}

export type VendorInvoiceStatus = 'NEW' | 'DUE_SOON' | 'LATE' | 'PAID';

/** An invoice as listed on Vendor Invoice References (status derived by the backend). */
export interface VendorInvoiceSummary {
  id: string;
  vendor_invoice_number: string;
  vendor_invoice_date: string | null;
  amount: string | null;
  terms_of_payment_days: number | null;
  due_date: string | null;
  has_document: boolean;
  attachment_original_name: string | null;
  partner: { id: string; name: string } | null;
  status: VendorInvoiceStatus;
}

/** One Vendor Invoice References row = one Goods Receipt and the invoice it was received against. */
export interface VendorInvoiceReceiptRow {
  id: string;
  gr_number: string;
  received_at: string | null;
  purchase_order: { id: string; po_number: string } | null;
  invoice: VendorInvoiceSummary;
}

export interface RimItem {
  id: string;
  code: string;
  brand: string;
  material: string | null;
  width_inch: string | null;
  diameter_inch: string | null;
  disc_thickness_mm: string | null;
  offset_mm: string | null;
  bolt_holes: number | null;
  bolt_diameter_mm: string | null;
  pcd_mm: string | null;
  hub_hole_diameter_mm: string | null;
  status: 'ACTIVE' | 'INACTIVE';
}

export interface WheelConfigurationItem {
  id: string;
  tenant_id: string | null;
  vehicle_category_id: string;
  position_code: string;
  label: string;
  axle_number: number | null;
  sequence: number | null;
  vehicle_category?: VehicleCategory;
}

export interface TireInstallationItem {
  id: string;
  tire_id: string;
  vehicle_id: string;
  wheel_position: string;
  installed_at: string;
  installation_date_source: 'KNOWN' | 'ESTIMATED' | 'UNKNOWN';
  installation_odometer: string | null;
  removed_at: string | null;
  vehicle?: VehicleItem;
}

export interface TireRotationItem {
  id: string;
  tire_id: string;
  from_position: string | null;
  to_position: string;
  odometer: string | null;
  occurred_at: string;
}

export interface TireInspectionItem {
  id: string;
  tire_id: string;
  tread_depth_mm: string | null;
  pressure_psi: string | null;
  condition: string | null;
  damage: string | null;
  recommendation: string | null;
  inspected_at: string;
}

export interface TireRemovalItem {
  id: string;
  tire_id: string;
  removal_odometer: string | null;
  removal_reason: string;
  disposition: 'REUSE' | 'RETREAD' | 'REPAIR' | 'SCRAP';
  removed_at: string;
  replaced_by_tire_id: string | null;
}

/** Phase E: shared send -> receive -> final-inspect -> approve governance shape for both cycle types. */
export type TireServiceCycleStatus = 'SENT' | 'RECEIVED' | 'FINAL_INSPECTED' | 'APPROVED' | 'REJECTED';
export type TireServiceCycleApprovalDisposition = 'RETURN_TO_SERVICE' | 'SCRAP' | 'QUARANTINE';

export interface TireRetreadItem {
  id: string;
  tire_id: string;
  cycle_number: number;
  sent_at: string;
  sent_by: string | null;
  received_at: string | null;
  received_by: string | null;
  partner_id: string | null;
  cost: string | null;
  notes: string | null;
  status: TireServiceCycleStatus;
  final_inspected_by: string | null;
  final_inspected_at: string | null;
  final_inspection_result: 'SAFE' | 'UNSAFE' | null;
  final_inspection_notes: string | null;
  approved_by: string | null;
  approved_at: string | null;
  approval_disposition: TireServiceCycleApprovalDisposition | null;
  approval_reason: string | null;
}

/** Phase E (G-27): distinct REPAIR lifecycle — same governance shape as retread, separate table. */
export type TireRepairItem = TireRetreadItem;

export interface TireItem {
  id: string;
  product_id: string;
  serial_number: string;
  manufacturer: string | null;
  manufacture_date_code: string | null;
  tire_size: string | null;
  pattern: string | null;
  construction_type: 'RADIAL' | 'BIAS' | null;
  tube_type: 'TUBELESS' | 'TUBE' | null;
  section_width_mm: number | null;
  aspect_ratio: number | null;
  rim_diameter_inch: string | null;
  load_index: number | null;
  speed_rating: string | null;
  ply_rating: number | null;
  purchase_date: string | null;
  purchase_cost: string | null;
  warranty_months: number | null;
  warranty_km: number | null;
  current_status: 'IN_STOCK' | 'RESERVED' | 'INSTALLED' | 'IN_USE' | 'REMOVED' | 'UNDER_INSPECTION' | 'RETREAD' | 'REPAIR' | 'QUARANTINED' | 'SCRAPPED' | 'SOLD' | 'LOST';
  current_vehicle_id: string | null;
  current_position: string | null;
  current_warehouse_id: string | null;
  product?: ProductItem;
  current_vehicle?: VehicleItem;
  current_warehouse?: Warehouse;
  installations?: TireInstallationItem[];
  rotations?: TireRotationItem[];
  inspections?: TireInspectionItem[];
  removals?: TireRemovalItem[];
  retreads?: TireRetreadItem[];
  repairs?: TireRepairItem[];
  scoringResults?: TireScoringResultItem[];
  sales?: TireSaleItem[];
}

/** Phase F (G-31): one structured scoring calculation. */
export interface TireScoringResultItem {
  id: string;
  tire_id: string;
  tire_inspection_id: string;
  tire_retread_id: string | null;
  tire_repair_id: string | null;
  scoring_type: 'REPAIR' | 'RETREAD';
  configuration_version_id: string;
  reference_tread_depth_mm: string;
  measured_tread_depth_mm: string;
  spa_raw_percent: string;
  spa_normalized_score: string;
  classification: string;
  ka_score: string | null;
  kf_score: string | null;
  critical_safety_fail: boolean;
  critical_safety_reasons: string | null;
  eligible_for_operational_reuse: boolean;
  computed_by: string | null;
  computed_at: string;
  finalized_by: string | null;
  finalized_at: string | null;
}

/** Phase F (BD-5): a tire's terminal sell disposition. */
export interface TireSaleItem {
  id: string;
  tire_id: string;
  sell_type: 'SELL_FOR_OPERATIONAL_REUSE' | 'SELL_AS_RETREADABLE_CASING' | 'SELL_AS_SCRAP_OR_RECYCLABLE_MATERIAL';
  tire_scoring_result_id: string | null;
  reason: string;
  sold_by: string | null;
  sold_at: string;
}

export interface ComponentInstallationItem {
  id: string;
  component_asset_id: string;
  vehicle_id: string;
  position_location: string | null;
  installed_at: string;
  installation_odometer: string | null;
  removed_at: string | null;
  vehicle?: VehicleItem;
}

export interface ComponentRemovalItem {
  id: string;
  component_asset_id: string;
  removal_odometer: string | null;
  removal_reason: string;
  disposition: 'REUSE' | 'REPAIR' | 'SCRAP';
  diagnosis_note: string | null;
  removed_at: string;
  replaced_by_asset_id: string | null;
}

export interface ComponentRepairItem {
  id: string;
  component_asset_id: string;
  description: string;
  started_at: string;
  completed_at: string | null;
  outcome: 'RECONDITIONED' | 'SCRAPPED' | 'RETURNED_TO_SERVICE' | null;
  cost: string | null;
}

export interface ComponentAssetItem {
  id: string;
  product_id: string | null;
  component_group_id: string | null;
  serial_number: string | null;
  asset_number: string | null;
  purchase_date: string | null;
  purchase_cost: string | null;
  current_status: 'IN_STOCK' | 'INSTALLED' | 'ACTIVE' | 'FAILED' | 'REMOVED' | 'UNDER_REPAIR' | 'RECONDITIONED' | 'SCRAPPED';
  current_vehicle_id: string | null;
  product?: ProductItem;
  component_group?: ComponentGroup;
  current_vehicle?: VehicleItem;
  installations?: ComponentInstallationItem[];
  removals?: ComponentRemovalItem[];
  repairs?: ComponentRepairItem[];
}

export interface WarrantyItem {
  id: string;
  coverage_basis: 'DATE' | 'MILEAGE' | 'ENGINE_HOUR' | 'COMBINATION';
  duration_months: number | null;
  duration_km: number | null;
  duration_engine_hours: number | null;
  starts_at: string;
  partner_id: string | null;
  product_id: string | null;
  component_asset_id: string | null;
  tire_id: string | null;
  status: 'ACTIVE' | 'EXPIRED' | 'VOID';
  notes: string | null;
  partner?: PartnerItem;
  product?: ProductItem;
}

export interface WarrantyClaimItem {
  id: string;
  claim_number: string;
  warranty_id: string | null;
  partner_id: string | null;
  vehicle_id: string;
  component_asset_id: string | null;
  tire_id: string | null;
  failure_date: string;
  failure_odometer: string | null;
  claim_amount: string | null;
  reason: string;
  status: 'DRAFT' | 'SUBMITTED' | 'UNDER_REVIEW' | 'APPROVED' | 'REJECTED' | 'REPLACEMENT' | 'REPAIR' | 'SETTLED' | 'CLOSED';
  review_note: string | null;
  vehicle?: VehicleItem;
  partner?: PartnerItem;
  warranty?: WarrantyItem;
}

// --- Phase 5: Tenant Configuration & Business Rules ---

export type ConfigurationType = 'NUMBERING' | 'TEMPLATE' | 'WORKFLOW' | 'NOTIFICATION' | 'TIRE_SCORING';
export type ConfigurationVersionStatus = 'DRAFT' | 'PUBLISHED' | 'ARCHIVED';

export interface ConfigurationVersionItem {
  id: string;
  configuration_set_id: string;
  version_number: number;
  status: ConfigurationVersionStatus;
  payload: Record<string, unknown>;
  change_summary: string | null;
  created_by: string | null;
  published_by: string | null;
  published_at: string | null;
  archived_at: string | null;
}

export interface ConfigurationSetItem {
  id: string;
  tenant_id: string | null;
  type: ConfigurationType;
  code: string;
  scope_type: string;
  name: string;
  is_system: boolean;
  versions: ConfigurationVersionItem[];
}

export interface ConfigurationHistoryRow {
  id: string;
  type: ConfigurationType;
  code: string;
  name: string;
  version_number: number;
  status: ConfigurationVersionStatus;
  created_by: string | null;
  published_by: string | null;
  published_at: string | null;
  archived_at: string | null;
  change_summary: string | null;
}

export interface NotificationRuleItem {
  id: string;
  tenant_id: string | null;
  event_code: string;
  name: string;
  is_active: boolean;
  is_system: boolean;
  condition_set: Record<string, unknown> | null;
  recipient_rules: Array<{ type: string; identifier?: string }>;
  channels: string[];
  escalation: Record<string, unknown> | null;
}

export interface NotificationEventInfo {
  code: string;
  platform_locked: boolean;
  variables: { scalars: string[]; sections: Record<string, string[]> };
}
