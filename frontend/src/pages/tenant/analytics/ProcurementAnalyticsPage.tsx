import { AnalyticsDomainPage } from './AnalyticsDomainPage';
import { t } from '../../../i18n/i18n';

export function ProcurementAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: t('analytics.sections.procurementAnalytics'),
        description: t('analytics.help.prPoVolumeValueReceiptTiming'),
        endpoint: '/app/analytics/procurement',
        exportSlug: 'procurement',
        permission: 'analytics.procurement.view',
        dimensionField: 'branch_id',
        dimensionLabel: t('common.fields.branch'),
        highlightFields: [
          { key: 'procurement_lead_time.avg_days', label: t('analytics.fields.avgLeadTimeDays') },
          { key: 'pr_count', label: t('analytics.fields.prs') },
          { key: 'po_count', label: t('analytics.fields.pos') },
          { key: 'po_value', label: t('analytics.fields.poValue') },
          { key: 'late_receipt', label: t('analytics.fields.lateReceipts') },
        ],
      }}
    />
  );
}
