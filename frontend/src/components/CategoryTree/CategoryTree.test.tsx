import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, test, vi } from 'vitest'
import CategoryTree from './CategoryTree'
import type {
  CategoryTreeCheckState,
  CategoryTreeMultipleSelection,
  CategoryTreeNode,
  CategoryTreeSingleSelection,
} from './CategoryTree.types'
import { buildCategoryTreeNode as buildNode } from './buildCategoryTreeNode'
import { buildTestTree } from './buildTestTree'

const singleSelection = (overrides: Partial<CategoryTreeSingleSelection> = {}): CategoryTreeSingleSelection => ({
  mode: 'single',
  selectedId: null,
  onSelect: vi.fn<(node: CategoryTreeNode) => void>(),
  ...overrides,
})

const multipleSelection = (
  states: Record<number, CategoryTreeCheckState> = {},
  onToggle = vi.fn<(node: CategoryTreeNode, checked: boolean) => void>(),
): CategoryTreeMultipleSelection => ({
  mode: 'multiple',
  checkStateOf: (node) => states[node.id] ?? 'none',
  onToggle,
})

/** 根(蒸留酒)だけ開いた状態から、ビーフィーター(深さ 5)まで順に開く */
const openToBeefeater = () => {
  fireEvent.click(screen.getByRole('button', { name: 'ジンを開く' }))
  fireEvent.click(screen.getByRole('button', { name: 'ドライジンを開く' }))
  fireEvent.click(screen.getByRole('button', { name: 'ロンドンドライジンを開く' }))
}

