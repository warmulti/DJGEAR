import React from 'react';
import {
  Activity,
  ArrowLeftRight,
  BarChart3,
  Blocks,
  Cpu,
  CreditCard,
  FileText,
  FolderTree,
  Globe,
  Layers,
  LayoutDashboard,
  Mail,
  MessageSquare,
  Package,
  Settings,
  ShieldCheck,
  ShoppingCart,
  Users,
} from 'lucide-react';
import { useI18n } from '../../i18n/I18nContext';
import { NAV_GROUPS, ROUTES, type RouteId } from '../../navigation/routes';

const ROUTE_ICONS: Record<RouteId, React.ReactNode> = {
  overview: <LayoutDashboard size={17} />,
  products: <Package size={17} />,
  orders: <ShoppingCart size={17} />,
  customers: <Users size={17} />,
  'catalog-devices': <Cpu size={17} />,
  'catalog-taxonomies': <FolderTree size={17} />,
  'catalog-skins': <Layers size={17} />,
  'catalog-import-export': <ArrowLeftRight size={17} />,
  sms: <MessageSquare size={17} />,
  email: <Mail size={17} />,
  forms: <FileText size={17} />,
  seo: <Globe size={17} />,
  analytics: <BarChart3 size={17} />,
  payments: <CreditCard size={17} />,
  backup: <ShieldCheck size={17} />,
  modules: <Blocks size={17} />,
  settings: <Settings size={17} />,
};

export interface SidebarProps {
  activeRoute: RouteId;
  onSelectRoute: (route: RouteId) => void;
  isOpen: boolean;
  onClose: () => void;
  siteTitle: string;
}

export function Sidebar({ activeRoute, onSelectRoute, isOpen, onClose, siteTitle }: SidebarProps) {
  const { t } = useI18n();

  return (
    <>
      <div
        className={`djg-backdrop ${isOpen ? 'is-open' : ''}`}
        onClick={onClose}
        aria-hidden="true"
      />
      <aside className={`djg-sidebar ${isOpen ? 'is-open' : ''}`} aria-label="DJGear Navigation">
        <div className="djg-brand">
          <div className="djg-brand-mark" aria-hidden="true">
            <Activity size={20} />
          </div>
          <div>
            <p className="djg-brand-title">{siteTitle || 'DJGear.ir'}</p>
            <p className="djg-brand-sub">{t('brandSubtitle')}</p>
          </div>
        </div>

        <nav>
          {NAV_GROUPS.map((group) => {
            const items = ROUTES.filter((r) => r.group === group.id);
            return (
              <div key={group.id} className="djg-nav-group">
                <div className="djg-nav-group-label">{t(group.labelKey)}</div>
                {items.map((route) => {
                  const isActive = route.id === activeRoute;
                  return (
                    <button
                      key={route.id}
                      type="button"
                      className={`djg-nav-item ${isActive ? 'is-active' : ''}`}
                      aria-current={isActive ? 'page' : undefined}
                      onClick={() => {
                        onSelectRoute(route.id);
                        onClose();
                      }}
                    >
                      <span className="djg-nav-item-main">
                        {ROUTE_ICONS[route.id]}
                        <span>{t(route.labelKey)}</span>
                      </span>
                      {route.status === 'planned' && (
                        <span
                          className="djg-nav-dot"
                          title={t('plannedBadge')}
                          aria-label={t('plannedBadge')}
                        />
                      )}
                    </button>
                  );
                })}
              </div>
            );
          })}
        </nav>
      </aside>
    </>
  );
}
