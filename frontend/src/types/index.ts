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
