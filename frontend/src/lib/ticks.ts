/** Clean axis ticks from 0 to at least `max` (a whole-number count): steps of 1, 2 or 5 times a power of ten. */
export function ticks(max: number): number[] {
  if (max <= 0) return [0, 1]
  const rough = max / 4
  const power = 10 ** Math.floor(Math.log10(rough))
  // Counts are whole things (dealers, machines), so never a step under 1.
  const step = Math.max(1, [1, 2, 5, 10].map((m) => m * power).find((s) => s >= rough) ?? power * 10)
  const out: number[] = []
  for (let v = 0; v < max + step; v += step) out.push(v)
  return out
}
