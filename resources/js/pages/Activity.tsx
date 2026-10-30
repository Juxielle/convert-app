import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Clock3, Download, FolderOpen, Trash2, X } from 'lucide-react';
import { Link } from 'react-router-dom';
import { api, mutateApi } from '../lib/api';
import type { PlanLimits } from '../lib/api';
import { ErrorState, JobState, Loading, Notice, QuotaIndicator } from '../components/ui';
import type { JobStatus } from '../components/ui';

type Job = {
  id: string; name: string; status: JobStatus; progress: number | null;
  input_size: number; output_size: number | null; expires_at: string;
  download_available: boolean; cancel_requested: boolean; delete_requested: boolean;
};
type JobPage = { data: Job[]; meta: { current_page: number; last_page: number } };
type Usage = { limits: PlanLimits; quota_used?: number; used: { daily_jobs: number; hourly_jobs: number; active_jobs: number; reserved_bytes: number } };
const active = (job: Job) => ['uploaded', 'queued', 'processing'].includes(job.status);

export default function Activity() {
  const [page, setPage] = useState(1);
  const client = useQueryClient();
  const jobs = useQuery({
    queryKey: ['jobs', page], queryFn: ({ signal }) => api<JobPage>(`/jobs?page=${page}`, signal, true),
    refetchInterval: query => query.state.data?.data.some(active) ? 3000 : false,
  });
  const usage = useQuery({ queryKey: ['usage'], queryFn: ({ signal }) => api<Usage>('/me/usage', signal), refetchInterval: query => query.state.data?.used.active_jobs ? 5000 : false });
  const action = useMutation({
    mutationFn: ({ job, type }: { job: Job; type: 'cancel' | 'delete' }) => mutateApi(`/jobs/${job.id}${type === 'cancel' ? '/cancel' : ''}`, type === 'cancel' ? 'POST' : 'DELETE'),
    onSuccess: () => { void client.invalidateQueries({ queryKey: ['jobs'] }); void client.invalidateQueries({ queryKey: ['usage'] }); },
  });
  return <section className="container page-section"><title>Mes fichiers — Convert</title><span className="eyebrow">VOTRE ESPACE DE TRAVAIL</span><h1>Mes fichiers.</h1><p className="page-lead">Les tâches de votre session et votre consommation réelle. Les outils images et documents sont disponibles.</p><div className="activity-layout"><div>{action.error && <Notice tone="error" onDismiss={() => action.reset()}>{action.error.message}</Notice>}{jobs.isPending ? <Loading label="Chargement de vos tâches…" /> : jobs.error ? <ErrorState message={jobs.error.message} retry={() => { void jobs.refetch(); }} /> : jobs.data?.data.length ? <><div className="activity-jobs">{jobs.data.data.map(job => <article className="panel activity-job" key={job.id}><div><h2>{job.name}</h2><JobState status={job.status} progress={job.progress ?? undefined} /><small>{(job.input_size / 1_000_000).toLocaleString('fr', { maximumFractionDigits: 2 })} Mo{job.output_size !== null && ` → ${(job.output_size / 1_000_000).toLocaleString('fr', { maximumFractionDigits: 2 })} Mo`}</small>{job.status === 'completed' && <small>Expire le {new Intl.DateTimeFormat('fr', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(job.expires_at))}</small>}{job.cancel_requested && active(job) && <small>Annulation demandée, en attente de l’arrêt du traitement.</small>}</div><div className="job-actions">{job.download_available && <a className="button secondary" href={`/api/v1/jobs/${job.id}/download`}><Download size={16} />Télécharger</a>}{active(job) && <button className="icon-button" title="Annuler" aria-label={`Annuler ${job.name}`} disabled={action.isPending || job.cancel_requested} onClick={() => action.mutate({ job, type: 'cancel' })}><X size={18} /></button>}<button className="icon-button" title="Supprimer" aria-label={`Supprimer ${job.name}`} disabled={action.isPending || job.delete_requested} onClick={() => action.mutate({ job, type: 'delete' })}><Trash2 size={17} /></button></div></article>)}</div><nav className="pagination" aria-label="Pagination des tâches"><button className="button secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>Précédent</button><span>Page {jobs.data.meta.current_page} / {jobs.data.meta.last_page}</span><button className="button secondary" disabled={page >= jobs.data.meta.last_page} onClick={() => setPage(page + 1)}>Suivant</button></nav></> : <div className="panel empty-state"><FolderOpen size={32} /><h2>Aucun fichier pour le moment</h2><p>Envoyez une image depuis un outil pour retrouver ici son résultat.</p><Link className="button secondary" to="/outils">Découvrir les outils</Link></div>}</div><aside>{usage.isPending ? <Loading label="Chargement de votre quota…" /> : usage.error ? <ErrorState message={usage.error.message} retry={() => { void usage.refetch(); }} /> : usage.data && <><QuotaIndicator limits={usage.data.limits} used={usage.data.quota_used ?? usage.data.used.daily_jobs} /><p className="usage-detail">{usage.data.used.active_jobs} tâche(s) active(s) · {(usage.data.used.reserved_bytes / 1_000_000).toLocaleString('fr')} Mo réservés</p></>}<div className="retention-note"><Clock3 size={18} /><p>Sans compte, cet espace est limité à votre session. Effacer ses cookies vous fait perdre l’accès aux résultats.</p></div></aside></div></section>;
}
