import { AnalyticsDomainPage } from './AnalyticsDomainPage';

export function MaintenanceAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: 'Maintenance Analytics',
        description: 'Scheduled, overdue, preventive/corrective/breakdown maintenance and compliance.',
        endpoint: '/app/analytics/maintenance',
        exportSlug: 'maintenance',
        permission: 'analytics.maintenance.view',
        dimensionField: 'branch_id',
        dimensionLabel: 'Branch',
        highlightFields: [
          { key: 'maintenance_compliance_percentage', label: 'Compliance %' },
          { key: 'scheduled_maintenance_count', label: 'Scheduled' },
          { key: 'overdue_maintenance', label: 'Overdue' },
          { key: 'preventive_maintenance', label: 'Preventive' },
          { key: 'corrective_maintenance', label: 'Corrective' },
          { key: 'repeat_maintenance', label: 'Repeat' },
        ],
      }}
    />
  );
}
