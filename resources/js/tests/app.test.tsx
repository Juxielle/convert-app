import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { vi } from 'vitest';
import App from '../App';
import type { Tool } from '../lib/api';
import { api, ApiError } from '../lib/api';
import { Dropzone, JobState } from '../components/ui';

const tools: Tool[] = [
  { slug: 'convertir-image', name: 'Convertir une image', category: 'images', description: 'Un nouveau format.', planned_formats: ['JPG', 'PNG'], target_formats: [], source_formats: [], enabled: false, status: 'planned', operation: 'convert' },
  { slug: 'convertir-audio', name: 'Convertir un audio', category: 'audio', description: 'Un nouveau son.', planned_formats: ['MP3'], target_formats: [], source_formats: [], enabled: false, status: 'planned', operation: 'convert' },
];
const guest = { max_file_bytes: 25_000_000, hourly_jobs: 5, daily_jobs: 10, concurrent_jobs: 1, timeout_seconds: 60, retention_hours: 1 };
function mount(path = '/outils') {
  vi.spyOn(globalThis, 'fetch').mockImplementation(async input => new Response(JSON.stringify({ data: String(input).endsWith('/limits') ? { enforced: false, plans: { guest, registered: guest } } : tools }), { status: 200 }));
  return render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={[path]}><App /></MemoryRouter></QueryClientProvider>);
}

it('filters tools by search and category, including an empty result', async () => {
  mount();
  const user = userEvent.setup();
  await screen.findByRole('heading', { name: 'Convertir une image' });
  await user.click(screen.getByRole('button', { name: 'Audio' }));
  expect(screen.queryByRole('heading', { name: 'Convertir une image' })).not.toBeInTheDocument();
  expect(screen.getByRole('heading', { name: 'Convertir un audio' })).toBeInTheDocument();
  await user.type(screen.getByRole('searchbox'), 'inexistant');
  expect(screen.getByText('Aucun outil trouvé')).toBeInTheDocument();
  await user.click(screen.getByRole('button', { name: 'Effacer la recherche' }));
  expect(screen.getByRole('heading', { name: 'Convertir un audio' })).toBeInTheDocument();
});

it('keeps upload, formats and conversion disabled with no fake usage', async () => {
  mount('/outils/convertir-image');
  expect(await screen.findByRole('button', { name: 'Sélectionner des fichiers' })).toBeDisabled();
  expect(screen.getByRole('combobox')).toBeDisabled();
  expect(screen.getByRole('button', { name: 'Conversion en préparation' })).toBeDisabled();
  expect(await screen.findByText('Le suivi de consommation sera activé avec les conversions.')).toBeInTheDocument();
  expect(screen.queryByRole('progressbar')).not.toBeInTheDocument();
  expect(vi.mocked(fetch).mock.calls.every(([url]) => String(url).startsWith('/api/v1/'))).toBe(true);
});

it('does not accept a file dropped on a disabled zone', () => {
  const onFiles = vi.fn();
  const { container } = render(<Dropzone disabled maxBytes={100} accept=".png" onFiles={onFiles} />);
  fireEvent.drop(container.querySelector('.dropzone')!, { dataTransfer: { files: [new File(['file'], 'test.png')] } });
  expect(onFiles).not.toHaveBeenCalled();
});

it('switches and persists theme and supports the mobile menu', async () => {
  mount('/faq');
  const user = userEvent.setup();
  await user.click(screen.getByRole('button', { name: 'Activer le mode sombre' }));
  expect(document.documentElement.dataset.theme).toBe('dark');
  expect(localStorage.getItem('convert-theme')).toBe('dark');
  await user.click(screen.getByRole('button', { name: 'Ouvrir le menu' }));
  expect(screen.getByRole('navigation', { name: 'Navigation mobile' })).toBeInTheDocument();
  await user.click(screen.getByRole('button', { name: 'Fermer le menu' }));
  expect(screen.queryByRole('navigation', { name: 'Navigation mobile' })).not.toBeInTheDocument();
});

it('shows a recoverable API error', async () => {
  mount();
  vi.mocked(fetch).mockResolvedValue(new Response(JSON.stringify({ error: { code: 'SERVICE_UNAVAILABLE', message: 'Service indisponible.' } }), { status: 503 }));
  // Trigger a new query on a new mount after the mock changes.
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter initialEntries={['/outils']}><App /></MemoryRouter></QueryClientProvider>);
  expect(await screen.findByText('Service indisponible.')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Réessayer' })).toBeInTheDocument();
});

it('maps network errors to a French API error', async () => {
  vi.spyOn(globalThis, 'fetch').mockRejectedValue(new TypeError('Network failed'));
  await expect(api('/tools')).rejects.toBeInstanceOf(ApiError);
  await expect(api('/tools')).rejects.toMatchObject({ code: 'NETWORK_ERROR', status: 0 });
});

it('never invents a processing percentage', () => {
  const { rerender } = render(<JobState status="processing" />);
  expect(screen.queryByRole('progressbar')).not.toBeInTheDocument();
  rerender(<JobState status="processing" progress={150} />);
  expect(screen.getByRole('progressbar')).toHaveAttribute('value', '100');
});

it('renders a friendly not-found page for an unknown tool', async () => {
  mount('/outils/inconnu');
  await waitFor(() => expect(screen.getByRole('heading', { name: 'Cette page s’est égarée.' })).toBeInTheDocument());
});
