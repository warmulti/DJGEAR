import { AlertTriangle, Inbox, Loader2, ShieldAlert, Wrench } from 'lucide-react';
import { useI18n } from '../../i18n/I18nContext';
import { Badge } from './Badge';

export function LoadingState({ message }: { message?: string }) {
  const { t } = useI18n();
  return (
    <div className="djg-state-box" role="status" aria-live="polite">
      <Loader2 size={28} color="#6366f1" />
      <h3 className="djg-state-title">{message ?? t('loadingTitle')}</h3>
      <p className="djg-state-desc">{t('loadingDesc')}</p>
    </div>
  );
}

export function EmptyState({ title, description }: { title?: string; description?: string }) {
  const { t } = useI18n();
  return (
    <div className="djg-state-box">
      <Inbox size={28} color="#64748b" />
      <h3 className="djg-state-title">{title ?? t('emptyTitle')}</h3>
      <p className="djg-state-desc">{description ?? t('emptyDesc')}</p>
    </div>
  );
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  const { t } = useI18n();
  return (
    <div className="djg-state-box" role="alert">
      <AlertTriangle size={28} color="#f43f5e" />
      <h3 className="djg-state-title">{t('errorTitle')}</h3>
      <p className="djg-state-desc">{message}</p>
      {onRetry && (
        <button type="button" className="djg-btn" onClick={onRetry}>
          {t('retry')}
        </button>
      )}
    </div>
  );
}

export function PermissionDeniedState({ capability }: { capability?: string }) {
  const { t } = useI18n();
  return (
    <div className="djg-state-box" role="alert">
      <ShieldAlert size={30} color="#f43f5e" />
      <h3 className="djg-state-title">{t('permissionDeniedTitle')}</h3>
      <p className="djg-state-desc">{t('permissionDeniedDesc')}</p>
      {capability && (
        <Badge tone="danger">
          {t('requiredCapability')}: {capability}
        </Badge>
      )}
    </div>
  );
}

export function PlannedModuleBanner({
  targetModuleId,
  contractSummary,
}: {
  targetModuleId: string;
  contractSummary?: string;
}) {
  const { t } = useI18n();
  return (
    <div className="djg-planned-banner" role="note">
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
        <Wrench size={18} color="#fbbf24" />
        <strong>{t('plannedModuleNoticeTitle')}</strong>
        <Badge tone="warning">{t('plannedBadge')}</Badge>
      </div>
      <p className="djg-state-desc" style={{ maxWidth: 'none' }}>
        {t('plannedModuleNoticeBody')}
      </p>
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 12, fontSize: 12.5, color: 'var(--djg-text-secondary)' }}>
        <span>
          {t('targetModuleId')}: <code className="djg-mono">{targetModuleId}</code>
        </span>
        {contractSummary && (
          <span>
            {t('coreContractsReady')}: <code className="djg-mono">{contractSummary}</code>
          </span>
        )}
      </div>
    </div>
  );
}
