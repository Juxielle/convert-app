import type { PlanLimits } from './api';

export type BillingPeriod = 'monthly' | 'yearly';
export type PricingPlan = {
  id: string; name: string; description: string; is_free: boolean; active: boolean;
  currency: 'XAF'; currency_label: string; monthly_price: number | null; yearly_price: number | null;
  billing_periods: BillingPeriod[]; features: string[]; limits: PlanLimits | null; guest_limits: PlanLimits | null;
  tools: { slug: string; name: string }[]; available: boolean; status: 'free' | 'coming_soon' | 'trial_available';
  trial_days?: number; trial_enabled?: boolean; trial_policy?: string; annual_savings?: number; annual_free_months?: number;
  entitlements?: Record<string, boolean>; monthly_window_days?: number;
  payment_available?: boolean; trial_payment_setup_available?: boolean; trial_requires_payment?: boolean;
};
export const quotaLabels: Record<string, string> = {
  max_file_bytes: 'Taille maximale par fichier', storage_bytes: 'Stockage temporaire', hourly_jobs: 'Opérations / heure glissante',
  daily_jobs: 'Opérations / 24 h glissantes', concurrent_jobs: 'Tâches simultanées', timeout_seconds: 'Durée maximale du traitement', retention_hours: 'Conservation après envoi',
  monthly_jobs: 'Tâches / mois (30 jours glissants)', history_hours: 'Historique des tâches',
};
export function quotaValue(key: string, value?: number | null): string {
  if (value === undefined) return 'À définir';
  if (value === null) return 'Sans plafond mensuel';
  if (key === 'history_hours') return value > 24 ? `${value / 24} jours` : `${value} h`;
  if (key.endsWith('_bytes') && value >= 1e9) return `${(value / 1e9).toLocaleString('fr')} Go`;
  if (key.endsWith('_bytes')) return `${(value / 1e6).toLocaleString('fr')} Mo`;
  if (key === 'retention_hours') return `${value} h`;
  if (key === 'timeout_seconds') return `${value} s`;
  return value.toLocaleString('fr');
}
