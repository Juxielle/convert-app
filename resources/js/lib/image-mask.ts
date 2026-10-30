// Select a connected region against the original colors, including removed pixels.
export function selectRegion(pixels: Uint8ClampedArray, width: number, height: number, x: number, y: number, tolerance: number): Uint8Array {
  const count = width * height;
  const selected = new Uint8Array(count);
  if (x < 0 || y < 0 || x >= width || y >= height) return selected;
  const seed = y * width + x;
  const offset = seed * 4;
  const threshold = 3 * (tolerance * 2.55) ** 2;
  const visited = new Uint8Array(count);
  const queue = new Int32Array(count);
  let head = 0, tail = 1;
  queue[0] = seed;
  visited[seed] = 1;
  const enqueue = (neighbor: number) => {
    if (neighbor >= 0 && neighbor < count && !visited[neighbor]) {
      visited[neighbor] = 1;
      queue[tail++] = neighbor;
    }
  };
  while (head < tail) {
    const index = queue[head++];
    const p = index * 4;
    const distance = (pixels[p] - pixels[offset]) ** 2 + (pixels[p + 1] - pixels[offset + 1]) ** 2 + (pixels[p + 2] - pixels[offset + 2]) ** 2;
    if (distance > threshold) continue;
    selected[index] = 1;
    const column = index % width;
    if (column > 0) enqueue(index - 1);
    if (column < width - 1) enqueue(index + 1);
    enqueue(index - width);
    enqueue(index + width);
  }
  return selected;
}

export function applyRegion(current: Uint8ClampedArray, original: Uint8ClampedArray, selection: Uint8Array, restore: boolean): void {
  for (let i = 0; i < selection.length; i++) {
    if (selection[i]) current[i * 4 + 3] = restore ? original[i * 4 + 3] : 0;
  }
}
