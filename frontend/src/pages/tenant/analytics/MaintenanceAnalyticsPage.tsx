import { AnalyticsDomainPage } from './AnalyticsDomainPage';
import { t } from '../../../i18n/i18n';

export function MaintenanceAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: t('analytics.sections.maintenanceAnalytics'),
        description: t('analytics.help.scheduledOverduePreventiveCorrectiveBreakdownMaintenance'),
        endpoint: '/app/analytics/maintenance',
        exportSlug: 'maintenance',
        permission: 'analytics.maintenance.view',
        dimensionField: 'branch_id',
        dimensionLabel: t('common.fields.branch'),
        highlightFields: [
          { key: 'maintenance_compliance_percentage', label: t('analytics.fields.compliancePercent') },
          { key: 'scheduled_maintenance_count', label: t('analytics.fields.scheduled') },
          { key: 'overdue_maintenance', label: t('dashboard.fields.overdue') },
          { key: 'preventive_maintenance', label: t('analytics.fields.preventive') },
          { key: 'corrective_maintenance', label: t('analytics.fields.corrective') },
          { key: 'repeat_maintenance', label: t('analytics.fields.repeat') },
        ],
      }}
    />
  );
}
