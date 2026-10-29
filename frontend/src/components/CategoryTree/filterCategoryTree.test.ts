import { describe, expect, test } from 'vitest'
import { filterCategoryTree } from './filterCategoryTree'
import { buildCategoryTreeNode as buildNode } from './buildCategoryTreeNode'
import { buildTestTree } from './buildTestTree'

const sorted = (ids: ReadonlySet<number> | undefined) => [...(ids ?? [])].sort((a, b) => a - b)

describe('filterCategoryTree', () => {
  test('TC-297-07: 深いノードに当たると、祖先を出して開き、当たらない兄弟は出さない', () => {
    const result = filterCategoryTree(buildTestTree(), 'びーふぃ')

    expect(sorted(result?.visibleIds)).toEqual([1, 11, 110, 1100, 11000])
    expect(sorted(result?.openIds)).toEqual([1, 11, 110, 1100])
  })

  test('TC-297-08: 「らむ」で「ラム」に当たる(カタカナとひらがなをそろえる)', () => {
    const result = filterCategoryTree(buildTestTree(), 'らむ')

    expect(result?.visibleIds.has(10)).toBe(true)
    expect(result?.visibleIds.has(11)).toBe(false)
  })

  test('TC-297-09: 英名でも当たり、全角の英数字と大文字・小文字をそろえる', () => {
    for (const query of ['ru', 'RU', 'ｒｕ', 'Ｒｕ']) {
      expect(filterCategoryTree(buildTestTree(), query)?.visibleIds.has(10), query).toBe(true)
    }
  })

  test('TC-297-10: 頭からの一致だけで当たり、途中の文字では当たらない', () => {
    const result = filterCategoryTree(buildTestTree(), 'む')

    expect(result?.visibleIds.size).toBe(0)
  })

  test('TC-297-11: 当たったノードの子孫は出すが、当たったノード自身は開かない', () => {
    const result = filterCategoryTree(buildTestTree(), 'ジン')

    expect(sorted(result?.visibleIds)).toEqual([1, 11, 110, 1100, 11000])
    expect(sorted(result?.openIds)).toEqual([1])
  })

  test('TC-297-12: 入力が空なら絞り込まない(null)', () => {
    expect(filterCategoryTree(buildTestTree(), '')).toBeNull()
  })

  test('TC-297-13: 英名の無いノードは名前だけで当たり、例外にならない', () => {
    const tree = [buildNode({ id: 1, name: 'ジュース', name_eng: null })]

    expect(sorted(filterCategoryTree(tree, 'じゅ')?.visibleIds)).toEqual([1])
    expect(sorted(filterCategoryTree(tree, 'ju')?.visibleIds)).toEqual([])
  })
})
