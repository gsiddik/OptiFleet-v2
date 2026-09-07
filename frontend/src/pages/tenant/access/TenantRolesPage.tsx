import { RoleManager } from '../../../components/RoleManager';

export function TenantRolesPage() {
  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Roles &amp; Permissions</h1>
      <RoleManager rolesEndpoint="/app/roles" permissionsEndpoint="/app/permissions" />
    </div>
  );
}
