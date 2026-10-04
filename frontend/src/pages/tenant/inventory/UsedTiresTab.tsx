import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { apiClient } from "../../../api/client";
import { inputStyle } from "../../../components/FormField";
import { Pagination } from "../../../components/Pagination";
import { Table, type Column } from "../../../components/Table";
import {
  EmptyState,
  ErrorState,
  LoadingState,
} from "../../../components/States";
import { useApiList } from "../../../hooks/useApiList";
import { formatQty } from "../../../utils/quantity";

interface UsedTireStockRow {
  id: string;
  warehouse: { id: string; code: string; name: string } | null;
  product: { id: string; name: string; sku: string | null } | null;
  quantity_on_hand: number;
  serials: { id: string; serial_number: string }[];
}

/**
 * Warehouse Stock → Used Tires: the used tire quantity (REUSE tires approved in Used Tire
 * Management) per warehouse and tire product. It is separate from the new-stock On Hand and is
 * issued through Part Requests as "Used" lines of a Tire Operation replacement.
 */
export function UsedTiresTab() {
  const [warehouseId, setWarehouseId] = useState("");
  const [search, setSearch] = useState("");
  const [debounced, setDebounced] = useState("");
  const [page, setPage] = useState(1);
  const [warehouses, setWarehouses] = useState<{ id: string; name: string }[]>(
    [],
  );

  useEffect(() => {
    apiClient
      .get("/app/warehouses", { params: { per_page: 100 } })
      .then((res) => setWarehouses(res.data.data))
      .catch(() => setWarehouses([]));
  }, []);
  useEffect(() => {
    const t = setTimeout(() => setDebounced(search.trim()), 300);
    return () => clearTimeout(t);
  }, [search]);

  const { data, meta, loading, error } = useApiList<UsedTireStockRow>(
    "/app/inventory/used-tires",
    {
      warehouse_id: warehouseId || undefined,
      search: debounced || undefined,
      page,
    },
  );

  const columns: Column<UsedTireStockRow>[] = [
    {
      key: "product",
      header: "Tire Product",
      render: (r) =>
        r.product ? (
          <Link to={`/app/tires/products/${r.product.id}`}>
            {r.product.name}
          </Link>
        ) : (
          "—"
        ),
    },
    { key: "sku", header: "SKU", render: (r) => r.product?.sku ?? "—" },
    {
      key: "warehouse",
      header: "Warehouse",
      render: (r) => r.warehouse?.name ?? "—",
    },
    {
      key: "qty",
      header: "Used Qty (REUSE)",
      render: (r) => (
        <span data-used-tire-qty={r.quantity_on_hand}>
          {formatQty(r.quantity_on_hand)}
        </span>
      ),
    },
    {
      key: "serials",
      header: "Serial Numbers",
      render: (r) =>
        r.serials.length === 0
          ? "—"
          : r.serials.map((t, i) => (
              <span key={t.id}>
                {i > 0 && ", "}
                <Link to={`/app/tires/${t.id}`}>{t.serial_number}</Link>
              </span>
            )),
    },
  ];

  return (
    <div>
      <p style={{ fontSize: 13, color: "#6b7280", marginTop: 0 }}>
        Used tires inspected as REUSE. Kept apart from new stock and issued
        through Part Requests (Used lines).
      </p>
      <div
        style={{ display: "flex", gap: 8, flexWrap: "wrap", marginBottom: 12 }}
      >
        <select
          aria-label="Used tires warehouse"
          value={warehouseId}
          onChange={(e) => {
            setWarehouseId(e.target.value);
            setPage(1);
          }}
          style={{ ...inputStyle, width: 200 }}
        >
          <option value="">All warehouses</option>
          {warehouses.map((w) => (
            <option key={w.id} value={w.id}>
              {w.name}
            </option>
          ))}
        </select>
        <input
          aria-label="Search used tires"
          placeholder="Search tire product…"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setPage(1);
          }}
          style={{ ...inputStyle, width: 240 }}
        />
      </div>
      {error && <ErrorState message={error} />}
      {!error && loading && <LoadingState />}
      {!error && !loading && data.length === 0 && (
        <EmptyState label="No used tires in stock." />
      )}
      {!error && !loading && data.length > 0 && (
        <Table columns={columns} rows={data} />
      )}
      {meta && <Pagination meta={meta} onPageChange={setPage} />}
    </div>
  );
}
