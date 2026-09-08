import { AnalyticsDomainPage } from './AnalyticsDomainPage';

export function VendorAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: 'Vendor Analytics',
        description: 'Purchases, lead time, on-time delivery, rejection, fulfillment, and price variance — independent checkable ratios, not a blended score.',
        endpoint: '/app/analytics/vendors',
        exportSlug: 'vendors',
        permission: 'analytics.vendor.view',
        dimensionField: 'vendor_id',
        dimensionLabel: 'Vendor',
        highlightFields: [
          { key: 'on_time_delivery.rate_percentage', label: 'On-Time %' },
          { key: 'total_purchases', label: 'Total Purchases' },
          { key: 'fulfillment.rate_percentage', label: 'Fulfillment %' },
          { key: 'rejected_quantity.rate_percentage', label: 'Rejected %' },
          { key: 'price_variance.avg_percentage', label: 'Price Variance %' },
        ],
      }}
    />
  );
}
