import type {
  AddToCartResponse,
  CustomerArtworkRevision,
  CustomerSkinJob,
  DpiReport,
  PublicDeviceItem,
  SkinsOptionsResponse,
} from './skins-types';

export class SkinsApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly code: string = 'request_failed'
  ) {
    super(message);
  }
}

interface Envelope<T> {
  ok?: boolean;
  data?: T;
  message?: string;
  code?: string;
}

async function parseEnvelope<T>(response: Response): Promise<T> {
  let parsed: Envelope<T> = {};
  try {
    parsed = (await response.json()) as Envelope<T>;
  } catch {
    parsed = {};
  }

  if (!response.ok) {
    throw new SkinsApiError(
      parsed.message || `Request failed (${response.status})`,
      response.status,
      parsed.code || 'http_error'
    );
  }

  return (parsed.data ?? (parsed as unknown as T)) as T;
}

export class SkinsApiClient {
  constructor(
    private readonly catalogApi: string,
    private readonly skinsApi: string,
    private readonly restNonce: string
  ) {}

  private authHeaders(includeJson = true): Record<string, string> {
    const headers: Record<string, string> = {};
    if (this.restNonce) {
      headers['X-WP-Nonce'] = this.restNonce;
    }
    if (includeJson) {
      headers['Content-Type'] = 'application/json';
    }
    return headers;
  }

  async listActiveDevices(): Promise<PublicDeviceItem[]> {
    const sep = this.catalogApi.includes('?') ? '&' : '?';
    const res = await fetch(`${this.catalogApi}${sep}per_page=30`, {
      method: 'GET',
      credentials: 'same-origin',
    });
    const data = await parseEnvelope<{ items?: PublicDeviceItem[] }>(res);
    return Array.isArray(data.items) ? data.items : [];
  }

  async getOptionsForDevice(deviceId: number): Promise<SkinsOptionsResponse> {
    const base = this.skinsApi.replace(/\/$/, '');
    const res = await fetch(`${base}/options?device_id=${encodeURIComponent(String(deviceId))}`, {
      method: 'GET',
      credentials: 'same-origin',
    });
    return parseEnvelope<SkinsOptionsResponse>(res);
  }

  async createDraftJob(payload: {
    device_id: number;
    skin_variant_id: number;
    plan_id: number;
    customer_notes?: string;
  }): Promise<CustomerSkinJob> {
    const base = this.skinsApi.replace(/\/$/, '');
    const res = await fetch(`${base}/jobs`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: this.authHeaders(true),
      body: JSON.stringify(payload),
    });
    return parseEnvelope<CustomerSkinJob>(res);
  }

  async uploadArtwork(
    jobUuid: string,
    file: File,
    note = ''
  ): Promise<{ job: CustomerSkinJob; artwork: CustomerArtworkRevision; dpi_report: DpiReport }> {
    const base = this.skinsApi.replace(/\/$/, '');
    const form = new FormData();
    form.append('file', file);
    if (note.trim() !== '') {
      form.append('note', note.trim());
    }

    const res = await fetch(`${base}/jobs/${encodeURIComponent(jobUuid)}/artwork`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: this.authHeaders(false),
      body: form,
    });
    return parseEnvelope<{ job: CustomerSkinJob; artwork: CustomerArtworkRevision; dpi_report: DpiReport }>(res);
  }

  async acknowledgeDpi(jobUuid: string, artworkId: number, sha256Hash: string): Promise<CustomerSkinJob> {
    const base = this.skinsApi.replace(/\/$/, '');
    const res = await fetch(`${base}/jobs/${encodeURIComponent(jobUuid)}/acknowledge-dpi`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: this.authHeaders(true),
      body: JSON.stringify({
        acknowledged: true,
        artwork_id: artworkId,
        sha256_hash: sha256Hash,
      }),
    });
    return parseEnvelope<CustomerSkinJob>(res);
  }

  async addJobToCart(jobUuid: string): Promise<AddToCartResponse> {
    const base = this.skinsApi.replace(/\/$/, '');
    const res = await fetch(`${base}/jobs/${encodeURIComponent(jobUuid)}/add-to-cart`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: this.authHeaders(true),
      body: JSON.stringify({}),
    });
    return parseEnvelope<AddToCartResponse>(res);
  }

  async listMyJobs(): Promise<CustomerSkinJob[]> {
    const base = this.skinsApi.replace(/\/$/, '');
    const res = await fetch(`${base}/my-jobs?page=1&per_page=25`, {
      method: 'GET',
      credentials: 'same-origin',
      headers: this.authHeaders(false),
    });
    const data = await parseEnvelope<{ items?: CustomerSkinJob[] }>(res);
    return Array.isArray(data.items) ? data.items : [];
  }

  async getMyJob(jobUuid: string): Promise<CustomerSkinJob> {
    const base = this.skinsApi.replace(/\/$/, '');
    const res = await fetch(`${base}/my-jobs/${encodeURIComponent(jobUuid)}`, {
      method: 'GET',
      credentials: 'same-origin',
      headers: this.authHeaders(false),
    });
    return parseEnvelope<CustomerSkinJob>(res);
  }

  async approveProof(jobUuid: string, comment = ''): Promise<CustomerSkinJob> {
    const base = this.skinsApi.replace(/\/$/, '');
    const res = await fetch(`${base}/my-jobs/${encodeURIComponent(jobUuid)}/approve-proof`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: this.authHeaders(true),
      body: JSON.stringify({ comment }),
    });
    return parseEnvelope<CustomerSkinJob>(res);
  }

  async requestRevision(jobUuid: string, comment = ''): Promise<CustomerSkinJob> {
    const base = this.skinsApi.replace(/\/$/, '');
    const res = await fetch(`${base}/my-jobs/${encodeURIComponent(jobUuid)}/request-revision`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: this.authHeaders(true),
      body: JSON.stringify({ comment }),
    });
    return parseEnvelope<CustomerSkinJob>(res);
  }

  async downloadRevisionBlob(downloadUrl: string): Promise<Blob> {
    const res = await fetch(downloadUrl, {
      method: 'GET',
      credentials: 'same-origin',
      headers: this.authHeaders(false),
    });
    if (!res.ok) {
      throw new SkinsApiError(`Download failed (${res.status})`, res.status, 'download_failed');
    }
    return res.blob();
  }
}
