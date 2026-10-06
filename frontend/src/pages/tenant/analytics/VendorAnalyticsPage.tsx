import { AnalyticsDomainPage } from './AnalyticsDomainPage';
import { t } from '../../../i18n/i18n';

export function VendorAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: t('analytics.sections.vendorAnalytics'),
        description: t('analytics.help.purchasesLeadTimeTimeDeliveryRejection'),
        endpoint: '/app/analytics/vendors',
        exportSlug: 'vendors',
        permission: 'analytics.vendor.view',
        dimensionField: 'vendor_id',
        dimensionLabel: t('common.fields.vendor'),
        highlightFields: [
          { key: 'on_time_delivery.rate_percentage', label: t('analytics.fields.onTimePercent') },
          { key: 'total_purchases', label: t('analytics.fields.totalPurchases') },
          { key: 'fulfillment.rate_percentage', label: t('analytics.fields.fulfillmentPercent') },
          { key: 'rejected_quantity.rate_percentage', label: t('analytics.fields.rejectedPercent') },
          { key: 'price_variance.avg_percentage', label: t('analytics.fields.priceVariancePercent') },
        ],
      }}
    />
  );
}
