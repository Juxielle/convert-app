import { useEffect, useRef, useState } from 'react';
import type { PointerEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Download, Sparkles, Undo2 } from 'lucide-react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';
import type { PlanLimits, Tool } from '../lib/api';
import { applyRegion, selectRegion } from '../lib/image-mask';
import { Dropzone, ErrorState, JobState, Loading, Notice, QuotaIndicator } from '../components/ui';
import type { JobStatus } from '../components/ui';

type Job = { id: string; status: JobStatus; progress: number | null; download_available: boolean };
type Mode = 'region' | 'erase' | 'restore';

async function decode(blob: Blob): Promise<HTMLImageElement> {
  const url = URL.createObjectURL(blob);
  try {
    const image = new Image();
    image.src = url;
    await image.decode();
    return image;
  } finally { URL.revokeObjectURL(url); }
}

export default function BackgroundTool({ tool }: { tool: Tool }) {
  const canvas = useRef<HTMLCanvasElement>(null);
  const original = useRef<ImageData | null>(null);
  const pixels = useRef<ImageData | null>(null);
  const selection = useRef<Uint8Array | null>(null);
  const history = useRef<Uint8Array[]>([]);
  const stroke = useRef<{ x: number; y: number } | null>(null);
  const generation = useRef(0);
  const [file, setFile] = useState<File | null>(null);
  const [mode, setMode] = useState<Mode>('region');
  const [tolerance, setTolerance] = useState(15);
  const [brush, setBrush] = useState(30);
  const [selected, setSelected] = useState(0);
  const [undoCount, setUndoCount] = useState(0);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [jobId, setJobId] = useState('');
  const [showOriginal, setShowOriginal] = useState(false);
  const client = useQueryClient();
  const usage = useQuery({ queryKey: ['usage'], queryFn: ({ signal }) => api<{ limits: PlanLimits; used: { daily_jobs: number }; quota_used?: number; entitlements?: Record<string, boolean> }>('/me/usage', signal) });
  const job = useQuery({ queryKey: ['job', jobId], enabled: !!jobId, queryFn: ({ signal }) => api<Job>(`/jobs/${jobId}`, signal), refetchInterval: query => query.state.error ? false : !query.state.data || ['uploaded', 'queued', 'processing'].includes(query.state.data.status) ? 1500 : false });
  const running = !!jobId && !job.error && (!job.data || ['uploaded', 'queued', 'processing'].includes(job.data.status));

  function paint(compare = showOriginal) {
    const context = canvas.current?.getContext('2d');
    if (!context || !pixels.current || !original.current) return;
    context.putImageData(compare ? original.current : pixels.current, 0, 0);
    if (!compare && selection.current) {
      const overlay = new ImageData(new Uint8ClampedArray(pixels.current.data), pixels.current.width, pixels.current.height);
      selection.current.forEach((value, i) => { if (value) { const p = i * 4; overlay.data[p] = 245; overlay.data[p + 1] = 85; overlay.data[p + 2] = 85; overlay.data[p + 3] = 180; } });
      context.putImageData(overlay, 0, 0);
    }
  }
  function clearSelection() { selection.current = null; setSelected(0); }
  function remember() {
    if (!pixels.current) return;
    history.current.push(Uint8Array.from({ length: pixels.current.width * pixels.current.height }, (_, i) => pixels.current!.data[i * 4 + 3]));
    if (history.current.length > 5) history.current.shift();
    setUndoCount(history.current.length);
  }
  async function load(files: File[]) {
    if (files.length !== 1) { setError('Sélectionnez une seule image à la fois.'); return; }
    const next = files[0];
    if (!/\.(png|jpe?g|webp)$/i.test(next.name)) { setError('Choisissez une image JPEG, PNG ou WebP.'); return; }
    const version = ++generation.current;
    setBusy(true); setError('');
    try {
      const image = await decode(next);
      if (version !== generation.current) return;
      const { naturalWidth: width, naturalHeight: height } = image;
      if (width * height > (tool.image_limits?.max_pixels ?? 6000000) || Math.max(width, height) > (tool.image_limits?.max_dimension ?? 8192)) throw new Error('Cette image dépasse les limites de dimensions affichées.');
      const surface = canvas.current!;
      surface.width = width; surface.height = height;
      const context = surface.getContext('2d', { willReadFrequently: true });
      if (!context) throw new Error('L’éditeur d’images est indisponible dans ce navigateur.');
      context.drawImage(image, 0, 0);
      original.current = context.getImageData(0, 0, width, height);
      pixels.current = context.getImageData(0, 0, width, height);
      history.current = []; clearSelection(); setUndoCount(0); setFile(next); setJobId(''); setShowOriginal(false); paint(false);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Impossible de lire cette image.'); }
    finally { if (version === generation.current) setBusy(false); }
  }
  const upload = useMutation({
    mutationFn: () => {
      const body = new FormData(); body.set('tool', tool.slug); body.set('target_format', 'png'); body.set('file', file!);
      return api<Job>('/conversions', undefined, false, 'POST', body);
    },
    onSuccess: data => { setJobId(data.id); void client.invalidateQueries({ queryKey: ['usage'] }); },
  });
  useEffect(() => {
    if (!job.data || !['completed', 'failed', 'cancelled', 'expired'].includes(job.data.status)) return;
    void client.invalidateQueries({ queryKey: ['usage'] }); void client.invalidateQueries({ queryKey: ['jobs'] });
    if (!job.data.download_available) return;
    const controller = new AbortController();
    const version = generation.current;
    setBusy(true);
    void (async () => {
      try {
        const response = await fetch(`/api/v1/jobs/${jobId}/download`, { credentials: 'same-origin', signal: controller.signal });
        if (!response.ok) throw new Error('Le résultat n’est plus disponible. Relancez le détourage.');
        const image = await decode(await response.blob());
        if (controller.signal.aborted || version !== generation.current || !original.current) return;
        const surface = document.createElement('canvas'); surface.width = original.current.width; surface.height = original.current.height;
        if (image.naturalWidth !== surface.width || image.naturalHeight !== surface.height) throw new Error('Dimensions du résultat inattendues.');
        const context = surface.getContext('2d')!; context.drawImage(image, 0, 0);
        const result = context.getImageData(0, 0, surface.width, surface.height);
        remember();
        // Keep original RGB so restoring a region recovers its original colors.
        for (let i = 3; i < result.data.length; i += 4) pixels.current!.data[i] = result.data[i];
        clearSelection(); setShowOriginal(false); paint(false);
      } catch (reason) { if (!controller.signal.aborted) setError(reason instanceof Error ? reason.message : 'Chargement impossible.'); }
      finally { if (!controller.signal.aborted) setBusy(false); }
    })();
    return () => controller.abort();
    // Process each completed job once, independently of local editor controls.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [job.data?.id, job.data?.status, job.data?.download_available, jobId, client]);

  const disabled = busy || upload.isPending || running;
  function position(event: PointerEvent<HTMLCanvasElement>) {
    const rect = event.currentTarget.getBoundingClientRect();
    return { x: Math.max(0, Math.min(event.currentTarget.width - 1, Math.floor((event.clientX - rect.left) * event.currentTarget.width / rect.width))), y: Math.max(0, Math.min(event.currentTarget.height - 1, Math.floor((event.clientY - rect.top) * event.currentTarget.height / rect.height))) };
  }
  function dab(x: number, y: number) {
    if (!pixels.current || !original.current) return;
    const { width, height, data } = pixels.current;
    const radius = brush / 2;
    for (let py = Math.max(0, Math.floor(y - radius)); py <= Math.min(height - 1, Math.ceil(y + radius)); py++) {
      for (let px = Math.max(0, Math.floor(x - radius)); px <= Math.min(width - 1, Math.ceil(x + radius)); px++) {
        if ((px - x) ** 2 + (py - y) ** 2 <= radius ** 2) { const p = (py * width + px) * 4 + 3; data[p] = mode === 'restore' ? original.current.data[p] : 0; }
      }
    }
  }
  function down(event: PointerEvent<HTMLCanvasElement>) {
    if (disabled || showOriginal || !pixels.current || !original.current || event.button !== 0) return;
    const point = position(event);
    if (mode === 'region') {
      selection.current = selectRegion(original.current.data, original.current.width, original.current.height, point.x, point.y, tolerance);
      setSelected(selection.current.reduce((sum, n) => sum + n, 0)); paint();
    } else {
      clearSelection(); remember(); stroke.current = point; event.currentTarget.setPointerCapture(event.pointerId); dab(point.x, point.y); paint();
    }
  }
  function move(event: PointerEvent<HTMLCanvasElement>) {
    if (!stroke.current || disabled) return;
    const next = position(event), previous = stroke.current;
    const steps = Math.ceil(Math.hypot(next.x - previous.x, next.y - previous.y) / Math.max(1, brush / 4));
    for (let i = 1; i <= steps; i++) dab(previous.x + (next.x - previous.x) * i / steps, previous.y + (next.y - previous.y) * i / steps);
    stroke.current = next; paint();
  }
  function apply(restore: boolean) {
    if (!pixels.current || !original.current || !selection.current) return;
    remember(); applyRegion(pixels.current.data, original.current.data, selection.current, restore); clearSelection(); paint();
  }
  function undo() {
    const alpha = history.current.pop();
    if (!alpha || !pixels.current) return;
    alpha.forEach((value, i) => { pixels.current!.data[i * 4 + 3] = value; });
    clearSelection(); setUndoCount(history.current.length); paint();
  }
  function download() {
    if (!pixels.current) return;
    const output = document.createElement('canvas'); output.width = pixels.current.width; output.height = pixels.current.height;
    output.getContext('2d')!.putImageData(pixels.current, 0, 0);
    output.toBlob(blob => {
      if (!blob) { setError('Impossible de créer le PNG.'); return; }
      const url = URL.createObjectURL(blob), link = document.createElement('a'); link.href = url; link.download = `${file?.name.replace(/\.[^.]+$/, '') ?? 'image'}-sans-fond.png`; link.click(); window.setTimeout(() => URL.revokeObjectURL(url), 10000);
    }, 'image/png');
  }

  return <div className="container page-section"><title>{tool.name} — Convert</title>
    <nav className="breadcrumb" aria-label="Fil d’Ariane"><Link to="/outils">Les outils</Link><span> / Détourage d’image</span></nav>
    <div className="tool-page-heading"><Sparkles size={30} /><div><span className="eyebrow">IMAGES · DÉTOURAGE ET RETOUCHES</span><h1>{tool.name}</h1><p className="page-lead">Un fond transparent, puis les retouches de votre choix.</p></div></div>
    <div className="tool-workspace"><div>
      {(error || upload.error || job.error) && <Notice tone="error">{error || upload.error?.message || job.error?.message}</Notice>}
      <Dropzone disabled={disabled || !usage.data} maxBytes={usage.data?.limits.max_file_bytes ?? 25000000} accept=".jpg,.jpeg,.png,.webp" onFiles={files => { void load(files); }} />
      <p className="catalog-disclaimer">Une image JPEG, PNG ou WebP · {(tool.image_limits?.max_pixels ?? 6000000) / 1000000} mégapixels maximum · Sortie PNG transparente</p>
      {file && <p><strong>{file.name}</strong> · {pixels.current?.width} × {pixels.current?.height} px</p>}
      <div className={`background-editor${file ? '' : ' is-empty'}`}>
        {!file && <p>Votre image et les zones sélectionnées apparaîtront ici.</p>}
        <canvas ref={canvas} aria-label="Éditeur de détourage : cliquez sur une zone à sélectionner" onPointerDown={down} onPointerMove={move} onPointerUp={() => { stroke.current = null; }} onPointerCancel={() => { stroke.current = null; }} />
      </div>
      {file && <div className="background-actions"><button type="button" className="button secondary" disabled={disabled} onClick={() => { setShowOriginal(!showOriginal); paint(!showOriginal); }}>{showOriginal ? 'Voir le résultat' : 'Voir l’original'}</button><button type="button" className="button secondary" disabled={!undoCount || disabled || showOriginal} onClick={undo}><Undo2 size={16} />Annuler la retouche</button><button type="button" className="button secondary" disabled={disabled} onClick={() => { remember(); pixels.current = new ImageData(new Uint8ClampedArray(original.current!.data), original.current!.width, original.current!.height); clearSelection(); setShowOriginal(false); paint(false); }}>Réinitialiser</button></div>}
      {busy && <Loading label="Chargement de l’image…" />}
      {job.data && <div aria-live="polite"><JobState status={job.data.status} progress={job.data.progress ?? undefined} />{job.data.status === 'failed' && <Notice tone="error">Le détourage a échoué. Réessayez ou utilisez les retouches manuelles.</Notice>}</div>}
      <p className="image-note">Les carreaux indiquent la transparence. Les retouches restent dans votre navigateur. Le détourage automatique traite l’original et remplace les retouches en cours ; vous pouvez ensuite l’annuler.</p>
    </div><aside className="tool-sidebar"><div className="panel settings-panel"><h2>1. Enlever le fond</h2><p>Détecter le sujet principal et retirer le fond automatiquement.</p><button type="button" className="button primary full-width" disabled={!file || disabled || !usage.data} onClick={() => { setError(''); setJobId(''); upload.mutate(); }}><Sparkles size={17} />{running ? 'Détourage en cours…' : 'Enlever le fond automatiquement'}</button><small>Une opération de votre quota. Les cheveux, contours fins et fonds complexes peuvent demander des retouches.</small>
      <h2>2. Choisir les zones</h2><div className="field"><label htmlFor="background-mode">Outil de retouche</label><select id="background-mode" value={mode} disabled={disabled || showOriginal} onChange={event => { setMode(event.target.value as Mode); clearSelection(); paint(); }}><option value="region">Sélection par zone de couleur</option><option value="erase">Pinceau : effacer</option><option value="restore">Pinceau : restaurer</option></select></div>
      {mode === 'region' ? <><div className="field"><label htmlFor="background-tolerance">Tolérance des couleurs : {tolerance} %</label><input id="background-tolerance" type="range" min="0" max="60" value={tolerance} disabled={disabled} onChange={event => setTolerance(Number(event.target.value))} /></div><p>Cliquez sur l’image : la zone de couleurs voisines est surlignée en rouge. Ajustez la tolérance puis cliquez à nouveau pour affiner.</p><p aria-live="polite">{selected ? `${selected.toLocaleString('fr')} pixel${selected > 1 ? 's sélectionnés' : ' sélectionné'}` : 'Aucune zone sélectionnée'}</p><button type="button" className="button secondary full-width" disabled={!selected || disabled || showOriginal} onClick={() => apply(false)}>Supprimer la sélection</button><button type="button" className="button secondary full-width" disabled={!selected || disabled || showOriginal} onClick={() => apply(true)}>Restaurer la sélection</button></> : <><div className="field"><label htmlFor="background-brush">Taille du pinceau : {brush} px</label><input id="background-brush" type="range" min="2" max="200" value={brush} disabled={disabled} onChange={event => setBrush(Number(event.target.value))} /></div><p>Glissez sur l’image avec la souris ou le doigt pour {mode === 'erase' ? 'effacer' : 'restaurer'} les parties souhaitées.</p></>}
      <h2>3. Télécharger</h2><button type="button" className="button primary full-width" disabled={!file || disabled} onClick={download}><Download size={17} />Télécharger le PNG</button><small>Dimensions originales conservées. Le téléchargement inclut vos retouches, sans le surlignage rouge.</small>
    </div>{usage.data && <QuotaIndicator limits={usage.data.limits} used={usage.data.quota_used ?? usage.data.used.daily_jobs} />}{usage.error && <ErrorState message={usage.error.message} retry={() => { void usage.refetch(); }} />}<Notice>Traitement automatique sur notre serveur, sans service tiers. Le résultat automatique reste disponible dans <Link to="/mes-fichiers">Mes fichiers</Link> pendant {usage.data?.limits.retention_hours ?? 1} h. Téléchargez vos retouches avant de quitter cette page.</Notice></aside></div>
  </div>;
}
