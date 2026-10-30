import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { vi } from 'vitest';
import Subscribe from '../pages/Subscribe';
import { freePlan, paidPlan } from './plan-fixtures';
import { openSaspayCheckout } from '../lib/payments';

vi.mock('../lib/payments', () => ({ openSaspayCheckout: vi.fn() }));

function mount(eligible = true, live = false, entry = '/souscription?plan=pro&period=yearly') {
  const mock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
    const path = String(url);
    if (path.endsWith('/plans')) return new Response(JSON.stringify({ data: [freePlan, paidPlan] }));
    if (path.endsWith('/payment-methods')) return new Response(JSON.stringify({ data: { available: live, trial_payment_setup_available: false, methods: [{ id: 'card', label: 'Carte bancaire', description: 'Paiement sécurisé', fields_enabled: false }, { id: 'mobile_money', label: 'Mobile Money', description: 'Paiement mobile', fields_enabled: false }] } }));
    if (path.endsWith('/payment-status')) return new Response(JSON.stringify({ data: { status: 'pending_payment', payment_confirmed: false, review_required: false, payment_status: 'PENDING', checkout_url: 'https://pay.saspay.me/checkout/example' } }));
    if (live && path.endsWith('/checkout')) return new Response(JSON.stringify({ data: { status: 'pending_payment', checkout_url: 'https://pay.saspay.me/checkout/example' } }));
    if (path.endsWith('/auth/session')) return new Response(JSON.stringify({ data: { user: { id: 1, name: 'Alice' } } }));
    if (path.endsWith('/me/subscription')) return new Response(JSON.stringify({ data: { trial_eligible: eligible, status: 'active' } }));
    if (path.endsWith('/quote')) {
      const body = JSON.parse(init!.body as string);
      return new Response(JSON.stringify({ data: { id: 'quote1', plan: paidPlan, mode: body.mode, period: body.period, payment_method: body.payment_method ?? null, payment_method_label: body.payment_method === 'mobile_money' ? 'Mobile Money' : body.payment_method === 'card' ? 'Carte bancaire' : null, amount: body.period === 'yearly' ? 39000 : 3900, trial_days: 15, expires_at: '2026-11-01T10:10:00Z', trial_policy: 'Un seul essai par compte.' } }));
    }
    return new Response(JSON.stringify({ data: path.endsWith('/trial') ? { status: 'trialing', trial_ends_at: '2026-11-15T10:00:00Z' } : { status: 'pending_payment', message: 'Le paiement sera bientôt disponible. Aucun paiement effectué.' } }));
  });
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={[entry]}><Subscribe /></MemoryRouter></QueryClientProvider>);
  return mock;
}

