import type { PlanLimits } from '../lib/api';
import { quotaValue } from '../lib/plans';

export default function QuotaMeter({ limits, used }: { limits: PlanLimits; used?: number }) {
  const maximum = limits.monthly_jobs ?? limits.daily_jobs;
  const monthly = limits.monthly_jobs != null;
  return <div className="quota-card"><div><span className="eyebrow">VOTRE QUOTA</span><span className="status-pill">{used === undefined ? 'Limites prévues' : 'Consommation réelle'}</span></div><h3>{used === undefined ? 'Un premier pas, sans compte.' : 'Votre utilisation, en un regard.'}</h3><p><strong>{quotaValue('max_file_bytes', limits.max_file_bytes)}</strong> par fichier <span>·</span> <strong>{maximum}</strong> opérations / {monthly ? '30 jours' : '24 h'}</p>{used === undefined ? <small>Le suivi de consommation sera activé avec les conversions.</small> : <><progress value={Math.max(0, Math.min(maximum, used))} max={maximum} aria-label={monthly ? 'Quota mensuel utilisé' : 'Quota journalier utilisé'} /><small>{used} / {maximum} opérations utilisées</small></>}<div className="quota-details"><span>{limits.hourly_jobs} opérations / heure glissante</span><span>Résultats conservés {limits.retention_hours} h</span></div></div>;
}
