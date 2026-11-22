import type { TranslationKey } from '../i18n/dictionaries';

export type RouteId =
  | 'overview'
  | 'products'
  | 'orders'
  | 'customers'
  | 'sms'
  | 'email'
  | 'forms'
  | 'seo'
  | 'analytics'
  | 'payments'
  | 'backup'
  | 'modules'
  | 'settings';

export type NavGroupId = 'core' | 'commerce' | 'comms' | 'growth' | 'operations';

export interface RouteDefinition {
  id: RouteId;
  group: NavGroupId;
  labelKey: TranslationKey;
  requiredCapability: string;
  secondaryCapabilities?: string[];
  status: 'live' | 'planned';
  targetModuleId?: string;
  contractSummary?: string;
}

export const NAV_GROUPS: Array<{ id: NavGroupId; labelKey: TranslationKey }> = [
  { id: 'core', labelKey: 'navGroupCore' },
  { id: 'commerce', labelKey: 'navGroupCommerce' },
  { id: 'comms', labelKey: 'navGroupComms' },
  { id: 'growth', labelKey: 'navGroupGrowth' },
  { id: 'operations', labelKey: 'navGroupOperations' },
];

export const ROUTES: RouteDefinition[] = [
  {
    id: 'overview',
    group: 'core',
    labelKey: 'navOverview',
    requiredCapability: 'djgear_view_dashboard',
    status: 'live',
  },
  {
    id: 'products',
    group: 'commerce',
    labelKey: 'navProducts',
    requiredCapability: 'djgear_view_dashboard',
    secondaryCapabilities: ['edit_products', 'manage_woocommerce'],
    status: 'live',
  },
  {
    id: 'orders',
    group: 'commerce',
    labelKey: 'navOrders',
    requiredCapability: 'djgear_view_dashboard',
    secondaryCapabilities: ['edit_shop_orders', 'manage_woocommerce'],
    status: 'live',
  },
  {
    id: 'customers',
    group: 'commerce',
    labelKey: 'navCustomers',
    requiredCapability: 'djgear_view_dashboard',
    secondaryCapabilities: ['list_users', 'manage_woocommerce'],
    status: 'live',
    targetModuleId: 'djgear-crm',
  },
  {
    id: 'sms',
    group: 'comms',
    labelKey: 'navSms',
    requiredCapability: 'djgear_view_dashboard',
    status: 'planned',
    targetModuleId: 'djgear-sms-center',
    contractSummary: 'DJGear\\Core\\Providers\\Sms\\SmsProviderInterface + ProviderRegistry',
  },
  {
    id: 'email',
    group: 'comms',
    labelKey: 'navEmail',
    requiredCapability: 'djgear_view_dashboard',
    status: 'planned',
    targetModuleId: 'djgear-email-center',
    contractSummary: 'DJGear\\Core\\Providers\\Email\\EmailProviderInterface + Mailpit dev router',
  },
  {
    id: 'forms',
    group: 'growth',
    labelKey: 'navForms',
    requiredCapability: 'djgear_view_dashboard',
    status: 'planned',
    targetModuleId: 'djgear-form-builder',
    contractSummary: 'DJGear\\Core\\Modules\\ModuleInterface + djgear/v1 RestKernel',
  },
  {
    id: 'seo',
    group: 'growth',
    labelKey: 'navSeo',
    requiredCapability: 'djgear_view_dashboard',
    status: 'planned',
    targetModuleId: 'djgear-seo-manager',
    contractSummary: 'DJGear\\Core\\I18n\\Translator (Persian RTL + English LTR) + WooCommerce schema hooks',
  },
  {
    id: 'analytics',
    group: 'growth',
    labelKey: 'navAnalytics',
    requiredCapability: 'djgear_view_dashboard',
    status: 'planned',
    targetModuleId: 'djgear-analytics',
    contractSummary: 'HPOS-safe WooCommerce OrderUtil + Redis Object Cache telemetry',
  },
  {
    id: 'payments',
    group: 'operations',
    labelKey: 'navPayments',
    requiredCapability: 'djgear_manage_integrations',
    status: 'planned',
    targetModuleId: 'djgear-payment-manager',
    contractSummary: 'DJGear\\Core\\Providers\\Payment\\PaymentProviderInterface (sandbox/live isolated)',
  },
  {
    id: 'backup',
    group: 'operations',
    labelKey: 'navBackup',
    requiredCapability: 'djgear_view_logs',
    status: 'planned',
    targetModuleId: 'djgear-backup-security',
    contractSummary: 'DJGear\\Core\\Logging\\AuditLoggerInterface (live audit stream active below)',
  },
  {
    id: 'modules',
    group: 'operations',
    labelKey: 'navModules',
    requiredCapability: 'djgear_manage_modules',
    status: 'live',
  },
  {
    id: 'settings',
    group: 'operations',
    labelKey: 'navSettings',
    requiredCapability: 'djgear_manage_core',
    status: 'live',
  },
];

export function hasRoutePermission(
  route: RouteDefinition,
  capabilities: Record<string, boolean>
): boolean {
  if (!capabilities[route.requiredCapability]) {
    return false;
  }
  if (route.secondaryCapabilities && route.secondaryCapabilities.length > 0) {
    return route.secondaryCapabilities.some((cap) => Boolean(capabilities[cap]));
  }
  return true;
}

export function getRouteById(id: RouteId): RouteDefinition {
  return ROUTES.find((r) => r.id === id) ?? ROUTES[0];
}
