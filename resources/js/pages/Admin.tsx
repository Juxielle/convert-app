import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { useEffect, useState } from 'react';
import { api, jsonApi, useSession, type PlanLimits } from '../lib/api';
import { ErrorState, Loading, Notice } from '../components/ui';
import { FormError } from './Account';
import AdminPlans from './AdminPlans';
import AdminSubscriptions from './AdminSubscriptions';

type Settings = { limits: { guest: PlanLimits; registered: PlanLimits }; tools: Record<string, boolean> };
type Overview = { states: Record<string, number>; jobs_24h: number; failure_rate_24h: number; mean_processing_seconds: number | null; reserved_bytes: number; free_disk_bytes: number | null; worker_last_seen: string | null; external_worker_last_seen: string | null; memory_php_bytes: number; memory_note: string };
type AdminJob = { id: string; tool: string; status: string; error_code: string | null };
type Audit = { id: number; action: string; target: string; created_at: string };
const labels: Record<string, string> = { max_file_bytes: 'Taille par fichier (octets)', storage_bytes: 'Stockage temporaire (octets)', hourly_jobs: 'Opérations par heure', daily_jobs: 'Opérations par jour', concurrent_jobs: 'Tâches simultanées', timeout_seconds: 'Délai maximal (secondes)', retention_hours: 'Conservation (heures)' };

export default function Admin() {
  const session = useSession();
  const enabled = session.data?.user?.role === 'admin';
  const overview = useQuery({ queryKey: ['admin-overview'], enabled, queryFn: ({ signal }) => api<Overview>('/admin/overview', signal), refetchInterval: 10000 });
  const settings = useQuery({ queryKey: ['admin-settings'], enabled, queryFn: ({ signal }) => api<Settings>('/admin/settings', signal) });
  const [page, setPage] = useState(1);
  const jobs = useQuery({ queryKey: ['admin-jobs', page], enabled, queryFn: ({ signal }) => api<{ data: AdminJob[]; last_page: number }>(`/admin/jobs?page=${page}`, signal, true), refetchInterval: 10000 });
  const audits = useQuery({ queryKey: ['admin-audit'], enabled, queryFn: ({ signal }) => api<{ data: Audit[] }>('/admin/audit', signal, true) });
  if (session.isPending) return <div className="container page-section"><Loading /></div>;
  if (session.error) return <div className="container page-section"><ErrorState message={session.error.message} retry={() => { void session.refetch(); }} /></div>;
  if (!enabled) return <section className="container page-section"><h1>Administration</h1><Notice tone="error">Cet espace est réservé aux administrateurs.</Notice><Link to="/profil">Mon profil</Link></section>;
  return <section className="container page-section"><title>Administration — Convert</title><span className="eyebrow">EXPLOITATION</span><h1>Administration</h1><Notice>Les réglages ne donnent aucun accès au contenu des fichiers. Les conversions déjà en cours conservent leurs limites ; désactiver un outil bloque ses prochains traitements.</Notice>
    {overview.error && <ErrorState message={overview.error.message} retry={() => { void overview.refetch(); }} />}
    {overview.data && <><div className="admin-metrics">{[['Tâches sur 24 h', overview.data.jobs_24h], ['Échecs sur 24 h', `${overview.data.failure_rate_24h} %`], ['Durée moyenne', overview.data.mean_processing_seconds === null ? 'Non disponible' : `${overview.data.mean_processing_seconds} s`], ['Stockage réservé', `${(overview.data.reserved_bytes / 1e6).toFixed(1)} Mo`], ['Disque libre', overview.data.free_disk_bytes === null ? 'Non disponible' : `${(overview.data.free_disk_bytes / 1e9).toFixed(1)} Go`]].map(([label, value]) => <article className="panel" key={label}><h2>{label}</h2><strong>{value}</strong></article>)}</div><p>Dernier signal de worker : {overview.data.worker_last_seen ? new Date(overview.data.worker_last_seen).toLocaleString('fr') : 'Aucun signal récent observé'}. Ce signal expire après deux minutes.</p><p>Signal du worker externe : {overview.data.external_worker_last_seen ? new Date(overview.data.external_worker_last_seen).toLocaleString('fr') : 'Aucun signal récent observé'}.</p><p>{overview.data.memory_note}</p><div className="panel"><h2>Répartition des tâches</h2>{Object.entries(overview.data.states).map(([state, count]) => <p key={state}>{state} : {count}</p>)}</div></>}
    <AdminPlans /><AdminSubscriptions />
    {settings.error && <ErrorState message={settings.error.message} retry={() => { void settings.refetch(); }} />}{settings.data && <SettingsForm initial={settings.data} />}
    <div className="panel"><h2>Tâches récentes</h2>{jobs.error && <ErrorState message={jobs.error.message} retry={() => { void jobs.refetch(); }} />}<div className="table-scroll"><table><thead><tr><th>Identifiant</th><th>Outil</th><th>État</th><th>Erreur</th></tr></thead><tbody>{jobs.data?.data.map(job => <tr key={job.id}><td>{job.id}</td><td>{job.tool}</td><td>{job.status}</td><td>{job.error_code ?? '—'}</td></tr>)}</tbody></table></div><div className="account-links"><button className="button secondary" disabled={page === 1} onClick={() => setPage(page - 1)}>Précédent</button><span>Page {page}</span><button className="button secondary" disabled={!jobs.data || page >= jobs.data.last_page} onClick={() => setPage(page + 1)}>Suivant</button></div></div>
    <div className="panel"><h2>Dernières actions administratives</h2>{audits.error && <ErrorState message={audits.error.message} retry={() => { void audits.refetch(); }} />}{audits.data?.data.map(log => <p key={log.id}>{new Date(log.created_at).toLocaleString('fr')} · {log.action} · {log.target}</p>)}</div>
  </section>;
}

