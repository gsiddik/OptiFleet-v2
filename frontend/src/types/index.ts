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
