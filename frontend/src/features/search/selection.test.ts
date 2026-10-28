import { describe, expect, test } from 'vitest'
import { buildLargeSearchTree, LARGE_SEARCH_TREE_SIZE } from './buildLargeSearchTree'
import {
  buildSelectionTree,
  checkStates,
  emptySelection,
  selectionFromIds,
  toggleNode,
  toSendGroups,
  toSendIds,
  type SelectionTreeNode,
  type TreeSelection,
} from './selection'

const node = (id: number, children: SelectionTreeNode[] = []): SelectionTreeNode => ({ id, children })

/** 洋酒(1) > ラム(10) > ホワイトラム(100)・ダークラム(101)・ゴールドラム(102)、洋酒 > ジン(11) */
const rumTree = () => buildSelectionTree([node(1, [node(10, [node(100), node(101), node(102)]), node(11)])])

/**
 * 深さ 5(根を 1 段目と数える)の葉 10000 まで、各段に兄弟がある木。
 * 1 > 10・11、10 > 100・101、100 > 1000・1001、1000 > 10000・10001。もう 1 つの根 2 > 20・21
 */
const deepTree = () =>
  buildSelectionTree([
    node(1, [node(10, [node(100, [node(1000, [node(10000), node(10001)]), node(1001)]), node(101)]), node(11)]),
    node(2, [node(20), node(21)]),
  ])

const sorted = (ids: Iterable<number>) => [...ids].sort((a, b) => a - b)

/** 画面の URL(#102 の追記の形)。材料のほかに、検索の方法と技法 6 つも入れて長めに見る */
const searchUrl = (ids: readonly number[]) =>
  `https://cocktail-next.trc-vlackhistory.net/result/Cocktails?n=${ids.join(',')}&mode=having&m=1,2,3,4,5,6`

/** 決まった種から作る乱数(テストを毎回同じにする) */
const random = (seed: number) => () => {
  seed = (seed * 1103515245 + 12345) % 2147483648
  return seed / 2147483648
}

