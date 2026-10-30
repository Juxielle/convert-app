import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { vi } from 'vitest';
import App from '../App';

function mount() {
  const tool = { slug: 'convertir-image', name: 'Convertir une image', category: 'images', operation: 'convert', enabled: true, status: 'available', description: 'Images', source_formats: ['jpg', 'png', 'webp'], target_formats: ['jpg', 'png', 'webp'], planned_formats: [], image_limits: { max_pixels: 6000000, max_dimension: 8192, max_batch_files: 5 } };
  const mock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
    if (init?.method === 'POST') return new Response(JSON.stringify({ data: [{ id: 'job1' }] }), { status: 202 });
    if (String(url).includes('/jobs/')) return new Response(JSON.stringify({ data: { id: 'job1', name: 'photo.png', status: 'completed', progress: 100, input_size: 100, output_size: 80, reduction_percent: 20, download_available: true } }));
    if (String(url).includes('/me/usage')) return new Response(JSON.stringify({ data: { limits: { max_file_bytes: 25000000, hourly_jobs: 5, daily_jobs: 10, retention_hours: 1 }, used: { daily_jobs: 0 } } }));
    return new Response(JSON.stringify({ data: [tool] }));
  });
  const result = render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={['/outils/convertir-image']}><App /></MemoryRouter></QueryClientProvider>);
  return { mock, ...result };
}

it('uploads selected images as multipart and shows measured results and a controlled download', async () => {
  const { mock, container } = mount();
  const user = userEvent.setup();
  await waitFor(() => expect(screen.getByRole('button', { name: 'Sélectionner des fichiers' })).toBeEnabled());
  const file = new File(['png fixture'], 'photo.png', { type: 'image/png' });
  await user.upload(container.querySelector('input[type="file"]')!, file);
  await user.click(screen.getByRole('button', { name: 'Traiter 1 image' }));
  expect(await screen.findByRole('link', { name: 'Télécharger' })).toHaveAttribute('href', '/api/v1/jobs/job1/download');
  expect(screen.getByText(/Réduction de 20 %/)).toBeInTheDocument();
  const post = mock.mock.calls.find(([, init]) => init?.method === 'POST')!;
  const data = post[1]!.body as FormData;
  expect(data.get('tool')).toBe('convertir-image');
  expect(data.getAll('files[]')).toHaveLength(1);
  expect(data.get('options[quality]')).toBe('82');
});

it('allows removing a selected file before upload', async () => {
  const { container } = mount();
  await waitFor(() => expect(screen.getByRole('button', { name: 'Sélectionner des fichiers' })).toBeEnabled());
  const user = userEvent.setup();
  await user.upload(container.querySelector('input[type="file"]')!, new File(['png'], 'photo.png', { type: 'image/png' }));
  await user.click(screen.getByRole('button', { name: 'Retirer photo.png' }));
  expect(screen.queryByText('photo.png')).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: /Traiter/ })).toBeDisabled();
});
