import { useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { useAuth } from "../../../../auth/AuthContext";
import { Pagination } from "../../../../components/Pagination";
import {
  EmptyState,
  ErrorState,
  LoadingState,
} from "../../../../components/States";
import { StatusBadge } from "../../../../components/StatusBadge";
import { Toolbar } from "../../../../components/Toolbar";
import { useApiList } from "../../../../hooks/useApiList";
import { formatDate } from "../../../../utils/date";

export interface ScrappedTireRow {
  id: string;
  serial_number: string;
  current_status: string;
  scrapped_at: string | null;
  product_id: string | null;
  product_name: string | null;
  sale_id: string | null;
  sale_status: string | null;
}

const cell = {
  padding: "8px 12px",
  borderBottom: "1px solid #f3f4f6",
} as const;

/**
 * Used Tire Management → Scrap → "Recently Scrapped": select one or more SCRAPPED tires (row Sell
 * or the bulk Sell button) and carry them to Sell Sparepart. The backend re-checks every tire.
 */
export function ScrappedTiresPanel() {
  const { hasPermission } = useAuth();
  const navigate = useNavigate();
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const { data, meta, loading, error } = useApiList<ScrappedTireRow>(
    "/app/tires-scrapped",
    { search: search || undefined, page, per_page: 20 },
    0,
  );
  const canSell = hasPermission("sparepart_sale.create");
  // A tire already in an open or approved sale cannot be sold again.
  const sellable = data.filter((t) => !t.sale_id);
  const allSelected =
    sellable.length > 0 && sellable.every((t) => selected.has(t.id));

  function toggle(id: string) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  function toggleAll() {
    // Select All covers the rows on the current page only.
    setSelected((prev) => {
      const next = new Set(prev);
      sellable.forEach((t) =>
        allSelected ? next.delete(t.id) : next.add(t.id),
      );
      return next;
    });
  }

  function sell(ids: string[]) {
    navigate(`/app/sparepart-sales?tire_ids=${ids.join(",")}`);
  }

  return (
    <section className="card" style={{ marginBottom: 16 }} data-scrapped-tires>
      <div
        style={{
          display: "flex",
          justifyContent: "space-between",
          alignItems: "center",
          gap: 8,
          flexWrap: "wrap",
        }}
      >
        <h3 style={{ margin: 0, fontSize: 15 }}>
          Recently Scrapped{" "}
          {meta && (
            <span style={{ color: "#6b7280", fontWeight: 400 }}>
              ({meta.total})
            </span>
          )}
        </h3>
        {canSell && (
          <button
            className="btn-primary"
            data-bulk-sell
            disabled={selected.size === 0}
            onClick={() => sell([...selected])}
          >
            Sell{selected.size > 0 ? ` (${selected.size})` : ""}
          </button>
        )}
      </div>
      <Toolbar
        search={search}
        onSearchChange={(value) => {
          setSearch(value);
          setPage(1);
        }}
      />
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && (
        <EmptyState label="No scrapped tires." />
      )}
      {!error && !loading && data.length > 0 && (
        <div
          style={{
            overflowX: "auto",
            border: "1px solid #e5e7eb",
            borderRadius: 8,
          }}
        >
          <table
            style={{ width: "100%", borderCollapse: "collapse", fontSize: 14 }}
          >
            <thead>
              <tr style={{ background: "#f9fafb", textAlign: "left" }}>
                {canSell && (
                  <th style={cell}>
                    <input
                      type="checkbox"
                      aria-label="Select all scrapped tires on this page"
                      checked={allSelected}
                      disabled={sellable.length === 0}
                      onChange={toggleAll}
                    />
                  </th>
                )}
                <th style={cell}>Serial Number</th>
                <th style={cell}>Product</th>
                <th style={cell}>Status</th>
                <th style={cell}>Scrapped</th>
                <th style={cell}>Sale</th>
                {canSell && <th style={cell}>Action</th>}
              </tr>
            </thead>
            <tbody>
              {data.map((t) => (
                <tr key={t.id} data-scrapped-row={t.serial_number}>
                  {canSell && (
                    <td style={cell}>
                      <input
                        type="checkbox"
                        aria-label={`Select ${t.serial_number}`}
                        checked={selected.has(t.id)}
                        disabled={Boolean(t.sale_id)}
                        onChange={() => toggle(t.id)}
                      />
                    </td>
                  )}
                  <td style={cell}>
                    <Link to={`/app/tires/${t.id}`}>{t.serial_number}</Link>
                  </td>
                  <td style={cell}>{t.product_name ?? "—"}</td>
                  <td style={cell}>
                    <StatusBadge status={t.current_status} />
                  </td>
                  <td style={cell}>
                    {t.scrapped_at ? formatDate(t.scrapped_at) : "—"}
                  </td>
                  <td style={cell}>
                    {t.sale_status ? (
                      <StatusBadge status={t.sale_status} />
                    ) : (
                      "—"
                    )}
                  </td>
                  {canSell && (
                    <td style={cell}>
                      <button
                        className="btn-secondary"
                        style={{ padding: "4px 10px", fontSize: 12 }}
                        disabled={Boolean(t.sale_id)}
                        onClick={() => sell([t.id])}
                      >
                        Sell
                      </button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {meta && meta.last_page > 1 && (
        <Pagination meta={meta} onPageChange={setPage} />
      )}
    </section>
  );
}
