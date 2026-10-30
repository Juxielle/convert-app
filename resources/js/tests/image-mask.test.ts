import { applyRegion, selectRegion } from '../lib/image-mask';

it('selects only connected colors and does not cross a different colored subject', () => {
  const colors = new Uint8ClampedArray([255,255,255,255, 255,0,0,255, 255,255,255,255]);
  const region = selectRegion(colors, 3, 1, 0, 0, 10);
  expect([...region]).toEqual([1, 0, 0]);
  const result = colors.slice();
  applyRegion(result, colors, region, false);
  expect(result[3]).toBe(0);
  expect(result[7]).toBe(255);
  applyRegion(result, colors, region, true);
  expect(result).toEqual(colors);
});

it('tolerance expands the region and preserves original transparency on restoration', () => {
  const colors = new Uint8ClampedArray([255,255,255,100, 240,240,240,255]);
  expect([...selectRegion(colors, 2, 1, 0, 0, 0)]).toEqual([1,0]);
  const region = selectRegion(colors, 2, 1, 0, 0, 10);
  expect([...region]).toEqual([1,1]);
  const result = colors.slice(); applyRegion(result, colors, region, false); applyRegion(result, colors, region, true);
  expect(result[3]).toBe(100);
  expect([...selectRegion(colors, 2, 1, -1, 0, 10)]).toEqual([0,0]);
});
