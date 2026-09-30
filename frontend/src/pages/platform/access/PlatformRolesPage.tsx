import { RoleManager } from '../../../components/RoleManager';

export function PlatformRolesPage() {
  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Platform Roles &amp; Permissions</h1>
      <RoleManager rolesEndpoint="/platform/roles" permissionsEndpoint="/platform/permissions?scope=platform" />
    </div>
  );
}
