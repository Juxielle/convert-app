import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Download, X } from 'lucide-react';
import { Link } from 'react-router-dom';
import { api, type PlanLimits, type Tool } from '../lib/api';
import { CategoryIcon, Dropzone, ErrorState, FormatSelect, JobState, Loading, Notice, QuotaIndicator, type JobStatus } from '../components/ui';

type Job = { id: string; name: string; status: JobStatus; progress: number | null; error_code: string | null; input_size: number; output_size: number | null; download_available: boolean };
const messages: Record<string, string> = {
  INVALID_MEDIA: 'Le fichier est endommagé ou incompatible.', MEDIA_TOO_COMPLEX: 'Le média dépasse les limites de durée, de résolution ou de pistes.',
  UNSUPPORTED_MEDIA_CODEC: 'Le codec de ce fichier n’est pas pris en charge.', PROTECTED_MEDIA: 'Les médias chiffrés ou protégés sont refusés.',
  MISSING_MEDIA_STREAM: 'Le fichier ne contient pas la piste audio ou vidéo nécessaire.', INVALID_OPTIONS: 'Vérifiez le début et la durée de l’extrait.',
  PROCESS_TIMEOUT: 'Le temps de traitement autorisé est dépassé. Essayez un fichier plus court ou un profil léger.',
  OUTPUT_LIMIT_EXCEEDED: 'Le résultat dépasse la taille autorisée. Essayez un format compressé ou un extrait plus court.',
};

