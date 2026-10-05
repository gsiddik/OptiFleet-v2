/** API contract of /app/tire-cycles and /app/tires/:id/cycle-history (TireCycleService::present). */
export type CycleKind = "RETREAD" | "REPAIR";
/** IN_PROCESS (opened) → RECEIVED → REINSPECTION (Tire Inspection submitted) → COMPLETED. */
export type CycleState =
  | "IN_PROCESS"
  | "RECEIVED"
  | "REINSPECTION"
  | "COMPLETED";

export interface TireCycle {
  id: string;
  kind: CycleKind;
  cycle_number: number;
  status: string;
  state: CycleState;
  vendor: { id: string; name: string } | null;
  estimated_price: string | null;
  notes: string | null;
  sent_at: string | null;
  received_at: string | null;
  inspection: { id: string; recommendation?: string; status?: string } | null;
  inspection_result: string | null;
  final_status: string | null;
  photos: { id: string; original_filename: string; mime_type: string }[];
}

export interface CycleListRow {
  tire: {
    id: string;
    serial_number: string;
    current_status: string;
    product: { id: string; name: string; sku: string | null } | null;
  };
  kind: CycleKind;
  cycle: TireCycle | null;
}
