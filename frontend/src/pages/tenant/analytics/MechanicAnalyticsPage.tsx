import { AnalyticsDomainPage } from './AnalyticsDomainPage';
import { t } from '../../../i18n/i18n';

export function MechanicAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: t('analytics.sections.mechanicAnalytics'),
        description: t('analytics.help.assignedCompletedJobsLaborTimeUtilization'),
        endpoint: '/app/analytics/mechanics',
        exportSlug: 'mechanics',
        permission: 'analytics.mechanic.view',
        dimensionField: 'mechanic_id',
        dimensionLabel: t('analytics.fields.mechanic'),
        highlightFields: [
          { key: 'utilization.percentage', label: t('analytics.fields.utilizationPercent') },
          { key: 'assigned_jobs', label: t('analytics.fields.assigned') },
          { key: 'completed_jobs', label: t('analytics.fields.completed') },
          { key: 'workload', label: t('analytics.fields.openWorkload') },
          { key: 'avg_job_duration_minutes', label: t('analytics.fields.avgDurationMin') },
        ],
      }}
    />
  );
}
