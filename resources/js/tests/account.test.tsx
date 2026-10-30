import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { vi } from 'vitest';
import App from '../App';
import { adminPlanCatalog, freePlan, paidPlan } from './plan-fixtures';

function mount(path: string, admin = false) {
  let user: { id: number; name: string; email: string; role: string; plan: string } | null = admin ? { id: 1, name: 'Admin', email: 'admin@example.test', role: 'admin', plan: 'free' } : null;
  const session = () => ({ user, csrf_token: 'fresh-token', password_reset_available: false });
  const mock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
    const route = String(url);
    if (route.includes('/auth/login') && init?.method === 'POST') { user = { id: 2, name: 'Alice', email: 'alice@example.test', role: 'user', plan: 'free' }; return new Response(JSON.stringify({ data: session() })); }
    if (route.includes('/auth/session')) return new Response(JSON.stringify({ data: session() }));
    if (route.includes('/admin/plans')) return new Response(JSON.stringify({ data: adminPlanCatalog }));
    if (route.includes('/admin/settings')) return new Response(JSON.stringify({ data: { limits: { guest: { hourly_jobs: 5, daily_jobs: 10 }, registered: { hourly_jobs: 20, daily_jobs: 50 } }, tools: { 'convertir-image': true } } }));
    if (route.includes('/admin/overview')) return new Response(JSON.stringify({ data: { states: { completed: 2 }, jobs_24h: 2, failure_rate_24h: 0, mean_processing_seconds: 1, reserved_bytes: 1000, free_disk_bytes: null, worker_last_seen: null } }));
    if (route.includes('/admin/jobs') || route.includes('/admin/audit')) return new Response(JSON.stringify({ data: [], last_page: 1 }));
    if (route.includes('/plans')) return new Response(JSON.stringify({ data: [freePlan, paidPlan] }));
    return new Response(JSON.stringify({ data: [] }));
  });
  const result = render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={[path]}><App /></MemoryRouter></QueryClientProvider>);
  return { mock, ...result };
}

it('logs in and opens the private profile without exposing a password in a URL', async () => {
  const { mock } = mount('/connexion');
  const user = userEvent.setup();
  await user.type(screen.getByLabelText('Adresse email'), 'alice@example.test');
  await user.type(screen.getByLabelText('Mot de passe'), 'a long password 123!');
  await user.click(screen.getByRole('button', { name: 'Connexion' }));
  expect(await screen.findByRole('heading', { name: 'Bonjour Alice.' })).toBeInTheDocument();
  const call = mock.mock.calls.find(([url, init]) => String(url).endsWith('/auth/login') && init?.method === 'POST')!;
  expect(JSON.parse(call[1]!.body as string).password).toBe('a long password 123!');
  expect(String(call[0])).not.toContain('password');
});

it('blocks the admin interface for visitors', async () => {
  const { mock } = mount('/administration');
  expect(await screen.findByText('Cet espace est réservé aux administrateurs.')).toBeInTheDocument();
  expect(mock.mock.calls.some(([url]) => String(url).includes('/admin/'))).toBe(false);
});

it('saves only bounded settings through the protected admin endpoint', async () => {
  const { mock } = mount('/administration', true);
  const user = userEvent.setup();
  const toggle = await screen.findByLabelText('convertir-image');
  await user.click(toggle);
  await user.click(screen.getByRole('button', { name: 'Enregistrer les réglages' }));
  await waitFor(() => expect(mock.mock.calls.some(([, init]) => init?.method === 'PATCH')).toBe(true));
  const call = mock.mock.calls.find(([, init]) => init?.method === 'PATCH')!;
  expect(JSON.parse(call[1]!.body as string).tools['convertir-image']).toBe(false);
  expect(screen.queryByRole('link', { name: 'Télécharger' })).not.toBeInTheDocument();
});

it('shows the actual free-plan limits and no purchase action', async () => {
  mount('/offres');
  expect(await screen.findByRole('heading', { name: 'Gratuit' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /Essayer gratuitement/ })).toHaveAttribute('href', '/souscription?plan=pro&period=monthly&mode=trial');
  expect(screen.getByRole('link', { name: 'Commencer gratuitement' })).toHaveAttribute('href', '/outils');
});
