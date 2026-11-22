import { useState } from 'react';
import { CheckCircle2, Download, Upload } from 'lucide-react';
import type { DjgearApi } from '../api/djgearApi';
import { ApiError } from '../api/httpClient';
import { Badge } from '../components/ui/Badge';
import { Card } from '../components/ui/Card';
import { PermissionDeniedState } from '../components/ui/StateFeedback';
import { useI18n } from '../i18n/I18nContext';
import type { CatalogImportValidation } from '../types/api';

export function CatalogImportExportPage({ api }: { api: DjgearApi }) {
  const { t, formatNumber } = useI18n();
  const [jsonText, setJsonText] = useState('{\n  "devices": []\n}');
  const [policy, setPolicy] = useState<'skip' | 'update' | 'fail'>('skip');
  const [validation, setValidation] = useState<CatalogImportValidation | null>(null);
  const [execSummary, setExecSummary] = useState<string | null>(null);
  const [exportOutput, setExportOutput] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);

  const parsePayload = (): Record<string, unknown> | null => {
    try {
      const parsed = JSON.parse(jsonText) as Record<string, unknown>;
      return { ...parsed, conflict_policy: policy };
    } catch {
      setError('Invalid JSON payload.');
      return null;
    }
  };

  const handleValidate = async () => {
    setError(null);
    setExecSummary(null);
    const payload = parsePayload();
    if (!payload) return;
    try {
      const res = await api.validateCatalogImport(payload);
      setValidation(res.data);
    } catch (err) {
      if (err instanceof ApiError && (err.isForbidden() || err.isUnauthorized())) {
        setForbidden(true);
      } else {
        setError(err instanceof Error ? err.message : 'Validation failed');
      }
    }
  };

  const handleExecute = async () => {
    setError(null);
    const payload = parsePayload();
    if (!payload) return;
    try {
      const res = await api.executeCatalogImport(payload);
      setExecSummary(
        `created=${res.data.created}, updated=${res.data.updated}, skipped=${res.data.skipped}, errors=${res.data.errors.length}`
      );
    } catch (err) {
      if (err instanceof ApiError && (err.isForbidden() || err.isUnauthorized())) {
        setForbidden(true);
      } else {
        setError(err instanceof Error ? err.message : 'Import failed');
      }
    }
  };

  const handleExport = async (format: 'json' | 'csv') => {
    setError(null);
    try {
      const res = await api.exportCatalog(format);
      setExportOutput(
        format === 'csv' ? (res.data.content ?? '') : JSON.stringify(res.data.payload ?? {}, null, 2)
      );
    } catch (err) {
      if (err instanceof ApiError && (err.isForbidden() || err.isUnauthorized())) {
        setForbidden(true);
      } else {
        setError(err instanceof Error ? err.message : 'Export failed');
      }
    }
  };

  if (forbidden) {
    return <PermissionDeniedState capability="djgear_import_export_catalog" />;
  }

  return (
    <>
      <div className="djg-page-header">
        <div>
          <h1 className="djg-page-title">{t('catalogImportTitle')}</h1>
          <p className="djg-page-subtitle">{t('catalogImportSubtitle')}</p>
        </div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button type="button" className="djg-btn" onClick={() => void handleExport('json')}>
            <Download size={14} />
            <span>{t('exportJson')}</span>
          </button>
          <button type="button" className="djg-btn" onClick={() => void handleExport('csv')}>
            <Download size={14} />
            <span>{t('exportCsv')}</span>
          </button>
        </div>
      </div>

      <Card title={t('validateDryRun')}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          <div className="djg-toolbar">
            <select
              className="djg-select"
              value={policy}
              onChange={(e) => setPolicy(e.target.value as 'skip' | 'update' | 'fail')}
            >
              <option value="skip">conflict_policy: skip</option>
              <option value="update">conflict_policy: update</option>
              <option value="fail">conflict_policy: fail</option>
            </select>
            <button type="button" className="djg-btn" onClick={() => void handleValidate()}>
              <CheckCircle2 size={14} />
              <span>{t('validateDryRun')}</span>
            </button>
            <button
              type="button"
              className="djg-btn djg-btn-primary"
              disabled={!validation?.valid}
              onClick={() => void handleExecute()}
            >
              <Upload size={14} />
              <span>{t('executeImport')}</span>
            </button>
          </div>

          <textarea
            className="djg-textarea djg-mono"
            rows={8}
            value={jsonText}
            onChange={(e) => {
              setJsonText(e.target.value);
              setValidation(null);
            }}
          />

          {error && <div style={{ color: 'var(--djg-danger)', fontSize: 13 }}>{error}</div>}
          {execSummary && <Badge tone="success">{execSummary}</Badge>}

          {validation && (
            <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
              <Badge tone={validation.valid ? 'success' : 'danger'}>
                {validation.valid ? 'VALID' : 'INVALID'}
              </Badge>
              <Badge tone="neutral">total: {formatNumber(validation.summary.total)}</Badge>
              <Badge tone="info">create: {formatNumber(validation.summary.create)}</Badge>
              <Badge tone="warning">update: {formatNumber(validation.summary.update)}</Badge>
              <Badge tone="neutral">skip: {formatNumber(validation.summary.skip)}</Badge>
              <Badge tone="danger">errors: {formatNumber(validation.summary.errors)}</Badge>
            </div>
          )}
        </div>
      </Card>

      {exportOutput !== null && (
        <Card title="Export Output">
          <textarea className="djg-textarea djg-mono" rows={10} readOnly value={exportOutput} style={{ width: '100%' }} />
        </Card>
      )}
    </>
  );
}