describe('selection', () => {
  test('TC-304-01: 親を選ぶ → 子を 1 つ外す → 戻す、で送る ID が 親 → 兄弟 → 親', () => {
    const tree = rumTree()

    const parent = toggleNode(tree, emptySelection, 10, true)
    const removed = toggleNode(tree, parent, 102, false)
    const restored = toggleNode(tree, removed, 102, true)

    expect(toSendIds(tree, parent)).toEqual([10])
    expect(toSendIds(tree, removed)).toEqual([100, 101])
    expect(toSendIds(tree, restored)).toEqual([10])
  })

  test('TC-304-02: 親を選ぶと子孫もチェック、子を 1 つ外すと親と祖先は「一部」、戻すとチェックに戻る', () => {
    const tree = rumTree()
    const parent = toggleNode(tree, emptySelection, 10, true)
    const removed = toggleNode(tree, parent, 102, false)

    const parentStates = checkStates(tree, parent)
    expect([10, 100, 101, 102].map((id) => parentStates.get(id))).toEqual(['checked', 'checked', 'checked', 'checked'])
    expect(parentStates.get(1)).toBe('partial')
    expect(parentStates.get(11)).toBe('none')

    const removedStates = checkStates(tree, removed)
    expect([1, 10, 100, 101, 102].map((id) => removedStates.get(id))).toEqual([
      'partial',
      'partial',
      'checked',
      'checked',
      'none',
    ])

    expect(checkStates(tree, toggleNode(tree, removed, 102, true)).get(10)).toBe('checked')
  })

  test('TC-304-03: 親を選んで子をすべて外すと、親のチェックも外れ、何も送らない', () => {
    const tree = rumTree()
    let selection = toggleNode(tree, emptySelection, 10, true)
    for (const child of [100, 101, 102]) selection = toggleNode(tree, selection, child, false)

    expect(checkStates(tree, selection).get(10)).toBe('none')
    expect(toSendIds(tree, selection)).toEqual([])
  })

  test('TC-304-04: 深さ 5 の葉を選ぶと、葉だけを送り、祖先はすべて「一部」', () => {
    const tree = deepTree()
    const selection = toggleNode(tree, emptySelection, 10000, true)

    expect(toSendIds(tree, selection)).toEqual([10000])
    const states = checkStates(tree, selection)
    expect([1, 10, 100, 1000].map((id) => states.get(id))).toEqual(['partial', 'partial', 'partial', 'partial'])
    expect(states.get(10000)).toBe('checked')
    expect(toSendIds(tree, toggleNode(tree, selection, 10000, false))).toEqual([])
  })

  test('TC-304-05: 根を選んで深さ 5 の葉を外すと、各段で残った兄弟に置き換わる', () => {
    const tree = deepTree()
    const root = toggleNode(tree, emptySelection, 1, true)
    const removed = toggleNode(tree, root, 10000, false)

    expect(toSendIds(tree, root)).toEqual([1])
    expect(toSendIds(tree, removed)).toEqual([10001, 1001, 101, 11])
    expect(toSendIds(tree, toggleNode(tree, removed, 10000, true))).toEqual([1])
  })

  test('TC-304-06: すべてのノードを選ぶと、根だけになる', () => {
    const tree = deepTree()
    const all = selectionFromIds(tree, tree.leavesOf.keys())

    expect(toSendIds(tree, all)).toEqual([1, 2])
    expect(new Set(checkStates(tree, all).values())).toEqual(new Set(['checked']))
  })

  test('TC-304-07: 約 600 ノードの木ですべて選ぶと根だけになり、URL が 2,000 文字以内', () => {
    const tree = buildSelectionTree(buildLargeSearchTree())
    expect(tree.leavesOf.size).toBe(LARGE_SEARCH_TREE_SIZE.total)

    const ids = toSendIds(tree, selectionFromIds(tree, tree.leavesOf.keys()))

    expect(ids).toHaveLength(LARGE_SEARCH_TREE_SIZE.roots)
    expect(searchUrl(ids).length).toBeLessThanOrEqual(2000)
  })

  test('TC-304-08: 畳めない ID が「全てチェック」で見えるノードの数(旧で 76)だけ残っても、URL が 2,000 文字以内', () => {
    const tree = buildSelectionTree(buildLargeSearchTree())
    // ID の大きいミドルから、同じカテゴリで 2 つまで選ぶ(どのカテゴリもすべては選ばれず、畳まれない)
    const picked: number[] = []
    for (const category of buildLargeSearchTree().flatMap((root) => root.children)) {
      picked.push(...category.children.slice(-2).map((child) => child.id))
    }
    const leaves = sorted(picked).slice(-76)

    const ids = toSendIds(tree, selectionFromIds(tree, leaves))

    expect(ids).toHaveLength(76)
    expect(searchUrl(ids).length).toBeLessThanOrEqual(2000)
  })

  test('TC-304-09: 送る ID → 状態 → 送る ID で元に戻り、送る ID は選んだ葉の数を超えない', () => {
    const tree = buildSelectionTree(buildLargeSearchTree())
    const allIds = [...tree.leavesOf.keys()]
    const next = random(304)

    for (let round = 0; round < 200; round++) {
      // 根・カテゴリ・ミドルを混ぜて入れ外しし、畳まれるもの・兄弟に置き換わるものを両方作る
      let selection: TreeSelection = emptySelection
      for (let step = 0; step < 1 + Math.floor(next() * 30); step++) {
        selection = toggleNode(tree, selection, allIds[Math.floor(next() * allIds.length)], next() < 0.7)
      }

      const ids = toSendIds(tree, selection)
      const restored = selectionFromIds(tree, ids)

      expect(sorted(restored)).toEqual(sorted(selection))
      expect(toSendIds(tree, restored)).toEqual(ids)
      expect(ids.length).toBeLessThanOrEqual(selection.size)
    }
  })

  test('TC-304-10: 木に無い ID・重複・祖先と子孫の組を入れても例外にならず、捨てる・1 つにする・祖先に含める', () => {
    const tree = rumTree()

    const selection = selectionFromIds(tree, [999, 10, 10, 100, 102, -1, Number.NaN])

    expect(sorted(selection)).toEqual([100, 101, 102])
    expect(toSendIds(tree, selection)).toEqual([10])
    expect(toSendIds(tree, selectionFromIds(tree, [999]))).toEqual([])
    expect(toSendIds(tree, selectionFromIds(tree, []))).toEqual([])
  })

  test('TC-304-11: 木に無い ID の入れ外しは、状態を変えない', () => {
    const tree = rumTree()
    const selection = toggleNode(tree, emptySelection, 100, true)

    expect(toggleNode(tree, selection, 999, true)).toBe(selection)
    expect(toggleNode(tree, selection, 999, false)).toBe(selection)
  })

  test('TC-304-12: 子が 1 つしか無い親は、その子を選ぶと親もチェックになり、親に畳む', () => {
    const tree = buildSelectionTree([node(1, [node(10, [node(100, [node(1000)])]), node(11)])])
    const selection = toggleNode(tree, emptySelection, 1000, true)

    expect(toSendIds(tree, selection)).toEqual([10])
    expect([10, 100, 1000].map((id) => checkStates(tree, selection).get(id))).toEqual(['checked', 'checked', 'checked'])
  })

  test('TC-304-13: 深い木で親を選んで子を外すと、残った兄弟は根の直下のノードごとの 1 つのまとまりになる', () => {
    // 洋酒(1) > ウイスキー(10) > スコッチ(100) > シングルモルト(1000)・ブレンデッド(1001)、ウイスキー > バーボン(101)
    const tree = buildSelectionTree([node(1, [node(10, [node(100, [node(1000), node(1001)]), node(101)])])])
    const withoutBlended = toggleNode(tree, toggleNode(tree, emptySelection, 10, true), 1001, false)

    expect(toSendIds(tree, withoutBlended)).toEqual([1000, 101])
    expect(toSendGroups(tree, withoutBlended)).toEqual([[1000, 101]])
  })

  test('TC-304-14: 根の直下のノードが同じなら 1 つ、違えば別のまとまり。根を送るときは根 1 つ', () => {
    // 洋酒(1) > スピリッツ(10) > ラム(100) > ホワイトラム(1000)・ダークラム(1001)、スピリッツ > ジン(101) > ドライジン(1010)・トニック用(1011)
    // 洋酒 > リキュール(11) > 杏(110)・桃(111)。もう 1 つの根 2 > 20・21
    const tree = buildSelectionTree([
      node(1, [node(10, [node(100, [node(1000), node(1001)]), node(101, [node(1010), node(1011)])]), node(11, [node(110), node(111)])]),
      node(2, [node(20), node(21)]),
    ])
    const pick = (...ids: number[]) => selectionFromIds(tree, ids)

    // ホワイトラムとドライジンは、根の直下のノード(スピリッツ)が同じなので 1 つのまとまり
    expect(toSendGroups(tree, pick(1000, 1010))).toEqual([[1000, 1010]])
    // スピリッツの配下とリキュールの配下は別のまとまり
    expect(toSendGroups(tree, pick(1000, 110))).toEqual([[1000], [110]])
    // 根の直下のノードごとに別のまとまりになる(根が違っても)
    expect(toSendGroups(tree, pick(1000, 110, 20))).toEqual([[1000], [110], [20]])
    // 根の直下のノードを丸ごと選べば、そのノード 1 つ
    expect(toSendGroups(tree, pick(1000, 1001, 1010, 1011))).toEqual([[10]])
    // 木をすべて選ぶと根 1 つずつ
    expect(toSendGroups(tree, selectionFromIds(tree, tree.leavesOf.keys()))).toEqual([[1], [2]])
    expect(toSendGroups(tree, emptySelection)).toEqual([])
  })

  test('TC-304-15: まとまりを平らにすると送る ID と同じ集合で、約 600 ノードの木でも根の直下のノードごとに分かれる', () => {
    const roots = buildLargeSearchTree()
    const tree = buildSelectionTree(roots)
    const middles = roots.flatMap((root) => root.children).flatMap((category) => category.children.slice(0, 1))
    const selection = selectionFromIds(tree, middles.map((middle) => middle.id))

    const groups = toSendGroups(tree, selection)

    expect(sorted(groups.flat())).toEqual(sorted(toSendIds(tree, selection)))
    expect(groups).toHaveLength(middles.length)
    for (const group of groups) expect(new Set(group.map((id) => tree.groupOf.get(id))).size).toBe(1)
  })
})
