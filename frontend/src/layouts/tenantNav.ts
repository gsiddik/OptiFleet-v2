/**
 * Tenant sidebar menu. Visibility is filtered by permission and active module in the layout;
 * the backend still enforces both on every route.
 */
import { t } from '../i18n/i18n';

export interface NavItem {
  to: string;
  /** English label: the stable identity (React keys, remembered expand state). Shown via `labelKey`. */
  label: string;
  labelKey: string;
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
  labelKey?: string;
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
  { label: null, icon: 'dashboard', items: [{ to: '/app/dashboard', label: 'Dashboard', labelKey: 'nav.items.dashboard', permission: null, module: null }] },
  {
    label: 'Vehicle', labelKey: 'nav.groups.vehicle',
    icon: 'vehicle',
    items: [
      { to: '/app/vehicles', label: 'List', labelKey: 'nav.items.list', permission: 'vehicle.view', module: 'VEHICLE' },
      { to: '/app/vehicle-transfers', label: 'Transfer', labelKey: 'nav.items.transfer', permission: 'vehicle.transfer', module: 'VEHICLE' },
      { to: '/app/vehicle-history', label: 'History', labelKey: 'nav.items.history', permission: 'maintenance_history.view', module: 'VEHICLE' },
    ],
  },
  {
    label: 'Inspection', labelKey: 'nav.groups.inspection',
    icon: 'inspection',
    items: [
      { to: '/app/inspections', label: 'Inspections', labelKey: 'nav.items.inspections', permission: 'inspection.view', module: 'INSPECTION' },
      { to: '/app/inspection-templates', label: 'Templates', labelKey: 'nav.items.templates', permission: 'inspection.view', module: 'INSPECTION' },
    ],
  },
  {
    label: 'Maintenance', labelKey: 'nav.groups.maintenance',
    icon: 'maintenance',
    items: [
      { to: '/app/maintenance-policies', label: 'Maintenance Packages', labelKey: 'nav.items.maintenancePackages', permission: 'maintenance_policy.view', module: 'MAINTENANCE' },
      { to: '/app/maintenance-schedules', label: 'Planning & Schedule', labelKey: 'nav.items.planningAndSchedule', permission: 'maintenance_schedule.view', module: 'MAINTENANCE' },
      { to: '/app/maintenance-requests', label: 'Maintenance Request', labelKey: 'nav.items.maintenanceRequest', permission: 'maintenance_request.view', module: 'MAINTENANCE' },
      { to: '/app/work-orders', label: 'Work Order', labelKey: 'nav.items.workOrder', permission: 'work_order.view', module: 'WORK_ORDER' },
      { to: '/app/part-requests', label: 'Part Requests', labelKey: 'nav.items.partRequests', permission: 'part_request.view', module: 'WORK_ORDER' },
      { to: '/app/external-work-order-invoices', label: 'External Work Order Invoices', labelKey: 'nav.items.externalWorkOrderInvoices', permission: 'external_work_order_invoice.view', module: 'WORK_ORDER' },
      { to: '/app/breakdowns', label: 'Breakdown', labelKey: 'nav.items.breakdown', permission: 'breakdown.view', module: 'MAINTENANCE' },
    ],
  },
  {
    label: 'Workshop Operations', labelKey: 'nav.groups.workshopOperations',
    icon: 'workshop',
    items: [
      { to: '/app/workspaces', label: 'Workspace', labelKey: 'nav.items.workspace', permission: 'workspace.view', module: 'WORKSHOP' },
      { to: '/app/workshop-scheduler', label: 'Scheduler', labelKey: 'nav.items.scheduler', permission: 'workspace.view', module: 'WORKSHOP' },
      { to: '/app/workers', label: 'Mechanic', labelKey: 'nav.items.mechanic', permission: 'worker.view', module: 'WORKSHOP' },
      { to: '/app/workspace-reservations', label: 'Assignment', labelKey: 'nav.items.assignment', permission: 'workspace.view', module: 'WORKSHOP' },
      { to: '/app/workers/workload', label: 'Workload', labelKey: 'nav.items.workload', permission: 'worker.view', module: 'WORKSHOP' },
    ],
  },
  {
    label: 'History', labelKey: 'nav.items.history',
    icon: 'history',
    items: [{ to: '/app/maintenance-history', label: 'Maintenance History', labelKey: 'nav.items.maintenanceHistory', permission: 'maintenance_history.view', module: null }],
  },
  {
    label: 'Inventory', labelKey: 'nav.groups.inventory',
    icon: 'inventory',
    items: [
      { to: '/app/products', label: 'Product', labelKey: 'nav.items.product', permission: 'product.view', module: 'INVENTORY' },
      { to: '/app/inventory', label: 'Warehouse Stock', labelKey: 'nav.items.warehouseStock', permission: 'inventory.view', module: 'INVENTORY' },
      // Physical-asset register (moved from the retired Component Management group; same page,
      // permission and module, so nobody loses access).
      { to: '/app/component-assets', label: 'Component Assets', labelKey: 'nav.items.componentAssets', permission: 'component_asset.view', module: 'COMPONENT' },
      { to: '/app/returns', label: 'Stock Return', labelKey: 'nav.items.stockReturn', permission: 'part_return.view', module: 'INVENTORY' },
      { to: '/app/stock-transfers', label: 'Stock Transfer', labelKey: 'nav.items.stockTransfer', permission: 'stock_transfer.view', module: 'INVENTORY' },
      // Goods Receipt lives under Inventory (it was listed twice: Procurement → Goods Receipt and
      // Inventory → Receiving, both the same page). Posting stays on the Purchase Order detail;
      // same route, permission and module, so nobody loses access.
      { to: '/app/goods-receipts', label: 'Goods Receipt', labelKey: 'nav.items.goodsReceipt', permission: 'goods_receipt.view', module: 'PROCUREMENT' },
      { to: '/app/stock-opnames', label: 'Stock Opname', labelKey: 'nav.items.stockOpname', permission: 'inventory.stock_opname', module: 'INVENTORY' },
      { to: '/app/inventory-valuation', label: 'Stock Valuation', labelKey: 'nav.items.stockValuation', permission: 'inventory_valuation.view', module: 'INVENTORY' },
      { to: '/app/inventory-reconciliation', label: 'Stock Reconciliation', labelKey: 'nav.items.stockReconciliation', permission: 'inventory_reconcile.view', module: 'INVENTORY' },
      { to: '/app/stock-movements', label: 'Stock Movement', labelKey: 'nav.items.stockMovement', permission: 'inventory.view', module: 'INVENTORY' },
      { to: '/app/used-part-returns', label: 'Used Sparepart Processing', labelKey: 'nav.items.usedSparepartProcessing', permission: 'used_part.view', module: 'INVENTORY' },
      { to: '/app/sparepart-sales', label: 'Sell Sparepart', labelKey: 'nav.items.sellSparepart', permission: 'sparepart_sale.view', module: 'INVENTORY' },
    ],
  },
  {
    label: 'Procurement', labelKey: 'nav.groups.procurement',
    icon: 'procurement',
    items: [
      { to: '/app/purchase-requests', label: 'Purchase Request', labelKey: 'nav.items.purchaseRequest', permission: 'purchase_request.view', module: 'PROCUREMENT' },
      { to: '/app/rfqs', label: 'RFQ', labelKey: 'nav.items.rfq', permission: 'rfq.view', module: 'PROCUREMENT' },
      { to: '/app/quotations', label: 'Quotation', labelKey: 'nav.items.quotation', permission: 'quotation.view', module: 'PROCUREMENT' },
      { to: '/app/purchase-orders', label: 'Purchase Order', labelKey: 'nav.items.purchaseOrder', permission: 'purchase_order.view', module: 'PROCUREMENT' },
      { to: '/app/vendor-invoice-references', label: 'Vendor Invoice Reference', labelKey: 'nav.items.vendorInvoiceReference', permission: 'vendor_invoice.view', module: 'PROCUREMENT' },
    ],
  },
  {
    label: 'Partner', labelKey: 'nav.groups.partner',
    icon: 'partner',
    items: [
      { to: '/app/partners', label: 'Vendor', labelKey: 'nav.items.vendor', permission: 'partner.view', module: 'PARTNER' },
    ],
  },
  {
    label: 'Tire Management', labelKey: 'nav.groups.tireManagement',
    icon: 'tire',
    items: [
      { to: '/app/rims', label: 'Rim', labelKey: 'nav.items.rim', permission: 'rim.view', module: 'TIRE' },
      { to: '/app/tires', label: 'Tire List', labelKey: 'nav.items.tireList', permission: 'tire.view', module: 'TIRE' },
      { to: '/app/wheel-configurations', label: 'Wheel Configuration', labelKey: 'nav.items.wheelConfiguration', permission: 'tire.view', module: 'TIRE' },
      { to: '/app/tire-operations', label: 'Tire Operations', labelKey: 'nav.items.tireOperations', permission: TIRE_OPERATION_PERMISSIONS, module: 'TIRE' },
      { to: '/app/used-tires', label: 'Used Tire Management', labelKey: 'nav.items.usedTireManagement', permission: USED_TIRE_PERMISSIONS, module: 'TIRE' },
      { to: '/app/tire-inspection-rules', label: 'Inspection Rules', labelKey: 'nav.items.inspectionRules', permission: ['tire_rule_profile.manage', ...USED_TIRE_INSPECTION_PERMISSIONS], module: 'TIRE' },
      { to: '/app/tire-history', label: 'History', labelKey: 'nav.items.history', permission: 'tire.view', module: 'TIRE' },
    ],
  },
  // RETIRED: Component Management. Its Installation / Removal / Replacement / Repair-Recondition /
  // History items only pointed at the Component Assets page (now Inventory → Component Assets),
  // where the asset detail keeps those actions and the history; services and APIs are unchanged.
  // ORPHANED (owner decision): Warranty, Eligibility and Claims are no longer in the active
  // navigation. Pages (pages/tenant/warranty), APIs, data and history are kept for a future decision.
  {
    label: 'Analytics', labelKey: 'nav.groups.analytics',
    icon: 'analytics',
    items: [
      { to: '/app/analytics/overview', label: 'Overview', labelKey: 'nav.items.overview', permission: 'analytics.overview.view', module: 'ANALYTICS' },
      { to: '/app/analytics/fleet', label: 'Fleet', labelKey: 'nav.items.fleet', permission: 'analytics.fleet.view', module: 'ANALYTICS' },
      { to: '/app/analytics/maintenance', label: 'Maintenance', labelKey: 'nav.groups.maintenance', permission: 'analytics.maintenance.view', module: 'ANALYTICS' },
      { to: '/app/analytics/work-orders', label: 'Work Order', labelKey: 'nav.items.workOrder', permission: 'analytics.work_order.view', module: 'ANALYTICS' },
      { to: '/app/analytics/breakdowns', label: 'Breakdown', labelKey: 'nav.items.breakdown', permission: 'analytics.breakdown.view', module: 'ANALYTICS' },
      { to: '/app/analytics/downtime', label: 'Downtime (MTTR/MTBF)', labelKey: 'nav.items.downtimeMttrMtbf', permission: 'analytics.breakdown.view', module: 'ANALYTICS' },
      { to: '/app/analytics/workshops', label: 'Workshop', labelKey: 'nav.items.workshop', permission: 'analytics.workshop.view', module: 'ANALYTICS' },
      { to: '/app/analytics/mechanics', label: 'Mechanic', labelKey: 'nav.items.mechanic', permission: 'analytics.mechanic.view', module: 'ANALYTICS' },
      { to: '/app/analytics/inventory', label: 'Inventory', labelKey: 'nav.groups.inventory', permission: 'analytics.inventory.view', module: 'ANALYTICS' },
      { to: '/app/analytics/procurement', label: 'Procurement', labelKey: 'nav.groups.procurement', permission: 'analytics.procurement.view', module: 'ANALYTICS' },
      { to: '/app/analytics/vendors', label: 'Vendor', labelKey: 'nav.items.vendor', permission: 'analytics.vendor.view', module: 'ANALYTICS' },
      { to: '/app/analytics/cost', label: 'Cost', labelKey: 'nav.items.cost', permission: 'analytics.cost.view', module: 'ANALYTICS' },
      { to: '/app/analytics/tires', label: 'Tire', labelKey: 'nav.items.tire', permission: 'analytics.tire.view', module: 'ANALYTICS' },
      { to: '/app/analytics/components', label: 'Component Reliability', labelKey: 'nav.items.componentReliability', permission: 'analytics.component.view', module: 'ANALYTICS' },
      { to: '/app/analytics/warranty', label: 'Warranty', labelKey: 'nav.items.warranty', permission: 'analytics.warranty.view', module: 'ANALYTICS' },
    ],
  },
  {
    label: 'Maintenance Intelligence', labelKey: 'nav.groups.maintenanceIntelligence',
    icon: 'intelligence',
    items: [
      { to: '/app/intelligence/overview', label: 'Overview', labelKey: 'nav.items.overview', permission: 'intelligence.overview.view', module: 'MAINTENANCE_INTELLIGENCE' },
      { to: '/app/intelligence/vehicles', label: 'Vehicle Health & Risk', labelKey: 'nav.items.vehicleHealthAndRisk', permission: 'intelligence.vehicle.view', module: 'MAINTENANCE_INTELLIGENCE' },
      { to: '/app/intelligence/components', label: 'Component Reliability', labelKey: 'nav.items.componentReliability', permission: 'intelligence.component.view', module: 'MAINTENANCE_INTELLIGENCE' },
      { to: '/app/intelligence/tires', label: 'Tire Intelligence', labelKey: 'nav.items.tireIntelligence', permission: 'intelligence.tire.view', module: 'MAINTENANCE_INTELLIGENCE' },
      { to: '/app/intelligence/recommendations', label: 'Recommendations', labelKey: 'nav.items.recommendations', permission: 'intelligence.recommendation.view', module: 'MAINTENANCE_INTELLIGENCE' },
    ],
  },
  {
    label: 'Master Data', labelKey: 'nav.groups.masterData',
    icon: 'masterdata',
    items: [
      { to: '/app/master-data/vehicle-categories', label: 'Vehicle Categories', labelKey: 'nav.items.vehicleCategories', permission: 'vehicle_category.view', module: 'CORE' },
      { to: '/app/master-data/component-groups', label: 'Component Groups', labelKey: 'nav.items.componentGroups', permission: 'component_group.view', module: 'CORE' },
      { to: '/app/master-data/component-categories', label: 'Component Categories', labelKey: 'nav.items.componentCategories', permission: 'component_category.view', module: 'CORE' },
      { to: '/app/master-data/component-subcategories', label: 'Component Subcategories', labelKey: 'nav.items.componentSubcategories', permission: 'component_subcategory.view', module: 'CORE' },
      { to: '/app/master-data/uoms', label: 'Units of Measure', labelKey: 'nav.items.unitsOfMeasure', permission: 'product.view', module: 'INVENTORY' },
      { to: '/app/master-data/vehicle-brands', label: 'Vehicle Brands', labelKey: 'nav.items.vehicleBrands', permission: 'vehicle_brand.view', module: 'CORE' },
      { to: '/app/master-data/vehicle-models', label: 'Vehicle Models', labelKey: 'nav.items.vehicleModels', permission: 'vehicle_brand.view', module: 'CORE' },
    ],
  },
  {
    label: 'Configuration', labelKey: 'nav.groups.configuration',
    icon: 'configuration',
    items: [
      { to: '/app/configuration/numbering', label: 'Document Numbering', labelKey: 'nav.items.documentNumbering', permission: 'configuration.view', module: null },
      { to: '/app/configuration/document-templates', label: 'Document Template', labelKey: 'nav.items.documentTemplate', permission: 'configuration.view', module: null },
      { to: '/app/configuration/workflows', label: 'Workflow', labelKey: 'nav.items.workflow', permission: 'configuration.view', module: null },
      { to: '/app/configuration/notifications', label: 'Notification', labelKey: 'nav.items.notification', permission: 'configuration.view', module: null },
      { to: '/app/configuration/history', label: 'Configuration History', labelKey: 'nav.items.configurationHistory', permission: 'configuration_history.view', module: null },
    ],
  },
  // Section 5.6: Audit Log sits after Configuration with a visual gap (TenantSidebar sets a
  // later ungrouped entry apart).
  { label: null, icon: 'audit', items: [{ to: '/app/audit-logs', label: 'Audit Log', labelKey: 'nav.items.auditLog', permission: 'audit.view', module: null }] },
];

/** The label shown for a menu entry, in the current language. */
export function navLabel(entry: { label: string | null; labelKey?: string }): string {
  return entry.labelKey ? t(entry.labelKey) : (entry.label ?? '');
}

/**
 * Case-insensitive menu search (on the shown, translated labels) over groups the user can already see (permission / module
 * filtering happens before this). A matching group keeps all its items; otherwise only matching
 * items stay, under their parent group for context.
 */
export function searchNav(groups: NavGroup[], query: string): NavGroup[] {
  const q = query.trim().toLowerCase();
  if (!q) return groups;
  return groups
    .map((group) => {
      if (group.label && navLabel(group).toLowerCase().includes(q)) return group;
      return { ...group, items: group.items.filter((item) => navLabel(item).toLowerCase().includes(q)) };
    })
    .filter((group) => group.items.length > 0);
}
