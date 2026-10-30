import type { PricingPlan } from '../lib/plans';

export const quotas = { max_file_bytes: 25000000, storage_bytes: 100000000, hourly_jobs: 5, daily_jobs: 10, concurrent_jobs: 1, timeout_seconds: 60, retention_hours: 24, history_hours: 24, monthly_jobs: null };
export const freePlan: PricingPlan = { id: 'free', name: 'Gratuit', description: 'Sans paiement.', is_free: true, active: true, currency: 'XAF', currency_label: 'FCFA', monthly_price: 0, yearly_price: 0, billing_periods: [], features: ['Fichiers privés'], limits: { ...quotas }, guest_limits: quotas, tools: [{ slug: 'convertir-image', name: 'Convertir une image' }], available: true, status: 'free' };
export const paidPlan: PricingPlan = { ...freePlan, id: 'pro', name: 'Pro', is_free: false, description: 'En préparation.', monthly_price: 3900, yearly_price: 39000, billing_periods: ['monthly', 'yearly'], features: ['Traitement prioritaire'], limits: { ...quotas, max_file_bytes: 500000000, monthly_jobs: 300, concurrent_jobs: 3, history_hours: 720 }, guest_limits: null, available: true, status: 'trial_available', trial_days: 15, trial_enabled: true, annual_savings: 7800, annual_free_months: 2, monthly_window_days: 30, trial_policy: 'Un seul essai par compte, toutes formules confondues.' };
export const adminPlanCatalog = {
  plans: [{ id: 1, slug: 'free', name: 'Gratuit', description: 'Sans paiement.', is_free: true, active: true, currency: 'XAF', monthly_price: 0, yearly_price: 0, billing_periods: [], features: ['Fichiers privés'], quotas, guest_limits: quotas, allowed_tools: null, display_order: 0 }],
  quota_bounds: { max_file_bytes: [1000, 100000000], storage_bytes: [1000000, 400000000], hourly_jobs: [1,100], daily_jobs: [1,300], concurrent_jobs: [1,2], timeout_seconds: [1,180], retention_hours: [1,24] },
  tools: [{ slug: 'convertir-image', name: 'Convertir une image' }],
};
