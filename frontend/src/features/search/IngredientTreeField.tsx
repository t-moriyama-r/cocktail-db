import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router'
import type { components } from '../../api/schema'
import CategoryTree from '../../components/CategoryTree/CategoryTree'
import type { CategoryTreeAppearance, CategoryTreeNode } from '../../components/CategoryTree/CategoryTree.types'
import { collectCategoryTreeIds } from '../../components/CategoryTree/collectCategoryTreeIds'
import { categoryPath } from '../../utils/detailPaths'
import {
  buildSelectionTree,
  checkStates,
  emptySelection,
  selectVisibleLeaves,
  toggleNode,
  type TreeSelection,
} from './selection'
import { sortSearchFormTree } from './sortSearchFormTree'
import styles from './IngredientTreeField.module.css'

type SearchFormNode = components['schemas']['SearchFormNodeResource']
type CategoryRole = components['schemas']['CategoryRole']

type Props = {
  /** GET /api/search-form の categories(API の順のまま渡す。旧と同じ並びにするのはこの欄) */
  categories: readonly SearchFormNode[]
  /** 選択の状態(選ばれた葉の ID の集合。selection.ts)。状態は持たず、外から受け取る */
  selection: TreeSelection
  /** チェックを入れた・外した後の状態を返す */
  onSelectionChange: (next: TreeSelection) => void
  /** ノードの名前を押して詳細へ移った後に呼ぶ。フォームをモーダルで出しているときに閉じるのに使う */
  onNavigate?: () => void
}

/** あるもの検索で、役割が「その他」の根の部分木に添える注記(旧は検索方法の説明に「"その他"は省略可能なので無視されます」とだけ書いていた) */
export const OTHER_ROOT_NOTE = 'あるもの検索では、持っていなくてもよい材料です'

/**
 * 検索フォームの材料の欄(#305)。木の部品(CategoryTree の複数選択)に、検索の選択の規則(selection.ts)をつなぐ。
 *
 * 旧(Forms/Search/Cocktail/form.vue)と同じにしているところ:
 * - 根(旧の種別)ごとにまとめ、根は API の順、根の中は名前の順(sortSearchFormTree)
 * - 根ごとに背景の色を分ける(役割なし = 蒸留酒・醸造酒・混成酒は水色、ソフトドリンクは白、その他は灰色)
 * - 根は見出しで、チェックも詳細へのリンクも無い
 * - ノードの名前を押すと、そのノードの詳細へ移り(フォームがモーダルなら閉じる)、チェックは変わらない(jumpCategoryAndCloseModal)
 * - 「全てチェック」「全てはずす」「メジャーでない項目の表示／非表示」(#306。check_all・check_disall・check_majorSwit)
 *   - メジャーでない項目は既定で隠す。隠しても選択は外れない。表示の状態は保存しない(開くたびに隠した状態から)
 *   - 「全てチェック」は見えているものだけを選び、隠れていた選択は外れる(#282 の決まったこと 3)
 *   - 「全てはずす」は隠れているものも含めてすべて外す
 *
 * 名前での絞り込み(#297)は木の部品が木の上に出す(旧に無い)。隠しているあいだは、隠れている材料には当たらない
 */
export default function IngredientTreeField({ categories, selection, onSelectionChange, onNavigate }: Props) {
  const navigate = useNavigate()
  const [showsNonMajor, setShowsNonMajor] = useState(false)
  const sorted = useMemo(() => sortSearchFormTree(categories), [categories])
  // 隠すのは出し方だけ。選択の規則(tree)は隠れているノードも含めた木で扱うので、隠しても選択は残る
  const nodes = useMemo(() => sorted.map((root) => toCategoryTreeNode(root, showsNonMajor)), [sorted, showsNonMajor])
  const tree = useMemo(() => buildSelectionTree(sorted), [sorted])
  const states = useMemo(() => checkStates(tree, selection), [tree, selection])
  const appearance = useMemo(() => buildAppearance(sorted), [sorted])

  return (
    <fieldset className={styles.field}>
      <legend className={styles.legend}>レシピ選択</legend>
      <div className={styles.actions}>
        <button
          type="button"
          className={styles.actionButton}
          onClick={() => onSelectionChange(selectVisibleLeaves(tree, collectCategoryTreeIds(nodes)))}
        >
          全てチェック
        </button>
        <button type="button" className={styles.actionButton} onClick={() => onSelectionChange(emptySelection)}>
          全てはずす
        </button>
        <button
          type="button"
          className={styles.actionButton}
          aria-pressed={showsNonMajor}
          onClick={() => setShowsNonMajor((current) => !current)}
        >
          メジャーでない項目の表示／非表示
        </button>
      </div>
      <CategoryTree
        label="材料"
        nodes={nodes}
        selection={{
          mode: 'multiple',
          checkStateOf: (node) => states.get(node.id) ?? 'none',
          onToggle: (node, checked) => onSelectionChange(toggleNode(tree, selection, node.id, checked)),
        }}
        onNameClick={(node) => {
          navigate(categoryPath(node.id))
          onNavigate?.()
        }}
        appearance={appearance}
      />
    </fieldset>
  )
}

/** 根の役割ごとの背景のクラス。役割なし(null)は旧の種別 1〜3(蒸留酒・醸造酒・混成酒) */
const ROOT_CLASS: Record<CategoryRole | 'none', string | undefined> = {
  none: styles.liquorRoot,
  soft_drink: styles.softDrinkRoot,
  other: styles.otherRoot,
}

function buildAppearance(roots: readonly SearchFormNode[]): CategoryTreeAppearance {
  // 根の ID → 根の役割。根だけが入るので、入っているかで根かどうかも分かる
  const roleOf = new Map(roots.map((root) => [root.id, root.root_role]))
  return {
    isHeading: (node) => roleOf.has(node.id),
    itemClassName: (node) => (roleOf.has(node.id) ? ROOT_CLASS[roleOf.get(node.id) ?? 'none'] : undefined),
    noteOf: (node) => (roleOf.get(node.id) === 'other' ? OTHER_ROOT_NOTE : undefined),
  }
}

/**
 * API のノードを、木の部品が受け取る形にする(検索フォームの API は英名を返さない)。
 * showsNonMajor が false なら、メジャーでない子を部分木ごと出さない(旧も、メジャーでないカテゴリの下のミドルは出なかった)。
 * 根(旧の種別)はメジャーかどうかを持たない(API では false)ので、いつも出す
 */
function toCategoryTreeNode(node: SearchFormNode, showsNonMajor: boolean): CategoryTreeNode {
  return {
    id: node.id,
    name: node.name,
    name_eng: null,
    is_major: node.is_major,
    children: node.children
      .filter((child) => showsNonMajor || child.is_major)
      .map((child) => toCategoryTreeNode(child, showsNonMajor)),
  }
}
