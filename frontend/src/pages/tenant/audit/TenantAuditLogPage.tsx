import { AuditLogTable } from '../../../components/AuditLogTable';
import { t } from '../../../i18n/i18n';

export function TenantAuditLogPage() {
  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>{t('breadcrumb.auditLogs')}</h1>
      <AuditLogTable endpoint="/app/audit-logs" />
    </div>
  );
}
