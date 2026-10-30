import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { vi } from 'vitest';
import App from '../App';

const limits = { max_file_bytes: 25_000_000, hourly_jobs: 5, daily_jobs: 10, concurrent_jobs: 1, timeout_seconds: 60, retention_hours: 1 };
function mount(jobs: object[] = []) {
  const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
    const url = String(input);
    if (init?.method === 'DELETE' || init?.method === 'POST') return new Response(JSON.stringify({ data: { deleted: true } }));
    if (url.includes('/me/usage')) return new Response(JSON.stringify({ data: { limits, used: { daily_jobs: 2, hourly_jobs: 2, active_jobs: 0, reserved_bytes: 0 } } }));
    return new Response(JSON.stringify({ data: jobs, meta: { current_page: 1, last_page: 1 } }));
  });
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={['/mes-fichiers']}><App /></MemoryRouter></QueryClientProvider>);
  return fetchMock;
}

it('shows a real quota and a clear empty history without an upload action', async () => {
  mount();
  expect(await screen.findByText('Aucun fichier pour le moment')).toBeInTheDocument();
  expect(await screen.findByText('2 / 10 opérations utilisées')).toBeInTheDocument();
  expect(screen.getByRole('progressbar')).toHaveAttribute('value', '2');
  expect(screen.queryByRole('button', { name: 'Sélectionner des fichiers' })).not.toBeInTheDocument();
});

it('downloads only through the controlled endpoint and submits deletion with CSRF', async () => {
  const meta = document.createElement('meta'); meta.name = 'csrf-token'; meta.content = 'test-csrf'; document.head.append(meta);
  try {
    const fetchMock = mount([{ id: '123', name: 'test.txt', status: 'completed', progress: 100, input_size: 100, output_size: 80, expires_at: '2030-01-01T12:00:00Z', download_available: true, cancel_requested: false, delete_requested: false }]);
    expect(await screen.findByRole('link', { name: 'Télécharger' })).toHaveAttribute('href', '/api/v1/jobs/123/download');
    await userEvent.setup().click(screen.getByRole('button', { name: 'Supprimer test.txt' }));
    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith('/api/v1/jobs/123', expect.objectContaining({ method: 'DELETE', credentials: 'same-origin', headers: expect.objectContaining({ 'X-CSRF-TOKEN': 'test-csrf' }) })));
  } finally { meta.remove(); }
});
