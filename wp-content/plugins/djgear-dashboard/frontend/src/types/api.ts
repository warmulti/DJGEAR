export type LocaleCode = 'fa_IR' | 'en_US';
export type TextDirection = 'rtl' | 'ltr';

export interface I18nMetadata {
  locale: string;
  direction: TextDirection;
  is_rtl: boolean;
  text_domain: string;
  supported_locales: string[];
}

export interface DashboardUser {
  id: number;
  displayName: string;
  email: string;
  roles: string[];
  capabilities: Record<string, boolean>;
}

export interface DashboardBootConfig {
  version: string;
  restUrl: string;
  nonce: string;
  adminUrl: string;
  siteTitle: string;
  environment: string;
  i18n: I18nMetadata;
  currentUser: DashboardUser;
}

export interface ApiMeta {
  total?: number;
  page?: number;
  per_page?: number;
}

export interface ApiEnvelope<T> {
  success: boolean;
  data: T;
  meta?: ApiMeta;
}

export interface HealthData {
  status: string;
  core: {
    version: string;
    environment: string;
    php_version: string;
    wp_version: string;
  };
  woocommerce: {
    active: boolean;
    version: string | null;
    hpos_compatible: boolean;
    hpos_tables_enabled: boolean;
    source_of_truth_policy: string;
  };
  i18n: I18nMetadata;
  providers: {
    email: string[];
    sms: string[];
    payment: string[];
  };
  object_cache: {
    external: boolean;
  };
}

export interface AuditLogItem {
  id: number | null;
  action: string;
  resource_type: string;
  resource_id: string;
  actor_id: number | null;
  context: Record<string, unknown>;
  ip_address: string;
  created_at: string | null;
}

export interface CommerceMetrics {
  woocommerce_active: boolean;
  currency: string;
  products_total: number;
  products_published: number;
  orders_total: number;
  orders_processing: number;
  orders_completed: number;
  customers_total: number;
  users_total: number;
}

export interface OverviewData {
  commerce: CommerceMetrics;
  modules: {
    registered_count: number;
    booted_ids: string[];
  };
  recent_audit: {
    total: number;
    items: AuditLogItem[];
  };
}

export interface ModuleItem {
  id: string;
  name: string;
  version: string;
  dependencies: string[];
  enabled: boolean;
  booted: boolean;
}

export interface ModulesData {
  modules: ModuleItem[];
  count: number;
}

export interface ProductItem {
  id: number;
  name: string;
  sku: string;
  type: string;
  status: string;
  price: string;
  regular_price: string;
  stock_status: string;
  stock_quantity: number | null;
  edit_url: string;
}

export interface OrderItem {
  id: number;
  order_number: string;
  status: string;
  total: string;
  currency: string;
  customer_name: string;
  customer_email: string;
  item_count: number;
  created_at: string | null;
  edit_url: string;
}

export interface CustomerItem {
  id: number;
  display_name: string;
  email: string;
  roles: string[];
  registered: string;
  order_count: number;
  total_spent: string;
  edit_url: string;
}

export interface DjgearSettings {
  brand_name: string;
  default_locale: LocaleCode;
  persian_digits: boolean;
  audit_retention_days: number;
  maintenance_notice: string;
}

export interface CatalogBrand {
  id: number;
  slug: string;
  name_fa: string;
  name_en: string;
  logo_attachment_id: number | null;
  logo_url: string | null;
  country_code: string;
  website_url: string;
  device_count: number;
}

export interface CatalogCategory {
  id: number;
  slug: string;
  parent_id: number;
  name_fa: string;
  name_en: string;
  icon_key: string;
  sort_order: number;
  device_count: number;
}

export interface CatalogDevice {
  id: number;
  slug: string;
  brand_term_id: number;
  category_term_id: number;
  brand: CatalogBrand | null;
  category: CatalogCategory | null;
  model_code: string;
  canonical_key: string;
  mpn: string | null;
  name_fa: string;
  name_en: string;
  summary_fa: string;
  summary_en: string;
  release_year: number | null;
  lifecycle_status: 'active' | 'discontinued' | 'upcoming' | 'draft';
  skin_ready: boolean;
  primary_wc_product_id: number | null;
  hero_image_url: string | null;
  updated_at: string;
}

export interface CatalogSpec {
  id?: number;
  spec_group: string;
  spec_key: string;
  label_fa: string;
  label_en: string;
  value_fa: string;
  value_en: string;
  value_type: string;
  numeric_value?: number | null;
  unit: string;
  is_filterable: boolean;
}

export interface CatalogCompatibility {
  id: number;
  source_device_id: number;
  target_type: 'device' | 'wc_product' | 'skin_definition';
  target_id: number;
  relation_type: string;
  fit_confidence: string;
  notes_fa: string;
  notes_en: string;
  wc_product?: { id: number; name: string; sku: string; price: string; currency: string };
  device?: { id: number; slug: string; model_code: string; name_fa: string; name_en: string };
}

export interface CatalogSkinDefinition {
  id: number;
  primary_device_id: number;
  compatible_device_ids: number[];
  template_code: string;
  template_version: string;
  status: 'draft' | 'active' | 'deprecated';
  width_mm: number;
  height_mm: number;
  bleed_mm: number;
  min_dpi: number;
  zones: Array<{ id: string; label_fa: string; label_en: string; required: boolean; printable: boolean }>;
  supported_finishes: string[];
  preview_attachment_id: number | null;
  preview_image_url: string | null;
  dieline_attachment_id?: number | null;
  dieline_url?: string | null;
  base_wc_product_id: number | null;
  internal_notes?: string;
  notes_fa: string;
  notes_en: string;
  updated_at: string;
}

export interface CatalogImportValidation {
  valid: boolean;
  schema_version: string;
  summary: { total: number; create: number; update: number; skip: number; errors: number };
  rows: Array<{ index: number; canonical_key?: string; model_code?: string; action: string; error?: string }>;
}

declare global {
  interface Window {
    DJGEAR_DASHBOARD_CONFIG?: DashboardBootConfig;
  }
}
