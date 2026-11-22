import { useCallback, useEffect, useState } from 'react';
import { Plus, RefreshCw, Trash2 } from 'lucide-react';
import type { DjgearApi } from '../api/djgearApi';
import { ApiError } from '../api/httpClient';
import { Badge } from '../components/ui/Badge';
import { Card } from '../components/ui/Card';
import { DataTable } from '../components/ui/DataTable';
import { PermissionDeniedState } from '../components/ui/StateFeedback';
import { useI18n } from '../i18n/I18nContext';
import type { SkinVariant } from '../types/api';

export function SkinVariantsPage({ api }: { api: DjgearApi }) {
  const { t, locale, formatNumber } = useI18n();
  const [variants, setVariants] = useState<SkinVariant[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);

  const [deviceId, setDeviceId] = useState('');
  const [wcProductId, setWcProductId] = useState('');
  const [modelCode, setModelCode] = useState('');
  const [modelNameFa, setModelNameFa] = useState('');
  const [modelNameEn, setModelNameEn] = useState('');
  const [colorCode, setColorCode] = useState('');
  const [colorNameFa, setColorNameFa] = useState('');
  const [colorNameEn, setColorNameEn] = useState('');
  const [colorHex, setColorHex] = useState('#111827');
  const [finish, setFinish] = useState<SkinVariant['finish']>('matte');
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    setForbidden(false);
    try {
      const res = await api.listSkinVariants();
      setVariants(res.data.items);
    } catch (err) {
      if (err instanceof ApiError && (err.isForbidden() || err.isUnauthorized())) {
        setForbidden(true);
      } else {
        setError(err instanceof Error ? err.message : 'Failed to load skin variants');
      }
    } finally {
      setLoading(false);
    }
  }, [api]);

  useEffect(() => {
    void load();
  }, [load]);

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await api.createSkinVariant({
        device_id: Number(deviceId),
        wc_product_id: Number(wcProductId),
        model_code: modelCode,
        model_name_fa: modelNameFa,
        model_name_en: modelNameEn,
        color_code: colorCode,
        color_name_fa: colorNameFa,
        color_name_en: colorNameEn,
        color_hex: colorHex,
        finish,
        status: 'active',
      });
      setModelCode('');
      setModelNameFa('');
      setModelNameEn('');
      setColorCode('');
      setColorNameFa('');
      setColorNameEn('');
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create skin variant');
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (id: number) => {
    try {
      await api.deleteSkinVariant(id);
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to delete skin variant');
    }
  };

  if (forbidden) {
    return <PermissionDeniedState capability="djgear_manage_skin_catalog" />;
  }

  return (
    <>
      <div className="djg-page-header">
        <div>
          <h1 className="djg-page-title">{t('skinVariantsTitle')}</h1>
          <p className="djg-page-subtitle">{t('skinVariantsSubtitle')}</p>
        </div>
        <button type="button" className="djg-btn" onClick={() => void load()}>
          <RefreshCw size={14} />
          <span>{t('refresh')}</span>
        </button>
      </div>

      <Card>
        <form onSubmit={(e) => void handleCreate(e)} style={{ display: 'grid', gap: 12, gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))' }}>
          <input className="djg-input" placeholder="Device ID" value={deviceId} onChange={(e) => setDeviceId(e.target.value)} required />
          <input className="djg-input" placeholder="WC Product ID" value={wcProductId} onChange={(e) => setWcProductId(e.target.value)} required />
          <input className="djg-input" placeholder="Model Code (e.g. stealth)" value={modelCode} onChange={(e) => setModelCode(e.target.value)} required />
          <input className="djg-input" placeholder="Model Name (EN)" value={modelNameEn} onChange={(e) => setModelNameEn(e.target.value)} required />
          <input className="djg-input" placeholder="نام مدل (FA)" value={modelNameFa} onChange={(e) => setModelNameFa(e.target.value)} required />
          <input className="djg-input" placeholder="Color Code (e.g. matte-black)" value={colorCode} onChange={(e) => setColorCode(e.target.value)} required />
          <input className="djg-input" placeholder="Color Name (EN)" value={colorNameEn} onChange={(e) => setColorNameEn(e.target.value)} required />
          <input className="djg-input" placeholder="نام رنگ (FA)" value={colorNameFa} onChange={(e) => setColorNameFa(e.target.value)} required />
          <input className="djg-input" type="text" placeholder="#111827" value={colorHex} onChange={(e) => setColorHex(e.target.value)} />
          <select className="djg-input" value={finish} onChange={(e) => setFinish(e.target.value as SkinVariant['finish'])}>
            <option value="matte">Matte</option>
            <option value="gloss">Gloss</option>
            <option value="satin">Satin</option>
            <option value="carbon">Carbon</option>
            <option value="brushed_metal">Brushed Metal</option>
          </select>
          <button type="submit" className="djg-btn djg-btn-primary" disabled={saving}>
            <Plus size={14} />
            <span>{saving ? t('saving') : t('createLabel')}</span>
          </button>
        </form>
      </Card>

      <Card>
        <DataTable<SkinVariant>
          rows={variants}
          rowKey={(v) => v.id}
          loading={loading}
          error={error}
          onRetry={() => void load()}
          columns={[
            {
              key: 'model',
              header: 'Model & Color',
              render: (v) => (
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                  <span
                    aria-hidden="true"
                    style={{
                      width: 18,
                      height: 18,
                      borderRadius: 6,
                      backgroundColor: v.color_hex,
                      border: '1px solid rgba(255,255,255,0.2)',
                      flexShrink: 0,
                    }}
                  />
                  <div>
                    <strong>{locale === 'fa_IR' ? v.model_name_fa : v.model_name_en}</strong>
                    <div style={{ fontSize: 12, color: 'var(--djg-text-secondary)' }}>
                      {locale === 'fa_IR' ? v.color_name_fa : v.color_name_en} · {v.finish}
                    </div>
                  </div>
                </div>
              ),
            },
            {
              key: 'device',
              header: 'Device / WC Product',
              render: (v) => (
                <div className="djg-mono" style={{ fontSize: 12 }}>
                  Device #{formatNumber(v.device_id)} · WC #{formatNumber(v.wc_product_id)}
                </div>
              ),
            },
            {
              key: 'price',
              header: t('colPrice'),
              render: (v) => (
                <span className="djg-mono">
                  {v.base_price !== null ? `${formatNumber(v.base_price)} ${v.currency}` : '—'}
                </span>
              ),
            },
            {
              key: 'status',
              header: t('colStatus'),
              render: (v) => (
                <Badge tone={v.status === 'active' ? 'success' : 'neutral'}>{v.status}</Badge>
              ),
            },
            {
              key: 'actions',
              header: t('colActions'),
              render: (v) => (
                <button type="button" className="djg-btn" onClick={() => void handleDelete(v.id)} aria-label={t('deleteLabel')}>
                  <Trash2 size={14} />
                </button>
              ),
            },
          ]}
        />
      </Card>
    </>
  );
}
