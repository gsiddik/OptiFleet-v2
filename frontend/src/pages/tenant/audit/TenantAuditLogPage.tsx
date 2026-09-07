import { AuditLogTable } from '../../../components/AuditLogTable';

export function TenantAuditLogPage() {
  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 16 }}>Audit Log</h1>
      <AuditLogTable endpoint="/app/audit-logs" />
    </div>
  );
}
