import { AnalyticsDomainPage } from './AnalyticsDomainPage';

export function WarrantyAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: 'Warranty Analytics',
        description: 'Claims submitted/approved/rejected/settled, claim value, and warranty utilization.',
        endpoint: '/app/analytics/warranty',
        exportSlug: 'warranty',
        permission: 'analytics.warranty.view',
        dimensionField: 'branch_id',
        dimensionLabel: 'Branch',
        highlightFields: [
          { key: 'claims_submitted', label: 'Submitted' },
          { key: 'settled', label: 'Settled' },
          { key: 'claim_value', label: 'Claim Value' },
          { key: 'warranty_utilization_percentage', label: 'Utilization %' },
        ],
      }}
    />
  );
}
