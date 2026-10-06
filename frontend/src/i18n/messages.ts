/**
 * Whole-sentence message templates (i18n structural preparation).
 *
 * Each message is one complete sentence keyed by its EN-ID dataset key
 * (docs/i18n/12-en-id-translation-dataset-final.csv), with named `{{param}}` placeholders, so
 * a language can reorder words freely. Never assemble a sentence from fragments. Where a
 * fallback changes the grammar (a missing name, approve vs cancel), use a separate key rather
 * than a word passed as a parameter. This is the English source only; the i18n rollout swaps
 * the lookup.
 */
const MESSAGES = {
  'common.fields.front': 'Front',
  'common.fields.rear': 'Rear',
  'common.fields.left': 'Left',
  'common.fields.right': 'Right',
  'common.fields.spareIndex': 'Spare {{index}}',
  'common.fields.partner': 'Partner',
  'account.fields.item': 'Item',
  'tire.fields.ready': 'Ready',
  'tire.help.current': 'Current',
  'common.tire.positionLabel': '{{axleGroup}} {{side}}, Axle {{axle}}, Pos. {{index}}',
  'tire.wheelConfiguration.positionDescription': '{{axleGroup}} axle {{axle}} · {{side}} · wheel {{index}}',
  'tire.wheelConfiguration.positionDescriptionClosestToBody': '{{axleGroup}} axle {{axle}} · {{side}} · wheel {{index}} (closest to body)',
  'tire.wheelConfiguration.historyEndedUpdated': 'Ended {{endedAt}} (updated to newer version)',
  'tire.wheelConfiguration.historyEndedUnmapped': 'Ended {{endedAt}} (unmapped)',
  'inventory.confirm.restockToWarehouse': '{{quantity}} will be added back to {{warehouseName}} stock.',
  'inventory.confirm.restockToUnknownWarehouse': '{{quantity}} will be added back to the warehouse stock.',
  'platform.subscriptions.messages.billingGenerated': 'Billing and invoice generated for {{tenantName}}.',
  'platform.subscriptions.messages.billingGeneratedNoTenant': 'Billing and invoice generated for the subscription.',
  'procurement.validation.returnQtyExceeded': '{{productName}}: at most {{returnable}} can be returned.',
  'tenantComponents.fields.saleSummary': '{{status}} · {{buyerName}} · {{decidedAt}}',
  'tire.import.rowStatusDuplicate': 'Duplicate — {{errors}}',
  'tire.import.rowStatusInvalid': 'Invalid — {{errors}}',
  'tire.operations.noSerialForProduct': 'No New Stock or Reuse serial of {{productName}}',
  'tire.operations.noSerialForThisProduct': 'No New Stock or Reuse serial of this product',
  'tire.retread.repairFormTitle': 'Repair Form — {{serialNumber}}',
  'tire.retread.retreadFormTitle': 'Retread Form — {{serialNumber}}',
  'workOrder.confirm.partRequestApprove': 'Approve {{lines}} for {{woNumber}}?',
  'workOrder.confirm.partRequestApproveNoWorkOrder': 'Approve {{lines}} for this Work Order?',
  'workOrder.confirm.partRequestCancel': 'Cancel {{lines}} for {{woNumber}}?',
  'workOrder.confirm.partRequestCancelNoWorkOrder': 'Cancel {{lines}} for this Work Order?',
} as const;

export type MessageKey = keyof typeof MESSAGES;
export type MessageParams = Readonly<Record<string, string | number>>;

/** Every catalog key (used by the dataset parity test). */
export const MESSAGE_KEYS = Object.keys(MESSAGES) as MessageKey[];

/** Template text for a key, with placeholders intact. */
export function messageTemplate(key: MessageKey): string {
  return MESSAGES[key];
}

/** Renders a message; a placeholder without a value is left visible rather than silently dropped. */
export function message(key: MessageKey, params: MessageParams = {}): string {
  return MESSAGES[key].replace(/\{\{(\w+)\}\}/g, (whole, name: string) => (name in params ? String(params[name]) : whole));
}
