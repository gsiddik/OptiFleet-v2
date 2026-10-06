/**
 * Display labels for the platform's workflow automated-action catalog (backend WorkflowActionCatalog).
 * Codes are canonical; labels are looked up, never derived by reformatting the code.
 */
export interface AutomatedActionEntry {
  readonly key: string;
  readonly en: string;
}

export const AUTOMATED_ACTIONS: Readonly<Record<string, AutomatedActionEntry>> = {
  SEND_NOTIFICATION: { key: 'workflow.automatedAction.sendNotification', en: 'Send notification' },
  ASSIGN_USER: { key: 'workflow.automatedAction.assignUser', en: 'Assign user' },
  CREATE_TASK: { key: 'workflow.automatedAction.createTask', en: 'Create task' },
  GENERATE_DOCUMENT_NUMBER: { key: 'workflow.automatedAction.generateDocumentNumber', en: 'Generate document number' },
  GENERATE_DOCUMENT: { key: 'workflow.automatedAction.generateDocument', en: 'Generate document' },
  RESERVE_STOCK: { key: 'workflow.automatedAction.reserveStock', en: 'Reserve stock' },
  RELEASE_RESERVATION: { key: 'workflow.automatedAction.releaseReservation', en: 'Release reservation' },
  CREATE_WORK_ORDER: { key: 'workflow.automatedAction.createWorkOrder', en: 'Create Work Order' },
  UPDATE_RESOURCE_STATUS: { key: 'workflow.automatedAction.updateResourceStatus', en: 'Update resource status' },
  CREATE_APPROVAL_RECORD: { key: 'workflow.automatedAction.createApprovalRecord', en: 'Create approval record' },
};

/** Label for an automated-action code; an unknown code is shown unchanged. */
export function automatedActionLabel(code: string): string {
  return AUTOMATED_ACTIONS[code]?.en ?? code;
}
