import { RoleManager } from '../../../components/RoleManager';
import { t } from '../../../i18n/i18n';

export function PlatformRolesPage() {
  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{t('platform.access.titles.platformRolesAndPermissions')}</h1>
      <RoleManager rolesEndpoint="/platform/roles" permissionsEndpoint="/platform/permissions?scope=platform" />
    </div>
  );
}
