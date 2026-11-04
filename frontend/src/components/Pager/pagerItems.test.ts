import { describe, expect, test } from 'vitest'
import { pagerItems, type PagerItem } from './pagerItems'

/** 並べるものを `1 … 3 4 5` の形の文字にする(読みやすくするため) */
const text = (items: PagerItem[]) => items.map((item) => (item.type === 'gap' ? '…' : String(item.page))).join(' ')

describe('ページを選ぶ部品に並べる番号(pagerItems)', () => {
  test.each([
    ['1 ページだけ', 1, 1, ''],
    ['2 ページの 1 ページ目', 1, 2, '1 2'],
    ['7 ページの 1 ページ目(前後 2 ページと最後の間は省く)', 1, 7, '1 2 3 … 7'],
    ['7 ページの 4 ページ目(最初・最後と 1 ページだけ空くところは番号を出す)', 4, 7, '1 2 3 4 5 6 7'],
    ['10 ページの 5 ページ目(最初と 1 ページだけ空くので番号を出す)', 5, 10, '1 2 3 4 5 6 7 … 10'],
    ['12 ページの 6 ページ目(前と後ろの両方を省く)', 6, 12, '1 … 4 5 6 7 8 … 12'],
    ['10 ページの 10 ページ目', 10, 10, '1 … 8 9 10'],
    ['10 ページで、最後より後の 12 ページ目', 12, 10, '1 … 8 9 10'],
  ])('TC-103-01: %s(%i ページ目・全 %i ページ)は「%s」', (_, current, last, expected) => {
    expect(text(pagerItems(current, last))).toBe(expected)
  })
})
