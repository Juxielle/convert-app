import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { vi } from 'vitest';
import App from '../App';

function mount(speech = false) {
  const tool = { slug: speech ? 'transcrire-media' : 'ocr-image', name: speech ? 'Transcrire un audio ou une vidéo' : 'Extraire le texte d’une image', category: speech ? 'audio' : 'images', operation: speech ? 'transcribe' : 'ocr', enabled: true, status: 'available', planned_formats: [], description: 'Extraction privée', source_formats: speech ? ['mp4', 'wav'] : ['png'], target_formats: ['txt'] };
  const languages = [{ code: speech ? 'auto' : 'fr-en', label: speech ? 'Détection automatique' : 'Français et anglais' }, { code: 'fr', label: 'Français' }, { code: 'en', label: 'Anglais' }, { code: 'es', label: 'Espagnol' }, { code: 'ar', label: 'Arabe' }, { code: 'zh', label: 'Chinois' }];
  const mock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
    if (init?.method === 'POST') return new Response(JSON.stringify({ data: { id: 'text1' } }), { status: 202 });
    if (String(url).endsWith('/download')) return new Response('Bonjour <script>texte seulement</script>', { headers: { 'Content-Type': 'text/plain' } });
    if (String(url).includes('/jobs/')) return new Response(JSON.stringify({ data: { id: 'text1', name: 'sample', status: 'completed', progress: 100, output_size: 45, download_available: true } }));
    if (String(url).includes('/me/usage')) return new Response(JSON.stringify({ data: { limits: { max_file_bytes: 25000000, daily_jobs: 10, timeout_seconds: 60, retention_hours: 1 }, used: { daily_jobs: 0 } } }));
    return new Response(JSON.stringify({ data: [{ ...tool, recognition_languages: languages }] }));
  });
  const view = render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={[`/outils/${tool.slug}`]}><App /></MemoryRouter></QueryClientProvider>);
  return { ...view, mock };
}

it('extracts OCR with language/layout options and previews protected text as plain text', async () => {
  const { container, mock } = mount();
  const user = userEvent.setup();
  await waitFor(() => expect(screen.getByRole('button', { name: 'Sélectionner des fichiers' })).toBeEnabled());
  await user.selectOptions(screen.getByLabelText('Mise en page'), 'block');
  await user.upload(container.querySelector('input[type="file"]')!, new File(['fixture'], 'sample.png', { type: 'image/png' }));
  await user.click(screen.getByRole('button', { name: 'Extraire le texte' }));
  expect(await screen.findByRole('link', { name: 'Télécharger le texte TXT' })).toHaveAttribute('href', '/api/v1/jobs/text1/download');
  const body = mock.mock.calls.find(([, init]) => init?.method === 'POST')![1]!.body as FormData;
  expect(body.get('options[language]')).toBe('fr-en');
  expect(body.get('options[layout]')).toBe('block');
  expect(body.get('target_format')).toBe('txt');
  await user.click(screen.getByRole('button', { name: 'Afficher le texte' }));
  expect(await screen.findByLabelText('Texte extrait — à relire')).toHaveValue('Bonjour <script>texte seulement</script>');
  expect(container.querySelector('script')).toBeNull();
});

it('accepts a video for transcription and submits only the speech language', async () => {
  const { container, mock } = mount(true);
  const user = userEvent.setup();
  await waitFor(() => expect(screen.getByRole('button', { name: 'Sélectionner des fichiers' })).toBeEnabled());
  await user.selectOptions(screen.getByLabelText('Langue du contenu'), 'fr');
  await user.upload(container.querySelector('input[type="file"]')!, new File(['fixture'], 'sample.mp4', { type: 'video/mp4' }));
  await user.click(screen.getByRole('button', { name: 'Extraire le texte' }));
  await screen.findByRole('link', { name: 'Télécharger le texte TXT' });
  const body = mock.mock.calls.find(([, init]) => init?.method === 'POST')![1]!.body as FormData;
  expect(body.get('tool')).toBe('transcrire-media');
  expect(body.get('options[language]')).toBe('fr');
  expect(body.has('options[layout]')).toBe(false);
});

it.each([{ speech: false, language: 'ar' }, { speech: true, language: 'es' }])('submits the new API language choice $language', async ({ speech, language }) => {
  const { container, mock } = mount(speech);
  const user = userEvent.setup();
  await waitFor(() => expect(screen.getByRole('button', { name: 'Sélectionner des fichiers' })).toBeEnabled());
  await user.selectOptions(screen.getByLabelText('Langue du contenu'), language);
  await user.upload(container.querySelector('input[type="file"]')!, new File(['fixture'], speech ? 'speech.mp4' : 'text.png', { type: speech ? 'video/mp4' : 'image/png' }));
  await user.click(screen.getByRole('button', { name: 'Extraire le texte' }));
  await screen.findByRole('link', { name: 'Télécharger le texte TXT' });
  const body = mock.mock.calls.find(([, init]) => init?.method === 'POST')![1]!.body as FormData;
  expect(body.get('options[language]')).toBe(language);
  await user.click(screen.getByRole('button', { name: 'Afficher le texte' }));
  expect(await screen.findByLabelText('Texte extrait — à relire')).toHaveAttribute('dir', 'auto');
});
