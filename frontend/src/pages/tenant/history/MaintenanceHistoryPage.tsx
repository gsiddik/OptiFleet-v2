import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { apiClient } from "../../../api/client";
import { inputStyle } from "../../../components/FormField";
import { Pagination } from "../../../components/Pagination";
import { StatusBadge } from "../../../components/StatusBadge";
import {
  EmptyState,
  ErrorState,
  LoadingState,
} from "../../../components/States";
import { useAuth } from "../../../auth/AuthContext";
import { useApiList } from "../../../hooks/useApiList";
import type { HistoryEventItem } from "../../../types";
import { formatDateTime } from "../../../utils/date";
import { t as tt } from '../../../i18n/i18n';

const TYPES = [
  "",
  "WORK_ORDER",
  "MAINTENANCE_REQUEST",
  "INSPECTION",
  "BREAKDOWN",
  "QC",
  "VEHICLE_RELEASE",
];
const cell = {
  padding: "8px 10px",
  borderBottom: "1px solid #f3f4f6",
  textAlign: "left",
} as const;

/** The record a history event comes from (Work Order events link to the Work Order). */
function eventLink(e: HistoryEventItem): string | null {
  if (e.type === "WORK_ORDER") return `/app/work-orders/${e.id}`;
  if (e.work_order_id) return `/app/work-orders/${e.work_order_id}`;
  return null;
}

/**
 * Maintenance History: every vehicle the user may see — the whole tenant or the assigned branches,
 * decided by the backend — newest first, server-side paginated. No vehicle has to be picked; the
 * filters are optional. (Vehicle → History stays vehicle-specific.)
 */
export function MaintenanceHistoryPage() {
  const { hasPermission } = useAuth();
  const [search, setSearch] = useState("");
  const [branchId, setBranchId] = useState("");
  const [type, setType] = useState("");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [page, setPage] = useState(1);
  const [branches, setBranches] = useState<{ id: string; name: string }[]>([]);
  const { data, meta, loading, error } = useApiList<HistoryEventItem>(
    "/app/maintenance-history",
    {
      search: search || undefined,
      branch_id: branchId || undefined,
      type: type || undefined,
      date_from: dateFrom || undefined,
      date_to: dateTo || undefined,
      page,
      per_page: 25,
    },
    0,
  );

  useEffect(() => {
    if (!hasPermission("branch.view")) return;
    apiClient
      .get("/app/branches", { params: { per_page: 100 } })
      .then((res) => setBranches(res.data.data))
      .catch(() => setBranches([]));
  }, [hasPermission]);

  const reset =
    <T,>(set: (v: T) => void) =>
    (v: T) => {
      set(v);
      setPage(1);
    };

  return (
    <div>
      <h1 style={{ fontSize: 22, marginBottom: 6 }}>{tt('history.titles.maintenanceHistory')}</h1>
      <p style={{ fontSize: 13, color: "#6b7280", marginTop: 0 }}>
        {tt('history.help.maintenanceEventsEveryVehicleAccessScope')}
      </p>
      <div
        style={{ display: "flex", gap: 8, flexWrap: "wrap", marginBottom: 14 }}
        data-history-filters
      >
        <input
          aria-label={tt('history.fields.searchRegistration')}
          placeholder={tt('history.search.searchRegistration')}
          value={search}
          onChange={(e) => reset(setSearch)(e.target.value)}
          style={{ ...inputStyle, width: 200 }}
        />
        {branches.length > 1 && (
          <select
            aria-label={tt('common.fields.branch')}
            value={branchId}
            onChange={(e) => reset(setBranchId)(e.target.value)}
            style={{ ...inputStyle, width: 200 }}
          >
            <option value="">{tt('history.filters.allBranches')}</option>
            {branches.map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}
              </option>
            ))}
          </select>
        )}
        <select
          aria-label={tt('history.fields.eventType')}
          value={type}
          onChange={(e) => reset(setType)(e.target.value)}
          style={{ ...inputStyle, width: 200 }}
        >
          {TYPES.map((t) => (
            <option key={t} value={t}>
              {t ? t.replaceAll("_", " ") : tt('history.filters.allEventTypes')}
            </option>
          ))}
        </select>
        <input
          aria-label={tt('history.fields.fromDate')}
          type="date"
          value={dateFrom}
          onChange={(e) => reset(setDateFrom)(e.target.value)}
          style={{ ...inputStyle, width: 160 }}
        />
        <input
          aria-label={tt('history.fields.toDate')}
          type="date"
          value={dateTo}
          onChange={(e) => reset(setDateTo)(e.target.value)}
          style={{ ...inputStyle, width: 160 }}
        />
      </div>

      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && (
        <EmptyState label={tt('history.empty.noMaintenanceHistoryAccessScope')} />
      )}
      {!error && !loading && data.length > 0 && (
        <div className="card" style={{ overflowX: "auto", padding: 0 }}>
          <table
            style={{ width: "100%", borderCollapse: "collapse", fontSize: 13 }}
            data-maintenance-history
          >
            <thead>
              <tr style={{ background: "#f9fafb" }}>
                <th style={cell}>{tt('common.fields.date')}</th>
                <th style={cell}>{tt('common.fields.vehicle')}</th>
                <th style={cell}>{tt('common.fields.branch')}</th>
                <th style={cell}>{tt('common.fields.event')}</th>
                <th style={cell}>{tt('history.fields.details')}</th>
              </tr>
            </thead>
            <tbody>
              {data.map((e) => {
                const link = eventLink(e);
                return (
                  <tr
                    key={`${e.type}-${e.id}`}
                    data-history-row={e.vehicle?.registration_number}
                  >
                    <td style={{ ...cell, whiteSpace: "nowrap" }}>
                      {formatDateTime(e.at)}
                    </td>
                    <td style={cell}>
                      <Link
                        to={`/app/vehicle-history?vehicle_id=${e.vehicle_id}`}
                      >
                        {e.vehicle?.registration_number ?? "—"}
                      </Link>
                      <div style={{ color: "#6b7280", fontSize: 12 }}>
                        {[e.vehicle?.brand, e.vehicle?.model]
                          .filter(Boolean)
                          .join(" ")}
                      </div>
                    </td>
                    <td style={cell}>{e.vehicle?.branch_name ?? "—"}</td>
                    <td style={cell}>
                      <StatusBadge status={e.type} />
                    </td>
                    <td style={cell}>
                      {link ? <Link to={link}>{e.summary}</Link> : e.summary}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
      {meta && meta.last_page > 1 && (
        <Pagination meta={meta} onPageChange={setPage} />
      )}
    </div>
  );
}
