/**
 * Static path-segment -> human label lookup for the Breadcrumb component.
 * Keyed by the raw URL segment (not the full path), so one entry covers a
 * segment wherever it appears in the tree. Anything not listed here falls
 * back to a humanized version of the segment (see humanizeSegment below) —
 * the breadcrumb is always derived from the real route, this dictionary
 * only improves wording, it never invents links that don't exist.
 */
export const SEGMENT_LABELS: Record<string, string> = {
  platform: 'Platform Portal',
  app: 'Home',
  dashboard: 'Dashboard',

  // Platform Portal
  tenants: 'Tenant Management',
  modules: 'Module Catalog',
  bundles: 'Bundle Management',
  pricing: 'Pricing Management',
  contracts: 'Contract Management',
  subscriptions: 'Subscriptions',
  billings: 'Billing',
  invoices: 'Invoices',
  payments: 'Payments',

  // Shared: Access / Audit
  access: 'Access Management',
  users: 'Users',
  roles: 'Roles',
  'audit-logs': 'Audit Log',

  // Tenant Portal — Vehicle
  vehicles: 'Vehicles',
  'vehicle-transfers': 'Vehicle Transfers',
  'vehicle-history': 'Vehicle History',

  // Inspection
  inspections: 'Inspections',
  'inspection-templates': 'Inspection Templates',

  // Maintenance
  'maintenance-schedules': 'Maintenance Planning & Schedule',
  'maintenance-policies': 'Maintenance Packages',
  'maintenance-requests': 'Maintenance Requests',
  breakdowns: 'Breakdowns',
  'work-orders': 'Work Orders',
  'part-requests': 'Part Requests',
  'external-work-order-invoices': 'External Work Order Invoices',
  'workshop-invoices': 'Workshop Invoices',

  // Workshop Operations
  workspaces: 'Workspaces',
  workers: 'Mechanics',
  'workshop-scheduler': 'Workshop Scheduler',
  'workspace-reservations': 'Workspace Assignments',
  workload: 'Workload',

  // Organization / Master Data
  organization: 'Organization',
  branches: 'Branches',
  workshops: 'Workshops',
  warehouses: 'Warehouses',
  'master-data': 'Master Data',
  'vehicle-categories': 'Vehicle Categories',
  'component-groups': 'Component Groups',
  'component-categories': 'Component Categories',
  'component-subcategories': 'Component Subcategories',
  'product-categories': 'Product Categories',
  uoms: 'Units of Measure',
  'vehicle-brands': 'Vehicle Brands',
  'vehicle-models': 'Vehicle Models',

  // Configuration
  configuration: 'Configuration',
  numbering: 'Document Numbering',
  'document-templates': 'Document Templates',
  workflows: 'Workflows',
  notifications: 'Notification Rules',
  'tire-scoring': 'Tire Scoring Configuration',
  history: 'Configuration History',

  // Account (Tenant self-service)
  account: 'Account',
  subscription: 'Subscription',
  contract: 'Contract',
  company: 'Company Profile',

  // Inventory / Procurement
  products: 'Products',
  inventory: 'Warehouse Stock',
  'stock-transfers': 'Stock Transfers',
  'stock-opnames': 'Stock Opname',
  'stock-movements': 'Stock Movements',
  'used-part-returns': 'Used Sparepart Processing',
  'sparepart-sales': 'Sparepart Sales',
  'purchase-requests': 'Purchase Requests',
  rfqs: 'RFQs',
  quotations: 'Vendor Quotations',
  'create-po': 'Create Purchase Order',
  'purchase-orders': 'Purchase Orders',
  'goods-receipts': 'Goods Receipts',
  'vendor-invoice-references': 'Vendor Invoice References',

  // Partners
  partners: 'Partners',
  suppliers: 'Suppliers',

  // Tire / Component / Warranty
  tires: 'Tires',
  'wheel-configurations': 'Wheel Configurations',
  rims: 'Rims',
  'component-assets': 'Component Assets',
  warranties: 'Warranties',
  'warranty-claims': 'Warranty Claims',

  // Analytics / Intelligence
  analytics: 'Analytics',
  overview: 'Overview',
  fleet: 'Fleet',
  maintenance: 'Maintenance',
  downtime: 'Downtime (MTTR/MTBF)',
  mechanics: 'Mechanics',
  procurement: 'Procurement',
  vendors: 'Vendors',
  cost: 'Cost',
  components: 'Component Reliability',
  warranty: 'Warranty',
  intelligence: 'Maintenance Intelligence',
  recommendations: 'Recommendations',
};

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const NUMERIC_ID_RE = /^\d+$/;

export function isDynamicSegment(segment: string): boolean {
  return UUID_RE.test(segment) || NUMERIC_ID_RE.test(segment);
}

export function humanizeSegment(segment: string): string {
  return segment
    .replace(/[-_]/g, ' ')
    .split(' ')
    .filter(Boolean)
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join(' ');
}
