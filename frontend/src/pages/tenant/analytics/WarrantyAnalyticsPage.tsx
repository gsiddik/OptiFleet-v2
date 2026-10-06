import { AnalyticsDomainPage } from './AnalyticsDomainPage';
import { t } from '../../../i18n/i18n';

export function WarrantyAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: t('analytics.sections.warrantyAnalytics'),
        description: t('analytics.help.claimsSubmittedApprovedRejectedSettledClaim'),
        endpoint: '/app/analytics/warranty',
        exportSlug: 'warranty',
        permission: 'analytics.warranty.view',
        dimensionField: 'branch_id',
        dimensionLabel: t('common.fields.branch'),
        highlightFields: [
          { key: 'claims_submitted', label: t('analytics.fields.submitted') },
          { key: 'settled', label: t('analytics.fields.settled') },
          { key: 'claim_value', label: t('analytics.fields.claimValue') },
          { key: 'warranty_utilization_percentage', label: t('analytics.fields.utilizationPercent') },
        ],
      }}
    />
  );
}
