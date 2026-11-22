import React, { useState } from 'react';
import { useI18n } from '../../i18n/I18nContext';
import { getRouteById, type RouteId } from '../../navigation/routes';
import type { DashboardBootConfig } from '../../types/api';
import { Sidebar } from './Sidebar';
import { Topbar } from './Topbar';

export interface AppShellProps {
  config: DashboardBootConfig;
  activeRoute: RouteId;
  onSelectRoute: (route: RouteId) => void;
  children: React.ReactNode;
}

export function AppShell({ config, activeRoute, onSelectRoute, children }: AppShellProps) {
  const { direction, t } = useI18n();
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const currentRoute = getRouteById(activeRoute);

  return (
    <div className="djg-shell" dir={direction} data-testid="djgear-shell">
      <Sidebar
        activeRoute={activeRoute}
        onSelectRoute={onSelectRoute}
        isOpen={sidebarOpen}
        onClose={() => setSidebarOpen(false)}
        siteTitle={config.siteTitle}
      />
      <div className="djg-main">
        <Topbar
          pageTitle={t(currentRoute.labelKey)}
          user={config.currentUser}
          adminUrl={config.adminUrl}
          environment={config.environment}
          sidebarOpen={sidebarOpen}
          onToggleSidebar={() => setSidebarOpen((prev) => !prev)}
        />
        <main className="djg-content">{children}</main>
      </div>
    </div>
  );
}