describe('CategoryTree', () => {
  test('TC-297-01: 単一選択で、深さ 5 のノードを開いて選べる', () => {
    const onSelect = vi.fn<(node: CategoryTreeNode) => void>()
    render(<CategoryTree label="カテゴリ" nodes={buildTestTree()} selection={singleSelection({ onSelect })} />)

    openToBeefeater()
    fireEvent.click(screen.getByRole('radio', { name: 'ビーフィーター' }))

    expect(onSelect).toHaveBeenCalledTimes(1)
    expect(onSelect.mock.calls[0][0].id).toBe(11000)
  })

  test('TC-297-02: 複数選択で、深さ 5 のノードを開いてチェックを入れ外しできる', () => {
    const onToggle = vi.fn<(node: CategoryTreeNode, checked: boolean) => void>()
    const tree = buildTestTree()
    const { rerender } = render(
      <CategoryTree label="カテゴリ" nodes={tree} selection={multipleSelection({}, onToggle)} />,
    )

    openToBeefeater()
    fireEvent.click(screen.getByRole('checkbox', { name: 'ビーフィーター' }))
    rerender(<CategoryTree label="カテゴリ" nodes={tree} selection={multipleSelection({ 11000: 'checked' }, onToggle)} />)
    fireEvent.click(screen.getByRole('checkbox', { name: 'ビーフィーター' }))

    expect(onToggle.mock.calls.map(([node, checked]) => [node.id, checked])).toEqual([
      [11000, true],
      [11000, false],
    ])
  })

  test('TC-297-03: 複数選択で、外から受け取った「チェック・一部・なし」を出し、「一部」を押すとチェックを入れる', () => {
    const onToggle = vi.fn<(node: CategoryTreeNode, checked: boolean) => void>()
    render(
      <CategoryTree
        label="カテゴリ"
        nodes={buildTestTree()}
        selection={multipleSelection({ 1: 'partial', 10: 'checked' }, onToggle)}
      />,
    )

    expect(screen.getByRole('checkbox', { name: '蒸留酒' })).toBePartiallyChecked()
    expect(screen.getByRole('checkbox', { name: 'ラム' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'ジン' })).not.toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'ジン' })).not.toBePartiallyChecked()

    fireEvent.click(screen.getByRole('checkbox', { name: '蒸留酒' }))
    expect(onToggle.mock.calls.map(([node, checked]) => [node.id, checked])).toEqual([[1, true]])
  })

  test('TC-297-04: 単一選択で、選べないノードは押せず、選んだことにならない', () => {
    const onSelect = vi.fn<(node: CategoryTreeNode) => void>()
    const tree = buildTestTree()
    const rootIds = new Set(tree.map((node) => node.id))
    render(
      <CategoryTree
        label="カテゴリ"
        nodes={tree}
        selection={singleSelection({ onSelect, isSelectable: (node) => !rootIds.has(node.id) })}
      />,
    )

    const root = screen.getByRole('radio', { name: '蒸留酒' })
    fireEvent.click(root)

    expect(root).toBeDisabled()
    expect(root).not.toBeChecked()
    expect(onSelect).not.toHaveBeenCalled()
    expect(screen.getByRole('radio', { name: 'ラム' })).toBeEnabled()
  })

  test('TC-297-05: 単一選択で、選んでいるノード(selectedId)のラジオボタンに印が付く', () => {
    render(<CategoryTree label="カテゴリ" nodes={buildTestTree()} selection={singleSelection({ selectedId: 10 })} />)

    expect(screen.getByRole('radio', { name: 'ラム' })).toBeChecked()
    expect(screen.getByRole('radio', { name: 'ジン' })).not.toBeChecked()
  })

  test('TC-297-06: 既定では根だけを開き、根の子は出し、孫は描かない', () => {
    render(<CategoryTree label="カテゴリ" nodes={buildTestTree()} selection={multipleSelection()} />)

    expect(screen.getByRole('button', { name: '蒸留酒を閉じる' })).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByRole('checkbox', { name: 'ラム' })).toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: 'ジュース' })).toBeInTheDocument()
    expect(screen.queryByRole('checkbox', { name: 'ホワイトラム' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'ラムを開く' })).toHaveAttribute('aria-expanded', 'false')
  })

  test('TC-297-14: 畳むと子を描かなくなり、開くとまた出る', () => {
    render(<CategoryTree label="カテゴリ" nodes={buildTestTree()} selection={multipleSelection()} />)

    fireEvent.click(screen.getByRole('button', { name: '蒸留酒を閉じる' }))
    expect(screen.queryByRole('checkbox', { name: 'ラム' })).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: '蒸留酒を開く' }))
    expect(screen.getByRole('checkbox', { name: 'ラム' })).toBeInTheDocument()
  })

  test('TC-297-15: 名前で絞り込むと、当たったノードと祖先だけを出し、入力を消すと元の開き方に戻る', () => {
    render(<CategoryTree label="カテゴリ" nodes={buildTestTree()} selection={multipleSelection()} />)
    const search = screen.getByRole('searchbox', { name: '名前で絞り込む' })

    fireEvent.change(search, { target: { value: 'びーふぃ' } })
    expect(screen.getByRole('checkbox', { name: 'ビーフィーター' })).toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: 'ロンドンドライジン' })).toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: '蒸留酒' })).toBeInTheDocument()
    expect(screen.queryByRole('checkbox', { name: 'ラム' })).not.toBeInTheDocument()
    expect(screen.queryByRole('checkbox', { name: 'ソフトドリンク' })).not.toBeInTheDocument()

    fireEvent.change(search, { target: { value: 'らむ' } })
    expect(screen.getByRole('checkbox', { name: 'ラム' })).toBeInTheDocument()
    expect(screen.queryByRole('checkbox', { name: 'ジン' })).not.toBeInTheDocument()

    fireEvent.change(search, { target: { value: '' } })
    expect(screen.getByRole('checkbox', { name: 'ジン' })).toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: 'ソフトドリンク' })).toBeInTheDocument()
    expect(screen.queryByRole('checkbox', { name: 'ビーフィーター' })).not.toBeInTheDocument()
  })

  test('TC-297-16: 絞り込みで何も当たらなければ、そう出す', () => {
    render(<CategoryTree label="カテゴリ" nodes={buildTestTree()} selection={multipleSelection()} />)

    fireEvent.change(screen.getByRole('searchbox', { name: '名前で絞り込む' }), { target: { value: 'ぬ' } })

    expect(screen.getByText('当たるものがありません')).toBeInTheDocument()
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument()
  })

  test('TC-297-17: 名前を押したときの動きを外から渡せ、渡さなければ名前は押せない', () => {
    const onNameClick = vi.fn<(node: CategoryTreeNode) => void>()
    const { unmount } = render(
      <CategoryTree label="カテゴリ" nodes={buildTestTree()} selection={multipleSelection()} onNameClick={onNameClick} />,
    )

    fireEvent.click(screen.getByRole('button', { name: 'ラム' }))
    expect(onNameClick.mock.calls.map(([node]) => node.id)).toEqual([10])
    unmount()

    render(<CategoryTree label="カテゴリ" nodes={buildTestTree()} selection={multipleSelection()} />)
    expect(screen.queryByRole('button', { name: 'ラム' })).not.toBeInTheDocument()
    expect(screen.getByText('ラム')).toBeInTheDocument()
  })

  test('TC-297-19: 木を後から受け取っても、既定どおり根を開く', () => {
    const { rerender } = render(<CategoryTree label="カテゴリ" nodes={[]} selection={multipleSelection()} />)

    rerender(<CategoryTree label="カテゴリ" nodes={buildTestTree()} selection={multipleSelection()} />)

    expect(screen.getByRole('button', { name: '蒸留酒を閉じる' })).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByRole('checkbox', { name: 'ラム' })).toBeInTheDocument()
  })

  test('TC-297-18: 600 ノードの木で、すべて開いてから閉じるまでが 1 秒以内で、閉じた子は描かない', () => {
    const { container } = render(
      <CategoryTree label="カテゴリ" nodes={buildLargeTree()} selection={multipleSelection()} />,
    )
    const openAll = screen.getByRole('button', { name: 'すべて開く' })
    const closeAll = screen.getByRole('button', { name: 'すべて閉じる' })
    // getAllByRole は 600 個の役割を計算して遅いので、描いた数は DOM から直接数える
    const countRows = () => container.querySelectorAll('input[type="checkbox"]').length

    // 1 回目は JIT のコンパイルと jsdom の初期化の時間が大きく混ざるので、一度開いて閉じてから測る。
    // 同じ PC で動くほかの処理に割り込まれた回で落ちないよう、3 回測っていちばん速い回を見る
    fireEvent.click(openAll)
    fireEvent.click(closeAll)

    const trials = Array.from({ length: 3 }, () => {
      const started = performance.now()
      fireEvent.click(openAll)
      const openedCount = countRows()
      fireEvent.click(closeAll)
      const closedCount = countRows()
      return { openedCount, closedCount, elapsed: performance.now() - started }
    })

    for (const { openedCount, closedCount } of trials) {
      expect(openedCount).toBe(600)
      expect(closedCount).toBe(6)
    }
    expect(Math.min(...trials.map(({ elapsed }) => elapsed))).toBeLessThan(1000)
  })
})

/** 根 6・各 9 の子・各 10 の孫で、ちょうど 600 ノードの木(実データと同じくらいの大きさ) */
function buildLargeTree(): CategoryTreeNode[] {
  return Array.from({ length: 6 }, (_, r) =>
    buildNode({
      id: r + 1,
      name: `根${r + 1}`,
      children: Array.from({ length: 9 }, (_, c) =>
        buildNode({
          id: 100 + r * 10 + c,
          name: `子${r + 1}-${c + 1}`,
          children: Array.from({ length: 10 }, (_, g) =>
            buildNode({ id: 1000 + r * 100 + c * 10 + g, name: `孫${r + 1}-${c + 1}-${g + 1}` }),
          ),
        }),
      ),
    }),
  )
}
