import { AnalyticsDomainPage } from './AnalyticsDomainPage';
import { t } from '../../../i18n/i18n';

export function InventoryAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: t('analytics.sections.inventoryAnalytics'),
        description: t('analytics.help.stockValueLowOutStockCounts'),
        endpoint: '/app/analytics/inventory',
        exportSlug: 'inventory',
        permission: 'analytics.inventory.view',
        dimensionField: 'warehouse_id',
        dimensionLabel: t('common.fields.warehouse'),
        highlightFields: [
          { key: 'inventory_value', label: t('analytics.fields.inventoryValue') },
          { key: 'stock_on_hand', label: t('analytics.fields.onHand') },
          { key: 'low_stock_count', label: t('dashboard.fields.lowStock') },
          { key: 'out_of_stock_count', label: t('dashboard.fields.outOfStock') },
          { key: 'stockout_rate_percentage', label: t('analytics.fields.stockoutPercent') },
        ],
      }}
    />
  );
}
