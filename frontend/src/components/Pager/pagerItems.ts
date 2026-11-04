/** ページを選ぶ部品(Pager)に並べるもの。ページの番号か、間を省いた印 */
export type PagerItem = { readonly type: 'page'; readonly page: number } | { readonly type: 'gap' }

/** いまのページの前後に出すページの数 */
const AROUND = 2

/**
 * ページを選ぶ部品に並べる番号。最初と最後のページ、いまのページとその前後 2 ページを出し、間が 2 ページ以上空くところは省く
 * (1 ページだけ空くときは、省く印より番号のほうが短いので番号を出す)。例: 12 ページ中の 6 ページ目なら 1 … 4 5 6 7 8 … 12。
 *
 * - 1 ページしか無いときは何も並べない(部品ごと出さない)
 * - いまのページが最後のページより後(URL で直接開いたとき)は、最後のページを中心に並べる
 */
export function pagerItems(current: number, last: number): PagerItem[] {
  if (last <= 1) return []
  const center = Math.min(Math.max(current, 1), last)
  const pages = new Set([1, last])
  for (let page = center - AROUND; page <= center + AROUND; page++) {
    if (page >= 1 && page <= last) pages.add(page)
  }
  const sorted = [...pages].sort((a, b) => a - b)

  const items: PagerItem[] = []
  sorted.forEach((page, index) => {
    const previous = sorted[index - 1]
    if (previous !== undefined && page - previous === 2) items.push({ type: 'page', page: previous + 1 })
    if (previous !== undefined && page - previous > 2) items.push({ type: 'gap' })
    items.push({ type: 'page', page })
  })
  return items
}
