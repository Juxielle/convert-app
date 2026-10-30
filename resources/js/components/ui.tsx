import { useId, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { ArrowDownUp, ArrowRight, AudioLines, Check, ChevronDown, FileText, Image, Link2, LoaderCircle, UploadCloud, Video, X, Info } from 'lucide-react';
import { Link } from 'react-router-dom';
import * as Select from '@radix-ui/react-select';
import type { Category, Tool } from '../lib/api';
import { fr } from '../lib/content';

export const categoryIcons = { images: Image, documents: FileText, audio: AudioLines, video: Video, external: Link2 };

export function CategoryIcon({ category, size = 24 }: { category: Category; size?: number }) {
  const Icon = categoryIcons[category];
  return <span className={`tool-icon icon-${category}`}><Icon size={size} strokeWidth={1.7} aria-hidden="true" /></span>;
}

export function Logo() {
  return <Link to="/" className="logo" aria-label="Convert, accueil"><span className="logo-symbol"><ArrowDownUp size={22} aria-hidden="true" /></span>convert<span className="logo-dot">.</span></Link>;
}

export function ToolCard({ tool }: { tool: Tool }) {
  return <Link className="tool-card" to={`/outils/${tool.slug}`}>
    <div className="card-top"><CategoryIcon category={tool.category} /><span className="status-pill">{tool.enabled ? (tool.required_plan ? 'Pro / Business' : 'Disponible') : tool.status === 'disabled' ? fr.disabled : fr.preparing}</span></div>
    <h3>{tool.name}</h3><p>{tool.description}</p>
    <div className="card-bottom"><span>{tool.enabled ? tool.target_formats.join(' · ').toUpperCase() : tool.planned_formats.length ? tool.planned_formats.join(' · ') : 'Sources à valider'}<span className="sr-only"> — {tool.enabled ? 'formats disponibles' : 'formats envisagés'}</span></span><ArrowRight size={17} aria-hidden="true" /></div>
  </Link>;
}

export function Notice({ children, tone = 'info', onDismiss }: { children: ReactNode; tone?: 'info' | 'error' | 'success'; onDismiss?: () => void }) {
  return <div className={`notice notice-${tone}`} role={tone === 'error' ? 'alert' : 'status'}><Info size={19} aria-hidden="true" /><div>{children}</div>{onDismiss && <button type="button" className="icon-button" aria-label="Fermer la notification" onClick={onDismiss}><X size={18} /></button>}</div>;
}

export function Loading({ label = 'Chargement des outils…' }: { label?: string }) {
  return <div className="loading" role="status"><LoaderCircle className="spin" size={22} aria-hidden="true" />{label}</div>;
}

export function ErrorState({ message, retry }: { message: string; retry: () => void }) {
  return <Notice tone="error"><p>{message}</p><button className="text-button" onClick={retry}>{fr.retry}</button></Notice>;
}

export function FormatSelect({ formats, value, onChange, disabled = false }: { formats: string[]; value: string; onChange: (value: string) => void; disabled?: boolean }) {
  const id = useId();
  return <div className="field"><label htmlFor={id}>Format de sortie</label><Select.Root value={value} onValueChange={onChange} disabled={disabled}><Select.Trigger id={id} className="select-trigger" aria-describedby={`${id}-help`}><Select.Value placeholder="Aucun format actif" /><Select.Icon><ChevronDown size={17} /></Select.Icon></Select.Trigger><Select.Portal><Select.Content className="select-content" position="popper"><Select.Viewport>{formats.map(format => <Select.Item className="select-item" key={format} value={format}><Select.ItemText>{format}</Select.ItemText><Select.ItemIndicator><Check size={16} /></Select.ItemIndicator></Select.Item>)}</Select.Viewport></Select.Content></Select.Portal></Select.Root><small id={`${id}-help`}>{disabled ? 'Disponible après validation du moteur de conversion.' : 'Choisissez un format compatible.'}</small></div>;
}

export function Dropzone({ disabled, maxBytes, accept, onFiles }: { disabled: boolean; maxBytes: number; accept: string; onFiles: (files: File[]) => void }) {
  const id = useId();
  const input = useRef<HTMLInputElement>(null);
  const [dragging, setDragging] = useState(false);
  const [error, setError] = useState('');
  function select(files: File[]) {
    if (disabled) return;
    const invalid = files.some(file => file.size === 0 || file.size > maxBytes);
    if (invalid) { setError(`Chaque fichier doit être non vide et inférieur ou égal à ${maxBytes / 1_000_000} Mo.`); return; }
    setError(''); onFiles(files);
  }
  return <><div className={`dropzone ${dragging ? 'dragging' : ''} ${disabled ? 'is-disabled' : ''}`} onDragOver={event => { event.preventDefault(); if (!disabled) setDragging(true); }} onDragLeave={() => setDragging(false)} onDrop={event => { event.preventDefault(); setDragging(false); select(Array.from(event.dataTransfer.files)); }} aria-disabled={disabled}>
    <span className="upload-icon"><UploadCloud size={30} strokeWidth={1.5} aria-hidden="true" /></span><h3>{disabled ? 'Le dépôt sera bientôt disponible' : 'Glissez vos fichiers ici'}</h3><p>{disabled ? 'Aucun fichier n’est envoyé dans cette version.' : 'ou choisissez-les sur votre appareil'}</p>
    <input id={id} ref={input} className="sr-only" tabIndex={-1} type="file" multiple accept={accept} disabled={disabled} onChange={event => { select(Array.from(event.target.files ?? [])); event.target.value = ''; }} />
    <button className="button primary" type="button" disabled={disabled} onClick={() => input.current?.click()} aria-describedby={`${id}-help`}><UploadCloud size={17} aria-hidden="true" />Sélectionner des fichiers</button><small id={`${id}-help`}>Limite prévue : {maxBytes / 1_000_000} Mo par fichier</small>
  </div>{error && <Notice tone="error">{error}</Notice>}</>;
}

export type JobStatus = 'uploaded' | 'queued' | 'processing' | 'completed' | 'failed' | 'cancelled' | 'expired';
export function JobState({ status, progress }: { status: JobStatus; progress?: number }) {
  const labels: Record<JobStatus, string> = { uploaded: 'Fichier reçu', queued: 'En attente', processing: 'Traitement en cours', completed: 'Terminé', failed: 'Échec du traitement', cancelled: 'Annulé', expired: 'Résultat expiré' };
  const measured = status === 'processing' && typeof progress === 'number' && Number.isFinite(progress);
  const value = measured ? Math.max(0, Math.min(100, progress)) : undefined;
  return <div className="job-state" role="status"><span>{labels[status]}</span>{measured && <progress aria-label="Progression du traitement" value={value} max={100} />}{status === 'processing' && !measured && <LoaderCircle className="spin" size={18} aria-hidden="true" />}</div>;
}

export { default as QuotaIndicator } from './QuotaMeter';
