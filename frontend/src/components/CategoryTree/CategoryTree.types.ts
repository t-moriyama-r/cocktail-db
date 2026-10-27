/** 木を表示して選ぶ部品(CategoryTree)が受け取る木の 1 ノード。API の型からこの形に変えて渡す */
export type CategoryTreeNode = {
  id: number
  name: string
  /** 英名。無いノードは null */
  name_eng: string | null
  is_major: boolean
  children: CategoryTreeNode[]
}

/** 複数選択で、ノードのチェックボックスに出す状態。「一部」は子孫の一部だけが選ばれている状態 */
export type CategoryTreeCheckState = 'checked' | 'partial' | 'none'

/** 1 つだけ選ぶ。選べないノードは isSelectable で false を返す(省くとすべて選べる) */
export type CategoryTreeSingleSelection = {
  mode: 'single'
  selectedId: number | null
  onSelect: (node: CategoryTreeNode) => void
  isSelectable?: (node: CategoryTreeNode) => boolean
}

/**
 * チェックボックスで複数選ぶ。どのノードがどう見えるかは部品の外の規則で決まるため、
 * 状態は外から受け取り、押されたことだけを onToggle で返す
 */
export type CategoryTreeMultipleSelection = {
  mode: 'multiple'
  checkStateOf: (node: CategoryTreeNode) => CategoryTreeCheckState
  /** checked は押した後にチェックを入れるなら true(「なし」「一部」を押したとき)、外すなら false */
  onToggle: (node: CategoryTreeNode, checked: boolean) => void
}

export type CategoryTreeSelection = CategoryTreeSingleSelection | CategoryTreeMultipleSelection

/** 名前での絞り込みの結果 */
export type CategoryTreeFilterResult = {
  /** 出すノード(当たったノードと、その祖先・子孫) */
  visibleIds: ReadonlySet<number>
  /** 当たったノードが見えるように開くノード(当たったノードの祖先) */
  openIds: ReadonlySet<number>
}