export default function MediaTool({ tool }: { tool: Tool }) {
  const [file, setFile] = useState<File>();
  const [error, setError] = useState('');
  const [id, setId] = useState('');
  const [format, setFormat] = useState(tool.target_formats[0] ?? '');
  const [bitrate, setBitrate] = useState(128);
  const [sampleRate, setSampleRate] = useState(44100);
  const [channels, setChannels] = useState(2);
  const [profile, setProfile] = useState('balanced');
  const [resolution, setResolution] = useState(720);
  const [fps, setFps] = useState(30);
  const [start, setStart] = useState(0);
  const [duration, setDuration] = useState('');
  const audio = tool.category === 'audio';
  const videoInput = tool.slug !== 'convertir-audio';
  const client = useQueryClient();
  const usage = useQuery({ queryKey: ['usage'], queryFn: ({ signal }) => api<{ limits: PlanLimits; used: { daily_jobs: number }; quota_used?: number; entitlements?: Record<string, boolean> }>('/me/usage', signal) });
  const job = useQuery({ queryKey: ['job', id], enabled: !!id, queryFn: ({ signal }) => api<Job>(`/jobs/${id}`, signal), refetchInterval: query => query.state.error ? false : !query.state.data || ['uploaded', 'queued', 'processing'].includes(query.state.data.status) ? 1000 : false });
  const active = !!job.data && ['uploaded', 'queued', 'processing'].includes(job.data.status);
  useEffect(() => {
    if (job.data && !active) { void client.invalidateQueries({ queryKey: ['usage'] }); void client.invalidateQueries({ queryKey: ['jobs'] }); }
  }, [job.data, active, client]);
  const upload = useMutation({
    mutationFn: () => {
      const body = new FormData();
      body.set('tool', tool.slug); body.set('target_format', format); body.set('file', file!);
      body.set('options[start]', String(start));
      if (duration) body.set('options[duration]', duration);
      const options = audio ? { bitrate, sample_rate: format === 'opus' ? 48000 : sampleRate, channels } : { profile, resolution, fps };
      Object.entries(options).forEach(([key, value]) => body.set(`options[${key}]`, String(value)));
      return api<Job>('/conversions', undefined, false, 'POST', body);
    },
    onSuccess: data => { setId(data.id); setFile(undefined); void client.invalidateQueries({ queryKey: ['usage'] }); void client.invalidateQueries({ queryKey: ['jobs'] }); },
  });
  const cancel = useMutation({ mutationFn: () => api(`/jobs/${id}/cancel`, undefined, false, 'POST'), onSuccess: () => { void job.refetch(); } });
  const maxBytes = Math.min(usage.data?.limits.max_file_bytes ?? 25_000_000, (videoInput ? tool.media_limits?.max_video_bytes : tool.media_limits?.max_audio_bytes) ?? 25_000_000);
  const maxDuration = (videoInput ? tool.media_limits?.video_duration : tool.media_limits?.audio_duration) ?? (videoInput ? 120 : 300);
  function addFiles(files: File[]) {
    if (files.length !== 1) { setError('Sélectionnez un seul fichier média.'); return; }
    if (!tool.source_formats.includes(files[0].name.split('.').pop()?.toLowerCase() ?? '')) { setError('Ce format de fichier n’est pas pris en charge.'); return; }
    if (files[0].size > maxBytes) { setError(`Le fichier dépasse ${maxBytes / 1_000_000} Mo.`); return; }
    setError(''); setFile(files[0]);
  }
  return <div className="container page-section">
    <title>{tool.name} — Convert</title>
    <nav className="breadcrumb" aria-label="Fil d’Ariane"><Link to="/outils">Les outils</Link><span> / {tool.name}</span></nav>
    <div className="tool-page-heading"><CategoryIcon category={tool.category} size={30} /><div><span className="eyebrow">{audio ? 'AUDIO' : 'VIDÉO'} · DISPONIBLE</span><h1>{tool.name}</h1><p className="page-lead">{tool.description}</p></div></div>
    <form className="tool-workspace" onSubmit={event => { event.preventDefault(); if (file && !active) upload.mutate(); }}>
      <div>
        {(error || upload.error) && <Notice tone="error">{error || upload.error?.message}</Notice>}
        <Dropzone disabled={upload.isPending || active || !usage.data} maxBytes={maxBytes} accept={tool.source_formats.map(value => `.${value}`).join(',')} onFiles={addFiles} />
        <p className="catalog-disclaimer">{tool.source_formats.join(' · ').toUpperCase()} · Un fichier · {maxDuration} s maximum{videoInput ? ' · Full HD / 60 images/s en entrée' : ' · Mono ou stéréo / 48 kHz maximum'}</p>
        {file && <ul className="selected-files"><li><span>{file.name}<small>{file.size.toLocaleString('fr')} octets</small></span><button type="button" className="icon-button" aria-label="Retirer le fichier" disabled={upload.isPending} onClick={() => setFile(undefined)}><X size={17} /></button></li></ul>}
        {upload.isPending && <Loading label="Envoi du média…" />}
        {id && <section className="image-results" aria-label="Résultat du média"><h2>Votre résultat</h2>{job.isPending ? <Loading label="Chargement de la tâche…" /> : job.error ? <ErrorState message={job.error.message} retry={() => { void job.refetch(); }} /> : job.data && <article className="panel">
          <h3>{job.data.name}</h3><JobState status={job.data.status} progress={job.data.progress ?? undefined} />
          {job.data.status === 'failed' && <Notice tone="error">{messages[job.data.error_code ?? ''] ?? 'Le média n’a pas pu être traité.'}</Notice>}
          {job.data.output_size !== null && <p>{job.data.input_size.toLocaleString('fr')} → {job.data.output_size.toLocaleString('fr')} octets</p>}
          {job.data.download_available && <a href={`/api/v1/jobs/${id}/download`} className="button secondary"><Download size={16} />Télécharger</a>}
          {active && <button type="button" className="button secondary" disabled={cancel.isPending} onClick={() => cancel.mutate()}>Annuler le traitement</button>}
          {cancel.error && <Notice tone="error">{cancel.error.message}</Notice>}
        </article>}<Link to="/mes-fichiers" className="quiet-link">Gérer ou supprimer mes tâches</Link></section>}
      </div>
      <aside className="tool-sidebar"><fieldset className="panel settings-panel" disabled={upload.isPending || active} style={{ minWidth: 0, margin: 0 }}><legend className="sr-only">Réglages du média</legend><h2>Vos réglages</h2>
        <FormatSelect formats={tool.target_formats} value={format} onChange={setFormat} disabled={upload.isPending || active} />
        {audio ? <>
          <div className="field"><label htmlFor="bitrate">Débit audio</label><select id="bitrate" className="select-trigger" value={bitrate} disabled={['wav', 'flac'].includes(format)} onChange={event => setBitrate(Number(event.target.value))}>{[64, 128, 192, 256, 320].map(value => <option key={value} value={value}>{value} kbit/s</option>)}</select><small>Sans effet sur WAV et FLAC.</small></div>
          <div className="field"><label htmlFor="sample-rate">Fréquence audio</label><select id="sample-rate" className="select-trigger" value={format === 'opus' ? 48000 : sampleRate} disabled={format === 'opus'} onChange={event => setSampleRate(Number(event.target.value))}>{[22050, 44100, 48000].map(value => <option key={value} value={value}>{value} Hz</option>)}</select></div>
          <div className="field"><label htmlFor="channels">Canaux</label><select id="channels" className="select-trigger" value={channels} onChange={event => setChannels(Number(event.target.value))}><option value="1">Mono</option><option value="2">Stéréo</option></select></div>
        </> : <>
          <div className="field"><label htmlFor="profile">Profil vidéo</label><select id="profile" className="select-trigger" value={profile} onChange={event => { setProfile(event.target.value); setResolution(event.target.value === 'light' ? 480 : 720); }}><option value="light">Léger</option><option value="balanced">Équilibré</option><option value="quality">Qualité</option></select></div>
          <div className="field"><label htmlFor="resolution">Résolution maximale</label><select id="resolution" className="select-trigger" value={resolution} onChange={event => setResolution(Number(event.target.value))}>{[360, 480, 720].map(value => <option key={value} value={value}>{value}p</option>)}</select></div>
          <div className="field"><label htmlFor="fps">Images par seconde</label><select id="fps" className="select-trigger" value={fps} onChange={event => setFps(Number(event.target.value))}>{[15, 24, 30].map(value => <option key={value} value={value}>{value}</option>)}</select></div>
        </>}
        <div className="dimension-fields"><div className="field"><label htmlFor="clip-start">Début (secondes)</label><input id="clip-start" type="number" min="0" max={maxDuration} step="0.1" required value={start} onChange={event => setStart(Number(event.target.value))} /></div><div className="field"><label htmlFor="clip-duration">Durée de l’extrait</label><input id="clip-duration" type="number" min="0.1" max={maxDuration} step="0.1" placeholder="Jusqu’à la fin" value={duration} onChange={event => setDuration(event.target.value)} /></div></div>
        <p className="image-note">{audio ? 'Sortie mono/stéréo, PCM 16 bits pour WAV et FLAC. Opus utilise 48 kHz.' : 'Sortie limitée à 720p / 30 images/s. Le poids peut augmenter selon la source. Les sous-titres et pistes supplémentaires ne sont pas conservés.'}</p>
        <button className="button primary full-width" type="submit" disabled={!file || !usage.data || upload.isPending || active}>{upload.isPending ? 'Envoi en cours…' : 'Traiter le média'}</button><small>Une opération. Résultat limité à {Math.floor((tool.media_limits?.max_output_bytes ?? 64_000_000) / 1_000_000)} Mo.</small>
      </fieldset>{usage.isPending ? <Loading label="Chargement du quota…" /> : usage.error ? <ErrorState message={usage.error.message} retry={() => { void usage.refetch(); }} /> : usage.data && <QuotaIndicator limits={usage.data.limits} used={usage.data.quota_used ?? usage.data.used.daily_jobs} />}
        <Notice>Conservation : {usage.data?.limits.retention_hours ?? 1} h à partir de l’envoi. Le traitement peut s’arrêter après {usage.data?.limits.timeout_seconds ?? 60} s. Les fichiers protégés et les codecs incompatibles sont refusés.</Notice>
      </aside>
    </form>
  </div>;
}
