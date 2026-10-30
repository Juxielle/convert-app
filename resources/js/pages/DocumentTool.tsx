import { useEffect, useState } from 'react';
import { useMutation, useQueries, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowDown, ArrowUp, Download, X } from 'lucide-react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';
import type { Tool, PlanLimits } from '../lib/api';
import { CategoryIcon, Dropzone, ErrorState, JobState, Loading, Notice, QuotaIndicator } from '../components/ui';
import type { JobStatus } from '../components/ui';

type Job = { id: string; name: string; status: JobStatus; progress: number | null; error_code: string | null; input_size: number; output_size: number | null; download_available: boolean };
const errorMessages: Record<string, string> = {
  PROTECTED_DOCUMENT: 'Ce document est protégé ou chiffré.', INVALID_DOCUMENT: 'Le document est endommagé ou incompatible.',
  DOCUMENT_TOO_COMPLEX: 'Le document dépasse les limites de pages, de cellules ou de décompression.',
  EXTERNAL_REFERENCE: 'Les ressources externes liées sont refusées.', UNSAFE_DOCUMENT: 'Les macros, objets intégrés et éléments actifs sont refusés.',
  INVALID_OPTIONS: 'La plage de pages ou la feuille demandée n’existe pas.', PROCESS_TIMEOUT: 'La durée maximale de traitement est dépassée.',
  OUTPUT_LIMIT_EXCEEDED: 'Le résultat dépasse la limite de stockage autorisée.',
};

