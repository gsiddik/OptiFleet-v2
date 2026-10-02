import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiClient, extractApiError } from '../../../api/client';
import { BackButton } from '../../../components/BackButton';
import { ErrorState, LoadingState } from '../../../components/States';
import { useBreadcrumbLabel } from '../../../navigation/BreadcrumbLabelContext';
import type { ProductItem, TireInventorySummary } from '../../../types';

type TireProductDetail = ProductItem & { inventory: TireInventorySummary };

/** Tire Detail: one Tire Product (Product of Item Type TIRE) and the inventory of its physical tires. */
export function TireProductDetailPage() {
  const { productId } = useParams<{ productId: string }>();
  const [product, setProduct] = useState<TireProductDetail | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiClient
      .get(`/app/tire-products/${productId}`)
      .then((res) => setProduct(res.data.data))
      .catch((e) => setError(extractApiError(e).message));
  }, [productId]);
  useBreadcrumbLabel(product?.id, product?.name);

  if (error) return <ErrorState message={error} />;
  if (!product) return <LoadingState />;

  return (
    <div>
      <BackButton fallbackTo="/app/tires" label="← Back to Tires" />
      <h1 style={{ fontSize: 22, margin: '8px 0 16px' }}>
        {product.brand ? `${product.brand} — ` : ''}
        {product.name}
      </h1>
      <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }} data-tire-inventory-summary>
        <Count label="New Stock" value={product.inventory.new_qty} />
        <Count label="Installed" value={product.inventory.installed_qty} />
        <Count label="Used Stock" value={product.inventory.used_qty} />
      </div>
    </div>
  );
}

function Count({ label, value }: { label: string; value: number }) {
  return (
    <div className="card" style={{ padding: '10px 16px', minWidth: 120 }}>
      <div style={{ fontSize: 12, color: '#6b7280' }}>{label}</div>
      <div style={{ fontSize: 20, fontWeight: 700 }}>{value}</div>
    </div>
  );
}
