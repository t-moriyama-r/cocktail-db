import { describe, expect, test } from 'vitest'
import { buildLargeSearchTree } from './buildLargeSearchTree'
import { defaultSearchFormValues, toSearchConditions, type SearchFormValues } from './searchForm'
import {
  defaultSearchQuery,
  formValuesFromQuery,
  formatSearchQuery,
  parseSearchQuery,
  searchQueryFromConditions,
  searchRequestBody,
  searchResultUrl,
} from './searchQuery'
import { buildSelectionTree, selectionFromIds, type SelectionTreeNode } from './selection'

const node = (id: number, children: SelectionTreeNode[] = []): SelectionTreeNode => ({ id, children })

/** 蒸留酒(1) > ラム(10) > ホワイトラム(100)・ダークラム(101)、ジン(11) / ソフトドリンク(3) > トニックウォーター(30)・オレンジジュース(31) */
const tree = buildSelectionTree([
  node(1, [node(10, [node(100), node(101)]), node(11)]),
  node(3, [node(30), node(31)]),
])

/** 旧と同じ 6 つの技法 */
const methods = [1, 2, 3, 4, 5, 6].map((id) => ({ id }))

const values = (overrides: Partial<SearchFormValues> & { nodes?: number[] }): SearchFormValues => ({
  ...defaultSearchFormValues,
  ...overrides,
  selection: selectionFromIds(tree, overrides.nodes ?? []),
})

/** フォームの値から、検索ボタンで移る URL を作る */
const urlOf = (formValues: SearchFormValues) =>
  searchResultUrl(searchQueryFromConditions(tree, methods, toSearchConditions(methods, formValues)))

