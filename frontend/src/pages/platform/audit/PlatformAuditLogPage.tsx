import { AuditLogTable } from '../../../components/AuditLogTable';

export function PlatformAuditLogPage() {
  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Audit Log</h1>
      <AuditLogTable endpoint="/platform/audit-logs" showTenantColumn />
    </div>
  );
}
