import axios, { AxiosError, type InternalAxiosRequestConfig } from 'axios';
import { appLocale } from '../i18n/locale';
import { t } from '../i18n/i18n';

export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api/v1';

export const apiClient = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    Accept: 'application/json',
  },
});

apiClient.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = localStorage.getItem('optifleet_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  // API language contract: the active UI locale. Messages are localized; codes never are.
  config.headers['Accept-Language'] = appLocale();
  const tenantId = localStorage.getItem('optifleet_active_tenant');
  if (tenantId) {
    config.headers['X-Tenant-ID'] = tenantId;
  }
  return config;
});

export interface ApiErrorShape {
  message: string;
  errors?: Record<string, string[]>;
  /**
   * Machine-readable error per field for coded validation errors ({code, params}; code = EN-ID dataset
   * key). Branch on this, never on the English message text.
   */
  codes?: Record<string, { code: string; params: Record<string, unknown> }>;
  /** Stable English error code of the API (e.g. `GOVERNANCE_UNAVAILABLE`, `ROLE_IN_USE`); branch on it, never on `message`. */
  code?: string;
  /** Reference to quote to support; the same id is in the server logs and the audit trail. */
  correlation_id?: string;
  /** The same request may succeed later (outage, rate limit). */
  retryable?: boolean;
  /** A change was sent but no answer arrived: it may or may not have been applied. */
  outcome_unknown?: boolean;
  /** Field-specific detail of a refusal (for example the permissions that cannot be delegated). */
  details?: Record<string, unknown>;
  /** HTTP status; absent when no answer arrived at all (offline, blocked, timeout). */
  status?: number;
}

export function extractApiError(error: unknown): ApiErrorShape {
  const axiosError = error as AxiosError<ApiErrorShape>;
  if (axiosError.response?.data && typeof axiosError.response.data === 'object') {
    return { ...axiosError.response.data, status: axiosError.response.status };
  }
  if (axiosError.response) {
    return { message: t('common.errors.unexpectedErrorOccurred'), status: axiosError.response.status };
  }
  return { message: axiosError.message ?? t('common.errors.unexpectedErrorOccurred') };
}

apiClient.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('optifleet_token');
    }
    return Promise.reject(error);
  },
);
