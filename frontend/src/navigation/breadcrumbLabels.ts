// i18n-audit: canonical-english — shown through breadcrumb.<camelCaseSegment> (tests/unit/i18nSharedUi.test.ts).
import { translated } from '../i18n/i18n';

/**
 * Static path-segment -> English label lookup for the Breadcrumb component.
 * Keyed by the raw URL segment (not the full path), so one entry covers a
 * segment wherever it appears in the tree. Every static route segment has an
 * entry (enforced by tests/unit/breadcrumbLabels.test.ts); humanizeSegment is
 * only a last-resort fallback for an unexpected segment —
 * the breadcrumb is always derived from the real route, this dictionary
 * only improves wording, it never invents links that don't exist.
 *
 * The labels shown come from `breadcrumb.<camelCaseSegment>` (segmentLabel); this English map is the
 * canonical text and the fallback.
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
  'workshop-invoices': 'Service Invoices',

  // Workshop Operations
  workspaces: 'Workspaces',
  workers: 'Mechanics',
  'workshop-scheduler': 'Workshop Scheduler',
  'workspace-reservations': 'Workspace Assignments',
  'maintenance-history': 'Maintenance History',
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
  'goods-receipts': 'Goods Receipt',
  'vendor-invoice-references': 'Vendor Invoice References',

  // Partners
  partners: 'Partners',
  suppliers: 'Suppliers',

  // Tire / Component / Warranty
  tires: 'Tires',
  'wheel-configurations': 'Wheel Configurations',
  rims: 'Rims',
  catalog: 'Catalog',
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
  // Explicit labels for segments that previously relied on the humanize fallback (i18n structural
  // preparation: every static route segment has a label, so it can be translated by key).
  edit: 'Edit',
  inspection: 'Inspection',
  login: 'Login',
  new: 'New',
  returns: 'Returns',
  'tire-history': 'Tire History',
  'tire-inspection-rules': 'Tire Inspection Rules',
  'tire-operations': 'Tire Operations',
  'tire-scoring': 'Tire Scoring',
  'used-tires': 'Used Tires',
  'vehicle-mapping': 'Vehicle Mapping',
};

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const NUMERIC_ID_RE = /^\d+$/;

export function isDynamicSegment(segment: string): boolean {
  return UUID_RE.test(segment) || NUMERIC_ID_RE.test(segment);
}

/** `maintenance-policies` → `breadcrumb.maintenancePolicies`. */
export function segmentKey(segment: string): string {
  return `breadcrumb.${segment.replace(/[-_]([a-z0-9])/g, (_, c: string) => c.toUpperCase())}`;
}

/** The label shown for a static segment, in the current language. */
export function segmentLabel(segment: string): string {
  const english = SEGMENT_LABELS[segment];
  return english ? translated(segmentKey(segment), english) : humanizeSegment(segment);
}

export function humanizeSegment(segment: string): string {
  return segment
    .replace(/[-_]/g, ' ')
    .split(' ')
    .filter(Boolean)
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join(' ');
}
