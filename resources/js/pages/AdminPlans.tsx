import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, jsonApi } from '../lib/api';
import { quotaLabels } from '../lib/plans';
import type { BillingPeriod } from '../lib/plans';
import { ErrorState, Loading, Notice } from '../components/ui';
import { FormError } from './Account';
import PlanTrialFields from '../components/PlanTrialFields';

type Quotas = Record<string, number | null>;
type AdminPlan = {
  id: number; slug: string; name: string; description: string; is_free: boolean; active: boolean; currency: 'XAF';
  monthly_price: number | null; yearly_price: number | null; billing_periods: BillingPeriod[]; features: string[];
  quotas: Quotas | null; guest_limits: Quotas | null; allowed_tools: string[] | null; display_order: number; updated_at?: string; trial_days?: number; trial_enabled?: boolean; entitlements?: Record<string, boolean>;
};
type Catalog = { plans: AdminPlan[]; quota_bounds: Record<string, [number, number]>; tools: { slug: string; name: string }[] };
const empty: AdminPlan = { id: 0, slug: '', name: '', description: '', is_free: false, active: true, currency: 'XAF', monthly_price: null, yearly_price: null, billing_periods: ['monthly', 'yearly'], features: [], quotas: null, guest_limits: null, allowed_tools: null, display_order: 30, trial_days: 15, trial_enabled: false, entitlements: { priority_processing: false, advanced_conversions: false, batch_processing: false, priority_support: false } };

export default function AdminPlans() {
  const catalog = useQuery({ queryKey: ['admin-plans'], queryFn: ({ signal }) => api<Catalog>('/admin/plans', signal) });
  const [selected, setSelected] = useState<number | 'new' | null>(null);
  const [saved, setSaved] = useState('');
  if (catalog.isPending) return <Loading label="Chargement des plans…" />;
  if (catalog.error) return <ErrorState message={catalog.error.message} retry={() => { void catalog.refetch(); }} />;
  const current = selected === 'new' ? empty : catalog.data.plans.find(plan => plan.id === selected) ?? catalog.data.plans[0];
  return <section id="admin-plans" className="admin-plans"><h2>Plans et abonnements</h2><p>Les prix sont des montants entiers en FCFA (XAF). Un prix vide signifie « À définir ». Les essais peuvent fonctionner sans prestataire de paiement.</p><div className="account-links"><div className="field"><label htmlFor="admin-plan-choice">Plan à modifier</label><select id="admin-plan-choice" value={selected === 'new' ? 'new' : current.id} onChange={event => { setSaved(''); setSelected(event.target.value === 'new' ? 'new' : Number(event.target.value)); }}>{catalog.data.plans.map(plan => <option key={plan.id} value={plan.id}>{plan.name}{plan.active ? '' : ' (inactif)'}</option>)}{selected === 'new' && <option value="new">Nouveau plan</option>}</select></div><button type="button" className="button secondary" onClick={() => { setSelected('new'); setSaved(''); }}>Ajouter un plan</button></div>{saved && <Notice>{saved}</Notice>}<PlanForm key={`${current.id}-${current.updated_at ?? ''}-${JSON.stringify(current.quotas)}-${JSON.stringify(current.guest_limits)}`} initial={current} catalog={catalog.data} saved={plan => { setSelected(plan.id); setSaved(`Plan « ${plan.name} » enregistré et journalisé.`); }} /></section>;
}

