import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { api, jsonApi, type PlanLimits, type Tool } from '../lib/api';
import { CategoryIcon, Dropzone, ErrorState, JobState, Loading, Notice, QuotaIndicator, type JobStatus } from '../components/ui';
import { FormError } from './Account';

type Job = { id: string; name: string; status: JobStatus; progress: number | null; error_code: string | null; download_available: boolean; output_size: number | null };
const messages: Record<string, string> = {
  NO_TEXT_FOUND: 'Aucun texte lisible détecté. Essayez une image plus nette ou un autre réglage de mise en page.',
  NO_SPEECH: 'Aucune parole exploitable détectée.', MISSING_MEDIA_STREAM: 'Ce fichier ne contient pas de piste audio.',
  MEDIA_TOO_COMPLEX: 'La durée, les pistes ou la résolution dépassent les limites.', UNSUPPORTED_MEDIA_CODEC: 'Le codec de ce fichier n’est pas pris en charge.',
  PROCESS_TIMEOUT: 'Le délai de traitement est dépassé. Essayez un extrait plus court.', INVALID_IMAGE: 'Cette image est invalide ou endommagée.',
  IMAGE_TOO_COMPLEX: 'Cette image dépasse les limites de dimensions ou de pixels.', INVALID_MEDIA: 'Ce média est invalide ou endommagé.',
};

