import type {
  ApiEnvelope,
  AuditLogItem,
  CatalogBrand,
  CatalogCategory,
  CatalogCompatibility,
  CatalogDevice,
  CatalogImportValidation,
  CatalogSkinDefinition,
  CatalogSpec,
  CustomerItem,
  DjgearSettings,
  HealthData,
  I18nMetadata,
  LocaleCode,
  ModulesData,
  OrderItem,
  OverviewData,
  ProductItem,
  SkinOrderJob,
  SkinPlan,
  SkinVariant,
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

  // --- Phase 3: Device Catalog API (djgear-device-catalog) ---
  public listCatalogBrands(): Promise<ApiEnvelope<{ items: CatalogBrand[] }>> {
    return this.client.get<{ items: CatalogBrand[] }>('djgear/v1/catalog/brands');
  }

  public createCatalogBrand(payload: Record<string, unknown>): Promise<ApiEnvelope<{ brand: CatalogBrand }>> {
    return this.client.post<{ brand: CatalogBrand }>('djgear/v1/catalog/brands', payload);
  }

  public deleteCatalogBrand(id: number): Promise<ApiEnvelope<{ deleted: boolean }>> {
    return this.client.delete<{ deleted: boolean }>(`djgear/v1/catalog/brands/${id}`);
  }

  public listCatalogCategories(): Promise<ApiEnvelope<{ items: CatalogCategory[] }>> {
    return this.client.get<{ items: CatalogCategory[] }>('djgear/v1/catalog/categories');
  }

  public createCatalogCategory(payload: Record<string, unknown>): Promise<ApiEnvelope<{ category: CatalogCategory }>> {
    return this.client.post<{ category: CatalogCategory }>('djgear/v1/catalog/categories', payload);
  }

  public deleteCatalogCategory(id: number): Promise<ApiEnvelope<{ deleted: boolean }>> {
    return this.client.delete<{ deleted: boolean }>(`djgear/v1/catalog/categories/${id}`);
  }

  public listCatalogDevices(params?: {
    q?: string;
    brand?: string | number;
    category?: string | number;
    status?: string;
    skin_ready?: string;
    page?: number;
    per_page?: number;
  }): Promise<ApiEnvelope<{ items: CatalogDevice[] }>> {
    return this.client.get<{ items: CatalogDevice[] }>('djgear/v1/catalog/devices', params);
  }

  public getCatalogDevice(id: number): Promise<
    ApiEnvelope<{
      device: CatalogDevice;
      specs: CatalogSpec[];
      compatibilities: CatalogCompatibility[];
      skins: CatalogSkinDefinition[];
    }>
  > {
    return this.client.get(`djgear/v1/catalog/devices/${id}`);
  }

  public createCatalogDevice(payload: Record<string, unknown>): Promise<ApiEnvelope<{ device: CatalogDevice }>> {
    return this.client.post<{ device: CatalogDevice }>('djgear/v1/catalog/devices', payload);
  }

  public deleteCatalogDevice(id: number): Promise<ApiEnvelope<{ deleted: boolean }>> {
    return this.client.delete<{ deleted: boolean }>(`djgear/v1/catalog/devices/${id}`);
  }

  public replaceCatalogSpecs(id: number, specs: CatalogSpec[]): Promise<ApiEnvelope<{ specs: CatalogSpec[] }>> {
    return this.client.put<{ specs: CatalogSpec[] }>(`djgear/v1/catalog/devices/${id}/specs`, { specs });
  }

  public listCatalogSkins(status?: string): Promise<ApiEnvelope<{ items: CatalogSkinDefinition[] }>> {
    return this.client.get<{ items: CatalogSkinDefinition[] }>('djgear/v1/catalog/skins', { status });
  }

  public createCatalogSkin(payload: Record<string, unknown>): Promise<ApiEnvelope<{ skin: CatalogSkinDefinition }>> {
    return this.client.post<{ skin: CatalogSkinDefinition }>('djgear/v1/catalog/skins', payload);
  }

  public deleteCatalogSkin(id: number): Promise<ApiEnvelope<{ deleted: boolean }>> {
    return this.client.delete<{ deleted: boolean }>(`djgear/v1/catalog/skins/${id}`);
  }

  public validateCatalogImport(payload: Record<string, unknown>): Promise<ApiEnvelope<CatalogImportValidation>> {
    return this.client.post<CatalogImportValidation>('djgear/v1/catalog/import/validate', payload);
  }

  public executeCatalogImport(
    payload: Record<string, unknown>
  ): Promise<ApiEnvelope<{ created: number; updated: number; skipped: number; errors: Array<{ index: number; message: string }> }>> {
    return this.client.post('djgear/v1/catalog/import/execute', payload);
  }

  public exportCatalog(format: 'json' | 'csv'): Promise<ApiEnvelope<{ format: string; payload?: unknown; content?: string }>> {
    return this.client.get('djgear/v1/catalog/export', { format });
  }

  // --- Phase 4: Commercial Custom Skins API (djgear-custom-skins) ---
  public listSkinVariants(params?: {
    device_id?: number;
    status?: string;
    q?: string;
    page?: number;
    per_page?: number;
  }): Promise<ApiEnvelope<{ items: SkinVariant[] }>> {
    return this.client.get<{ items: SkinVariant[] }>('djgear/v1/skins/admin/variants', params);
  }

  public createSkinVariant(payload: Record<string, unknown>): Promise<ApiEnvelope<SkinVariant>> {
    return this.client.post<SkinVariant>('djgear/v1/skins/admin/variants', payload);
  }

  public updateSkinVariant(id: number, payload: Record<string, unknown>): Promise<ApiEnvelope<SkinVariant>> {
    return this.client.put<SkinVariant>(`djgear/v1/skins/admin/variants/${id}`, payload);
  }

  public deleteSkinVariant(id: number): Promise<ApiEnvelope<{ deleted: boolean }>> {
    return this.client.delete<{ deleted: boolean }>(`djgear/v1/skins/admin/variants/${id}`);
  }

  public listSkinPlans(): Promise<ApiEnvelope<{ items: SkinPlan[] }>> {
    return this.client.get<{ items: SkinPlan[] }>('djgear/v1/skins/admin/plans');
  }

  public createSkinPlan(payload: Record<string, unknown>): Promise<ApiEnvelope<SkinPlan>> {
    return this.client.post<SkinPlan>('djgear/v1/skins/admin/plans', payload);
  }

  public updateSkinPlan(id: number, payload: Record<string, unknown>): Promise<ApiEnvelope<SkinPlan>> {
    return this.client.put<SkinPlan>(`djgear/v1/skins/admin/plans/${id}`, payload);
  }

  public deleteSkinPlan(id: number): Promise<ApiEnvelope<{ deleted: boolean }>> {
    return this.client.delete<{ deleted: boolean }>(`djgear/v1/skins/admin/plans/${id}`);
  }

  public listSkinJobs(params?: {
    status?: string;
    q?: string;
    page?: number;
    per_page?: number;
  }): Promise<ApiEnvelope<{ items: SkinOrderJob[]; counts: Record<string, number> }>> {
    return this.client.get<{ items: SkinOrderJob[]; counts: Record<string, number> }>('djgear/v1/skins/admin/jobs', params);
  }

  public transitionSkinJob(
    id: number,
    payload: { to_status: string; tracking_code?: string; internal_notes?: string; comment?: string }
  ): Promise<ApiEnvelope<SkinOrderJob>> {
    return this.client.post<SkinOrderJob>(`djgear/v1/skins/admin/jobs/${id}/transition`, payload);
  }
}
