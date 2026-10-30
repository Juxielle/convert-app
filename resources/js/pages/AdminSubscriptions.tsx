import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '../lib/api';
import { ErrorState, Loading } from '../components/ui';

type Row = { id: number; user_id: number; status: string; plan: { name: string }; price_amount: number; billing_period: string | null; starts_at: string; ends_at: string | null; trial_ends_at: string | null };
export default function AdminSubscriptions() {
  const [status, setStatus] = useState('trialing');
  const [page, setPage] = useState(1);
  const data = useQuery({ queryKey: ['admin-subscriptions', status, page], queryFn: ({ signal }) => api<{ data: Row[]; last_page: number }>(`/admin/subscriptions?status=${status}&page=${page}`, signal, true), refetchInterval: 15000 });
  return <section className="panel"><h2>Abonnements des utilisateurs</h2><div className="field"><label htmlFor="admin-sub-status">Statut</label><select id="admin-sub-status" value={status} onChange={event => { setStatus(event.target.value); setPage(1); }}><option value="trialing">En essai</option><option value="active">Actifs</option><option value="expired">Expirés</option><option value="canceled">Annulés</option><option value="pending_payment">Paiement en attente</option></select></div>{data.isPending ? <Loading /> : data.error ? <ErrorState message={data.error.message} retry={() => { void data.refetch(); }} /> : <><div className="table-scroll"><table><thead><tr><th>ID / utilisateur</th><th>Formule / état</th><th>Début</th><th>Fin / fin d’essai</th><th>Tarif choisi (non preuve de paiement)</th></tr></thead><tbody>{data.data?.data.map(row => <tr key={row.id}><td>{row.id} / {row.user_id}</td><td>{row.plan.name} · {row.status}</td><td>{new Date(row.starts_at).toLocaleString('fr')}</td><td>{row.trial_ends_at || row.ends_at ? new Date(row.trial_ends_at ?? row.ends_at!).toLocaleString('fr') : 'Sans échéance'}</td><td>{row.price_amount?.toLocaleString('fr')} FCFA {row.billing_period ?? ''}</td></tr>)}</tbody></table></div>{!data.data?.data.length && <p>Aucun abonnement dans cet état.</p>}<div className="account-links"><button className="button secondary" disabled={page === 1} onClick={() => setPage(page - 1)}>Précédent</button><span>Page {page}</span><button className="button secondary" disabled={!data.data || page >= data.data.last_page} onClick={() => setPage(page + 1)}>Suivant</button></div></>}</section>;
}
