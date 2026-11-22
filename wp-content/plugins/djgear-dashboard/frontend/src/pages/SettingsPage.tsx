import React, { useCallback, useEffect, useState } from 'react';
import { Save } from 'lucide-react';
import type { DjgearApi } from '../api/djgearApi';
import { ApiError } from '../api/httpClient';
import { Badge } from '../components/ui/Badge';
import { Card } from '../components/ui/Card';
import { ErrorState, LoadingState, PermissionDeniedState } from '../components/ui/StateFeedback';
import { useI18n } from '../i18n/I18nContext';
import type { DjgearSettings, LocaleCode } from '../types/api';

export function SettingsPage({ api }: { api: DjgearApi }) {
  const { t } = useI18n();
  const [form, setForm] = useState<DjgearSettings | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    setForbidden(false);
    try {
      const res = await api.getSettings();
      setForm(res.data.settings);
    } catch (err) {
      if (err instanceof ApiError && (err.isForbidden() || err.isUnauthorized())) {
        setForbidden(true);
      } else {
        setError(err instanceof Error ? err.message : 'Failed to load settings');
      }
    } finally {
      setLoading(false);
    }
  }, [api]);

  useEffect(() => {
    void load();
  }, [load]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!form) return;
    setSaving(true);
    setSaved(false);
    setError(null);
    try {
      const res = await api.updateSettings(form);
      setForm(res.data.settings);
      setSaved(true);
    } catch (err) {
      if (err instanceof ApiError && (err.isForbidden() || err.isUnauthorized())) {
        setForbidden(true);
      } else {
        setError(err instanceof Error ? err.message : 'Failed to save settings');
      }
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return <LoadingState />;
  }

  if (forbidden) {
    return <PermissionDeniedState capability="djgear_manage_core" />;
  }

  if (error && !form) {
    return <ErrorState message={error} onRetry={() => void load()} />;
  }

  if (!form) {
    return null;
  }

  return (
    <>
      <div className="djg-page-header">
        <div>
          <h1 className="djg-page-title">{t('settingsTitle')}</h1>
          <p className="djg-page-subtitle">{t('settingsSubtitle')}</p>
        </div>
        {saved && <Badge tone="success">{t('settingsSaved')}</Badge>}
      </div>

      <Card>
        <form onSubmit={(e) => void handleSubmit(e)} style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
          <div className="djg-form-grid">
            <div className="djg-field">
              <label htmlFor="djg-brand-name">{t('fieldBrandName')}</label>
              <input
                id="djg-brand-name"
                type="text"
                className="djg-input"
                value={form.brand_name}
                maxLength={80}
                onChange={(e) => setForm({ ...form, brand_name: e.target.value })}
              />
            </div>

            <div className="djg-field">
              <label htmlFor="djg-default-locale">{t('fieldDefaultLocale')}</label>
              <select
                id="djg-default-locale"
                className="djg-select"
                value={form.default_locale}
                onChange={(e) => setForm({ ...form, default_locale: e.target.value as LocaleCode })}
              >
                <option value="fa_IR">فارسی (fa_IR — RTL)</option>
                <option value="en_US">English (en_US — LTR)</option>
              </select>
            </div>

            <div className="djg-field">
              <label htmlFor="djg-audit-retention">{t('fieldAuditRetention')}</label>
              <input
                id="djg-audit-retention"
                type="number"
                className="djg-input"
                min={7}
                max={365}
                value={form.audit_retention_days}
                onChange={(e) => setForm({ ...form, audit_retention_days: Number(e.target.value) })}
              />
            </div>

            <div className="djg-field" style={{ justifyContent: 'center' }}>
              <label style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer' }}>
                <input
                  type="checkbox"
                  checked={form.persian_digits}
                  onChange={(e) => setForm({ ...form, persian_digits: e.target.checked })}
                />
                <span>{t('fieldPersianDigits')}</span>
              </label>
            </div>
          </div>

          <div className="djg-field">
            <label htmlFor="djg-maint-notice">{t('fieldMaintenanceNotice')}</label>
            <textarea
              id="djg-maint-notice"
              className="djg-textarea"
              rows={2}
              maxLength={240}
              value={form.maintenance_notice}
              onChange={(e) => setForm({ ...form, maintenance_notice: e.target.value })}
            />
          </div>

          {error && <div style={{ color: 'var(--djg-danger)', fontSize: 13 }}>{error}</div>}

          <div>
            <button type="submit" className="djg-btn djg-btn-primary" disabled={saving}>
              <Save size={15} />
              <span>{saving ? t('saving') : t('saveSettings')}</span>
            </button>
          </div>
        </form>
      </Card>
    </>
  );
}
