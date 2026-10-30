import { useEffect, useState } from 'react';
import { ArrowLeft, ArrowRight, Check, CreditCard, Layers3, LockKeyhole, ShieldCheck, SlidersHorizontal, Sparkles } from 'lucide-react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useLocation, useSearchParams } from 'react-router-dom';
import { openSaspayCheckout } from '../lib/payments';
import { api, jsonApi, useSession } from '../lib/api';
import type { BillingPeriod, PricingPlan } from '../lib/plans';
import { quotaValue } from '../lib/plans';
import { ErrorState, Loading, Notice } from '../components/ui';
import { FormError } from './Account';

type Quote = { id: string; plan: PricingPlan; mode: 'trial' | 'pay'; period: BillingPeriod; amount: number; trial_days: number; expires_at: string; trial_policy: string; payment_method: string | null; payment_method_label: string | null };
type PaymentOptions = { available: boolean; hosted_checkout?: boolean; trial_payment_setup_available?: boolean; trial_requires_payment?: boolean; methods: { id: string; label: string; description: string; fields_enabled: boolean }[] };
export default function Subscribe() {
  const [params] = useSearchParams();
  const location = useLocation();
  const session = useSession();
  const plans = useQuery({ queryKey: ['plans'], queryFn: ({ signal }) => api<PricingPlan[]>('/plans', signal) });
  const access = useQuery({ queryKey: ['subscription'], enabled: !!session.data?.user, queryFn: ({ signal }) => api<{ trial_eligible: boolean; status: string }>('/me/subscription', signal) });
  const [selected, setSelected] = useState(params.get('plan') ?? 'pro');
  const [period, setPeriod] = useState<BillingPeriod>(params.get('period') === 'yearly' ? 'yearly' : 'monthly');
  const [mode, setMode] = useState<'trial' | 'pay'>(params.get('mode') === 'pay' ? 'pay' : 'trial');
  const [paymentMethod, setPaymentMethod] = useState('card');
  const paymentOptions = useQuery({ queryKey: ['payment-methods'], enabled: !!session.data?.user, queryFn: ({ signal }) => api<PaymentOptions>('/payment-methods', signal) });
  const [confirmed, setConfirmed] = useState(false);
  const client = useQueryClient();
  const quote = useMutation({ mutationFn: () => jsonApi<Quote>('/subscriptions/quote', { plan: selected, period, mode, payment_method: paymentMethod }) });
  const submit = useMutation({ mutationFn: () => jsonApi<{ status: string; message?: string; trial_ends_at?: string; checkout_url?: string }>(quote.data!.mode === 'trial' ? '/subscriptions/trial' : '/subscriptions/checkout', { quote_id: quote.data!.id, confirmed }), onSuccess: data => { if (data.checkout_url) openSaspayCheckout(data.checkout_url); for (const key of ['session', 'subscription', 'usage', 'jobs']) void client.invalidateQueries({ queryKey: [key] }); } });
  const paymentId = params.get('payment');
  const paymentStatus = useQuery({
    queryKey: ['payment-status', paymentId],
    enabled: !!session.data?.user && !!paymentId && /^\d+$/.test(paymentId),
    queryFn: ({ signal }) => api<{ status: string; payment_confirmed: boolean; review_required: boolean; payment_status: string; checkout_url: string | null }>(`/subscriptions/${paymentId}/payment-status`, signal),
    refetchInterval: query => query.state.error || query.state.data?.payment_confirmed || query.state.data?.review_required || ['CANCELLED', 'EXPIRED'].includes(query.state.data?.payment_status ?? '') ? false : 5000,
    retry: false,
  });
  useEffect(() => {
    if (paymentStatus.data?.payment_confirmed) {
      for (const key of ['session', 'subscription', 'usage', 'jobs']) void client.invalidateQueries({ queryKey: [key] });
    }
  }, [paymentStatus.data?.payment_confirmed, client]);
  function reset() { quote.reset(); submit.reset(); setConfirmed(false); }
  if (session.isPending || plans.isPending) return <div className="container page-section"><Loading /></div>;
  if (session.error || plans.error) return <div className="container page-section"><ErrorState message={(session.error ?? plans.error)!.message} retry={() => { void session.refetch(); void plans.refetch(); }} /></div>;
  if (!session.data?.user) return <section className="container page-section narrow"><h1>Votre souscription</h1><Notice>Connectez-vous ou créez un compte gratuit pour choisir une formule et vérifier votre éligibilité à l’essai.</Notice><div className="account-links"><Link className="button primary" to={`/connexion?retour=${encodeURIComponent(location.pathname + location.search)}`}>Se connecter</Link><Link className="button secondary" to={`/inscription?retour=${encodeURIComponent(location.pathname + location.search)}`}>Créer un compte gratuit</Link></div></section>;
  const plan = plans.data?.find(item => item.id === selected && !item.is_free);
  const price = period === 'monthly' ? plan?.monthly_price : plan?.yearly_price;
  const trialSetupBlocked = (paymentOptions.data?.available || paymentOptions.data?.trial_requires_payment) && !paymentOptions.data?.trial_payment_setup_available;
  const eligible = access.data?.trial_eligible && plan?.trial_enabled && !trialSetupBlocked;
  const busy = quote.isPending || submit.isPending;
  const summaryPlan = quote.data?.plan ?? plan;
  const summaryPrice = quote.data?.amount ?? price;
  const summaryPeriod = quote.data?.period ?? period;
  const summaryMode = quote.data?.mode ?? mode;
  return <section className="container page-section subscribe-page">
    <title>Votre souscription — Convert</title>
    <Link className="subscribe-back" to="/tarifs"><ArrowLeft size={15} />Revoir les offres</Link>
    <header className="subscribe-heading"><span className="eyebrow">VOTRE PROCHAIN ACCÈS</span><h1>Choisir ma formule</h1><p>Une formule adaptée à vos besoins. Des conditions claires avant de confirmer.</p></header>
    {paymentId && <section className="panel" aria-label="Statut du paiement">
      {paymentStatus.isPending && <Loading label="Vérification de votre paiement SasPay…" />}
      {paymentStatus.error && <ErrorState message={paymentStatus.error.message} retry={() => { void paymentStatus.refetch(); }} />}
      {paymentStatus.data && <Notice>{paymentStatus.data.review_required ? 'Votre paiement a été reçu et nécessite une vérification. Contactez le support.' : paymentStatus.data.payment_confirmed ? 'Votre paiement est confirmé. Consultez votre abonnement pour connaître votre accès actuel.' : 'Votre paiement est en attente de confirmation par SasPay. Aucun accès payant n’est encore activé.'}<p><Link to="/mon-abonnement">Consulter mon abonnement</Link></p></Notice>}
      {paymentStatus.data?.checkout_url && <button className="button primary" onClick={() => openSaspayCheckout(paymentStatus.data.checkout_url!)}>Reprendre le paiement sur SasPay</button>}
      {['CANCELLED', 'EXPIRED'].includes(paymentStatus.data?.payment_status ?? '') && <Notice>Cette session de paiement est annulée ou expirée. Vous pouvez préparer une nouvelle souscription.</Notice>}
    </section>}
    <div className="subscribe-layout">
      <div className="subscribe-main">
        <section className="panel subscribe-form"><fieldset disabled={busy || submit.isSuccess}><legend className="sr-only">Formule et mode de souscription</legend>
          <div className="subscribe-section-title"><span className="subscribe-section-icon"><SlidersHorizontal size={19} /></span><div><h2>Personnalisez votre accès</h2><p>Choisissez votre formule et votre périodicité.</p></div></div>
          <div className="subscribe-fields"><div className="field"><label htmlFor="subscribe-plan">Formule</label><select id="subscribe-plan" value={selected} onChange={event => { setSelected(event.target.value); reset(); }}><option value="" disabled>Sélectionner une formule</option>{plans.data?.filter(item => !item.is_free).map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></div><div className="field"><label htmlFor="subscribe-period">Périodicité</label><select id="subscribe-period" value={period} onChange={event => { setPeriod(event.target.value as BillingPeriod); reset(); }}><option value="monthly">Mensuelle</option><option value="yearly">Annuelle</option></select></div></div>
          {!plan && <Notice tone="error">Cette formule n’est pas disponible.</Notice>}
          <section className="subscribe-payment" aria-label="Moyen de paiement"><h2>Votre moyen de paiement</h2><p>Choisissez Carte bancaire ou Mobile Money pour votre demande.</p>
            {paymentOptions.isPending && <Loading label="Chargement des moyens de paiement…" />}
            {paymentOptions.error && <ErrorState message={paymentOptions.error.message} retry={() => { void paymentOptions.refetch(); }} />}
            <div className="subscribe-payment-methods">{paymentOptions.data?.methods.map(method => <label key={method.id} className={`subscribe-option${paymentMethod === method.id ? ' is-selected' : ''}`}><input type="radio" name="payment-method" value={method.id} checked={paymentMethod === method.id} onChange={() => { setPaymentMethod(method.id); reset(); }} /><span className="subscribe-option-body"><strong>{method.label}</strong><span>{method.description}</span></span></label>)}</div>
            {paymentOptions.data?.available && <Notice>Vous renseignerez vos coordonnées de carte bancaire ou de Mobile Money sur la page sécurisée SasPay. Les moyens proposés dépendent de votre pays.</Notice>}
            {paymentOptions.data && !paymentOptions.data.available && <><p className="subscribe-payment-status"><LockKeyhole size={15} />Bientôt disponible — aucun paiement ne peut encore être encaissé.</p>
              <fieldset disabled className="subscribe-payment-preview"><legend>{paymentMethod === 'card' ? 'Informations de carte bancaire' : 'Informations Mobile Money'} — aperçu</legend>{paymentMethod === 'card' ? <><div className="field"><label htmlFor="payment-cardholder">Nom du titulaire</label><input id="payment-cardholder" autoComplete="off" placeholder="Nom sur la carte" /></div><div className="field"><label htmlFor="payment-number">Numéro de carte</label><input id="payment-number" autoComplete="off" placeholder="•••• •••• •••• ••••" /></div><div className="subscribe-fields"><div className="field"><label htmlFor="payment-expiry">Date d’expiration</label><input id="payment-expiry" autoComplete="off" placeholder="MM / AA" /></div><div className="field"><label htmlFor="payment-cvv">Code de sécurité (CVV)</label><input id="payment-cvv" autoComplete="off" placeholder="•••" /></div></div></> : <><div className="field"><label htmlFor="payment-operator">Opérateur Mobile Money</label><select id="payment-operator"><option>Disponible après intégration du prestataire</option></select></div><div className="field"><label htmlFor="payment-phone">Numéro de téléphone Mobile Money</label><input id="payment-phone" type="tel" autoComplete="off" placeholder="Numéro associé à votre compte mobile" /></div></>}</fieldset>
              <p className="subscribe-price-note">Ces champs sont désactivés jusqu’à l’intégration du prestataire. Seul le moyen de paiement choisi est enregistré. Ne communiquez aucun code PIN ni code de validation.</p></>}
          </section>
        </fieldset></section>
        <div className="subscribe-help"><LockKeyhole size={17} /><p>Vos fichiers restent privés. Vous gardez le contrôle de votre formule et de vos résultats.</p></div>
      </div>
      <aside className="panel subscribe-summary" aria-label={quote.data ? 'Récapitulatif avant confirmation' : 'Aperçu de la formule'}>
        <div className="subscribe-summary-heading"><span className="eyebrow">{quote.data ? 'À VÉRIFIER' : 'VOTRE SÉLECTION'}</span><span className="subscribe-summary-mark"><Layers3 size={22} /></span></div>
        <h2>{quote.data ? 'Récapitulatif avant confirmation' : 'Votre formule, en un regard'}</h2>
        <div className="subscribe-plan-line"><strong>{summaryPlan?.name ?? 'Sélectionnez une formule'}</strong><span>{summaryPeriod === 'monthly' ? 'Mensuel' : 'Annuel'}</span></div>
        <div className="subscribe-summary-price">{summaryPrice != null ? <><strong>{summaryPrice.toLocaleString('fr')}</strong><span>FCFA<small>par {summaryPeriod === 'monthly' ? 'mois' : 'an'}</small></span></> : <strong>Tarif indisponible</strong>}</div>
        <p className="subscribe-price-note">{summaryMode === 'trial' ? 'Tarif de la formule après l’essai, uniquement si vous choisissez de payer.' : paymentOptions.data?.available ? 'Montant de la formule. SasPay affichera les éventuels frais avant le paiement.' : 'Tarif de la formule choisie. Le paiement n’est pas encore disponible.'}</p>
        <dl className="subscribe-summary-limits"><div><dt>Fichier maximum</dt><dd>{quotaValue('max_file_bytes', summaryPlan?.limits?.max_file_bytes)}</dd></div><div><dt>Tâches par mois</dt><dd>{summaryPlan?.limits?.monthly_jobs?.toLocaleString('fr') ?? '—'}</dd></div><div><dt>Tâches simultanées</dt><dd>{summaryPlan?.limits?.concurrent_jobs ?? '—'}</dd></div><div><dt>Historique</dt><dd>{quotaValue('history_hours', summaryPlan?.limits?.history_hours)}</dd></div></dl>
        <div className="subscribe-payment-summary"><span>Moyen de paiement</span><strong>{quote.data?.payment_method_label ?? paymentOptions.data?.methods.find(method => method.id === paymentMethod)?.label ?? 'Chargement…'}</strong></div>
        <fieldset className="subscribe-summary-options" disabled={busy || submit.isSuccess}><legend className="sr-only">Mode de souscription</legend>
          <div className="subscribe-mode-heading"><h2>Comment commencer ?</h2><p>Choisissez un essai gratuit ou un paiement immédiat.</p></div>
          <div className="subscribe-options">
            <label className={`subscribe-option${mode === 'trial' ? ' is-selected' : ''}${!eligible ? ' is-disabled' : ''}`}>
              <input type="radio" name="subscribe-mode" aria-label={`Essayer gratuitement pendant ${plan?.trial_days ?? '…'} jours`} checked={mode === 'trial'} disabled={!eligible} onChange={() => { setMode('trial'); reset(); }} />
              <span className="subscribe-option-body"><span className="subscribe-option-top"><strong>Commencer par un essai</strong><span className="subscribe-option-tag">{plan?.trial_days ?? '…'} jours gratuits</span></span><span>Informations de paiement requises pour l’essai gratuit. Aucun prélèvement pendant l’essai.</span></span><Sparkles className="subscribe-option-icon" size={20} aria-hidden="true" />
            </label>
            <label className={`subscribe-option${mode === 'pay' ? ' is-selected' : ''}`}>
              <input type="radio" name="subscribe-mode" aria-label="Payer maintenant" checked={mode === 'pay'} onChange={() => { setMode('pay'); reset(); }} />
              <span className="subscribe-option-body"><span className="subscribe-option-top"><strong>Payer maintenant</strong><span className="subscribe-option-tag is-neutral">{paymentOptions.data?.available ? 'SasPay' : 'Bientôt disponible'}</span></span><span>{paymentOptions.data?.available ? 'Réglez votre formule sur la page sécurisée SasPay. Votre accès sera activé après confirmation du paiement.' : 'Préparez votre demande. Le paiement n’est pas encore ouvert : aucun montant ne sera débité.'}</span></span><CreditCard className="subscribe-option-icon" size={20} aria-hidden="true" />
            </label>
          </div>
          {access.isPending && <Loading label="Vérification de votre essai…" />}{access.error && <ErrorState message={access.error.message} retry={() => { void access.refetch(); }} />}
          {trialSetupBlocked && <Notice>La validation des coordonnées de paiement sans débit pour l’essai gratuit n’est pas encore disponible. Vous pouvez choisir un paiement immédiat.</Notice>}
          {!access.isPending && !eligible && !trialSetupBlocked && <Notice>Votre essai n’est pas disponible. {plan?.trial_policy} Vous pouvez préparer une demande de paiement ; elle ne changera pas vos droits tant que le paiement n’est pas réellement confirmé.</Notice>}
          <FormError error={quote.error} /><button type="button" className="button primary full-width subscribe-review" disabled={!plan || price == null || !plan.billing_periods.includes(period) || (mode === 'trial' && !eligible) || !paymentOptions.data?.methods.some(method => method.id === paymentMethod)} onClick={() => { setConfirmed(false); submit.reset(); quote.mutate(); }}>{quote.isPending ? 'Vérification…' : 'Vérifier le récapitulatif'}<ArrowRight size={17} /></button>
          <p className="subscribe-reassurance"><ShieldCheck size={15} />Aucune activation sans votre confirmation.</p>
        </fieldset>
        <div className="subscribe-due"><span>{summaryMode === 'pay' && paymentOptions.data?.available ? 'À régler sur SasPay' : 'Prélevé aujourd’hui'}</span><strong>{summaryMode === 'pay' && paymentOptions.data?.available ? `${summaryPrice?.toLocaleString('fr')} FCFA` : '0 FCFA'}</strong></div>
        {quote.data ? <div className="subscribe-confirmation">
          <Notice>{quote.data.mode === 'trial' ? `L’essai commence à la confirmation et se termine après ${quote.data.trial_days} jours. Sans paiement confirmé, retour automatique au Gratuit. Aucun prélèvement ni reconduction payante. ${quote.data.trial_policy}` : paymentOptions.data?.available ? 'Vous allez être redirigé vers SasPay pour payer. Votre formule sera activée uniquement après vérification du paiement. Aucun renouvellement automatique.' : 'Le paiement sera bientôt disponible. Cette demande restera en attente ; elle ne sera pas présentée comme payée et n’activera aucun droit supplémentaire.'}</Notice><p className="subscribe-quote-expiry">Récapitulatif valable jusqu’au {new Date(quote.data.expires_at).toLocaleString('fr')}.</p>
          {!submit.isSuccess && <><label className="subscribe-accept"><input type="checkbox" checked={confirmed} disabled={busy} onChange={event => setConfirmed(event.target.checked)} /><span>J’ai lu les conditions ci-dessus et je confirme ce choix.</span></label><FormError error={submit.error} /><button className="button primary full-width" disabled={!confirmed || busy || (quote.data.mode === 'trial' && !!trialSetupBlocked)} onClick={() => submit.mutate()}>{busy ? 'Confirmation…' : quote.data.mode === 'trial' ? 'Démarrer mon essai gratuit' : paymentOptions.data?.available ? 'Payer avec SasPay' : 'Confirmer la demande de paiement'}</button></>}
          {submit.isSuccess && !submit.data.checkout_url && <Notice>{submit.data.status === 'trialing' ? `Votre essai est actif jusqu’au ${new Date(submit.data.trial_ends_at!).toLocaleString('fr')}. Aucun montant n’a été prélevé.` : submit.data.message ?? `État de la demande : ${submit.data.status}. Aucun paiement n’a été confirmé.`}<p><Link className="quiet-link" to="/mon-abonnement">Consulter mon abonnement →</Link></p></Notice>}
        </div> : <p className="subscribe-preview-note"><Check size={15} />Vérifiez le récapitulatif pour lire les conditions et confirmer votre choix.</p>}
      </aside>
    </div>
    <div className="subscribe-bottom-links"><Link to="/tarifs">Comparer les offres</Link><span aria-hidden="true">·</span><Link to="/mon-abonnement">Mon abonnement</Link></div>
  </section>;
}
