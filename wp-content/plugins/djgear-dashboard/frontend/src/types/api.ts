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

declare global {
  interface Window {
    DJGEAR_DASHBOARD_CONFIG?: DashboardBootConfig;
  }
}
