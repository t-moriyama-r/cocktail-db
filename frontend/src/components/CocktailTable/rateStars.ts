/**
 * 評価を ★ の数で出す。評価の無いカクテル(null)と 0 は、★ が 1 つも無い空欄に見えないよう「-」にする(旧と同じ)。
 */
export function rateStars(rate: number | null): string {
  return rate ? '★'.repeat(rate) : '-'
}
