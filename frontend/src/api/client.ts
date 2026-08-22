import { createApiClient, type QueryParams } from '@webhatchery/api-client';

type RequestConfig = { params?: QueryParams; headers?: HeadersInit };
type ApiResponse<T> = { data: T; status: number };
let tokenResolver: (() => string | null) | null = null;

export function registerAuthTokenResolver(resolver: () => string | null): void {
  tokenResolver = resolver;
}

const apiBaseUrl = import.meta.env.VITE_API_BASE_URL;
if (!apiBaseUrl) throw new Error('VITE_API_BASE_URL environment variable is required.');

const sharedApi = createApiClient({
  baseURL: apiBaseUrl,
  preserveEnvelope: true,
  tokenProvider: () => tokenResolver?.() ?? null,
});

const request = async <T>(method: string, endpoint: string, body?: unknown, config?: RequestConfig): Promise<ApiResponse<T>> => ({
  data: await sharedApi.request<T>(endpoint, {
    method,
    body,
    headers: config?.headers,
    query: config?.params,
  }),
  status: 200,
});

const api = {
  get: <T>(endpoint: string, config?: RequestConfig) => request<T>('GET', endpoint, undefined, config),
  post: <T>(endpoint: string, body?: unknown, config?: RequestConfig) => request<T>('POST', endpoint, body, config),
  put: <T>(endpoint: string, body?: unknown, config?: RequestConfig) => request<T>('PUT', endpoint, body, config),
  patch: <T>(endpoint: string, body?: unknown, config?: RequestConfig) => request<T>('PATCH', endpoint, body, config),
  delete: <T>(endpoint: string, config?: RequestConfig) => request<T>('DELETE', endpoint, undefined, config),
};

export default api;
