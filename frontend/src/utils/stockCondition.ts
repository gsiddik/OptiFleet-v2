import { t } from '../i18n/i18n';
/** A Part Request line / planned part of USED stock (REUSE tires) is labelled apart from new stock. */
export function lineName(
  name: string,
  stockCondition?: "NEW" | "USED" | null,
): string {
  return stockCondition === "USED" ? t('common.fields.nameUsed', { name: name }) : name;
}