it('requires a server recap and explicit acceptance before activating a trial', async () => {
  const mock = mount();
  const user = userEvent.setup();
  const review = await screen.findByRole('button', { name: 'Vérifier le récapitulatif' });
  await waitFor(() => expect(review).toBeEnabled());
  expect(mock.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(false);
  expect(screen.queryByRole('list', { name: 'Étapes de souscription' })).not.toBeInTheDocument();
  expect(screen.getByRole('complementary')).toContainElement(screen.getByRole('radio', { name: 'Payer maintenant' }));
  expect(screen.getByRole('complementary')).toBeVisible();
  expect(await screen.findByLabelText('Num\u00e9ro de carte')).toBeDisabled();
  await user.click(review);
  const confirm = await screen.findByRole('button', { name: 'Démarrer mon essai gratuit' });
  expect(confirm).toBeDisabled();
  await user.click(screen.getByRole('checkbox', { name: /J’ai lu les conditions/ }));
  await user.click(confirm);
  expect(await screen.findByText(/Votre essai est actif jusqu’au/)).toBeInTheDocument();
  const call = mock.mock.calls.find(([url]) => String(url).endsWith('/trial'))!;
  expect(JSON.parse(call[1]!.body as string)).toEqual({ quote_id: 'quote1', confirmed: true });
  const quote = mock.mock.calls.find(([url]) => String(url).endsWith('/quote'))!;
  expect(JSON.parse(quote[1]!.body as string)).toEqual({ plan: 'pro', period: 'yearly', mode: 'trial', payment_method: 'card' });
});

it('uses hosted checkout in production and blocks a trial without payment setup', async () => {
  const mock = mount(true, true);
  const user = userEvent.setup();
  await screen.findByText(/Vous renseignerez vos coordonnées/);
  expect(screen.getByRole('radio', { name: /Essayer gratuitement/ })).toBeDisabled();
  expect(screen.queryByLabelText('Numéro de carte')).not.toBeInTheDocument();
  await user.click(screen.getByRole('radio', { name: 'Payer maintenant' }));
  await user.click(screen.getByRole('button', { name: 'Vérifier le récapitulatif' }));
  const confirm = await screen.findByRole('button', { name: 'Payer avec SasPay' });
  expect(confirm).toBeDisabled();
  await user.click(screen.getByRole('checkbox', { name: /J’ai lu les conditions/ }));
  await user.click(confirm);
  await waitFor(() => expect(openSaspayCheckout).toHaveBeenCalledWith('https://pay.saspay.me/checkout/example'));
  expect(mock.mock.calls.some(([url]) => String(url).endsWith('/trial'))).toBe(false);
});

it('checks the returned payment on the server without trusting the URL status', async () => {
  const mock = mount(true, true, '/souscription?payment=12&status=SUCCESS');
  expect(await screen.findByText(/Votre paiement est en attente de confirmation par SasPay/)).toBeInTheDocument();
  expect(mock.mock.calls.some(([url]) => String(url).endsWith('/subscriptions/12/payment-status'))).toBe(true);
  expect(screen.getByRole('button', { name: 'Reprendre le paiement sur SasPay' })).toBeInTheDocument();
});

it('keeps payment pending without claiming success and disables an ineligible trial', async () => {
  const mock = mount(false);
  const user = userEvent.setup();
  await screen.findByRole('button', { name: 'Vérifier le récapitulatif' });
  await waitFor(() => expect(screen.getByRole('radio', { name: /Essayer gratuitement/ })).toBeDisabled());
  await user.click(screen.getByRole('radio', { name: 'Payer maintenant' }));
  expect(await screen.findByLabelText('Numéro de carte')).toBeDisabled();
  expect(screen.getByLabelText('Code de sécurité (CVV)')).toBeDisabled();
  await user.click(screen.getByRole('button', { name: 'Vérifier le récapitulatif' }));
  const confirm = await screen.findByRole('button', { name: 'Confirmer la demande de paiement' });
  await user.click(screen.getByRole('checkbox', { name: /J’ai lu les conditions/ }));
  await user.click(confirm);
  expect(await screen.findByText('Le paiement sera bientôt disponible. Aucun paiement effectué.')).toBeInTheDocument();
  expect(mock.mock.calls.some(([url]) => String(url).endsWith('/trial'))).toBe(false);
  expect(screen.queryByText(/paiement réussi/i)).not.toBeInTheDocument();
});

it('offers Mobile Money and submits only its method identifier', async () => {
  const mock = mount();
  const user = userEvent.setup();
  await user.click(await screen.findByRole('radio', { name: 'Payer maintenant' }));
  await user.click(await screen.findByRole('radio', { name: /Mobile Money Paiement mobile/ }));
  expect(screen.getByLabelText('Numéro de téléphone Mobile Money')).toBeDisabled();
  expect(screen.queryByLabelText('Numéro de carte')).not.toBeInTheDocument();
  await user.click(screen.getByRole('button', { name: 'Vérifier le récapitulatif' }));
  await screen.findByRole('button', { name: 'Confirmer la demande de paiement' });
  const quote = mock.mock.calls.find(([url]) => String(url).endsWith('/quote'))!;
  expect(JSON.parse(quote[1]!.body as string)).toEqual({ plan: 'pro', period: 'yearly', mode: 'pay', payment_method: 'mobile_money' });
  expect(screen.getByRole('radio', { name: /Mobile Money Paiement mobile/ })).toBeChecked();
  await user.click(screen.getByRole('radio', { name: /Essayer gratuitement/ }));
  expect(screen.getByLabelText('Numéro de téléphone Mobile Money')).toBeDisabled();
  expect(screen.queryByRole('button', { name: 'Confirmer la demande de paiement' })).not.toBeInTheDocument();
});

