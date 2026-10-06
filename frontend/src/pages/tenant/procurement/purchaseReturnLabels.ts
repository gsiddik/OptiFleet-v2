import type { PurchaseReturnOption } from "../../../types";
import { translatedRecord } from '../../../i18n/i18n';

export const RETURN_OPTION_LABEL: Record<PurchaseReturnOption, string> = translatedRecord({
  REFUND: "Refund Request",
  REDELIVERY: "Redelivery Request",
}, { REFUND: 'procurement.returnOption.refund', REDELIVERY: 'procurement.returnOption.redelivery' });
