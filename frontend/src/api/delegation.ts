/**
 * Users, invitations and roles of a centrally governed tenant, through OptiFleet's delegation API (which calls the OptiNexus delegation API
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

/** An unaccepted invitation past its expiry is reported as EXPIRED by OptiNexus. */
export type InvitationStatus = 'PENDING' | 'ACCEPTED' | 'REVOKED' | 'EXPIRED';

export type InvitationDeliveryStatus = 'QUEUED' | 'SENT' | 'FAILED';

/** Language of the invitation e-mail and of the OptiNexus page where the person sets a password. */
export type InvitationLocale = 'en' | 'id';

/**
 * An invitation as OptiNexus reports it (through OptiFleet's backend). The link is e-mailed by OptiNexus and is never part of an
 * answer, so there is nothing here that could grant access: the person gets access only by accepting on OptiNexus.
 */
export interface DelegatedInvitation {
  id: string;
  email: string | null;
  name: string | null;
  status: InvitationStatus;
  locale: InvitationLocale | null;
  role_ids: string[];
  roles: { id: string; code: string | null; name: string | null }[];
  invited_by: { id: string | null; name: string | null } | null;
  created_at: string | null;
  expires_at: string | null;
  last_sent_at: string | null;
  send_count: number;
  delivery: { status: InvitationDeliveryStatus | null; attempted_at: string | null; error_code: string | null };
  accepted_at: string | null;
  revoked_at: string | null;
  /** OptiNexus decides: PENDING and EXPIRED invitations that did not reach the resend limit. */
  can_resend: boolean;
  /** OptiNexus decides: PENDING and EXPIRED invitations. */
  can_revoke: boolean;
}

/**
 * The user seats of this tenant for this product as OptiNexus counted them (contract 2d). `available` is never negative: a limit below
 * usage is `over_limit` (with `over_by`). Any state but NOT_CONTROLLED / LIMITED / UNLIMITED means the limit is not decided and additions are
 * refused; it never means unlimited. `null` where an older OptiNexus sends no figures.
 */
export type SeatState = 'NOT_CONTROLLED' | 'LIMITED' | 'UNLIMITED' | 'ENTITLEMENT_MISSING' | 'ENTITLEMENT_INVALID' | 'ENTITLEMENT_UNAVAILABLE';

export interface DelegationSeats {
  state: SeatState;
  controlled: boolean;
  limit: number | null;
  unlimited: boolean;
  used: number;
  reserved: number;
  available: number | null;
  over_limit: boolean;
  over_by: number;
  can_add: boolean;
  blocked_reason: string | null;
}

/** A list with the seats OptiNexus counted for the same tenant and product. */
export interface WithSeats<T> {
  items: T[];
  seats: DelegationSeats | null;
}

export const delegationApi = {
  catalog: async () => (await apiClient.get<{ data: DelegationCatalog }>('/app/delegation/catalog')).data.data,
  users: async (search: string): Promise<WithSeats<DelegatedUser>> => {
    const body = (await apiClient.get<{ data: DelegatedUser[]; seats?: DelegationSeats | null }>('/app/delegation/users', { params: { search: search || undefined, per_page: 100 } })).data;
    return { items: body.data, seats: body.seats ?? null };
  },
  /** @deprecated OptiNexus deprecates direct creation (the person would have no verified e-mail and could not sign in): use `invite`. */
  addUser: async (input: { name: string; email: string; role_ids: string[] }) =>
    (await apiClient.post<{ data: DelegatedUser & { outcome: AddUserOutcome } }>('/app/delegation/users', input)).data.data,
  invitations: async (filter: { status?: InvitationStatus | ''; search?: string }): Promise<WithSeats<DelegatedInvitation>> => {
    const body = (
      await apiClient.get<{ data: DelegatedInvitation[]; seats?: DelegationSeats | null }>('/app/delegation/invitations', {
        params: { status: filter.status || undefined, search: filter.search || undefined, per_page: 100 },
      })
    ).data;
    return { items: body.data, seats: body.seats ?? null };
  },
  invite: async (input: { name: string; email: string; role_ids: string[]; locale: InvitationLocale }) =>
    (await apiClient.post<{ data: DelegatedInvitation }>('/app/delegation/invitations', input)).data.data,
  resendInvitation: async (invitationId: string) =>
    (await apiClient.post<{ data: DelegatedInvitation }>(`/app/delegation/invitations/${invitationId}/resend`, {})).data.data,
  revokeInvitation: async (invitationId: string) =>
    (await apiClient.post<{ data: DelegatedInvitation }>(`/app/delegation/invitations/${invitationId}/revoke`, {})).data.data,
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
