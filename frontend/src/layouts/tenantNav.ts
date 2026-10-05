/**
 * Tenant sidebar menu. Visibility is filtered by permission and active module in the layout;
 * the backend still enforces both on every route.
 */
export interface NavItem {
  to: string;
  label: string;
  /** Required permission; a list means any one of them (e.g. a page whose tabs each need their own). */
  permission: string | string[] | null;
  module: string | null;
}

/** Whether the user holds the item's permission (any of a list). The backend enforces each action. */
export function navItemAllowed(item: NavItem, hasPermission: (permission: string) => boolean): boolean {
  if (!item.permission) return true;
  return Array.isArray(item.permission) ? item.permission.some(hasPermission) : hasPermission(item.permission);
}
export interface NavGroup {
  label: string | null;
  /** NavIcon name — shown next to the group, and alone when the sidebar is minimized. */
  icon: string;
  items: NavItem[];
}

/** Tire Operations tabs: Installation, Rotation, Inspection. */
export const TIRE_OPERATION_PERMISSIONS = ['tire.install', 'tire.rotate', 'tire.inspect'];
/** Used Tire Management tabs: Removed (inspect / approve), Retread (any retread or repair step — repair is a kind of retread) and Scrap. */
export const RETREAD_PERMISSIONS = [
  'tire_retread.send',
  'tire_retread.receive',
  'tire_retread.inspect',
  'tire_retread.approve',
  'tire_repair.send',
  'tire_repair.receive',
  'tire_repair.inspect',
  'tire_repair.approve',
];
export const USED_TIRE_INSPECTION_PERMISSIONS = ['tire.inspect', 'tire_used_inspection.approve'];
export const USED_TIRE_PERMISSIONS = [...USED_TIRE_INSPECTION_PERMISSIONS, ...RETREAD_PERMISSIONS, 'tire.scrap'];

