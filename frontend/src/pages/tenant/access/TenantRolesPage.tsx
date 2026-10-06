import { RoleManager } from '../../../components/RoleManager';
import { t } from '../../../i18n/i18n';

export function TenantRolesPage() {
  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{t('access.titles.rolesAndPermissions')}</h1>
      <RoleManager rolesEndpoint="/app/roles" permissionsEndpoint="/app/permissions" />
    </div>
  );
}
