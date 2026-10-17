import { apiClient, extractApiError } from './client';

/** A product the signed-in person may open from here (as OptiNexus answers now). The link itself is only given by `launchProduct`. */
export interface SwitcherProduct {
  code: string;
  name: string;
}

export interface SwitcherState {
  /** False when there is nothing to offer: not an OptiNexus session, tenant not linked, or OptiNexus unreachable (fail closed). */
  available: boolean;
  reason: 'not_sso_session' | 'not_linked' | 'provider_unavailable' | null;
  products: SwitcherProduct[];
  /** Unix seconds until which the server may reuse this answer. */
  expires_at: number | null;
}

/** Product switcher (audit A29): both calls are answered by the server from OptiNexus; the browser never composes a link. */
export async function listProducts(): Promise<SwitcherState> {
  try {
    const { data } = await apiClient.get<{ data: SwitcherState }>('/auth/products');
    return data.data;
  } catch (error) {
    throw extractApiError(error);
  }
}

/** The URL for one product, checked against a fresh OptiNexus answer. Refused with code PRODUCT_NOT_AVAILABLE when it is not offered. */
export async function launchProduct(code: string): Promise<{ code: string; url: string }> {
  try {
    const { data } = await apiClient.post<{ data: { code: string; url: string } }>('/auth/products/launch', { code });
    return data.data;
  } catch (error) {
    throw extractApiError(error);
  }
}