export const NAV_GROUPS: NavGroup[] = [
  { label: null, icon: 'dashboard', items: [{ to: '/app/dashboard', label: 'Dashboard', permission: null, module: null }] },
  {
    label: 'Vehicle',
    icon: 'vehicle',
    items: [
      { to: '/app/vehicles', label: 'List', permission: 'vehicle.view', module: 'VEHICLE' },
      { to: '/app/vehicle-transfers', label: 'Transfer', permission: 'vehicle.transfer', module: 'VEHICLE' },
      { to: '/app/vehicle-history', label: 'History', permission: 'maintenance_history.view', module: 'VEHICLE' },
    ],
  },
  {
    label: 'Inspection',
    icon: 'inspection',
    items: [
      { to: '/app/inspections', label: 'Inspections', permission: 'inspection.view', module: 'INSPECTION' },
      { to: '/app/inspection-templates', label: 'Templates', permission: 'inspection.view', module: 'INSPECTION' },
    ],
  },
  {
    label: 'Maintenance',
    icon: 'maintenance',
    items: [
      { to: '/app/maintenance-policies', label: 'Maintenance Packages', permission: 'maintenance_policy.view', module: 'MAINTENANCE' },
      { to: '/app/maintenance-schedules', label: 'Planning & Schedule', permission: 'maintenance_schedule.view', module: 'MAINTENANCE' },
      { to: '/app/maintenance-requests', label: 'Maintenance Request', permission: 'maintenance_request.view', module: 'MAINTENANCE' },
      { to: '/app/work-orders', label: 'Work Order', permission: 'work_order.view', module: 'WORK_ORDER' },
      { to: '/app/part-requests', label: 'Part Requests', permission: 'part_request.view', module: 'WORK_ORDER' },
      { to: '/app/external-work-order-invoices', label: 'External Work Order Invoices', permission: 'external_work_order_invoice.view', module: 'WORK_ORDER' },
      { to: '/app/breakdowns', label: 'Breakdown', permission: 'breakdown.view', module: 'MAINTENANCE' },
    ],
  },
  {
    label: 'Workshop Operations',
    icon: 'workshop',
    items: [
      { to: '/app/workspaces', label: 'Workspace', permission: 'workspace.view', module: 'WORKSHOP' },
      { to: '/app/workshop-scheduler', label: 'Scheduler', permission: 'workspace.view', module: 'WORKSHOP' },
      { to: '/app/workers', label: 'Mechanic', permission: 'worker.view', module: 'WORKSHOP' },
      { to: '/app/workspace-reservations', label: 'Assignment', permission: 'workspace.view', module: 'WORKSHOP' },
      { to: '/app/workers/workload', label: 'Workload', permission: 'worker.view', module: 'WORKSHOP' },
    ],
  },
  {
    label: 'History',
    icon: 'history',
    items: [{ to: '/app/maintenance-history', label: 'Maintenance History', permission: 'maintenance_history.view', module: null }],
  },
  {
    label: 'Inventory',
    icon: 'inventory',
    items: [
      { to: '/app/products', label: 'Product', permission: 'product.view', module: 'INVENTORY' },
      { to: '/app/inventory', label: 'Warehouse Stock', permission: 'inventory.view', module: 'INVENTORY' },
      // Physical-asset register (moved from the retired Component Management group; same page,
      // permission and module, so nobody loses access).
      { to: '/app/component-assets', label: 'Component Assets', permission: 'component_asset.view', module: 'COMPONENT' },
      { to: '/app/returns', label: 'Return', permission: 'part_return.view', module: 'INVENTORY' },
      { to: '/app/stock-transfers', label: 'Transfer', permission: 'stock_transfer.view', module: 'INVENTORY' },
      // Goods Receipt lives under Inventory (it was listed twice: Procurement → Goods Receipt and
      // Inventory → Receiving, both the same page). Posting stays on the Purchase Order detail;
      // same route, permission and module, so nobody loses access.
      { to: '/app/goods-receipts', label: 'Goods Receipt', permission: 'goods_receipt.view', module: 'PROCUREMENT' },
      { to: '/app/stock-opnames', label: 'Stock Opname', permission: 'inventory.stock_opname', module: 'INVENTORY' },
      { to: '/app/stock-movements', label: 'Stock Movement', permission: 'inventory.view', module: 'INVENTORY' },
      { to: '/app/used-part-returns', label: 'Used Sparepart Processing', permission: 'used_part.view', module: 'INVENTORY' },
      { to: '/app/sparepart-sales', label: 'Sell Sparepart', permission: 'sparepart_sale.view', module: 'INVENTORY' },
    ],
  },
  {
    label: 'Procurement',
    icon: 'procurement',
    items: [
      { to: '/app/purchase-requests', label: 'Purchase Request', permission: 'purchase_request.view', module: 'PROCUREMENT' },
      { to: '/app/rfqs', label: 'RFQ', permission: 'rfq.view', module: 'PROCUREMENT' },
      { to: '/app/quotations', label: 'Quotation', permission: 'quotation.view', module: 'PROCUREMENT' },
      { to: '/app/purchase-orders', label: 'Purchase Order', permission: 'purchase_order.view', module: 'PROCUREMENT' },
      { to: '/app/vendor-invoice-references', label: 'Vendor Invoice Reference', permission: 'vendor_invoice.view', module: 'PROCUREMENT' },
    ],
  },
  {
    label: 'Partner',
    icon: 'partner',
    items: [
      { to: '/app/partners', label: 'Vendor', permission: 'partner.view', module: 'PARTNER' },
    ],
  },
  {
    label: 'Tire Management',
    icon: 'tire',
    items: [
      { to: '/app/rims', label: 'Rim', permission: 'rim.view', module: 'TIRE' },
      { to: '/app/tires', label: 'Tire List', permission: 'tire.view', module: 'TIRE' },
      { to: '/app/wheel-configurations', label: 'Wheel Configuration', permission: 'tire.view', module: 'TIRE' },
      { to: '/app/tire-operations', label: 'Tire Operations', permission: TIRE_OPERATION_PERMISSIONS, module: 'TIRE' },
      { to: '/app/used-tires', label: 'Used Tire Management', permission: USED_TIRE_PERMISSIONS, module: 'TIRE' },
      { to: '/app/tire-inspection-rules', label: 'Inspection Rules', permission: ['tire_rule_profile.manage', ...USED_TIRE_INSPECTION_PERMISSIONS], module: 'TIRE' },
      { to: '/app/tire-history', label: 'History', permission: 'tire.view', module: 'TIRE' },
    ],
  },
  // RETIRED: Component Management. Its Installation / Removal / Replacement / Repair-Recondition /
  // History items only pointed at the Component Assets page (now Inventory → Component Assets),
  // where the asset detail keeps those actions and the history; services and APIs are unchanged.
  // ORPHANED (owner decision): Warranty, Eligibility and Claims are no longer in the active
  // navigation. Pages (pages/tenant/warranty), APIs, data and history are kept for a future decision.
  {
    label: 'Analytics',
    icon: 'analytics',
    items: [
      { to: '/app/analytics/overview', label: 'Overview', permission: 'analytics.overview.view', module: 'ANALYTICS' },
      { to: '/app/analytics/fleet', label: 'Fleet', permission: 'analytics.fleet.view', module: 'ANALYTICS' },
      { to: '/app/analytics/maintenance', label: 'Maintenance', permission: 'analytics.maintenance.view', module: 'ANALYTICS' },
      { to: '/app/analytics/work-orders', label: 'Work Order', permission: 'analytics.work_order.view', module: 'ANALYTICS' },
      { to: '/app/analytics/breakdowns', label: 'Breakdown', permission: 'analytics.breakdown.view', module: 'ANALYTICS' },
      { to: '/app/analytics/downtime', label: 'Downtime (MTTR/MTBF)', permission: 'analytics.breakdown.view', module: 'ANALYTICS' },
      { to: '/app/analytics/workshops', label: 'Workshop', permission: 'analytics.workshop.view', module: 'ANALYTICS' },
      { to: '/app/analytics/mechanics', label: 'Mechanic', permission: 'analytics.mechanic.view', module: 'ANALYTICS' },
      { to: '/app/analytics/inventory', label: 'Inventory', permission: 'analytics.inventory.view', module: 'ANALYTICS' },
      { to: '/app/analytics/procurement', label: 'Procurement', permission: 'analytics.procurement.view', module: 'ANALYTICS' },
      { to: '/app/analytics/vendors', label: 'Vendor', permission: 'analytics.vendor.view', module: 'ANALYTICS' },
      { to: '/app/analytics/cost', label: 'Cost', permission: 'analytics.cost.view', module: 'ANALYTICS' },
      { to: '/app/analytics/tires', label: 'Tire', permission: 'analytics.tire.view', module: 'ANALYTICS' },
      { to: '/app/analytics/components', label: 'Component Reliability', permission: 'analytics.component.view', module: 'ANALYTICS' },
      { to: '/app/analytics/warranty', label: 'Warranty', permission: 'analytics.warranty.view', module: 'ANALYTICS' },
    ],
  },
  {
    label: 'Maintenance Intelligence',
    icon: 'intelligence',
    items: [
      { to: '/app/intelligence/overview', label: 'Overview', permission: 'intelligence.overview.view', module: 'MAINTENANCE_INTELLIGENCE' },
      { to: '/app/intelligence/vehicles', label: 'Vehicle Health & Risk', permission: 'intelligence.vehicle.view', module: 'MAINTENANCE_INTELLIGENCE' },
      { to: '/app/intelligence/components', label: 'Component Reliability', permission: 'intelligence.component.view', module: 'MAINTENANCE_INTELLIGENCE' },
      { to: '/app/intelligence/tires', label: 'Tire Intelligence', permission: 'intelligence.tire.view', module: 'MAINTENANCE_INTELLIGENCE' },
      { to: '/app/intelligence/recommendations', label: 'Recommendations', permission: 'intelligence.recommendation.view', module: 'MAINTENANCE_INTELLIGENCE' },
    ],
  },
  {
    label: 'Master Data',
    icon: 'masterdata',
    items: [
      { to: '/app/master-data/vehicle-categories', label: 'Vehicle Categories', permission: 'vehicle_category.view', module: 'CORE' },
      { to: '/app/master-data/component-groups', label: 'Component Groups', permission: 'component_group.view', module: 'CORE' },
      { to: '/app/master-data/component-categories', label: 'Component Categories', permission: 'component_category.view', module: 'CORE' },
      { to: '/app/master-data/component-subcategories', label: 'Component Subcategories', permission: 'component_subcategory.view', module: 'CORE' },
      { to: '/app/master-data/uoms', label: 'Units of Measure', permission: 'product.view', module: 'INVENTORY' },
      { to: '/app/master-data/vehicle-brands', label: 'Vehicle Brands', permission: 'vehicle_brand.view', module: 'CORE' },
      { to: '/app/master-data/vehicle-models', label: 'Vehicle Models', permission: 'vehicle_brand.view', module: 'CORE' },
    ],
  },
  {
    label: 'Configuration',
    icon: 'configuration',
    items: [
      { to: '/app/configuration/numbering', label: 'Document Numbering', permission: 'configuration.view', module: null },
      { to: '/app/configuration/document-templates', label: 'Document Template', permission: 'configuration.view', module: null },
      { to: '/app/configuration/workflows', label: 'Workflow', permission: 'configuration.view', module: null },
      { to: '/app/configuration/notifications', label: 'Notification', permission: 'configuration.view', module: null },
      { to: '/app/configuration/tire-scoring', label: 'Tire Scoring', permission: 'configuration.view', module: 'TIRE' },
      { to: '/app/configuration/history', label: 'Configuration History', permission: 'configuration_history.view', module: null },
    ],
  },
  // Section 5.6: Audit Log sits after Configuration with a visual gap (TenantSidebar sets a
  // later ungrouped entry apart).
  { label: null, icon: 'audit', items: [{ to: '/app/audit-logs', label: 'Audit Log', permission: 'audit.view', module: null }] },
];

/**
 * Case-insensitive menu search over groups the user can already see (permission / module
 * filtering happens before this). A matching group keeps all its items; otherwise only matching
 * items stay, under their parent group for context.
 */
export function searchNav(groups: NavGroup[], query: string): NavGroup[] {
  const q = query.trim().toLowerCase();
  if (!q) return groups;
  return groups
    .map((group) => {
      if (group.label?.toLowerCase().includes(q)) return group;
      return { ...group, items: group.items.filter((item) => item.label.toLowerCase().includes(q)) };
    })
    .filter((group) => group.items.length > 0);
}
