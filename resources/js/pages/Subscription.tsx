import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { api, jsonApi } from '../lib/api';
import type { PlanLimits } from '../lib/api';
import type { BillingPeriod, PricingPlan } from '../lib/plans';
import { quotaLabels, quotaValue } from '../lib/plans';
import { ErrorState, Loading, Notice } from '../components/ui';
import { FormError } from './Account';

export type SubscriptionDetails = {
  plan: PricingPlan; is_guest: boolean; status: string; starts_at: string | null; ends_at: string | null; renews_at: string | null;
  limits: PlanLimits; used: { daily_jobs: number; hourly_jobs: number; monthly_jobs?: number; active_jobs: number; reserved_bytes: number };
  external_used: { daily_jobs: number }; history: { id: number; type: string; metadata: { plan?: string }; created_at: string }[];
  can_change_plan: boolean; payment_available: boolean; trial_eligible?: boolean; trial_policy?: string;
  trial_started_at?: string | null; trial_ends_at?: string | null; trial_remaining_seconds?: number | null;
  price_amount?: number; currency?: string; billing_period?: BillingPeriod | null;
  notices?: { id: number; key: string; message: string }[];
  pending_payments?: { id: number; plan: { name: string }; status: string; price_amount: number; billing_period: BillingPeriod }[];
};
const date = (value: string) => new Date(value).toLocaleString('fr', { timeZone: 'Africa/Libreville', dateStyle: 'long', timeStyle: 'short' });
const statuses: Record<string, string> = { active: 'Actif', trialing: 'Essai en cours', expired: 'Expiré', canceled: 'Annulé', pending_payment: 'Paiement en attente' };

export function SubscriptionSummary() {
  const details = useQuery({ queryKey: ['subscription'], queryFn: ({ signal }) => api<SubscriptionDetails>('/me/subscription', signal), refetchInterval: 15000 });
  if (details.isPending) return <Loading label="Chargement de votre abonnement…" />;
  if (details.error) return <ErrorState message={details.error.message} retry={() => { void details.refetch(); }} />;
  const info = details.data;
  const monthly = info.limits.monthly_jobs;
  const meters: [string, number, number][] = [[monthly ? 'Tâches sur 30 jours' : 'Opérations sur 24 h', monthly ? info.used.monthly_jobs ?? 0 : info.used.daily_jobs, monthly ?? info.limits.daily_jobs], ['Tâches simultanées', info.used.active_jobs, info.limits.concurrent_jobs]];
  return <div className="subscription-space"><div className="panel subscription-current"><div><span className="plan-badge plan-badge-free">{statuses[info.status] ?? info.status}</span><h2>Votre formule : {info.plan.name}</h2><p>{info.is_guest ? 'Session visiteur' : 'Compte personnel'} · {info.plan.is_free ? '0 FCFA · Sans limite de durée' : `${info.price_amount?.toLocaleString('fr')} FCFA / ${info.billing_period === 'yearly' ? 'an' : 'mois'}`}</p></div><div><p>{info.starts_at && `Début : ${date(info.starts_at)}`}</p><p>{info.ends_at ? `Fin : ${date(info.ends_at)}` : 'Sans date de fin'}</p><p>{info.renews_at ? `Renouvellement le ${date(info.renews_at)}` : 'Aucun renouvellement prévu'}</p></div></div>
    {info.status === 'trialing' && <Notice><strong>Essai gratuit : {Math.ceil((info.trial_remaining_seconds ?? 0) / 86400)} jour(s) restant(s).</strong><p>Prochaine échéance : fin de l’essai le {date(info.trial_ends_at!)}. Sans paiement confirmé, retour au Gratuit. Aucun prélèvement automatique. Le tarif choisi ne sera pas débité automatiquement.</p></Notice>}
    {info.notices?.map(notice => <Notice key={notice.id}>{notice.message}</Notice>)}
    <section className="panel"><h2>Votre consommation</h2><p>Les quotas sont glissants et ne se remettent pas à zéro lors d’un changement de formule ou de périodicité.</p><div className="subscription-meters">{meters.map(([label, used, limit]) => <div key={label}><div><span>{label}</span><strong>{used} / {limit}</strong></div><progress aria-label={label} value={used} max={limit} /></div>)}</div><p>Stockage réservé : {quotaValue('storage_bytes', info.used.reserved_bytes)} / {quotaValue('storage_bytes', info.limits.storage_bytes)}. Téléchargements externes : {info.external_used.daily_jobs} sur 24 h, quota distinct.</p><Link className="quiet-link" to="/mes-fichiers">Gérer mes fichiers et libérer le stockage →</Link></section>
    <section className="panel"><h2>Les limites de votre accès</h2><dl className="subscription-limits">{Object.entries(quotaLabels).map(([key, label]) => <div key={key}><dt>{label}</dt><dd>{quotaValue(key, info.limits[key as keyof PlanLimits])}</dd></div>)}</dl><p>{info.plan.tools.length} outils disponibles. Les limites de sécurité des formats continuent de s’appliquer.</p><ul className="pricing-features">{info.plan.features.map(feature => <li key={feature}>{feature}</li>)}</ul></section>
    {!info.is_guest && info.status === 'trialing' && <TrialControls key={`${info.plan.id}-${info.billing_period}`} info={info} />}
    {info.pending_payments?.map(payment => <Notice key={payment.id}>Demande {payment.plan.name} · {payment.price_amount.toLocaleString('fr')} FCFA / {payment.billing_period === 'yearly' ? 'an' : 'mois'} : paiement en attente. Aucun droit supplémentaire activé. {info.payment_available && <Link to={`/souscription?payment=${payment.id}`}>Vérifier ou reprendre le paiement SasPay</Link>}</Notice>)}
    <div className="account-links"><Link className="button primary" to="/tarifs">Comparer les formules</Link>{info.is_guest ? <Link className="button secondary" to="/inscription">Créer un compte gratuit</Link> : <><Link className="button secondary" to={`/souscription?plan=${info.plan.is_free ? 'pro' : info.plan.id}&period=${info.billing_period ?? 'monthly'}&mode=pay`}>Payer ou préparer une souscription</Link><Link to="/souscription?plan=business&mode=pay">Passer à Business</Link></>}</div>
    {!info.is_guest && <Notice>{info.trial_eligible ? 'Votre compte peut bénéficier d’un essai, sous réserve de disponibilité de la formule.' : 'Votre essai a été utilisé ou est en cours.'} {info.trial_policy}</Notice>}
    {!info.is_guest && <section className="panel"><h2>Historique de votre abonnement</h2><ul className="subscription-history">{info.history.map(event => <li key={event.id}><span>{{ free_assigned: 'Formule gratuite attribuée', expired: 'Période expirée', canceled: 'Abonnement clôturé', trial_started: 'Essai démarré', payment_pending: 'Demande de paiement en attente', period_changed: 'Périodicité modifiée' }[event.type] ?? 'Abonnement mis à jour'}{event.metadata.plan && ` · ${event.metadata.plan}`}</span><time dateTime={event.created_at}>{date(event.created_at)}</time></li>)}</ul></section>}
  </div>;
}

