import { useQuery } from '@tanstack/react-query';
import { Link, useLocation } from 'react-router-dom';
import { api } from '../lib/api';

export default function TrialAlert({ enabled }: { enabled: boolean }) {
  const location = useLocation();
  const subscription = useQuery({ queryKey: ['subscription'], enabled, queryFn: ({ signal }) => api<{ status: string; trial_remaining_seconds: number | null; notices: { id: number; message: string }[] }>('/me/subscription', signal), refetchInterval: 60000 });
  const info = subscription.data;
  if (!enabled || location.pathname === '/mon-abonnement' || info?.status !== 'trialing' || info.trial_remaining_seconds == null || info.trial_remaining_seconds > 3 * 86400) return null;
  return <aside className="container trial-alert" role="status"><p>{info.notices.at(-1)?.message ?? 'Votre essai arrive à son terme. Sans paiement confirmé, votre compte reviendra au Gratuit, sans prélèvement automatique.'}</p><Link className="quiet-link" to="/mon-abonnement">Consulter mon essai →</Link></aside>;
}
