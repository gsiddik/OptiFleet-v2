import { AnalyticsDomainPage } from './AnalyticsDomainPage';
import { t } from '../../../i18n/i18n';

export function WorkshopAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: t('analytics.sections.workshopAnalytics'),
        description: t('analytics.help.throughputQueueTimeWorkspaceUtilization'),
        endpoint: '/app/analytics/workshops',
        exportSlug: 'workshops',
        permission: 'analytics.workshop.view',
        dimensionField: 'workshop_id',
        dimensionLabel: t('common.fields.workshop'),
        highlightFields: [
          { key: 'workspace_utilization.percentage', label: t('analytics.fields.utilizationPercent') },
          { key: 'wo_throughput', label: t('analytics.fields.throughput') },
          { key: 'open_wo', label: t('analytics.fields.openWo') },
          { key: 'overdue_wo', label: t('analytics.fields.overdueWo') },
          { key: 'avg_queue_time_minutes', label: t('analytics.fields.avgQueueMin') },
        ],
      }}
    />
  );
}
