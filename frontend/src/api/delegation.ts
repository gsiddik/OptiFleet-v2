/**
 * Users and roles of a centrally governed tenant, through OptiFleet's delegation API (which calls the OptiNexus delegation API
 * with the signed-in person as the acting user). Nothing here decides anything: the backend (and OptiNexus behind it) is the
 * authority, this module only types the answers and classifies failures for the screens.
 */
import { apiClient, extractApiError } from './client';
import { classifyFailure, type FailureKind } from './failureKind';

export interface DelegatedRole {
  id: string;
  code: string | null;
  name: string;
  description: string | null;
  /** Provided by the platform operator for every organization. */
  template: boolean;
  /** Created through delegation for this organization: can be renamed, changed and deleted here. */
  editable: boolean;
  /** Permission keys (`optifleet.<permission>`). */
  permissions: string[];
}

export interface DelegatedUser {
  /** The OptiNexus user id. */
  id: string;
  name: string | null;
  email: string | null;
  status: string | null;
  membership_status: string | null;
  is_tenant_admin: boolean;
  application_access: string | null;
  roles: DelegatedRole[];
  /** The local membership once the person has signed in here; used for the local data scope only. */
  local_member_id: string | null;
}

export interface CatalogPermission {
  key: string;
  name: string;
  action_name: string;
  description: string | null;
  capability: string | null;
}

export interface CatalogFeature {
  code: string;
  name: string;
  permissions: CatalogPermission[];
}

export interface CatalogModule {
  code: string;
  name: string;
  features: CatalogFeature[];
}

export interface DelegationCatalog {
  modules: CatalogModule[];
  roles: DelegatedRole[];
  delegable_count: number;
}

export type AddUserOutcome = 'created' | 'linked' | 'existing';

export const delegationApi = {
  catalog: async () => (await apiClient.get<{ data: DelegationCatalog }>('/app/delegation/catalog')).data.data,
  users: async (search: string) =>
    (await apiClient.get<{ data: DelegatedUser[] }>('/app/delegation/users', { params: { search: search || undefined, per_page: 100 } })).data.data,
  addUser: async (input: { name: string; email: string; role_ids: string[] }) =>
    (await apiClient.post<{ data: DelegatedUser & { outcome: AddUserOutcome } }>('/app/delegation/users', input)).data.data,
  setUserRoles: async (userId: string, roleIds: string[]) =>
    (await apiClient.put<{ data: DelegatedUser }>(`/app/delegation/users/${userId}/roles`, { role_ids: roleIds })).data.data,
  revokeUser: async (userId: string) => {
    await apiClient.delete(`/app/delegation/users/${userId}`);
  },
  createRole: async (input: { name: string; description: string | null; permissions: string[] }) =>
    (await apiClient.post<{ data: DelegatedRole }>('/app/delegation/roles', input)).data.data,
  updateRole: async (roleId: string, input: { name?: string; description?: string | null; permissions?: string[] }) =>
    (await apiClient.put<{ data: DelegatedRole }>(`/app/delegation/roles/${roleId}`, input)).data.data,
  deleteRole: async (roleId: string) => {
    await apiClient.delete(`/app/delegation/roles/${roleId}`);
  },
};

export { classifyFailure, extractApiError };
export type { FailureKind };
