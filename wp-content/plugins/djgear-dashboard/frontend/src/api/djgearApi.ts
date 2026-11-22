import type {
  ApiEnvelope,
  AuditLogItem,
  CustomerItem,
  DjgearSettings,
  HealthData,
  I18nMetadata,
  LocaleCode,
  ModulesData,
  OrderItem,
  OverviewData,
  ProductItem,
} from '../types/api';
import { WpRestClient } from './httpClient';

export class DjgearApi {
  constructor(private readonly client: WpRestClient) {}

  public getHealth(): Promise<ApiEnvelope<HealthData>> {
    return this.client.get<HealthData>('djgear/v1/health');
  }

  public getOverview(): Promise<ApiEnvelope<OverviewData>> {
    return this.client.get<OverviewData>('djgear/v1/dashboard/overview');
  }

  public getModules(): Promise<ApiEnvelope<ModulesData>> {
    return this.client.get<ModulesData>('djgear/v1/modules');
  }

  public getAuditLogs(params?: {
    page?: number;
    per_page?: number;
    action?: string;
  }): Promise<ApiEnvelope<{ items: AuditLogItem[] }>> {
    return this.client.get<{ items: AuditLogItem[] }>('djgear/v1/audit-logs', params);
  }

  public getProducts(params?: {
    page?: number;
    per_page?: number;
    search?: string;
    status?: string;
  }): Promise<ApiEnvelope<{ items: ProductItem[]; currency: string }>> {
    return this.client.get<{ items: ProductItem[]; currency: string }>('djgear/v1/dashboard/products', params);
  }

  public getOrders(params?: {
    page?: number;
    per_page?: number;
    status?: string;
  }): Promise<ApiEnvelope<{ items: OrderItem[] }>> {
    return this.client.get<{ items: OrderItem[] }>('djgear/v1/dashboard/orders', params);
  }

  public getCustomers(params?: {
    page?: number;
    per_page?: number;
    search?: string;
  }): Promise<ApiEnvelope<{ items: CustomerItem[] }>> {
    return this.client.get<{ items: CustomerItem[] }>('djgear/v1/dashboard/customers', params);
  }

  public getSettings(): Promise<ApiEnvelope<{ settings: DjgearSettings }>> {
    return this.client.get<{ settings: DjgearSettings }>('djgear/v1/dashboard/settings');
  }

  public updateSettings(
    settings: Partial<DjgearSettings>
  ): Promise<ApiEnvelope<{ settings: DjgearSettings; saved: boolean }>> {
    return this.client.post<{ settings: DjgearSettings; saved: boolean }>('djgear/v1/dashboard/settings', settings);
  }

  public switchUserLocale(locale: LocaleCode): Promise<ApiEnvelope<{ i18n: I18nMetadata }>> {
    return this.client.post<{ i18n: I18nMetadata }>('djgear/v1/dashboard/preferences/locale', { locale });
  }
}
