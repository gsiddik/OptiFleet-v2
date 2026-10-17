import { lazy, Suspense } from 'react';
import { useAuth } from '../../../auth/AuthContext';
import { LoadingState } from '../../../components/States';
import { t } from '../../../i18n/i18n';
import { TenantRolesPage } from './TenantRolesPage';
import { TenantUsersPage } from './TenantUsersPage';

const CentralUsersPage = lazy(() => import('./CentralUsersPage').then((m) => ({ default: m.CentralUsersPage })));
const CentralRolesPage = lazy(() => import('./CentralRolesPage').then((m) => ({ default: m.CentralRolesPage })));

/** Which authority decides access in the active organization: the screens differ, the routes do not. */
function useCentral(): boolean {
  const { user } = useAuth();
  return user?.governance?.mode === 'CENTRAL';
}

export function UsersPage() {
  const central = useCentral();
  if (!central) return <TenantUsersPage />;
  return (
    <Suspense fallback={<LoadingState label={t('common.actions.loading')} />}>
      <CentralUsersPage />
    </Suspense>
  );
}

export function RolesPage() {
  const central = useCentral();
  if (!central) return <TenantRolesPage />;
  return (
    <Suspense fallback={<LoadingState label={t('common.actions.loading')} />}>
      <CentralRolesPage />
    </Suspense>
  );
}
