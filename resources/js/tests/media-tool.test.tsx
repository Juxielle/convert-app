import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { vi } from 'vitest';
import App from '../App';

function mount(audio = false, processing = false) {
  const slug = audio ? 'convertir-audio' : 'compresser-video';
  const tool = { slug, name: audio ? 'Convertir un audio' : 'Compresser une vidéo', category: audio ? 'audio' : 'video', operation: 'convert', enabled: true, status: 'available', description: 'Média', source_formats: audio ? ['wav'] : ['mp4'], target_formats: audio ? ['mp3', 'wav', 'opus'] : ['mp4'], planned_formats: [] };
  const mock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
    if (init?.method === 'POST') return new Response(JSON.stringify({ data: { id: 'media1' } }), { status: 202 });
    if (String(url).includes('/jobs/')) return new Response(JSON.stringify({ data: { id: 'media1', name: 'test', status: processing ? 'processing' : 'completed', progress: processing ? 37 : 100, input_size: 1000, output_size: processing ? null : 800, download_available: !processing } }));
    if (String(url).includes('/me/usage')) return new Response(JSON.stringify({ data: { limits: { max_file_bytes: 25000000, daily_jobs: 10, retention_hours: 1 }, used: { daily_jobs: 0 } } }));
    return new Response(JSON.stringify({ data: [tool] }));
  });
  const result = render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={[`/outils/${slug}`]}><App /></MemoryRouter></QueryClientProvider>);
  return { mock, ...result };
}

it('submits a fixed video profile and exposes the protected download', async () => {
  const { mock, container } = mount();
  await waitFor(() => expect(screen.getByRole('button', { name: 'Sélectionner des fichiers' })).toBeEnabled());
  const user = userEvent.setup();
  await user.selectOptions(screen.getByLabelText('Profil vidéo'), 'light');
  await user.upload(container.querySelector('input[type="file"]')!, new File(['fixture'], 'test.mp4', { type: 'video/mp4' }));
  await user.click(screen.getByRole('button', { name: 'Traiter le média' }));
  expect(await screen.findByRole('link', { name: 'Télécharger' })).toHaveAttribute('href', '/api/v1/jobs/media1/download');
  const body = mock.mock.calls.find(([, init]) => init?.method === 'POST')![1]!.body as FormData;
  expect(body.get('options[profile]')).toBe('light');
  expect(body.get('options[resolution]')).toBe('480');
  expect(body.get('options[fps]')).toBe('30');
  expect(body.get('file')).toBeInstanceOf(File);
  expect(body.has('files[]')).toBe(false);
});

it('sends audio and trim settings and displays measured progress with cancellation', async () => {
  const { mock, container } = mount(true, true);
  await waitFor(() => expect(screen.getByRole('button', { name: 'Sélectionner des fichiers' })).toBeEnabled());
  const user = userEvent.setup();
  await user.selectOptions(screen.getByLabelText('Canaux'), '1');
  await user.type(screen.getByLabelText('Durée de l’extrait'), '1.5');
  await user.upload(container.querySelector('input[type="file"]')!, new File(['fixture'], 'test.wav', { type: 'audio/wav' }));
  await user.click(screen.getByRole('button', { name: 'Traiter le média' }));
  expect(await screen.findByRole('progressbar', { name: 'Progression du traitement' })).toHaveAttribute('value', '37');
  expect(screen.queryByRole('link', { name: 'Télécharger' })).not.toBeInTheDocument();
  const body = mock.mock.calls.find(([, init]) => init?.method === 'POST')![1]!.body as FormData;
  expect(body.get('options[channels]')).toBe('1');
  expect(body.get('options[duration]')).toBe('1.5');
  await user.click(screen.getByRole('button', { name: 'Annuler le traitement' }));
  await waitFor(() => expect(mock.mock.calls.some(([url, init]) => String(url).endsWith('/jobs/media1/cancel') && init?.method === 'POST')).toBe(true));
});