function SettingsForm({ initial }: { initial: Settings }) {
  const [draft, setDraft] = useState(initial);
  useEffect(() => { setDraft(initial); }, [initial]);
  const client = useQueryClient();
  const update = useMutation({ mutationFn: () => jsonApi<Settings>('/admin/settings', draft, 'PATCH'), onSuccess: () => { for (const key of ['admin-audit', 'admin-settings', 'admin-plans', 'tools', 'limits', 'usage', 'plans', 'subscription']) void client.invalidateQueries({ queryKey: [key] }); } });
  return <form className="panel account-form" onSubmit={event => { event.preventDefault(); update.mutate(); }}><h2>Quotas et outils</h2><FormError error={update.error} />{update.isSuccess && <Notice>Réglages enregistrés et journalisés.</Notice>}<fieldset disabled={update.isPending}><legend className="sr-only">Réglages autorisés</legend><div className="admin-quota-grid">{(['guest', 'registered'] as const).map(role => <section key={role}><h3>{role === 'guest' ? 'Visiteur' : 'Compte gratuit'}</h3>{Object.entries(draft.limits[role]).filter(([key]) => labels[key]).map(([key, value]) => <div className="field" key={key}><label htmlFor={`${role}-${key}`}>{labels[key]}</label><input id={`${role}-${key}`} type="number" min="1" required value={value ?? ''} onChange={event => setDraft({ ...draft, limits: { ...draft.limits, [role]: { ...draft.limits[role], [key]: Number(event.target.value) } } })} /></div>)}</section>)}</div><h3>Outils autorisés</h3><p>Une activation ne remplace pas la vérification des moteurs et codecs sur l’hôte.</p>{Object.entries(draft.tools).map(([tool, enabled]) => <label className="admin-toggle" key={tool}><input type="checkbox" checked={enabled} onChange={event => setDraft({ ...draft, tools: { ...draft.tools, [tool]: event.target.checked } })} />{tool}</label>)}<button className="button primary">Enregistrer les réglages</button></fieldset></form>;
}