export default function RecognitionTool({ tool }: { tool: Tool }) {
  const ocr = tool.operation === 'ocr';
  const [file, setFile] = useState<File>();
  const [error, setError] = useState('');
  const [language, setLanguage] = useState(ocr ? 'fr-en' : 'auto');
  const [layout, setLayout] = useState('auto');
  const [id, setId] = useState('');
  const [copyMessage, setCopyMessage] = useState('');
  const client = useQueryClient();
  const usage = useQuery({ queryKey: ['usage'], queryFn: ({ signal }) => api<{ limits: PlanLimits; used: { daily_jobs: number }; quota_used?: number; entitlements?: Record<string, boolean> }>('/me/usage', signal) });
  const job = useQuery({ queryKey: ['job', id], enabled: !!id, queryFn: ({ signal }) => api<Job>(`/jobs/${id}`, signal), refetchInterval: query => query.state.error ? false : !query.state.data || ['uploaded', 'queued', 'processing'].includes(query.state.data.status) ? 1000 : false });
  const active = !!id && (!job.data || ['uploaded', 'queued', 'processing'].includes(job.data.status));
  useEffect(() => { if (job.data && !active) { void client.invalidateQueries({ queryKey: ['usage'] }); void client.invalidateQueries({ queryKey: ['jobs'] }); } }, [job.data, active, client]);
  const preview = useMutation({ mutationFn: async () => {
    const response = await fetch(`/api/v1/jobs/${id}/download`, { credentials: 'same-origin', headers: { Accept: 'text/plain' } });
    if (!response.ok) throw new Error('Le texte est indisponible ou a expiré.');
    return response.text();
  } });
  const upload = useMutation({ mutationFn: () => {
    const body = new FormData(); body.set('tool', tool.slug); body.set('target_format', 'txt'); body.set('file', file!); body.set('options[language]', language);
    if (ocr) body.set('options[layout]', layout);
    return api<Job>('/conversions', undefined, false, 'POST', body);
  }, onSuccess: data => { preview.reset(); setCopyMessage(''); setId(data.id); setFile(undefined); void client.invalidateQueries({ queryKey: ['usage'] }); void client.invalidateQueries({ queryKey: ['jobs'] }); } });
  const cancel = useMutation({ mutationFn: () => jsonApi(`/jobs/${id}/cancel`, {}), onSuccess: () => { void job.refetch(); } });
  const maxBytes = Math.min(usage.data?.limits.max_file_bytes ?? 25_000_000, (ocr ? tool.recognition_limits?.max_image_bytes : tool.recognition_limits?.max_media_bytes) ?? (ocr ? 10_000_000 : 25_000_000));
  function select(files: File[]) {
    if (files.length !== 1) { setError('Sélectionnez un seul fichier.'); return; }
    if (!tool.source_formats.includes(files[0].name.split('.').pop()?.toLowerCase() ?? '')) { setError('Ce format n’est pas compatible avec cet outil.'); return; }
    if (files[0].size > maxBytes) { setError(`Ce fichier dépasse ${maxBytes / 1e6} Mo.`); return; }
    setError(''); setFile(files[0]);
  }
  return <section className="container page-section"><title>{tool.name} — Convert</title><nav className="breadcrumb"><Link to="/outils">Les outils</Link></nav><div className="tool-page-heading"><CategoryIcon category={tool.category} size={30} /><div><span className="eyebrow">{ocr ? 'OCR D’IMAGE' : 'TRANSCRIPTION AUDIO / VIDÉO'}</span><h1>{tool.name}</h1><p className="page-lead">{tool.description}</p></div></div><form className="tool-workspace" onSubmit={event => { event.preventDefault(); if (file) upload.mutate(); }}><div>
    {error && <Notice tone="error">{error}</Notice>}<FormError error={upload.error} />
    <Dropzone disabled={!usage.data || active || upload.isPending} maxBytes={maxBytes} accept={tool.source_formats.map(format => `.${format}`).join(',')} onFiles={select} />
    <p className="catalog-disclaimer">{tool.source_formats.join(' · ').toUpperCase()} · Un fichier · {ocr ? `${(tool.recognition_limits?.max_pixels ?? 6_000_000) / 1e6} mégapixels maximum` : `${tool.recognition_limits?.max_duration ?? 60} secondes maximum, vidéo Full HD / 60 images/s`}</p>
    {file && <div className="panel"><p>{file.name} · {file.size.toLocaleString('fr')} octets</p><button className="button secondary" type="button" disabled={upload.isPending} onClick={() => setFile(undefined)}>Retirer le fichier</button></div>}
    {id && <section className="image-results" aria-label="Résultat de l’extraction"><h2>Votre texte</h2>{job.error ? <ErrorState message={job.error.message} retry={() => { void job.refetch(); }} /> : job.data ? <div className="panel"><h3>{job.data.name}</h3><JobState status={job.data.status} progress={job.data.progress ?? undefined} />{job.data.error_code && <Notice tone="error">{messages[job.data.error_code] ?? 'Le texte n’a pas pu être extrait de ce fichier.'}</Notice>}{job.data.download_available && <div className="account-links"><a className="button primary" href={`/api/v1/jobs/${id}/download`}>Télécharger le texte TXT</a><button className="button secondary" type="button" disabled={preview.isPending} onClick={() => preview.mutate()}>Afficher le texte</button></div>}{active && <button className="button secondary" type="button" disabled={cancel.isPending} onClick={() => cancel.mutate()}>Annuler l’extraction</button>}<FormError error={cancel.error} /><FormError error={preview.error} />{preview.data !== undefined && <><label htmlFor="extracted-text">Texte extrait — à relire</label><textarea className="extracted-text" id="extracted-text" rows={12} readOnly dir="auto" value={preview.data} /><button type="button" className="button secondary" onClick={async () => { try { await navigator.clipboard.writeText(preview.data); setCopyMessage('Texte copié.'); } catch { setCopyMessage('La copie automatique est indisponible. Sélectionnez le texte pour le copier.'); } }}>Copier le texte</button><p role="status">{copyMessage}</p></>}</div> : <Loading label="Chargement de la tâche…" />}<Link to="/mes-fichiers">Historique et suppression de mes fichiers</Link></section>}
    </div><aside className="tool-sidebar"><fieldset className="panel settings-panel recognition-settings" disabled={active || upload.isPending}><legend className="sr-only">Réglages d’extraction</legend><h2>Vos réglages</h2><div className="field"><label htmlFor="recognition-language">Langue du contenu</label><select id="recognition-language" className="select-trigger" value={language} onChange={event => setLanguage(event.target.value)}>{(tool.recognition_languages ?? [{ code: ocr ? 'fr-en' : 'auto', label: ocr ? 'Français et anglais' : 'Détection automatique' }, { code: 'fr', label: 'Français' }, { code: 'en', label: 'Anglais' }]).map(item => <option key={item.code} value={item.code}>{item.label}</option>)}</select></div>{ocr && <div className="field"><label htmlFor="recognition-layout">Mise en page</label><select id="recognition-layout" className="select-trigger" value={layout} onChange={event => setLayout(event.target.value)}><option value="auto">Automatique</option><option value="block">Bloc de texte</option><option value="sparse">Texte dispersé</option></select></div>}<p className="image-note">{ocr ? 'Texte imprimé de préférence. Les écritures manuscrites, petits caractères et photos floues peuvent être mal reconnus. La mise en page n’est pas conservée.' : 'La piste audio de la vidéo est extraite automatiquement. Bruit, musique, accents ou voix superposées peuvent produire des erreurs, omissions ou texte inventé. Aucun repérage des locuteurs ni traduction.'}</p><p>Sortie : texte UTF-8 (.txt). Traitement local, sans envoi à une API tierce.</p><button className="button primary full-width" disabled={!file || !usage.data || upload.isPending || active}>{upload.isPending ? 'Envoi…' : 'Extraire le texte'}</button><small>Une opération. Relisez le résultat avant utilisation.</small></fieldset>{usage.error ? <ErrorState message={usage.error.message} retry={() => { void usage.refetch(); }} /> : usage.data ? <QuotaIndicator limits={usage.data.limits} used={usage.data.quota_used ?? usage.data.used.daily_jobs} /> : <Loading label="Chargement du quota…" />}<Notice>Conservation : {usage.data?.limits.retention_hours ?? 1} h depuis l’envoi. Traitement limité à {usage.data?.limits.timeout_seconds ?? 60} secondes. Gardez votre session pour retrouver le texte.</Notice></aside></form></section>;
}