function TrialControls({ info }: { info: SubscriptionDetails }) {
  const [period, setPeriod] = useState<BillingPeriod>(info.billing_period ?? 'monthly');
  const [confirmed, setConfirmed] = useState(false);
  const [cancelConfirmed, setCancelConfirmed] = useState(false);
  const client = useQueryClient();
  const refresh = () => { for (const key of ['subscription', 'usage', 'session', 'jobs']) void client.invalidateQueries({ queryKey: [key] }); };
  const price = period === 'monthly' ? info.plan.monthly_price : info.plan.yearly_price;
  const change = useMutation({ mutationFn: () => jsonApi('/subscriptions/period', { period, expected_amount: price, confirmed }, 'PATCH'), onSuccess: refresh });
  const cancel = useMutation({ mutationFn: () => jsonApi('/subscriptions/cancel-trial', { confirmed: cancelConfirmed }), onSuccess: refresh });
  return <section className="panel account-form"><h2>Gérer mon essai</h2><p>Changer la périodicité conserve la même date de fin et la même consommation. Changer de formule ne donne pas droit à un nouvel essai.</p><div className="field"><label htmlFor="trial-period">Périodicité souhaitée après l’essai</label><select id="trial-period" value={period} onChange={event => { setPeriod(event.target.value as BillingPeriod); setConfirmed(false); }}>{info.plan.billing_periods.map(value => <option key={value} value={value}>{value === 'monthly' ? 'Mensuelle' : 'Annuelle'}</option>)}</select></div><p>{price?.toLocaleString('fr')} FCFA / {period === 'monthly' ? 'mois' : 'an'} · Aucun prélèvement automatique</p><label className="subscription-choice"><input type="checkbox" checked={confirmed} onChange={event => setConfirmed(event.target.checked)} />Je confirme cette périodicité et son tarif, sans prolongation de l’essai.</label><FormError error={change.error} /><button className="button secondary" disabled={!confirmed || period === info.billing_period || change.isPending || price == null} onClick={() => change.mutate()}>Enregistrer la périodicité</button><hr /><label className="subscription-choice"><input type="checkbox" checked={cancelConfirmed} onChange={event => setCancelConfirmed(event.target.checked)} />Je souhaite arrêter mon essai et revenir immédiatement au Gratuit, sans nouvel essai possible.</label><FormError error={cancel.error} /><button className="button secondary" disabled={!cancelConfirmed || cancel.isPending} onClick={() => cancel.mutate()}>Arrêter l’essai</button></section>;
}

export default function SubscriptionPage() {
  return <section className="container page-section"><title>Mon abonnement — Convert</title><span className="eyebrow">VOTRE ACCÈS À CONVERT</span><h1>Mon abonnement</h1><p className="page-lead">Votre formule, vos échéances et votre consommation au même endroit.</p><SubscriptionSummary /></section>;
}