function PlanForm({ initial, catalog, saved }: { initial: AdminPlan; catalog: Catalog; saved: (plan: AdminPlan) => void }) {
  const [draft, setDraft] = useState(initial);
  const [features, setFeatures] = useState(initial.features.join('\n'));
  const client = useQueryClient();
  const update = useMutation({ mutationFn: () => jsonApi<AdminPlan>(initial.id ? `/admin/plans/${initial.id}` : '/admin/plans', {
    ...(!initial.id ? { slug: draft.slug } : {}), name: draft.name, description: draft.description, active: draft.active,
    trial_days: draft.trial_days ?? (initial.is_free ? 0 : 15), trial_enabled: draft.trial_enabled ?? false, entitlements: draft.entitlements ?? { priority_processing: false, advanced_conversions: false, batch_processing: false, priority_support: false },
    currency: draft.currency, monthly_price: draft.monthly_price, yearly_price: draft.yearly_price,
    billing_periods: draft.billing_periods, features: features.split('\n').map(line => line.trim()).filter(Boolean),
    quotas: draft.quotas, allowed_tools: draft.allowed_tools, display_order: draft.display_order,
    ...(initial.is_free ? { guest_limits: draft.guest_limits } : {}),
  }, initial.id ? 'PATCH' : 'POST'), onSuccess: plan => {
    for (const key of ['admin-plans', 'admin-settings', 'admin-audit', 'plans', 'usage', 'subscription', 'limits']) void client.invalidateQueries({ queryKey: [key] });
    saved(plan);
  } });
  function quotas(role: 'quotas' | 'guest_limits', values: Quotas) {
    return <div className="admin-quota-grid">{Object.entries(catalog.quota_bounds).map(([key, [min, max]]) => <div className="field" key={key}><label htmlFor={`plan-${role}-${key}`}>{quotaLabels[key]}{key.endsWith('_bytes') ? ' (octets)' : (key === 'retention_hours' || key === 'history_hours') ? ' (heures)' : key === 'timeout_seconds' ? ' (secondes)' : ''}</label><input id={`plan-${role}-${key}`} type="number" min={min} max={max} required={!initial.is_free || key !== 'monthly_jobs'} value={values[key] ?? ''} onChange={event => setDraft({ ...draft, [role]: { ...values, [key]: event.target.value === '' ? null : Number(event.target.value) } })} /></div>)}</div>;
  }
  return <form className="panel account-form admin-plan-form" onSubmit={event => { event.preventDefault(); update.mutate(); }}><h3>{initial.id ? `Modifier ${initial.name}` : 'Créer un plan payant à venir'}</h3><FormError error={update.error} /><fieldset disabled={update.isPending}><legend className="sr-only">Paramètres du plan</legend>
    {!initial.id && <div className="field"><label htmlFor="plan-slug">Identifiant unique</label><input id="plan-slug" required pattern="[a-z][a-z0-9-]{1,59}" value={draft.slug} onChange={event => setDraft({ ...draft, slug: event.target.value })} /></div>}
    <div className="admin-quota-grid"><div className="field"><label htmlFor="plan-name">Nom</label><input id="plan-name" required maxLength={100} value={draft.name} onChange={event => setDraft({ ...draft, name: event.target.value })} /></div><div className="field"><label htmlFor="plan-order">Ordre d’affichage</label><input id="plan-order" type="number" required min="0" max="1000" value={draft.display_order} onChange={event => setDraft({ ...draft, display_order: Number(event.target.value) })} /></div></div>
    <div className="field"><label htmlFor="plan-description">Description</label><textarea id="plan-description" required maxLength={2000} value={draft.description} onChange={event => setDraft({ ...draft, description: event.target.value })} /></div>
    <label className="admin-toggle"><input type="checkbox" checked={draft.active} disabled={initial.is_free} onChange={event => setDraft({ ...draft, active: event.target.checked })} />Afficher ce plan (actif)</label>{initial.is_free && <small>La formule gratuite est permanente, active et à 0 FCFA.</small>}
    {!initial.is_free && <><h3>Prix et périodicités</h3><p>Tarifs à valider manuellement. L’économie annuelle est calculée depuis les prix configurés. Les montants configurés sont visibles publiquement, avec les conditions d’essai configurées.</p><div className="admin-quota-grid">{(['monthly', 'yearly'] as const).map(period => { const field = period === 'monthly' ? 'monthly_price' : 'yearly_price'; return <div key={period}><label className="admin-toggle"><input type="checkbox" checked={draft.billing_periods.includes(period)} onChange={event => setDraft({ ...draft, billing_periods: event.target.checked ? [...draft.billing_periods, period] : draft.billing_periods.filter(value => value !== period) })} />{period === 'monthly' ? 'Mensuel' : 'Annuel'}</label><div className="field"><label htmlFor={`plan-${field}`}>Prix {period === 'monthly' ? 'mensuel' : 'annuel'} en FCFA</label><input id={`plan-${field}`} type="number" min="1" max="1000000000" step="1" placeholder="À définir" value={draft[field] ?? ''} onChange={event => setDraft({ ...draft, [field]: event.target.value === '' ? null : Number(event.target.value) })} /></div></div>; })}</div></>}
    <div className="field"><label htmlFor="plan-features">Fonctionnalités affichées (une par ligne)</label><textarea id="plan-features" rows={5} value={features} onChange={event => setFeatures(event.target.value)} /><small>Les textes décrivent l’offre. Les droits effectifs dépendent des outils et quotas ci-dessous.</small></div>
    <h3>Quotas {initial.is_free ? 'du compte gratuit' : 'de la future formule'}</h3>{!initial.is_free && <label className="admin-toggle"><input type="checkbox" checked={draft.quotas !== null} onChange={event => setDraft({ ...draft, quotas: event.target.checked ? { ...catalog.plans.find(plan => plan.is_free)!.quotas! } : null })} />Définir les quotas (sinon « À définir »)</label>}{draft.quotas && quotas('quotas', draft.quotas)}
    {initial.is_free && draft.guest_limits && <><h3>Quotas gratuits sans compte</h3><p>Ces valeurs partagent la configuration centrale utilisée par les conversions et les réglages existants.</p>{quotas('guest_limits', draft.guest_limits)}</>}
    <PlanTrialFields isFree={initial.is_free} trialDays={draft.trial_days ?? 15} trialEnabled={draft.trial_enabled ?? false} entitlements={draft.entitlements ?? { priority_processing: false, advanced_conversions: false, batch_processing: false, priority_support: false }} onChange={changes => setDraft({ ...draft, ...changes })} />
    <h3>Outils inclus</h3><label className="admin-toggle"><input type="checkbox" checked={draft.allowed_tools === null} onChange={event => setDraft({ ...draft, allowed_tools: event.target.checked ? null : [] })} />Tous les outils opérationnels, y compris les prochains outils activés</label>{draft.allowed_tools !== null && catalog.tools.map(tool => <label className="admin-toggle" key={tool.slug}><input type="checkbox" checked={draft.allowed_tools!.includes(tool.slug)} onChange={event => setDraft({ ...draft, allowed_tools: event.target.checked ? [...draft.allowed_tools!, tool.slug] : draft.allowed_tools!.filter(value => value !== tool.slug) })} />{tool.name}</label>)}
    <Notice>Le paiement n’est pas intégré. Cet écran ne peut pas attribuer d’abonnement payant à un utilisateur.</Notice><button className="button primary" type="submit">{update.isPending ? 'Enregistrement…' : 'Enregistrer le plan'}</button>
  </fieldset></form>;
}