describe('searchQuery', () => {
  describe('条件 → URL', () => {
    test('TC-102-01: 材料だけ選んだら n だけを書き、既定値(OR 検索・かんたん検索なし・技法はすべて・1 ページ目)は書かない', () => {
      expect(urlOf(values({ nodes: [11] }))).toBe('/result/Cocktails?n=11')
    })

    test('TC-102-02: 検索方法・かんたん検索・技法をクエリに書く。ID はカンマ区切りで、%2C にしない', () => {
      const formValues = values({ nodes: [100, 30], mode: 'having', isEasy: true, uncheckedMethodIds: new Set([2, 4, 5, 6]) })
      expect(urlOf(formValues)).toBe('/result/Cocktails?n=100,30&mode=having&easy=1&m=1,3')
    })

    test('TC-102-03: 技法をすべて外したら空の m を書き、読むと技法はすべて外れたフォームに戻る', () => {
      const url = urlOf(values({ nodes: [11], uncheckedMethodIds: new Set([1, 2, 3, 4, 5, 6]) }))
      expect(url).toBe('/result/Cocktails?n=11&m=')

      const restored = formValuesFromQuery(tree, methods, parseSearchQuery(url.split('?')[1]))
      expect([...restored.uncheckedMethodIds]).toEqual([1, 2, 3, 4, 5, 6])
    })

    test('TC-102-04: 子がすべて選ばれた親は、親 1 つに畳んで書く。木がすべて選ばれたら根だけ', () => {
      expect(urlOf(values({ nodes: [100, 101] }))).toBe('/result/Cocktails?n=10')
      expect(urlOf(values({ nodes: [100, 101, 11, 30, 31] }))).toBe('/result/Cocktails?n=1,3')
    })

    test('TC-102-05: URL から戻したフォームの値で検索し直すと、同じ URL になる(往復で条件が変わらない)', () => {
      const original = '/result/Cocktails?n=100,11,30&mode=and&easy=1&m=2,5'
      const restored = formValuesFromQuery(tree, methods, parseSearchQuery(original.split('?')[1]))
      expect(urlOf(restored)).toBe(original)
    })

    test('TC-102-12: すべてのノードを選んでも、URL は 2,000 文字以内(実データと同じ約 600 ノード・5 桁の ID の木)', () => {
      const large = buildSelectionTree(buildLargeSearchTree())
      const everything = selectionFromIds(large, large.roots.map((root) => root.id))
      const query = searchQueryFromConditions(large, methods, {
        mode: 'having',
        isEasy: true,
        methodIds: [1, 3],
        selection: everything,
      })
      const url = `https://example.com${searchResultUrl({ ...query, sort: { key: 'recipe_count', isAsc: false }, page: 99 })}`

      expect(url.length).toBeLessThanOrEqual(2000)
      expect(query.nodeIds).toEqual(large.roots.map((root) => root.id))
    })

    test('TC-102-21: 並べ替えとページを書く。1 ページ目は書かない', () => {
      expect(formatSearchQuery({ ...defaultSearchQuery, nodeIds: [11], sort: { key: 'strength', isAsc: false }, page: 2 })).toBe(
        'n=11&sort=strength&order=desc&page=2',
      )
      expect(formatSearchQuery({ ...defaultSearchQuery, nodeIds: [11], sort: { key: 'name', isAsc: true }, page: 1 })).toBe(
        'n=11&sort=name&order=asc',
      )
    })
  })

  describe('URL → 条件', () => {
    test('TC-102-06: 数字でない ID(文字・負の数・小数・0・空)は無視し、ほかの ID は残す。重複は 1 つにする', () => {
      expect(parseSearchQuery('?n=abc,12,-3,1.5,0,,12,45').nodeIds).toEqual([12, 45])
      expect(parseSearchQuery('?n=x').nodeIds).toEqual([])
      expect(parseSearchQuery('').nodeIds).toEqual([])
    })

    test.each([
      ['mode=and', 'and'],
      ['mode=having', 'having'],
      ['mode=xor', 'or'],
      ['mode=AND', 'or'],
      ['mode=', 'or'],
    ])('TC-102-07: %s は検索方法 %s(知らない mode は OR 検索)', (search, mode) => {
      expect(parseSearchQuery(search).mode).toBe(mode)
    })

    test.each([
      ['page=2', 2],
      ['page=1', 1],
      ['page=0', 1],
      ['page=-1', 1],
      ['page=1.5', 1],
      ['page=abc', 1],
      ['page=99999999999999999999', 1],
    ])('TC-102-08: %s はページ %i(1 より小さい・整数でないページは 1 ページ目)', (search, page) => {
      expect(parseSearchQuery(search).page).toBe(page)
    })

    test.each([
      ['sort=name&order=desc', { key: 'name', isAsc: false }],
      ['sort=rate&order=asc', { key: 'rate', isAsc: true }],
      // 向きが無い・不正なら、見出しを初めて押したときの向き(甘さ・評価・度数・動画は降順、ほかは昇順)
      ['sort=rate', { key: 'rate', isAsc: false }],
      ['sort=name&order=up', { key: 'name', isAsc: true }],
      ['sort=price&order=asc', null],
      ['sort=id&order=desc', null],
      ['order=desc', null],
    ])('TC-102-09: %s は並び %j(知らない列は既定の並び)', (search, sort) => {
      expect(parseSearchQuery(search).sort).toEqual(sort)
    })

    test.each([
      ['', null],
      ['m=1,3', [1, 3]],
      ['m=', []],
      ['m=abc', null],
      ['m=abc,2', [2]],
    ])('TC-102-10: 「%s」は技法 %j(null はすべて。値があるのに 1 つも読めなければすべて)', (search, methodIds) => {
      expect(parseSearchQuery(search).methodIds).toEqual(methodIds)
    })

    test('TC-102-22: かんたん検索は easy=1 のときだけ', () => {
      expect(parseSearchQuery('easy=1').isEasy).toBe(true)
      expect(parseSearchQuery('easy=0').isEasy).toBe(false)
      expect(parseSearchQuery('easy=true').isEasy).toBe(false)
      expect(parseSearchQuery('').isEasy).toBe(false)
    })

    test('TC-102-11: フォームの値に戻すとき、木に無いノードの ID と、無い技法の ID は捨てる', () => {
      const restored = formValuesFromQuery(tree, methods, parseSearchQuery('n=999,11&m=1,77'))
      expect([...restored.selection]).toEqual([11])
      expect([...restored.uncheckedMethodIds]).toEqual([2, 3, 4, 5, 6])
    })
  })

  describe('検索 API に送る本文', () => {
    test('TC-102-13: OR・あるもの検索は category_ids、AND 検索は根の直下のノードごとのまとまり(category_groups)で送る', () => {
      const selection = selectionFromIds(tree, [100, 11, 30])
      const conditions = { isEasy: false, methodIds: [1, 2], selection }

      expect(searchRequestBody(tree, { ...conditions, mode: 'or' }, null, 1)).toEqual({
        mode: 'or',
        category_ids: [100, 11, 30],
        method_ids: [1, 2],
        is_easy: false,
        page: 1,
      })
      expect(searchRequestBody(tree, { ...conditions, mode: 'and' }, { key: 'rate', isAsc: false }, 3)).toEqual({
        mode: 'and',
        category_groups: [[100], [11], [30]],
        method_ids: [1, 2],
        is_easy: false,
        sort: 'rate',
        order: 'desc',
        page: 3,
      })
    })
  })
})
