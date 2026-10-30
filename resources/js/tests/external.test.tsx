import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { vi } from 'vitest';
import ExternalTool from '../pages/ExternalTool';

it('requires rights confirmation and submits a Commons source without pretending to transcode', async () => {
  const mock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
    if (init?.method === 'POST') return new Response(JSON.stringify({ data: { id: 'commons1' } }), { status: 202 });
    if (String(url).includes('/jobs/')) return new Response(JSON.stringify({ data: { id: 'commons1', name: 'Test.webm', status: 'completed', download_available: true, output_size: 1000, attribution: { artist: 'Creator', license: 'CC0 1.0', url: 'https://commons.wikimedia.org/wiki/File:Test.webm', license_url: 'https://creativecommons.org/publicdomain/zero/1.0/' } } }));
    if (String(url).includes('/me/usage')) return new Response(JSON.stringify({ data: { external_used: { daily_jobs: 0 } } }));
    return new Response(JSON.stringify({ data: { enabled: true, sources: [{ id: 'commons', name: 'Wikimedia Commons' }], limits: { daily_jobs: 5, hourly_jobs: 2 } } }));
  });
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter><ExternalTool /></MemoryRouter></QueryClientProvider>);
  const user = userEvent.setup();
  await waitFor(() => expect(screen.getByLabelText('Page du fichier Wikimedia Commons')).toBeEnabled());
  await user.type(screen.getByLabelText('Page du fichier Wikimedia Commons'), 'https://commons.wikimedia.org/wiki/File:Test.webm');
  expect(screen.getByRole('button', { name: 'Récupérer le fichier WebM' })).toBeDisabled();
  await user.click(screen.getByRole('checkbox'));
  await user.click(screen.getByRole('button', { name: 'Récupérer le fichier WebM' }));
  expect(await screen.findByRole('link', { name: /Télécharger le WebM/ })).toHaveAttribute('href', '/api/v1/jobs/commons1/download');
  const request = mock.mock.calls.find(([, init]) => init?.method === 'POST')!;
  expect(JSON.parse(request[1]!.body as string)).toEqual({ url: 'https://commons.wikimedia.org/wiki/File:Test.webm', rights_confirmed: true });
  expect(screen.getByText('Creator · CC0 1.0')).toBeInTheDocument();
});
