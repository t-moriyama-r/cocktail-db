import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest'
import { defaultSearchFormValues, type SearchFormValues } from './searchForm'
import { buildSelectionTree, selectionFromIds, type SelectionTreeNode } from './selection'
import { hasSavedSearch, loadSearch, saveSearch, SEARCH_STORAGE_KEYS } from './searchStorage'

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

beforeEach(() => localStorage.clear())
afterEach(() => {
  vi.restoreAllMocks()
  localStorage.clear()
})

describe('searchStorage', () => {
  test('TC-104-07: 保存した値を読むと、検索方法・かんたん検索・技法・材料が同じフォームの値に戻る。保存先ごとに別に持つ', () => {
    const saved = values({ nodes: [100, 30], mode: 'having', isEasy: true, uncheckedMethodIds: new Set([2, 6]) })
    expect(saveSearch('user', tree, methods, saved)).toBe(true)
    saveSearch('instant', tree, methods, values({ nodes: [11] }))

    const user = loadSearch('user', tree, methods)
    expect(user?.mode).toBe('having')
    expect(user?.isEasy).toBe(true)
    expect([...(user?.uncheckedMethodIds ?? [])].sort()).toEqual([2, 6])
    expect([...(user?.selection ?? [])].sort((a, b) => a - b)).toEqual([30, 100])
    expect([...(loadSearch('instant', tree, methods)?.selection ?? [])]).toEqual([11])
  })

  test('TC-104-08: 新しいキーは版を入れた名前で、旧のキー(searchInstant・searchUser)は読まず、書き換えない', () => {
    localStorage.setItem('searchUser', '{"searchMethod":"or","methods":[1],"categories":[11],"middles":[],"is_easily":false}')
    localStorage.setItem('searchInstant', '{"searchMethod":"and"}')

    expect(hasSavedSearch('user')).toBe(false)
    expect(hasSavedSearch('instant')).toBe(false)
    expect(loadSearch('user', tree, methods)).toBeNull()

    saveSearch('user', tree, methods, values({ nodes: [11] }))
    expect(SEARCH_STORAGE_KEYS).toEqual({ instant: 'cocktail-db.search.instant.v1', user: 'cocktail-db.search.user.v1' })
    expect(localStorage.getItem('cocktail-db.search.user.v1')).toBe('n=11')
    expect(localStorage.getItem('searchUser')).toBe('{"searchMethod":"or","methods":[1],"categories":[11],"middles":[],"is_easily":false}')
  })

  test('TC-104-09: 子がすべて選ばれた親は親 1 つに畳んで保存し、技法がすべてなら技法を書かない', () => {
    saveSearch('user', tree, methods, values({ nodes: [100, 101, 30], mode: 'and' }))
    expect(localStorage.getItem(SEARCH_STORAGE_KEYS.user)).toBe('n=10,30&mode=and')
  })

  test('TC-104-10: 技法がすべてチェックで保存した後に技法が増えても、読むと増えた技法もチェックが入る', () => {
    saveSearch('user', tree, methods, values({ nodes: [11] }))
    const loaded = loadSearch('user', tree, [...methods, { id: 7 }])
    expect(loaded?.uncheckedMethodIds.size).toBe(0)
  })

  test('TC-104-11: 保存した値の、木に無いノード・無い技法・不正な値は捨てて読む', () => {
    localStorage.setItem(SEARCH_STORAGE_KEYS.user, 'n=11,999,abc&mode=xor&easy=yes&m=1,99')

    const loaded = loadSearch('user', tree, methods)
    expect([...(loaded?.selection ?? [])]).toEqual([11])
    expect(loaded?.mode).toBe('or')
    expect(loaded?.isEasy).toBe(false)
    expect([...(loaded?.uncheckedMethodIds ?? [])].sort()).toEqual([2, 3, 4, 5, 6])
  })

  test('TC-104-12: 壊れた値(JSON など URL の形でないもの)でも例外を投げず、既定値として読む', () => {
    localStorage.setItem(SEARCH_STORAGE_KEYS.instant, '{"broken":')

    expect(() => loadSearch('instant', tree, methods)).not.toThrow()
    const loaded = loadSearch('instant', tree, methods)
    expect(loaded?.mode).toBe('or')
    expect(loaded?.selection.size).toBe(0)
  })

  test('TC-104-13: localStorage が例外を投げる環境では、保存は false を返し、保存が無いものとして扱う', () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('QuotaExceededError')
    })
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('SecurityError')
    })

    expect(saveSearch('user', tree, methods, values({ nodes: [11] }))).toBe(false)
    expect(hasSavedSearch('user')).toBe(false)
    expect(loadSearch('user', tree, methods)).toBeNull()
  })
})
