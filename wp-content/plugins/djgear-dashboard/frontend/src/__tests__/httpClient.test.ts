import { describe, expect, it, vi } from 'vitest';
import { ApiError, WpRestClient } from '../api/httpClient';

describe('WpRestClient', () => {
  it('sends X-WP-Nonce and same-origin credentials on GET requests', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ success: true, data: { status: 'healthy' } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    );

    const client = new WpRestClient({
      restUrl: 'http://localhost:8080/wp-json/',
      nonce: 'wp_nonce_123',
      fetchImpl: fetchMock as unknown as typeof fetch,
    });

    const result = await client.get<{ status: string }>('djgear/v1/health', { page: 1 });
    expect(result.success).toBe(true);
    expect(result.data.status).toBe('healthy');

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(url).toBe('http://localhost:8080/wp-json/djgear/v1/health?page=1');
    expect(init.credentials).toBe('same-origin');
    expect((init.headers as Headers).get('X-WP-Nonce')).toBe('wp_nonce_123');
  });

  it('throws typed ApiError for 401 and 403 responses', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ code: 'djgear_rest_forbidden', message: 'Forbidden' }), {
        status: 403,
        headers: { 'Content-Type': 'application/json' },
      })
    );

    const client = new WpRestClient({
      restUrl: 'http://localhost:8080/wp-json',
      nonce: 'n',
      fetchImpl: fetchMock as unknown as typeof fetch,
    });

    await expect(client.get('djgear/v1/modules')).rejects.toSatisfy((err: unknown) => {
      return err instanceof ApiError && err.isForbidden() && err.code === 'djgear_rest_forbidden';
    });
  });
});
