import { AnalyticsDomainPage } from './AnalyticsDomainPage';
import { t } from '../../../i18n/i18n';

export function WorkOrderAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: t('analytics.sections.workOrderAnalytics'),
        description: t('analytics.help.createdCompletedOverdueWorkOrdersCycle'),
        endpoint: '/app/analytics/work-orders',
        exportSlug: 'work-orders',
        permission: 'analytics.work_order.view',
        dimensionField: 'workshop_id',
        dimensionLabel: t('common.fields.workshop'),
        highlightFields: [
          { key: 'avg_cycle_time_minutes', label: t('analytics.fields.avgCycleMin') },
          { key: 'created', label: t('platform.tenants.fields.created') },
          { key: 'completed', label: t('analytics.fields.completed') },
          { key: 'overdue', label: t('dashboard.fields.overdue') },
          { key: 'rework.rate_percentage', label: t('analytics.fields.reworkPercent') },
        ],
      }}
    />
  );
}
