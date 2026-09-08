import { AnalyticsDomainPage } from './AnalyticsDomainPage';

export function InventoryAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: 'Inventory Analytics',
        description: 'Stock value, low/out-of-stock counts, movement, and turnover.',
        endpoint: '/app/analytics/inventory',
        exportSlug: 'inventory',
        permission: 'analytics.inventory.view',
        dimensionField: 'warehouse_id',
        dimensionLabel: 'Warehouse',
        highlightFields: [
          { key: 'inventory_value', label: 'Inventory Value' },
          { key: 'stock_on_hand', label: 'On Hand' },
          { key: 'low_stock_count', label: 'Low Stock' },
          { key: 'out_of_stock_count', label: 'Out of Stock' },
          { key: 'stockout_rate_percentage', label: 'Stockout %' },
        ],
      }}
    />
  );
}
