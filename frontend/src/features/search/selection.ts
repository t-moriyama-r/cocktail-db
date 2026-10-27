import type { CategoryTreeCheckState } from '../../components/CategoryTree/CategoryTree.types'

/**
 * 検索の材料の木で、選択の規則(#161 の決まったこと 3 の P・#282 の決まったこと 2)を扱う純関数。
 * 画面(#101)・URL(#102)・保存(#104)・旧の保存値(#175)が同じ規則を使う。
 *
 * 選択の状態は「選ばれた葉の ID の集合」で持つ。子のあるノードは、配下の葉がすべて選ばれていればチェック、
 * 一部なら「一部」、1 つも無ければ「なし」と見なす(ふつうの三状態のチェックボックス)。
 * そのため、子を最後の 1 つまで外すと親のチェックも外れる(#282 の 2)。
 */

/** 木の 1 ノード。API の型(#95)や CategoryTreeNode をそのまま渡せるよう、使うものだけにしている */
export type SelectionTreeNode = {
  readonly id: number
  readonly children: readonly SelectionTreeNode[]
}

/** 木を引きやすい形にしたもの。木が変わらないあいだは作り直さずに使い回す */
export type SelectionTree = {
  readonly roots: readonly SelectionTreeNode[]
  /** ノードの ID → 配下の葉の ID(葉なら自分だけ) */
  readonly leavesOf: ReadonlyMap<number, readonly number[]>
}

/** 選択の状態。選ばれた葉の ID の集合 */
export type TreeSelection = ReadonlySet<number>

/**
 * 木から SelectionTree を作る。木が深くても呼び出しが積み上がらないよう、再帰せずにたどる。
 * 木の中で ID は重ならない前提(カテゴリ木の ID は一意)
 */
export function buildSelectionTree(roots: readonly SelectionTreeNode[]): SelectionTree {
  const leavesOf = new Map<number, readonly number[]>()
  // 行きがけに積み、帰りがけ(子をすべて済ませた後)に子の葉を集める
  const stack = roots.map((node) => ({ node, visited: false })).reverse()
  for (let entry = stack.pop(); entry !== undefined; entry = stack.pop()) {
    const { node, visited } = entry
    if (!visited) {
      stack.push({ node, visited: true }, ...node.children.map((child) => ({ node: child, visited: false })).reverse())
      continue
    }
    const leaves = node.children.flatMap((child) => leavesOf.get(child.id) ?? [])
    leavesOf.set(node.id, leaves.length === 0 ? [node.id] : leaves)
  }
  return { roots, leavesOf }
}

/** 何も選んでいない状態 */
export const emptySelection: TreeSelection = new Set()

/**
 * ノードのチェックを入れる(checked = true)・外す(false)操作の後の状態を返す。
 * 入れると配下の葉をすべて選び、外すと配下の葉をすべて外す。木に無い ID なら、状態を変えずに返す
 */
export function toggleNode(tree: SelectionTree, selection: TreeSelection, id: number, checked: boolean): TreeSelection {
  const leaves = tree.leavesOf.get(id)
  if (leaves === undefined) return selection
  const next = new Set(selection)
  for (const leaf of leaves) {
    if (checked) next.add(leaf)
    else next.delete(leaf)
  }
  return next
}

/** ノードごとの表示(チェック・一部・なし)。木にあるすべてのノードの分を返す */
export function checkStates(tree: SelectionTree, selection: TreeSelection): ReadonlyMap<number, CategoryTreeCheckState> {
  const states = new Map<number, CategoryTreeCheckState>()
  for (const [id, leaves] of tree.leavesOf) {
    const selected = leaves.filter((leaf) => selection.has(leaf)).length
    states.set(id, selected === 0 ? 'none' : selected === leaves.length ? 'checked' : 'partial')
  }
  return states
}

/**
 * 選択の状態から、送る ID を作る。根から見ていき、チェックのノードはそれ 1 つに畳んで配下を見ない。
 * 「一部」のノードは子を見る。結果として、親を選んで子を外すと残った兄弟が送られ(何段でも繰り返す)、
 * 部分木がすべて選ばれていれば親 1 つになる。並びは木の順(行きがけ順)
 */
export function toSendIds(tree: SelectionTree, selection: TreeSelection): number[] {
  const states = checkStates(tree, selection)
  const ids: number[] = []
  const stack = [...tree.roots].reverse()
  for (let node = stack.pop(); node !== undefined; node = stack.pop()) {
    const state = states.get(node.id)
    if (state === 'checked') ids.push(node.id)
    else if (state === 'partial') stack.push(...[...node.children].reverse())
  }
  return ids
}

/**
 * 送る ID の集合(URL・保存値・#175 で直した旧の保存値)から、選択の状態を作る。
 * 木に無い ID は捨てる。重複は 1 つにする。祖先と子孫の両方があれば、祖先の配下として選ぶ(祖先に含める)
 */
export function selectionFromIds(tree: SelectionTree, ids: Iterable<number>): TreeSelection {
  const selection = new Set<number>()
  for (const id of ids) {
    for (const leaf of tree.leavesOf.get(id) ?? []) selection.add(leaf)
  }
  return selection
}
