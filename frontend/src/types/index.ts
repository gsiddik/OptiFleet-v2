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

export interface ComponentGroup {
  id: string;
  tenant_id: string | null;
  code: string;
  name: string;
  parent_id: string | null;
  sequence: number;
  description: string | null;
  is_system: boolean;
  status: 'ACTIVE' | 'INACTIVE';
}

export interface RoleItem {
  id: string;
  name: string;
  scope: 'platform' | 'tenant';
  is_system: boolean;
  permissions: string[];
}

export interface PermissionItem {
  id: string;
  name: string;
  group: string;
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
  model: string;
  vehicle_type: string | null;
  registration_number: string;
  vin: string | null;
  chassis_number: string | null;
  engine_number: string | null;
  year: number | null;
  fuel_type: string | null;
  transmission_type: string | null;
  current_odometer: string;
  engine_hour: string | null;
  status: 'ACTIVE' | 'IN_MAINTENANCE' | 'BREAKDOWN' | 'OUT_OF_SERVICE' | 'INACTIVE' | 'DISPOSED';
  operational_status: 'AVAILABLE' | 'IN_USE' | 'ON_HOLD';
  branch?: Branch;
  default_workshop?: Workshop;
  vehicle_category?: VehicleCategory;
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
  vehicle?: VehicleItem;
  template?: InspectionTemplateItem;
  results?: InspectionResultItem[];
  findings?: InspectionFindingItem[];
}

export interface MaintenancePackageItemRow {
  id: string;
  component_group_id: string | null;
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
  maintenance_type: 'PREVENTIVE' | 'CORRECTIVE' | 'BREAKDOWN' | 'INSPECTION' | 'CAMPAIGN';
  description: string | null;
  standard_labor_hours: string | null;
  status: 'DRAFT' | 'ACTIVE' | 'ARCHIVED';
  items?: MaintenancePackageItemRow[];
  intervals?: MaintenanceIntervalItem[];
}

export interface MaintenanceScheduleItem {
  id: string;
  vehicle_id: string;
  maintenance_package_id: string;
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
  source_type: 'USER' | 'INSPECTION' | 'SCHEDULE' | 'BREAKDOWN' | 'TELEMATICS' | 'MECHANIC';
  priority: 'LOW' | 'MEDIUM' | 'HIGH' | 'URGENT';
  complaint: string;
  status: 'DRAFT' | 'SUBMITTED' | 'UNDER_REVIEW' | 'APPROVED' | 'WORK_ORDER_CREATED' | 'REJECTED' | 'NEED_INFORMATION' | 'CANCELLED';
  review_note: string | null;
  work_order_id: string | null;
  vehicle?: VehicleItem;
  branch?: Branch;
  workshop?: Workshop;
  component_group?: ComponentGroup;
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
  labor_logs?: WorkOrderLaborLogItem[];
}

export interface WorkOrderPlannedPartItem {
  id: string;
  maintenance_job_id: string | null;
  product_reference: string | null;
  description: string;
  quantity: string;
  notes: string | null;
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
  target_start_at: string | null;
  target_completion_at: string | null;
  started_at: string | null;
  completed_at: string | null;
  closed_at: string | null;
  status: 'DRAFT' | 'SUBMITTED' | 'APPROVED' | 'ASSIGNED' | 'SCHEDULED' | 'IN_PROGRESS' | 'ON_HOLD' | 'WAITING_PART' | 'QC_PENDING' | 'REWORK' | 'COMPLETED' | 'CLOSED' | 'REJECTED' | 'CANCELLED';
  vehicle?: VehicleItem;
  branch?: Branch;
  workshop?: Workshop;
  findings?: WorkOrderFindingItem[];
  diagnoses?: WorkOrderDiagnosisItem[];
  corrective_actions?: WorkOrderCorrectiveActionItem[];
  jobs?: MaintenanceJobItem[];
  planned_parts?: WorkOrderPlannedPartItem[];
  additional_works?: WorkOrderAdditionalWorkItem[];
  mechanic_assignments?: WorkOrderMechanicAssignmentItem[];
  road_tests?: RoadTestItem[];
  vehicle_release?: VehicleReleaseItem | null;
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
  status: 'ACTIVE' | 'INACTIVE';
  branch?: Branch;
  workshop?: Workshop;
  skills?: WorkerSkillItem[];
  active_job_count?: number;
}

export interface WorkspaceItem {
  id: string;
  workshop_id: string;
  code: string;
  name: string;
  workspace_type: 'GENERAL_SERVICE_BAY' | 'HEAVY_VEHICLE_BAY' | 'INSPECTION_BAY' | 'ELECTRICAL_BAY' | 'TIRE_BAY' | 'QC_BAY' | 'WASHING_BAY' | 'PARKING_LOT' | 'HOLDING_AREA' | 'OTHER';
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
  findings?: { id: string; description: string; severity: string; resolved: boolean }[];
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
