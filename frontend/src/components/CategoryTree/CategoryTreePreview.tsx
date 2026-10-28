import { useState } from 'react'
import CategoryTree from './CategoryTree'
import type { CategoryTreeCheckState, CategoryTreeNode } from './CategoryTree.types'
import { collectCategoryTreeIds } from './collectCategoryTreeIds'
import { buildCategoryTreeNode as buildNode } from './buildCategoryTreeNode'

/**
 * 部品を画面で確かめるための見本(preview.html から開く)。単一選択と複数選択を並べる。
 * 複数選択の「一部」の出し方は #304 の規則で決まるため、ここでは子孫に選んだものがあれば「一部」と出すだけにしている
 */
export default function CategoryTreePreview() {
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [checkedIds, setCheckedIds] = useState<ReadonlySet<number>>(new Set())
  const [clicked, setClicked] = useState('')

  const checkStateOf = (node: CategoryTreeNode): CategoryTreeCheckState => {
    if (checkedIds.has(node.id)) return 'checked'
    const descendants = collectCategoryTreeIds(node.children)
    return [...descendants].some((id) => checkedIds.has(id)) ? 'partial' : 'none'
  }

  return (
    <main style={{ display: 'grid', gap: 'var(--space-5)', padding: 'var(--space-4)', maxWidth: 640 }}>
      <section>
        <h2>単一選択(根は選べない)</h2>
        <CategoryTree
          label="単一選択"
          nodes={SAMPLE_TREE}
          selection={{
            mode: 'single',
            selectedId,
            onSelect: (node) => setSelectedId(node.id),
            isSelectable: (node) => !ROOT_IDS.has(node.id),
          }}
        />
        <p data-testid="single-result">選んだもの: {selectedId ?? 'なし'}</p>
      </section>
      <section>
        <h2>複数選択(名前を押すと、渡した動きが動く)</h2>
        <CategoryTree
          label="複数選択"
          nodes={SAMPLE_TREE}
          selection={{
            mode: 'multiple',
            checkStateOf,
            onToggle: (node, checked) =>
              setCheckedIds((current) => {
                const next = new Set(current)
                if (checked) next.add(node.id)
                else next.delete(node.id)
                return next
              }),
          }}
          onNameClick={(node) => setClicked(node.name)}
        />
        <p data-testid="name-click-result">名前を押したノード: {clicked || 'なし'}</p>
      </section>
    </main>
  )
}

const SAMPLE_TREE: CategoryTreeNode[] = [
  buildNode({
    id: 1,
    name: '蒸留酒',
    name_eng: 'Spirits',
    children: [
      buildNode({
        id: 10,
        name: 'ラム',
        name_eng: 'Rum',
        children: [
          buildNode({ id: 100, name: 'ホワイトラム', name_eng: 'White Rum' }),
          buildNode({ id: 101, name: 'ダークラム', name_eng: 'Dark Rum' }),
          buildNode({ id: 102, name: 'ゴールドラム', name_eng: 'Gold Rum' }),
        ],
      }),
      buildNode({
        id: 11,
        name: 'ジン',
        name_eng: 'Gin',
        children: [
          buildNode({
            id: 110,
            name: 'ドライジン',
            name_eng: 'Dry Gin',
            children: [
              buildNode({
                id: 1100,
                name: 'ロンドンドライジン',
                name_eng: 'London Dry Gin',
                children: [
                  buildNode({ id: 11000, name: 'ビーフィーター', name_eng: 'Beefeater' }),
                  buildNode({ id: 11001, name: 'タンカレー', name_eng: 'Tanqueray' }),
                ],
              }),
            ],
          }),
          buildNode({ id: 111, name: 'オールドトムジン', name_eng: 'Old Tom Gin' }),
        ],
      }),
      buildNode({ id: 12, name: 'ウォッカ', name_eng: 'Vodka' }),
      buildNode({ id: 13, name: 'テキーラ', name_eng: 'Tequila' }),
    ],
  }),
  buildNode({
    id: 2,
    name: '混成酒',
    name_eng: 'Liqueur',
    children: [
      buildNode({ id: 20, name: 'カシスリキュール', name_eng: 'Cassis Liqueur' }),
      buildNode({ id: 21, name: 'ラムレーズン', name_eng: 'Rum Raisin' }),
    ],
  }),
  buildNode({
    id: 3,
    name: 'ソフトドリンク',
    name_eng: 'Soft Drink',
    children: [
      buildNode({ id: 30, name: 'オレンジジュース', name_eng: 'Orange Juice' }),
      buildNode({ id: 31, name: 'トニックウォーター', name_eng: 'Tonic Water' }),
    ],
  }),
]

const ROOT_IDS = new Set(SAMPLE_TREE.map((node) => node.id))
