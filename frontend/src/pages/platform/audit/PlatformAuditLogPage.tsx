import { AuditLogTable } from '../../../components/AuditLogTable';
import { t } from '../../../i18n/i18n';

export function PlatformAuditLogPage() {
  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{t('platform.audit.titles.auditLog')}</h1>
      <AuditLogTable endpoint="/platform/audit-logs" showTenantColumn />
    </div>
  );
}
