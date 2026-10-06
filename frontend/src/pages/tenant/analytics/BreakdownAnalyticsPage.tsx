import { AnalyticsDomainPage } from './AnalyticsDomainPage';
import { t } from '../../../i18n/i18n';

export function BreakdownAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: t('analytics.sections.breakdownAndDowntimeAnalytics'),
        description: t('analytics.help.breakdownCountsSeverityRecurringBreakdownsResponse'),
        endpoint: '/app/analytics/breakdowns',
        exportSlug: 'breakdowns',
        permission: 'analytics.breakdown.view',
        dimensionField: 'branch_id',
        dimensionLabel: t('common.fields.branch'),
        highlightFields: [
          { key: 'breakdown_count', label: t('analytics.fields.breakdowns') },
          { key: 'by_severity.immobilized', label: t('analytics.fields.immobilized') },
          { key: 'recurring_breakdown_vehicles', label: t('analytics.fields.recurringVehicles') },
          { key: 'avg_response_time_minutes', label: t('analytics.fields.avgResponseMin') },
          { key: 'avg_repair_time_minutes', label: t('analytics.fields.avgRepairMin') },
        ],
      }}
    />
  );
}
