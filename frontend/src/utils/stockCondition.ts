/** A Part Request line / planned part of USED stock (REUSE tires) is labelled apart from new stock. */
export function lineName(
  name: string,
  stockCondition?: "NEW" | "USED" | null,
): string {
  return stockCondition === "USED" ? `${name} (Used)` : name;
}
