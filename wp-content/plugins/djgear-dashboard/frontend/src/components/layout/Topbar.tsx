import { ArrowUpRight, Languages, Menu, X } from 'lucide-react';
import { useI18n } from '../../i18n/I18nContext';
import type { DashboardUser } from '../../types/api';
import { Badge } from '../ui/Badge';

export interface TopbarProps {
  pageTitle: string;
  user: DashboardUser;
  adminUrl: string;
  environment: string;
  sidebarOpen: boolean;
  onToggleSidebar: () => void;
}

export function Topbar({
  pageTitle,
  user,
  adminUrl,
  environment,
  sidebarOpen,
  onToggleSidebar,
}: TopbarProps) {
  const { t, toggleLocale, isSwitching } = useI18n();

  return (
    <header className="djg-topbar">
      <div className="djg-topbar-start">
        <button
          type="button"
          className="djg-btn djg-mobile-menu-btn"
          onClick={onToggleSidebar}
          aria-label={sidebarOpen ? t('menuClose') : t('menuOpen')}
        >
          {sidebarOpen ? <X size={16} /> : <Menu size={16} />}
        </button>
        <strong style={{ fontSize: 15 }}>{pageTitle}</strong>
        <Badge tone={environment === 'production' ? 'success' : 'info'}>{environment}</Badge>
      </div>

      <div className="djg-topbar-end">
        <button
          type="button"
          className="djg-btn"
          onClick={() => void toggleLocale()}
          disabled={isSwitching}
          data-testid="language-switcher"
        >
          <Languages size={15} />
          <span>{t('languageToggle')}</span>
        </button>

        <a href={adminUrl} className="djg-btn">
          <span>{t('returnToWpAdmin')}</span>
          <ArrowUpRight size={14} />
        </a>

        <Badge tone="neutral">
          {user.displayName || user.email} {user.roles[0] ? `(${user.roles[0]})` : ''}
        </Badge>
      </div>
    </header>
  );
}
