import axios, { AxiosError, type InternalAxiosRequestConfig } from 'axios';

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
}

export function extractApiError(error: unknown): ApiErrorShape {
  const axiosError = error as AxiosError<ApiErrorShape>;
  if (axiosError.response?.data) {
    return axiosError.response.data;
  }
  return { message: axiosError.message ?? 'Unexpected error occurred' };
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
