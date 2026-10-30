import { useMemo, useState } from 'react'
import { MemoryRouter, useLocation } from 'react-router'
import type { components } from '../../api/schema'
import IngredientTreeField from './IngredientTreeField'
import { buildSelectionTree, emptySelection, toSendIds, type TreeSelection } from './selection'

type SearchFormNode = components['schemas']['SearchFormNodeResource']

/**
 * 部品を画面で確かめるための見本(preview.html から開く)。検索のフォームの代わりに選択の状態を持ち、
 * 送る ID と、名前を押して移った先の URL を下に出す(欄は移っても残す。モーダルのフォームと同じ)。
 * 木は旧の 5 つの種別を根にし、「ビーフィーター」を深さ 5(根を 1 段目と数える)にした見本の値。並びは API の順のまま渡す
 */
export default function IngredientTreeFieldPreview() {
  const [selection, setSelection] = useState<TreeSelection>(emptySelection)
  const [navigatedCount, setNavigatedCount] = useState(0)
  const tree = useMemo(() => buildSelectionTree(SAMPLE_CATEGORIES), [])

  return (
    <MemoryRouter>
      <main style={{ display: 'grid', gap: 'var(--space-4)', padding: 'var(--space-4)', maxWidth: 'var(--content-max-width)' }}>
        <IngredientTreeField
          categories={SAMPLE_CATEGORIES}
          selection={selection}
          onSelectionChange={setSelection}
          onNavigate={() => setNavigatedCount((count) => count + 1)}
        />
        <p data-testid="send-ids">送る ID: {toSendIds(tree, selection).join(', ') || 'なし'}</p>
        <CurrentPath navigatedCount={navigatedCount} />
      </main>
    </MemoryRouter>
  )
}

function CurrentPath({ navigatedCount }: { navigatedCount: number }) {
  return (
    <p data-testid="current-path">
      いまの URL: {useLocation().pathname}(名前を押して移った回数: {navigatedCount})
    </p>
  )
}

const node = (
  id: number,
  name: string,
  children: SearchFormNode[] = [],
  root_role: SearchFormNode['root_role'] = null,
  is_major = true,
): SearchFormNode => ({ id, name, is_major, root_role, children })

const SAMPLE_CATEGORIES: SearchFormNode[] = [
  node(1, '蒸留酒', [
    node(10, 'ラム', [node(100, 'ホワイトラム'), node(101, 'ダークラム'), node(102, 'ゴールドラム')]),
    node(11, 'ジン', [
      node(110, 'ドライジン', [node(1100, 'ロンドンドライジン', [node(11000, 'ビーフィーター'), node(11001, 'タンカレー')])]),
      node(111, 'オールドトムジン', [], null, false),
    ]),
    node(12, 'ウォッカ'),
    node(13, 'テキーラ'),
    node(14, 'ウイスキー', [node(140, 'スコッチ'), node(141, 'バーボン')]),
  ]),
  node(2, '醸造酒', [node(20, 'ワイン', [node(200, '赤ワイン'), node(201, '白ワイン')]), node(21, 'ビール')]),
  node(3, '混成酒', [node(30, 'カシスリキュール'), node(31, 'アマレット'), node(32, 'うめ酒')]),
  node(4, 'ソフトドリンク', [node(40, 'トニックウォーター'), node(41, 'オレンジジュース'), node(42, 'コーラ')], 'soft_drink'),
  node(5, 'その他', [node(50, 'ミント'), node(51, 'レモン', [node(510, 'レモンピール')]), node(52, '砂糖')], 'other'),
]
