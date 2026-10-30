import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, vi } from 'vitest';
import BackgroundTool from '../pages/BackgroundTool';
import type { Tool } from '../lib/api';

const tool = { slug: 'supprimer-fond', name: 'Enlever le fond d’une image', operation: 'remove-background', category: 'images', enabled: true, target_formats: ['png'] } as Tool;

afterEach(() => vi.unstubAllGlobals());

function mount() {
  class Pixels {
    constructor(public data: Uint8ClampedArray, public width: number, public height: number) {}
  }
  vi.stubGlobal('ImageData', Pixels);
  vi.stubGlobal('PointerEvent', MouseEvent);
  vi.stubGlobal('Image', class { naturalWidth = 3; naturalHeight = 1; src = ''; decode() { return Promise.resolve(); } });
  vi.stubGlobal('URL', { createObjectURL: vi.fn(() => 'blob:test'), revokeObjectURL: vi.fn() });
  const context = { drawImage: vi.fn(), putImageData: vi.fn(), getImageData: vi.fn(() => new Pixels(new Uint8ClampedArray([255,255,255,255, 255,0,0,255, 255,255,255,255]), 3, 1)) };
  vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(context as unknown as CanvasRenderingContext2D);
  vi.spyOn(HTMLCanvasElement.prototype, 'getBoundingClientRect').mockReturnValue({ left: 0, top: 0, width: 300, height: 100 } as DOMRect);
  const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, init) => {
    if (init?.method === 'POST') return new Response(JSON.stringify({ data: { id: 'bg1', status: 'queued' } }), { status: 202 });
    if (String(url).includes('/jobs/')) return new Response(JSON.stringify({ data: { id: 'bg1', status: 'processing', progress: 20, download_available: false } }));
    return new Response(JSON.stringify({ data: { limits: { max_file_bytes: 25000000, daily_jobs: 10, retention_hours: 1 }, used: { daily_jobs: 0 } } }));
  });
  const view = render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter><BackgroundTool tool={tool} /></MemoryRouter></QueryClientProvider>);
  return { ...view, context, fetchMock };
}

it('previews a connected selection and can erase, undo and restore it without an API call', async () => {
  const { container, context, fetchMock } = mount();
  const user = userEvent.setup();
  await waitFor(() => expect(screen.getByRole('button', { name: 'Sélectionner des fichiers' })).toBeEnabled());
  await user.upload(container.querySelector('input[type=file]')!, new File(['png'], 'photo.png', { type: 'image/png' }));
  await screen.findByText('photo.png');
  fireEvent.pointerDown(container.querySelector('canvas')!, { clientX: 10, clientY: 10, button: 0 });
  expect(screen.getByText('1 pixel sélectionné')).toBeInTheDocument();
  await user.click(screen.getByRole('button', { name: 'Supprimer la sélection' }));
  expect(context.putImageData.mock.lastCall![0].data[3]).toBe(0);
  expect(context.putImageData.mock.lastCall![0].data[7]).toBe(255);
  await user.click(screen.getByRole('button', { name: 'Annuler la retouche' }));
  expect(context.putImageData.mock.lastCall![0].data[3]).toBe(255);
  expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(false);
});

it('submits the original image as PNG output to the queued automatic tool', async () => {
  const { container, fetchMock } = mount();
  const user = userEvent.setup();
  await waitFor(() => expect(screen.getByRole('button', { name: 'Sélectionner des fichiers' })).toBeEnabled());
  await user.upload(container.querySelector('input[type=file]')!, new File(['png'], 'photo.png', { type: 'image/png' }));
  await screen.findByText('photo.png');
  await user.click(screen.getByRole('button', { name: 'Enlever le fond automatiquement' }));
  await waitFor(() => expect(screen.getByRole('button', { name: 'Détourage en cours…' })).toBeDisabled());
  const post = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST')!;
  const data = post[1]!.body as FormData;
  expect(data.get('tool')).toBe('supprimer-fond');
  expect(data.get('target_format')).toBe('png');
  expect((data.get('file') as File).name).toBe('photo.png');
});
