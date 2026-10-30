import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { vi } from 'vitest';
import TrialAlert from '../components/TrialAlert';

it('shows an approaching-trial notice outside the subscription page without a payment claim', async () => {
  vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ data: { status: 'trialing', trial_remaining_seconds: 86000, notices: [{ id: 1, message: 'Votre essai se termine demain. Aucun prélèvement automatique.' }] } })));
  render(<QueryClientProvider client={new QueryClient()}><MemoryRouter initialEntries={['/outils']}><TrialAlert enabled /></MemoryRouter></QueryClientProvider>);
  expect(await screen.findByText('Votre essai se termine demain. Aucun prélèvement automatique.')).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Consulter mon essai →' })).toHaveAttribute('href', '/mon-abonnement');
});
