import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { vi } from 'vitest';
import App from '../App';

function mount(merge = false) {
  const slug = merge ? 'fusionner-pdf' : 'diviser-pdf';
  const tool = { slug, name: merge ? 'Fusionner des PDF' : 'Extraire des pages PDF', category: 'documents', operation: merge ? 'merge' : 'split', enabled: true, status: 'available', description: 'PDF', source_formats: ['pdf'], target_formats: ['pdf'], planned_formats: [], document_limits: { max_input_bytes: 10000000, max_pages: 50 } };
  const mock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
    if (init?.method === 'POST') return new Response(JSON.stringify({ data: merge ? [{ id: 'doc1' }] : { id: 'doc1' } }), { status: 202 });
    if (String(url).includes('/jobs/')) return new Response(JSON.stringify({ data: { id: 'doc1', name: 'document.pdf', status: 'completed', progress: 100, input_size: 1000, output_size: 800, download_available: true } }));
    if (String(url).includes('/me/usage')) return new Response(JSON.stringify({ data: { limits: { max_file_bytes: 25000000, hourly_jobs: 5, daily_jobs: 10, retention_hours: 1 }, used: { daily_jobs: 0 } } }));
    return new Response(JSON.stringify({ data: [tool] }));
  });
  const result = render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={[`/outils/${slug}`]}><App /></MemoryRouter></QueryClientProvider>);
  return { mock, ...result };
}

it('submits page-range options and links the protected result', async () => {
  const { mock, container } = mount();
  await waitFor(() => expect(screen.getByRole('button', { name: 'Sélectionner des fichiers' })).toBeEnabled());
  const user = userEvent.setup();
  await user.upload(container.querySelector('input[type="file"]')!, new File(['%PDF-fixture'], 'test.pdf', { type: 'application/pdf' }));
  await user.click(screen.getByRole('button', { name: 'Traiter le document' }));
  expect(await screen.findByRole('link', { name: 'Télécharger' })).toHaveAttribute('href', '/api/v1/jobs/doc1/download');
  const request = mock.mock.calls.find(([, init]) => init?.method === 'POST')!;
  const body = request[1]!.body as FormData;
  expect(body.get('options[first_page]')).toBe('1');
  expect(body.get('options[last_page]')).toBe('1');
  expect(body.get('file')).toBeInstanceOf(File);
});

it('requires two PDFs for merging and preserves reordered input files', async () => {
  const { mock, container } = mount(true);
  await waitFor(() => expect(screen.getByRole('button', { name: 'Sélectionner des fichiers' })).toBeEnabled());
  const user = userEvent.setup();
  const first = new File(['%PDF-first'], 'first.pdf', { type: 'application/pdf' });
  const second = new File(['%PDF-second'], 'second.pdf', { type: 'application/pdf' });
  await user.upload(container.querySelector('input[type="file"]')!, first);
  expect(screen.getByRole('button', { name: 'Fusionner les PDF' })).toBeDisabled();
  await user.upload(container.querySelector('input[type="file"]')!, second);
  await user.click(screen.getByRole('button', { name: 'Monter second.pdf' }));
  await user.click(screen.getByRole('button', { name: 'Fusionner les PDF' }));
  await screen.findByRole('link', { name: 'Télécharger' });
  const request = mock.mock.calls.find(([, init]) => init?.method === 'POST')!;
  const files = (request[1]!.body as FormData).getAll('files[]') as File[];
  expect(files.map(file => file.name)).toEqual(['second.pdf', 'first.pdf']);
});
