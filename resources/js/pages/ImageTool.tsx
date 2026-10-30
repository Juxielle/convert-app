import { useEffect, useState } from 'react';
import { useMutation, useQueries, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowRight, Download, X } from 'lucide-react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';
import type { Tool, PlanLimits } from '../lib/api';
import { CategoryIcon, Dropzone, ErrorState, FormatSelect, JobState, Loading, Notice, QuotaIndicator } from '../components/ui';
import type { JobStatus } from '../components/ui';

type Job = { id: string; name: string; status: JobStatus; progress: number | null; error_code: string | null; input_size: number; output_size: number | null; reduction_percent: number | null; download_available: boolean };
export default function ImageTool({ tool }: { tool: Tool }) {
  const [files, setFiles] = useState<File[]>([]);
  const [format, setFormat] = useState(tool.target_formats[0] ?? 'jpg');
  const [quality, setQuality] = useState(82);
  const [width, setWidth] = useState('');
  const [height, setHeight] = useState('');
  const [error, setError] = useState('');
  const [ids, setIds] = useState<string[]>([]);
  const client = useQueryClient();
  const usage = useQuery({ queryKey: ['usage'], queryFn: ({ signal }) => api<{ limits: PlanLimits; used: { daily_jobs: number }; quota_used?: number; entitlements?: Record<string, boolean> }>('/me/usage', signal) });
  const maxFiles = usage.data?.entitlements?.batch_processing ? tool.image_limits?.max_batch_files ?? 5 : 1;
  const jobs = useQueries({ queries: ids.map(id => ({ queryKey: ['job', id], queryFn: ({ signal }: { signal: AbortSignal }) => api<Job>(`/jobs/${id}`, signal), refetchInterval: (query: { state: { data: Job | undefined; error?: unknown } }) => query.state.error ? false as const : !query.state.data || ['uploaded', 'queued', 'processing'].includes(query.state.data.status) ? 2000 : false as const })) });
  const finished = ids.length > 0 && jobs.every(query => query.data && !['uploaded', 'queued', 'processing'].includes(query.data.status));
  useEffect(() => { if (finished) { void client.invalidateQueries({ queryKey: ['usage'] }); void client.invalidateQueries({ queryKey: ['jobs'] }); } }, [finished, client]);
  const upload = useMutation({
    mutationFn: async () => {
      const body = new FormData();
      body.set('tool', tool.slug); body.set('target_format', format); body.set('options[quality]', String(quality));
      if (width && tool.operation !== 'compress') body.set('options[width]', width);
      if (height && tool.operation !== 'compress') body.set('options[height]', height);
      files.forEach(file => body.append('files[]', file));
      return api<Job[]>('/conversions', undefined, false, 'POST', body);
    },
    onSuccess: data => { setIds(data.map(job => job.id)); setFiles([]); void client.invalidateQueries({ queryKey: ['usage'] }); void client.invalidateQueries({ queryKey: ['jobs'] }); },
  });
  function addFiles(incoming: File[]) {
    if (files.length + incoming.length > maxFiles) { setError(`Sélectionnez au maximum ${maxFiles} images par lot.`); return; }
    if (incoming.some(file => !/\.(jpe?g|png|webp)$/i.test(file.name))) { setError('Sélectionnez uniquement des images JPEG, PNG ou WebP.'); return; }
    setError(''); setFiles([...files, ...incoming]);
  }
  return <div className="container page-section"><title>{tool.name} — Convert</title><nav className="breadcrumb" aria-label="Fil d’Ariane"><Link to="/outils">Les outils</Link><span> / {tool.name}</span></nav><div className="tool-page-heading"><CategoryIcon category="images" size={30} /><div><span className="eyebrow">IMAGES · DISPONIBLE</span><h1>{tool.name}</h1><p className="page-lead">JPEG, PNG et WebP. Vos fichiers restent privés.</p></div></div><form className="tool-workspace" onSubmit={event => { event.preventDefault(); if (files.length) upload.mutate(); }}><div>{(error || upload.error) && <Notice tone="error">{error || upload.error?.message}</Notice>}<Dropzone disabled={upload.isPending || !usage.data} maxBytes={usage.data?.limits.max_file_bytes ?? 25_000_000} accept=".jpg,.jpeg,.png,.webp" onFiles={addFiles} /><p className="catalog-disclaimer">{maxFiles} images par lot · {((tool.image_limits?.max_pixels ?? 6_000_000) / 1_000_000)} mégapixels maximum · Images animées refusées</p><ul className="selected-files">{files.map((file, index) => <li key={`${file.name}-${index}`}><span>{file.name}<small>{(file.size / 1_000_000).toLocaleString('fr', { maximumFractionDigits: 2 })} Mo</small></span><button type="button" className="icon-button" disabled={upload.isPending} aria-label={`Retirer ${file.name}`} onClick={() => setFiles(files.filter((_, i) => index !== i))}><X size={17} /></button></li>)}</ul>{upload.isPending && <Loading label="Envoi des fichiers…" />}{ids.length > 0 && <section className="image-results" aria-label="Résultats des conversions"><h2>Vos résultats</h2>{jobs.map((query, index) => query.isPending ? <Loading key={ids[index]} label="Chargement de la tâche…" /> : query.error ? <ErrorState key={ids[index]} message={query.error.message} retry={() => { void query.refetch(); }} /> : query.data && <article className="panel" key={query.data.id}><h3>{query.data.name}</h3><JobState status={query.data.status} progress={query.data.progress ?? undefined} />{query.data.status === 'failed' && <p>Le traitement a échoué. Vérifiez que l’image est valide et respecte les limites.</p>}{query.data.output_size !== null && <p>{query.data.input_size.toLocaleString('fr')} → {query.data.output_size.toLocaleString('fr')} octets · {(query.data.reduction_percent ?? 0) >= 0 ? 'Réduction' : 'Augmentation'} de {Math.abs(query.data.reduction_percent ?? 0)} %</p>}{query.data.download_available && <a href={`/api/v1/jobs/${query.data.id}/download`} className="button secondary"><Download size={16} />Télécharger</a>}</article>)}<Link className="quiet-link" to="/mes-fichiers">Gérer, annuler ou supprimer mes tâches <ArrowRight size={16} /></Link></section>}</div><aside className="tool-sidebar"><div className="panel settings-panel"><h2>Vos réglages</h2><FormatSelect formats={tool.target_formats} value={format} onChange={setFormat} disabled={upload.isPending} /><div className="field image-field"><label htmlFor="quality">Qualité JPEG / WebP : {quality}</label><input id="quality" type="range" min="1" max="100" value={quality} disabled={format === 'png' || upload.isPending} onChange={event => setQuality(Number(event.target.value))} /><small>{format === 'png' ? 'PNG : compression sans perte maximale, sans réglage de qualité.' : 'Une qualité plus basse peut réduire le poids, avec une perte de détails.'}</small></div>{tool.operation !== 'compress' && <div className="dimension-fields"><div className="field"><label htmlFor="image-width">Largeur max. (px)</label><input id="image-width" type="number" min="1" max={tool.image_limits?.max_dimension ?? 8192} value={width} disabled={upload.isPending} onChange={event => setWidth(event.target.value)} /></div><div className="field"><label htmlFor="image-height">Hauteur max. (px)</label><input id="image-height" type="number" min="1" max={tool.image_limits?.max_dimension ?? 8192} value={height} disabled={upload.isPending} onChange={event => setHeight(event.target.value)} /></div></div>}<p className="image-note">Proportions conservées, sans agrandissement. La transparence devient blanche en JPEG. Les métadonnées sont supprimées. Une conversion peut augmenter le poids.</p><button type="submit" className="button primary full-width" disabled={!files.length || upload.isPending || !usage.data}>{upload.isPending ? 'Envoi en cours…' : `Traiter ${files.length || ''} image${files.length > 1 ? 's' : ''}`}<ArrowRight size={16} /></button><small>Chaque image compte comme une opération. Le lot est accepté intégralement ou refusé.</small></div>{usage.isPending ? <Loading label="Chargement des limites…" /> : usage.error ? <ErrorState message={usage.error.message} retry={() => { void usage.refetch(); }} /> : usage.data && <QuotaIndicator limits={usage.data.limits} used={usage.data.quota_used ?? usage.data.used.daily_jobs} />}<Notice>Conservation : {usage.data?.limits.retention_hours ?? 1} h à partir de l’envoi. Gardez cette session pour télécharger vos fichiers.</Notice></aside></form></div>;
}