export default function DocumentTool({ tool }: { tool: Tool }) {
  const [files, setFiles] = useState<File[]>([]);
  const [error, setError] = useState('');
  const [ids, setIds] = useState<string[]>([]);
  const [first, setFirst] = useState(1);
  const [last, setLast] = useState(1);
  const [angle, setAngle] = useState(90);
  const [dpi, setDpi] = useState(96);
  const [sheet, setSheet] = useState(1);
  const merge = tool.slug === 'fusionner-pdf';
  const maxFiles = merge ? 5 : 1;
  const client = useQueryClient();
  const usage = useQuery({ queryKey: ['usage'], queryFn: ({ signal }) => api<{ limits: PlanLimits; used: { daily_jobs: number }; quota_used?: number; entitlements?: Record<string, boolean> }>('/me/usage', signal) });
  const jobs = useQueries({ queries: ids.map(id => ({ queryKey: ['job', id], queryFn: ({ signal }: { signal: AbortSignal }) => api<Job>(`/jobs/${id}`, signal), refetchInterval: (query: { state: { data: Job | undefined; error?: unknown } }) => query.state.error ? false as const : !query.state.data || ['uploaded', 'queued', 'processing'].includes(query.state.data.status) ? 2000 : false as const })) });
  const finished = ids.length > 0 && jobs.every(query => query.data && !['uploaded', 'queued', 'processing'].includes(query.data.status));
  useEffect(() => { if (finished) { void client.invalidateQueries({ queryKey: ['usage'] }); void client.invalidateQueries({ queryKey: ['jobs'] }); } }, [finished, client]);
  const upload = useMutation({
    mutationFn: async () => {
      const body = new FormData(); body.set('tool', tool.slug); body.set('target_format', tool.target_formats[0]);
      files.forEach(file => body.append(merge ? 'files[]' : 'file', file));
      if (tool.operation === 'split') { body.set('options[first_page]', String(first)); body.set('options[last_page]', String(last)); }
      if (tool.operation === 'rotate') body.set('options[angle]', String(angle));
      if (tool.slug === 'pdf-vers-images') body.set('options[dpi]', String(dpi));
      if (tool.slug === 'xlsx-vers-csv') body.set('options[sheet]', String(sheet));
      return api<Job | Job[]>('/conversions', undefined, false, 'POST', body);
    },
    onSuccess: data => { const result = Array.isArray(data) ? data : [data]; setIds(result.map(job => job.id)); setFiles([]); void client.invalidateQueries({ queryKey: ['usage'] }); void client.invalidateQueries({ queryKey: ['jobs'] }); },
  });
  const maxBytes = Math.min(usage.data?.limits.max_file_bytes ?? 25_000_000, tool.document_limits?.max_input_bytes ?? 10_000_000);
  function addFiles(incoming: File[]) {
    const next = merge ? [...files, ...incoming] : incoming;
    if (next.length > maxFiles) { setError(`Sélectionnez au maximum ${maxFiles} fichier(s).`); return; }
    if (next.some(file => !tool.source_formats.includes(file.name.split('.').pop()?.toLowerCase() ?? ''))) { setError('Ce format de fichier n’est pas pris en charge par cet outil.'); return; }
    if (next.reduce((sum, file) => sum + file.size, 0) > maxBytes) { setError(`La sélection dépasse ${maxBytes / 1_000_000} Mo.`); return; }
    setError(''); setFiles(next);
  }
  function move(index: number, offset: number) { const next = [...files]; [next[index], next[index + offset]] = [next[index + offset], next[index]]; setFiles(next); }
  return <div className="container page-section"><title>{tool.name} — Convert</title><nav className="breadcrumb" aria-label="Fil d’Ariane"><Link to="/outils">Les outils</Link><span> / {tool.name}</span></nav><div className="tool-page-heading"><CategoryIcon category="documents" size={30} /><div><span className="eyebrow">PDF & DOCUMENTS · DISPONIBLE</span><h1>{tool.name}</h1><p className="page-lead">{tool.description}</p></div></div><form className="tool-workspace" onSubmit={event => { event.preventDefault(); if (files.length >= (merge ? 2 : 1)) upload.mutate(); }}><div>{(error || upload.error) && <Notice tone="error">{error || upload.error?.message}</Notice>}<Dropzone disabled={upload.isPending || !usage.data} maxBytes={maxBytes} accept={tool.source_formats.map(format => `.${format}`).join(',')} onFiles={addFiles} /><p className="catalog-disclaimer">{tool.source_formats.join(' · ').toUpperCase()} · {tool.slug.includes('csv') ? '10 000 lignes · 100 colonnes' : `${tool.document_limits?.max_pages ?? 50} pages maximum`} · {merge ? '2 à 5 PDF, 10 Mo au total' : 'Un fichier à la fois'}</p><ul className="selected-files">{files.map((file, index) => <li key={`${index}-${file.name}`}><span>{index + 1}. {file.name}<small>{file.size.toLocaleString('fr')} octets</small></span><div>{merge && <><button className="icon-button" type="button" aria-label={`Monter ${file.name}`} disabled={index === 0 || upload.isPending} onClick={() => move(index, -1)}><ArrowUp size={16} /></button><button className="icon-button" type="button" aria-label={`Descendre ${file.name}`} disabled={index === files.length - 1 || upload.isPending} onClick={() => move(index, 1)}><ArrowDown size={16} /></button></>}<button className="icon-button" type="button" aria-label={`Retirer ${file.name}`} disabled={upload.isPending} onClick={() => setFiles(files.filter((_, i) => i !== index))}><X size={17} /></button></div></li>)}</ul>{upload.isPending && <Loading label="Envoi du document…" />}{ids.length > 0 && <section className="image-results" aria-label="Résultats des documents"><h2>Votre résultat</h2>{jobs.map((query, index) => query.isPending ? <Loading key={ids[index]} label="Chargement de la tâche…" /> : query.error ? <ErrorState key={ids[index]} message={query.error.message} retry={() => { void query.refetch(); }} /> : query.data && <article className="panel" key={query.data.id}><h3>{query.data.name}</h3><JobState status={query.data.status} progress={query.data.progress ?? undefined} />{query.data.status === 'failed' && <Notice tone="error">{errorMessages[query.data.error_code ?? ''] ?? 'Le document n’a pas pu être traité.'}</Notice>}{query.data.output_size !== null && <p>{query.data.input_size.toLocaleString('fr')} → {query.data.output_size.toLocaleString('fr')} octets</p>}{query.data.download_available && <a href={`/api/v1/jobs/${query.data.id}/download`} className="button secondary"><Download size={16} />Télécharger</a>}</article>)}<Link to="/mes-fichiers" className="quiet-link">Gérer, annuler ou supprimer mes tâches</Link></section>}</div><aside className="tool-sidebar"><div className="panel settings-panel"><h2>Vos réglages</h2><p className="image-note">Sortie : <strong>{tool.target_formats[0] === 'zip' ? 'ZIP des pages en PNG' : tool.target_formats[0]?.toUpperCase()}</strong></p>{tool.operation === 'split' && <div className="dimension-fields"><div className="field"><label htmlFor="first-page">Première page</label><input id="first-page" type="number" min="1" max="50" value={first} onChange={event => setFirst(Number(event.target.value))} required /></div><div className="field"><label htmlFor="last-page">Dernière page</label><input id="last-page" type="number" min={first} max="50" value={last} onChange={event => setLast(Number(event.target.value))} required /></div></div>}{tool.operation === 'rotate' && <div className="field"><label htmlFor="rotation">Rotation de toutes les pages</label><select id="rotation" className="select-trigger" value={angle} onChange={event => setAngle(Number(event.target.value))}>{[90, 180, 270].map(value => <option key={value} value={value}>{value}°</option>)}</select></div>}{tool.slug === 'pdf-vers-images' && <div className="field"><label htmlFor="dpi">Résolution des pages</label><select id="dpi" className="select-trigger" value={dpi} onChange={event => setDpi(Number(event.target.value))}>{[72, 96, 150].map(value => <option key={value} value={value}>{value} dpi</option>)}</select><small>Plafond de 20 mégapixels cumulés sur le document.</small></div>}{tool.slug === 'xlsx-vers-csv' && <div className="field dimension-fields"><label htmlFor="sheet-number">Numéro de la feuille</label><input id="sheet-number" type="number" min="1" max="20" value={sheet} onChange={event => setSheet(Number(event.target.value))} required /></div>}<p className="image-note">{tool.slug === 'office-vers-pdf' ? 'La mise en page, les polices et les sauts de page peuvent changer. Vérifiez le PDF obtenu. DOC/XLS/PPT anciens ne sont pas activés.' : tool.slug.includes('csv') ? 'CSV en UTF-8. Les formules sont exportées comme du texte neutralisé, sans calcul. Limites : 10 000 lignes et 100 colonnes.' : tool.slug === 'compresser-pdf' ? 'Compression sans perte des flux : le poids peut rester identique ou augmenter. Les PDF protégés et actifs sont refusés.' : 'Les PDF protégés, les formulaires et les éléments actifs sont refusés.'}</p><button className="button primary full-width" type="submit" disabled={upload.isPending || !usage.data || files.length < (merge ? 2 : 1)}>{upload.isPending ? 'Envoi en cours…' : merge ? 'Fusionner les PDF' : 'Traiter le document'}</button><small>{merge ? 'Les fichiers sont fusionnés dans l’ordre de la liste. Une fusion compte comme une opération.' : 'Une conversion compte comme une opération.'}</small></div>{usage.isPending ? <Loading label="Chargement du quota…" /> : usage.error ? <ErrorState message={usage.error.message} retry={() => { void usage.refetch(); }} /> : usage.data && <QuotaIndicator limits={usage.data.limits} used={usage.data.quota_used ?? usage.data.used.daily_jobs} />}<Notice>Conservation : {usage.data?.limits.retention_hours ?? 1} h à partir de l’envoi. Gardez votre session pour récupérer le résultat.</Notice></aside></form></div>;
}
