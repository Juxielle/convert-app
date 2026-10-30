import { useQuery } from '@tanstack/react-query';

export type Category = 'images' | 'documents' | 'audio' | 'video' | 'external';
export type Tool = {
  required_plan?: string | null;
  slug: string; name: string; category: Category; description: string;
  planned_formats: string[]; source_formats: string[]; target_formats: string[];
  operation: string; enabled: boolean; status: 'planned' | 'disabled' | 'available';
  image_limits?: { max_pixels: number; max_dimension: number; max_batch_files: number } | null;
  document_limits?: { max_input_bytes: number; max_pages: number; max_render_pixels: number } | null;
  recognition_limits?: { max_image_bytes: number; max_media_bytes: number; max_pixels: number; max_duration: number; max_text_bytes: number } | null;
  recognition_languages?: { code: string; label: string }[] | null;
  media_limits?: { max_audio_bytes: number; max_video_bytes: number; max_output_bytes: number; audio_duration: number; video_duration: number } | null;
};
export type PlanLimits = {
  monthly_jobs?: number | null; history_hours?: number;
  storage_bytes?: number;
  max_file_bytes: number; hourly_jobs: number; daily_jobs: number;
  concurrent_jobs: number; timeout_seconds: number; retention_hours: number;
};
export type Limits = { enforced: boolean; message: string; plans: { guest: PlanLimits; registered: PlanLimits } };

export class ApiError extends Error {
  constructor(public status: number, public code: string, message: string, public fields?: Record<string, string[]>) { super(message); }
}

export async function api<T>(path: string, signal?: AbortSignal, paginated = false, method: 'GET' | 'POST' | 'DELETE' = 'GET', payload?: FormData): Promise<T> {
  let response: Response;
  try {
    const headers: Record<string, string> = { Accept: 'application/json' };
    if (method !== 'GET') headers['X-CSRF-TOKEN'] = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    response = await fetch(`/api/v1${path}`, { method, headers, credentials: 'same-origin', signal, body: payload });
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') throw error;
    throw new ApiError(0, 'NETWORK_ERROR', 'Connexion impossible. Vérifiez votre connexion et réessayez.');
  }
  let body;
  try { body = await response.json(); } catch {
    throw new ApiError(response.status, 'INVALID_RESPONSE', 'Le serveur a renvoyé une réponse inattendue.');
  }
  if (!response.ok) throw new ApiError(response.status, body.error?.code ?? 'UNKNOWN_ERROR', body.error?.message ?? 'Une erreur est survenue.');
  return (paginated ? body : body.data) as T;
}

export async function mutateApi(path: string, method: 'POST' | 'DELETE'): Promise<unknown> {
  return api(path, undefined, false, method);
}

export const useTools = () => useQuery({ queryKey: ['tools'], queryFn: ({ signal }) => api<Tool[]>('/tools', signal), staleTime: 60_000 });
export const useLimits = () => useQuery({ queryKey: ['limits'], queryFn: ({ signal }) => api<Limits>('/limits', signal), staleTime: 60_000 });

export type Account = { id: number; name: string; email: string; role: 'user' | 'admin'; plan: string };
export type Session = { user: Account | null; csrf_token: string; password_reset_available: boolean };
export const useSession = () => useQuery({ queryKey: ['session'], queryFn: ({ signal }) => api<Session>('/auth/session', signal), staleTime: 30_000 });

export async function jsonApi<T>(path: string, values: unknown, method: 'POST' | 'PATCH' = 'POST'): Promise<T> {
  let response: Response;
  try {
    response = await fetch(`/api/v1${path}`, { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' }, body: JSON.stringify(values) });
  } catch { throw new ApiError(0, 'NETWORK_ERROR', 'Connexion impossible. Réessayez.'); }
  let body;
  try { body = await response.json(); } catch { throw new ApiError(response.status, 'INVALID_RESPONSE', 'Réponse inattendue du serveur.'); }
  if (!response.ok) throw new ApiError(response.status, body.error?.code ?? 'UNKNOWN_ERROR', body.error?.message ?? 'Une erreur est survenue.', body.error?.fields);
  if (body.data?.csrf_token) {
    const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
    if (meta) meta.content = body.data.csrf_token;
  }
  return body.data as T;
}
