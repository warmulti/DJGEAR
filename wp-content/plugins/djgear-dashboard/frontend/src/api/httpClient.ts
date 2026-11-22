import type { ApiEnvelope } from '../types/api';

export class ApiError extends Error {
  public readonly status: number;
  public readonly code: string;

  constructor(status: number, code: string, message: string) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
  }

  public isUnauthorized(): boolean {
    return this.status === 401;
  }

  public isForbidden(): boolean {
    return this.status === 403;
  }
}

export interface HttpClientConfig {
  restUrl: string;
  nonce: string;
  fetchImpl?: typeof fetch;
}

export class WpRestClient {
  private readonly baseUrl: string;
  private readonly nonce: string;
  private readonly fetchFn: typeof fetch;

  constructor(config: HttpClientConfig) {
    this.baseUrl = config.restUrl.replace(/\/+$/, '');
    this.nonce = config.nonce;
    this.fetchFn = config.fetchImpl ?? window.fetch.bind(window);
  }

  public async get<T>(path: string, params?: Record<string, string | number | undefined>): Promise<ApiEnvelope<T>> {
    const url = this.buildUrl(path, params);
    return this.request<T>(url, { method: 'GET' });
  }

  public async post<T>(path: string, body: Record<string, unknown>): Promise<ApiEnvelope<T>> {
    const url = this.buildUrl(path);
    return this.request<T>(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
  }

  private buildUrl(path: string, params?: Record<string, string | number | undefined>): string {
    const cleanPath = path.replace(/^\/+/, '');
    const url = new URL(`${this.baseUrl}/${cleanPath}`, window.location.origin);

    if (params) {
      for (const [key, value] of Object.entries(params)) {
        if (value !== undefined && value !== '') {
          url.searchParams.set(key, String(value));
        }
      }
    }

    return url.toString();
  }

  private async request<T>(url: string, init: RequestInit): Promise<ApiEnvelope<T>> {
    const headers = new Headers(init.headers);
    headers.set('X-WP-Nonce', this.nonce);
    headers.set('Accept', 'application/json');

    let response: Response;
    try {
      response = await this.fetchFn(url, {
        ...init,
        headers,
        credentials: 'same-origin',
      });
    } catch (err) {
      throw new ApiError(0, 'network_error', err instanceof Error ? err.message : 'Network failure');
    }

    let json: Record<string, unknown> | null = null;
    try {
      json = (await response.json()) as Record<string, unknown>;
    } catch {
      json = null;
    }

    if (!response.ok) {
      const code = typeof json?.code === 'string' ? json.code : `http_${response.status}`;
      const message =
        typeof json?.message === 'string'
          ? json.message
          : `Request failed with HTTP ${response.status}`;
      throw new ApiError(response.status, code, message);
    }

    return json as unknown as ApiEnvelope<T>;
  }
}
