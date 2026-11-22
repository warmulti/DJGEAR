import { useMemo, useState } from 'react';
import { DjgearApi } from './api/djgearApi';
import { WpRestClient } from './api/httpClient';
import { AppShell } from './components/layout/AppShell';
import { PermissionDeniedState } from './components/ui/StateFeedback';
import { I18nProvider } from './i18n/I18nContext';
import { getRouteById, hasRoutePermission, type RouteId } from './navigation/routes';
import { CatalogDevicesPage } from './pages/CatalogDevicesPage';
import { CatalogImportExportPage } from './pages/CatalogImportExportPage';
import { CatalogSkinsPage } from './pages/CatalogSkinsPage';
import { CatalogTaxonomiesPage } from './pages/CatalogTaxonomiesPage';
import { CustomersPage } from './pages/CustomersPage';
import { ModuleHubPage } from './pages/ModuleHubPage';
import { ModulesPage } from './pages/ModulesPage';
import { OrdersPage } from './pages/OrdersPage';
import { OverviewPage } from './pages/OverviewPage';
import { ProductsPage } from './pages/ProductsPage';
import { SettingsPage } from './pages/SettingsPage';
import type { DashboardBootConfig } from './types/api';

export interface AppProps {
  config: DashboardBootConfig;
  api?: DjgearApi;
  initialRoute?: RouteId;
}

export function App({ config, api: injectedApi, initialRoute = 'overview' }: AppProps) {
  const api = useMemo(() => {
    if (injectedApi) {
      return injectedApi;
    }
    const client = new WpRestClient({
      restUrl: config.restUrl,
      nonce: config.nonce,
    });
    return new DjgearApi(client);
  }, [injectedApi, config.restUrl, config.nonce]);

  const [activeRoute, setActiveRoute] = useState<RouteId>(initialRoute);
  const route = getRouteById(activeRoute);
  const allowed = hasRoutePermission(route, config.currentUser.capabilities);

  const renderPage = () => {
    if (!allowed) {
      return <PermissionDeniedState capability={route.requiredCapability} />;
    }

    switch (route.id) {
      case 'overview':
        return <OverviewPage api={api} />;
      case 'products':
        return <ProductsPage api={api} />;
      case 'orders':
        return <OrdersPage api={api} />;
      case 'customers':
        return <CustomersPage api={api} />;
      case 'catalog-devices':
        return (
          <CatalogDevicesPage
            api={api}
            canManage={Boolean(config.currentUser.capabilities.djgear_manage_catalog)}
          />
        );
      case 'catalog-taxonomies':
        return <CatalogTaxonomiesPage api={api} />;
      case 'catalog-skins':
        return <CatalogSkinsPage api={api} />;
      case 'catalog-import-export':
        return <CatalogImportExportPage api={api} />;
      case 'modules':
        return <ModulesPage api={api} />;
      case 'settings':
        return <SettingsPage api={api} />;
      default:
        return (
          <ModuleHubPage
            api={api}
            route={route}
            canViewLogs={Boolean(config.currentUser.capabilities.djgear_view_logs)}
          />
        );
    }
  };

  return (
    <I18nProvider initialLocale={config.i18n.locale} api={api}>
      <AppShell config={config} activeRoute={activeRoute} onSelectRoute={setActiveRoute}>
        {renderPage()}
      </AppShell>
    </I18nProvider>
  );
}
