import { render, screen, within, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { vi } from 'vitest';
import Plans from '../pages/Plans';
import SubscriptionPage from '../pages/Subscription';
import AdminPlans from '../pages/AdminPlans';
import { adminPlanCatalog, freePlan, paidPlan, quotas } from './plan-fixtures';

function mount(page: React.ReactNode) {
  const mock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
    const path = String(url);
    if (path.includes('/admin/plans')) return new Response(JSON.stringify({ data: init?.method === 'PATCH' ? adminPlanCatalog.plans[0] : adminPlanCatalog }));
    if (path.includes('/auth/session')) return new Response(JSON.stringify({ data: { user: null } }));
    if (path.includes('/me/subscription')) return new Response(JSON.stringify({ data: { plan: freePlan, is_guest: true, status: 'active', renews_at: null, starts_at: null, ends_at: null, limits: quotas, used: { daily_jobs: 3, hourly_jobs: 1, active_jobs: 0, reserved_bytes: 0 }, external_used: { daily_jobs: 0 }, history: [], payment_available: false, can_change_plan: false } }));
    return new Response(JSON.stringify({ data: [freePlan, paidPlan, { ...paidPlan, id: 'business', name: 'Business' }] }));
  });
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>{page}</MemoryRouter></QueryClientProvider>);
  return mock;
}

it('uses API quotas for both free access profiles and keeps all paid actions disabled across periods', async () => {
  const mock = mount(<Plans />);
  const user = userEvent.setup();
  const free = await screen.findByRole('heading', { name: 'Gratuit' });
  const card = free.closest('article')!;
  expect(within(card).getByText('25 Mo')).toBeInTheDocument();
  await user.click(screen.getByRole('button', { name: 'Annuel' }));
  expect(screen.getByRole('button', { name: 'Annuel' })).toHaveAttribute('aria-pressed', 'true');
  expect(screen.getAllByRole('link', { name: /Essayer gratuitement/ })).toHaveLength(2);
  expect(screen.getAllByText(/mois offerts en annuel/)).toHaveLength(2);
  expect(mock.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(false);
});

it('shows actual usage and does not invent a free renewal date or payment', async () => {
  mount(<SubscriptionPage />);
  expect(await screen.findByText('Votre formule : Gratuit')).toBeInTheDocument();
  expect(screen.getByRole('progressbar', { name: 'Opérations sur 24 h' })).toHaveAttribute('value', '3');
  expect(screen.getByText(/Aucun renouvellement prévu/)).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Comparer les formules' })).toHaveAttribute('href', '/tarifs');
  expect(screen.queryByRole('button', { name: /payer|souscrire/i })).not.toBeInTheDocument();
});

it('saves plan metadata, quotas and features through the admin endpoint without subscription assignment', async () => {
  const mock = mount(<AdminPlans />);
  const user = userEvent.setup();
  const name = await screen.findByLabelText('Nom');
  await user.clear(name); await user.type(name, 'Découverte');
  await user.click(screen.getByRole('button', { name: 'Enregistrer le plan' }));
  await waitFor(() => expect(mock.mock.calls.some(([, init]) => init?.method === 'PATCH')).toBe(true));
  const call = mock.mock.calls.find(([, init]) => init?.method === 'PATCH')!;
  const body = JSON.parse(call[1]!.body as string);
  expect(body.name).toBe('Découverte'); expect(body.quotas.daily_jobs).toBe(10);
  expect(body.monthly_price).toBe(0); expect(body.current_subscription_id).toBeUndefined();
  expect(String(call[0])).toBe('/api/v1/admin/plans/1');
});
