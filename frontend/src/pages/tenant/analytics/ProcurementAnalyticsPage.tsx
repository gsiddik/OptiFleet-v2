import { AnalyticsDomainPage } from './AnalyticsDomainPage';

export function ProcurementAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: 'Procurement Analytics',
        description: 'PR/PO volume and value, receipt timing, and lead time.',
        endpoint: '/app/analytics/procurement',
        exportSlug: 'procurement',
        permission: 'analytics.procurement.view',
        dimensionField: 'branch_id',
        dimensionLabel: 'Branch',
        highlightFields: [
          { key: 'procurement_lead_time.avg_days', label: 'Avg Lead Time (days)' },
          { key: 'pr_count', label: 'PRs' },
          { key: 'po_count', label: 'POs' },
          { key: 'po_value', label: 'PO Value' },
          { key: 'late_receipt', label: 'Late Receipts' },
        ],
      }}
    />
  );
}
