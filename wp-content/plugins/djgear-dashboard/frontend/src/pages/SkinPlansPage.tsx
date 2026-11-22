import { useCallback, useEffect, useState } from 'react';
import { Plus, RefreshCw, Trash2 } from 'lucide-react';
import type { DjgearApi } from '../api/djgearApi';
import { ApiError } from '../api/httpClient';
import { Badge } from '../components/ui/Badge';
import { Card } from '../components/ui/Card';
import { DataTable } from '../components/ui/DataTable';
import { PermissionDeniedState } from '../components/ui/StateFeedback';
import { useI18n } from '../i18n/I18nContext';
import type { SkinPlan } from '../types/api';

export function SkinPlansPage({ api }: { api: DjgearApi }) {
  const { t, locale, formatNumber } = useI18n();
  const [plans, setPlans] = useState<SkinPlan[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);

  const [slug, setSlug] = useState('');
  const [nameEn, setNameEn] = useState('');
  const [nameFa, setNameFa] = useState('');
  const [planType, setPlanType] = useState<'standard' | 'custom_design'>('standard');
  const [surcharge, setSurcharge] = useState('0');
  const [maxRevisions, setMaxRevisions] = useState('0');
  const [leadDays, setLeadDays] = useState('3');
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    setForbidden(false);
    try {
      const res = await api.listSkinPlans();
      setPlans(res.data.items);
    } catch (err) {
      if (err instanceof ApiError && (err.isForbidden() || err.isUnauthorized())) {
        setForbidden(true);
      } else {
        setError(err instanceof Error ? err.message : 'Failed to load ordering plans');
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
    const isCustom = planType === 'custom_design';
    try {
      await api.createSkinPlan({
        slug,
        name_en: nameEn,
        name_fa: nameFa,
        plan_type: planType,
        allows_artwork_upload: isCustom,
        requires_artwork: isCustom,
        requires_proof_approval: isCustom,
        max_revisions: isCustom ? Number(maxRevisions || 3) : 0,
        price_surcharge: Number(surcharge || 0),
        estimated_lead_days: Number(leadDays || 3),
        is_active: true,
      });
      setSlug('');
      setNameEn('');
      setNameFa('');
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create ordering plan');
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (id: number) => {
    try {
      await api.deleteSkinPlan(id);
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to delete ordering plan');
    }
  };

  if (forbidden) {
    return <PermissionDeniedState capability="djgear_manage_skin_plans" />;
  }

  return (
    <>
      <div className="djg-page-header">
        <div>
          <h1 className="djg-page-title">{t('skinPlansTitle')}</h1>
          <p className="djg-page-subtitle">{t('skinPlansSubtitle')}</p>
        </div>
        <button type="button" className="djg-btn" onClick={() => void load()}>
          <RefreshCw size={14} />
          <span>{t('refresh')}</span>
        </button>
      </div>

      <Card>
        <form onSubmit={(e) => void handleCreate(e)} style={{ display: 'grid', gap: 12, gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))' }}>
          <input className="djg-input" placeholder="Plan Slug (e.g. custom-logo)" value={slug} onChange={(e) => setSlug(e.target.value)} required />
          <input className="djg-input" placeholder="Plan Name (EN)" value={nameEn} onChange={(e) => setNameEn(e.target.value)} required />
          <input className="djg-input" placeholder="نام پلن (FA)" value={nameFa} onChange={(e) => setNameFa(e.target.value)} required />
          <select className="djg-input" value={planType} onChange={(e) => setPlanType(e.target.value as 'standard' | 'custom_design')}>
            <option value="standard">{t('planTypeStandard')}</option>
            <option value="custom_design">{t('planTypeCustom')}</option>
          </select>
          <input className="djg-input" type="number" min="0" placeholder="Price Surcharge" value={surcharge} onChange={(e) => setSurcharge(e.target.value)} />
          <input className="djg-input" type="number" min="0" placeholder="Max Revisions" value={maxRevisions} onChange={(e) => setMaxRevisions(e.target.value)} />
          <input className="djg-input" type="number" min="1" placeholder="Lead Days" value={leadDays} onChange={(e) => setLeadDays(e.target.value)} />
          <button type="submit" className="djg-btn djg-btn-primary" disabled={saving}>
            <Plus size={14} />
            <span>{saving ? t('saving') : t('createLabel')}</span>
          </button>
        </form>
      </Card>

      <Card>
        <DataTable<SkinPlan>
          rows={plans}
          rowKey={(p) => p.id}
          loading={loading}
          error={error}
          onRetry={() => void load()}
          columns={[
            {
              key: 'name',
              header: 'Plan',
              render: (p) => (
                <div>
                  <strong>{locale === 'fa_IR' ? p.name_fa : p.name_en}</strong>
                  <div className="djg-mono" style={{ fontSize: 12, color: 'var(--djg-text-secondary)' }}>
                    {p.slug}
                  </div>
                </div>
              ),
            },
            {
              key: 'type',
              header: 'Artwork Policy',
              render: (p) => (
                <Badge tone={p.allows_artwork_upload ? 'info' : 'neutral'}>
                  {p.allows_artwork_upload ? t('artworkAllowedYes') : t('artworkAllowedNo')}
                </Badge>
              ),
            },
            {
              key: 'surcharge',
              header: 'Surcharge / Lead',
              render: (p) => (
                <span className="djg-mono">
                  +{formatNumber(p.price_surcharge)} · {formatNumber(p.estimated_lead_days)}d · rev {formatNumber(p.max_revisions)}
                </span>
              ),
            },
            {
              key: 'status',
              header: t('colStatus'),
              render: (p) => (
                <Badge tone={p.is_active ? 'success' : 'neutral'}>
                  {p.is_active ? t('activeBadge') : t('disabledBadge')}
                </Badge>
              ),
            },
            {
              key: 'actions',
              header: t('colActions'),
              render: (p) => (
                <button type="button" className="djg-btn" onClick={() => void handleDelete(p.id)} aria-label={t('deleteLabel')}>
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
