import { fireEvent, render, screen, within } from '@testing-library/react'
import { useState } from 'react'
import { MemoryRouter, useLocation } from 'react-router'
import { describe, expect, test, vi } from 'vitest'
import type { components } from '../../api/schema'
import IngredientTreeField, { OTHER_ROOT_NOTE } from './IngredientTreeField'
import { buildSelectionTree, emptySelection, toSendIds, type TreeSelection } from './selection'

type SearchFormNode = components['schemas']['SearchFormNodeResource']

/** GET /api/search-form の木の 1 ノード。指定しないものは、メジャー・役割なし・子なし */
const node = (id: number, name: string, children: SearchFormNode[] = [], root_role: SearchFormNode['root_role'] = null): SearchFormNode => ({
  id,
  name,
  is_major: true,
  root_role,
  children,
})

/**
 * テストの木(API の順)。「ビーフィーター」が深さ 5(根を 1 段目と数える)。根の中は名前の順になっていない
 *
 * 蒸留酒 > ラム > ホワイトラム・ダークラム
 *        > ジン > ドライジン > ロンドンドライジン > ビーフィーター・タンカレー
 *        > ウォッカ
 * ソフトドリンク(soft_drink) > トニックウォーター・オレンジジュース
 * その他(other) > ミント
 */
const buildCategories = (): SearchFormNode[] => [
  node(1, '蒸留酒', [
    node(10, 'ラム', [node(100, 'ホワイトラム'), node(101, 'ダークラム')]),
    node(11, 'ジン', [node(110, 'ドライジン', [node(1100, 'ロンドンドライジン', [node(11000, 'ビーフィーター'), node(11001, 'タンカレー')])])]),
    node(12, 'ウォッカ'),
  ]),
  node(3, 'ソフトドリンク', [node(30, 'トニックウォーター'), node(31, 'オレンジジュース')], 'soft_drink'),
  node(5, 'その他', [node(50, 'ミント')], 'other'),
]

/** いまの URL のパスを出す(名前を押して移った先を確かめる) */
function CurrentPath() {
  return <p data-testid="path">{useLocation().pathname}</p>
}

/** 選択の状態を持つ親(フォームの代わり)。送る ID を出し、欄は画面を移っても残す(モーダルのフォームと同じ) */
function Harness({ categories, onNavigate }: { categories: SearchFormNode[]; onNavigate?: () => void }) {
  const [selection, setSelection] = useState<TreeSelection>(emptySelection)
  const tree = buildSelectionTree(categories)
  return (
    <MemoryRouter initialEntries={['/']}>
      <IngredientTreeField categories={categories} selection={selection} onSelectionChange={setSelection} onNavigate={onNavigate} />
      <p data-testid="send-ids">{toSendIds(tree, selection).join(',')}</p>
      <CurrentPath />
    </MemoryRouter>
  )
}

const sendIds = () => screen.getByTestId('send-ids').textContent

/** 根(蒸留酒)だけ開いた状態から、ビーフィーター(深さ 5)まで順に開く */
const openToBeefeater = () => {
  fireEvent.click(screen.getByRole('button', { name: 'ジンを開く' }))
  fireEvent.click(screen.getByRole('button', { name: 'ドライジンを開く' }))
  fireEvent.click(screen.getByRole('button', { name: 'ロンドンドライジンを開く' }))
}

/** 木の中の、根の段の行(li)を上から並べる */
const rootItems = () => within(screen.getByRole('list', { name: '材料' })).getAllByRole('listitem').filter((item) => item.parentElement?.getAttribute('aria-label') === '材料')

describe('IngredientTreeField', () => {
  test('TC-305-01: 深さ 5 のノードを開いてチェックでき、そのノードを送る', () => {
    render(<Harness categories={buildCategories()} />)

    openToBeefeater()
    fireEvent.click(screen.getByRole('checkbox', { name: 'ビーフィーター' }))

    expect(screen.getByRole('checkbox', { name: 'ビーフィーター' })).toBeChecked()
    // 祖先は「一部」(#304 の規則。旧の checkParent のように親にチェックは入れない。docs/decisions/search-screen.md の 2)
    expect(screen.getByRole('checkbox', { name: 'ロンドンドライジン' })).toBePartiallyChecked()
    expect(screen.getByRole('checkbox', { name: 'ジン' })).toBePartiallyChecked()
    expect(sendIds()).toBe('11000')
  })

  test('TC-305-02: 親にチェックを入れると子孫もチェックの表示になり、子を 1 つ外すと親は「一部」で、残った兄弟を送る', () => {
    render(<Harness categories={buildCategories()} />)

    fireEvent.click(screen.getByRole('checkbox', { name: 'ラム' }))
    fireEvent.click(screen.getByRole('button', { name: 'ラムを開く' }))
    expect(screen.getByRole('checkbox', { name: 'ホワイトラム' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'ダークラム' })).toBeChecked()
    expect(sendIds()).toBe('10')

    fireEvent.click(screen.getByRole('checkbox', { name: 'ダークラム' }))
    expect(screen.getByRole('checkbox', { name: 'ラム' })).toBePartiallyChecked()
    expect(sendIds()).toBe('100')

    fireEvent.click(screen.getByRole('checkbox', { name: 'ダークラム' }))
    expect(screen.getByRole('checkbox', { name: 'ラム' })).toBeChecked()
    expect(sendIds()).toBe('10')
  })

  test('TC-305-03: 根は API の順のまま、根の中は名前の順(かなをそろえて)に並ぶ', () => {
    render(<Harness categories={buildCategories()} />)

    expect(rootItems().map((item) => item.querySelector('span')?.textContent)).toEqual([
      '蒸留酒',
      'ソフトドリンク',
      'その他',
    ])
    const children = (name: string) =>
      within(screen.getByRole('button', { name: `${name}を閉じる` }).parentElement!.querySelector('ul')!)
        .getAllByRole('checkbox')
        .map((checkbox) => checkbox.getAttribute('aria-label'))
    expect(children('蒸留酒')).toEqual(['ウォッカ', 'ジン', 'ラム'])
    expect(children('ソフトドリンク')).toEqual(['オレンジジュース', 'トニックウォーター'])
  })

  test('TC-305-04: 根は見出しで、チェックボックスも詳細へのボタンも無く、開閉はできる', () => {
    render(<Harness categories={buildCategories()} />)

    for (const root of ['蒸留酒', 'ソフトドリンク', 'その他']) {
      expect(screen.queryByRole('checkbox', { name: root })).not.toBeInTheDocument()
      expect(screen.queryByRole('button', { name: root })).not.toBeInTheDocument()
      expect(screen.getByText(root)).toBeInTheDocument()
    }

    fireEvent.click(screen.getByRole('button', { name: '蒸留酒を閉じる' }))
    expect(screen.queryByRole('checkbox', { name: 'ラム' })).not.toBeInTheDocument()
  })

  test('TC-305-05: 根の子をすべてチェックすると、根 1 つに畳んで送る', () => {
    render(<Harness categories={buildCategories()} />)

    fireEvent.click(screen.getByRole('checkbox', { name: 'ミント' }))

    expect(sendIds()).toBe('5')
  })

  test('TC-305-06: 役割が「その他」の根にだけ、あるもの検索では持っていなくてよいことを添える', () => {
    render(<Harness categories={buildCategories()} />)

    const notes = screen.getAllByText(OTHER_ROOT_NOTE)
    expect(notes).toHaveLength(1)
    expect(notes[0].parentElement).toHaveTextContent(/^▾その他/)
  })

  test('TC-305-07: ノードの名前を押すと詳細へ移って onNavigate を呼び、チェックは変わらない', () => {
    const onNavigate = vi.fn<() => void>()
    render(<Harness categories={buildCategories()} onNavigate={onNavigate} />)
    openToBeefeater()
    fireEvent.click(screen.getByRole('checkbox', { name: 'ビーフィーター' }))

    fireEvent.click(screen.getByRole('button', { name: 'ビーフィーター' }))
    expect(screen.getByTestId('path')).toHaveTextContent('/category/11000')
    expect(screen.getByRole('checkbox', { name: 'ビーフィーター' })).toBeChecked()

    fireEvent.click(screen.getByRole('button', { name: 'ウォッカ' }))
    expect(screen.getByTestId('path')).toHaveTextContent('/category/12')
    expect(screen.getByRole('checkbox', { name: 'ウォッカ' })).not.toBeChecked()

    // モーダルを閉じるのは呼び出し側の仕事なので、移るたびに知らせていることを確かめる
    expect(onNavigate).toHaveBeenCalledTimes(2)
    expect(sendIds()).toBe('11000')
  })

  test('TC-305-08: onNavigate を渡さなくても、名前を押すと詳細へ移る', () => {
    render(<Harness categories={buildCategories()} />)

    fireEvent.click(screen.getByRole('button', { name: 'オレンジジュース' }))

    expect(screen.getByTestId('path')).toHaveTextContent('/category/31')
  })
})

/**
 * メジャーでない材料のある木(#306)。根は API ではメジャーでない(false)
 *
 * 蒸留酒 > ラム > ホワイトラム・ダークラム・アグリコールラム(メジャーでない)
 *        > ジン > オールドトムジン(メジャーでない)
 *        > ピスコ(メジャーでない) > ピスコ・アチョラード
 *        > ウォッカ
 * その他(other) > ミント
 */
const buildNonMajorCategories = (): SearchFormNode[] => {
  const minor = (target: SearchFormNode): SearchFormNode => ({ ...target, is_major: false })
  return [
    minor(
      node(1, '蒸留酒', [
        node(10, 'ラム', [node(100, 'ホワイトラム'), node(101, 'ダークラム'), minor(node(102, 'アグリコールラム'))]),
        node(11, 'ジン', [minor(node(110, 'オールドトムジン'))]),
        minor(node(13, 'ピスコ', [node(130, 'ピスコ・アチョラード')])),
        node(12, 'ウォッカ'),
      ]),
    ),
    minor(node(5, 'その他', [node(50, 'ミント')], 'other')),
  ]
}

const switchNonMajor = () => fireEvent.click(screen.getByRole('button', { name: 'メジャーでない項目の表示／非表示' }))

describe('IngredientTreeField の一括操作とメジャーでない材料(#306)', () => {
  test('TC-306-01: 既定では、メジャーでない材料を部分木ごと隠し、根はメジャーでなくても出す', () => {
    render(<Harness categories={buildNonMajorCategories()} />)
    fireEvent.click(screen.getByRole('button', { name: 'すべて開く' }))

    expect(screen.getByRole('button', { name: 'メジャーでない項目の表示／非表示' })).toHaveAttribute('aria-pressed', 'false')
    expect(screen.getByText('蒸留酒')).toBeInTheDocument()
    expect(screen.getByText('その他')).toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: 'ホワイトラム' })).toBeInTheDocument()
    for (const hidden of ['アグリコールラム', 'オールドトムジン', 'ピスコ', 'ピスコ・アチョラード']) {
      expect(screen.queryByRole('checkbox', { name: hidden })).not.toBeInTheDocument()
    }
  })

  test('TC-306-02: 切り替えると出し、もう一度押すと隠す', () => {
    render(<Harness categories={buildNonMajorCategories()} />)
    fireEvent.click(screen.getByRole('button', { name: 'すべて開く' }))

    switchNonMajor()
    expect(screen.getByRole('button', { name: 'メジャーでない項目の表示／非表示' })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByRole('checkbox', { name: 'アグリコールラム' })).toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: 'ピスコ' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'ピスコを開く' }))
    expect(screen.getByRole('checkbox', { name: 'ピスコ・アチョラード' })).toBeInTheDocument()

    switchNonMajor()
    expect(screen.queryByRole('checkbox', { name: 'アグリコールラム' })).not.toBeInTheDocument()
    expect(screen.queryByRole('checkbox', { name: 'ピスコ' })).not.toBeInTheDocument()
  })

  test('TC-306-03: 隠しても選択は外れず、出し直すとチェックのまま', () => {
    render(<Harness categories={buildNonMajorCategories()} />)
    fireEvent.click(screen.getByRole('button', { name: 'すべて開く' }))
    switchNonMajor()
    fireEvent.click(screen.getByRole('button', { name: 'ピスコを開く' }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'アグリコールラム' }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'ピスコ' }))
    expect(sendIds()).toBe('102,13')

    switchNonMajor()
    expect(sendIds()).toBe('102,13')
    // 隠れている子が選ばれているので、見えている親は「一部」のまま
    expect(screen.getByRole('checkbox', { name: 'ラム' })).toBePartiallyChecked()

    switchNonMajor()
    expect(screen.getByRole('checkbox', { name: 'アグリコールラム' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'ピスコ・アチョラード' })).toBeChecked()
  })

  test('TC-306-04: 「全てはずす」は、隠れているものも含めてすべて外す', () => {
    render(<Harness categories={buildNonMajorCategories()} />)
    fireEvent.click(screen.getByRole('button', { name: 'すべて開く' }))
    switchNonMajor()
    fireEvent.click(screen.getByRole('checkbox', { name: 'アグリコールラム' }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'ホワイトラム' }))
    switchNonMajor()

    fireEvent.click(screen.getByRole('button', { name: '全てはずす' }))
    expect(sendIds()).toBe('')

    switchNonMajor()
    expect(screen.getByRole('checkbox', { name: 'アグリコールラム' })).not.toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'ホワイトラム' })).not.toBeChecked()
  })

  test('TC-306-05: 隠しているときの「全てチェック」は、見えているものだけを選び、隠れていた選択は外れる', () => {
    render(<Harness categories={buildNonMajorCategories()} />)
    fireEvent.click(screen.getByRole('button', { name: 'すべて開く' }))
    switchNonMajor()
    fireEvent.click(screen.getByRole('button', { name: 'ピスコを開く' }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'ピスコ' }))
    switchNonMajor()

    fireEvent.click(screen.getByRole('button', { name: '全てチェック' }))
    // 見えている葉(ホワイトラム・ダークラム・ウォッカ・ミント)だけ。ミントは根の子がすべてなので根 5 に畳む
    expect(sendIds()).toBe('100,101,12,5')
    expect(screen.getByRole('checkbox', { name: 'ラム' })).toBePartiallyChecked()

    switchNonMajor()
    expect(screen.getByRole('checkbox', { name: 'アグリコールラム' })).not.toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'ピスコ' })).not.toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'ピスコ・アチョラード' })).not.toBeChecked()
  })

  test('TC-306-06: 出しているときの「全てチェック」は、すべてを選び、根に畳む', () => {
    render(<Harness categories={buildNonMajorCategories()} />)
    switchNonMajor()

    fireEvent.click(screen.getByRole('button', { name: '全てチェック' }))

    expect(sendIds()).toBe('1,5')
  })

  test('TC-306-07: 隠しているとき、子がすべて隠れている見えるノードは「全てチェック」で選ばない', () => {
    render(<Harness categories={buildNonMajorCategories()} />)
    fireEvent.click(screen.getByRole('button', { name: 'すべて開く' }))

    fireEvent.click(screen.getByRole('button', { name: '全てチェック' }))

    // ジンの子はオールドトムジン(メジャーでない)だけ。選ぶと隠れている材料を選ぶことになるので、選ばない
    expect(screen.getByRole('checkbox', { name: 'ジン' })).not.toBeChecked()
    expect(sendIds()).toBe('100,101,12,5')
  })

  test('TC-306-08: 隠しているときに見えている親にチェックを入れると、隠れている子も選ぶ', () => {
    render(<Harness categories={buildNonMajorCategories()} />)

    fireEvent.click(screen.getByRole('checkbox', { name: 'ラム' }))
    expect(sendIds()).toBe('10')

    fireEvent.click(screen.getByRole('button', { name: 'ラムを開く' }))
    switchNonMajor()
    expect(screen.getByRole('checkbox', { name: 'アグリコールラム' })).toBeChecked()
  })

  test('TC-306-09: 名前での絞り込みは欄の木の上にあり、隠しているあいだは隠れている材料に当たらない', () => {
    render(<Harness categories={buildNonMajorCategories()} />)
    const search = screen.getByRole('searchbox', { name: '名前で絞り込む' })
    const tree = screen.getByRole('list', { name: '材料' })
    expect(search.compareDocumentPosition(tree) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()

    fireEvent.change(search, { target: { value: 'ぴすこ' } })
    expect(screen.getByText('当たるものがありません')).toBeInTheDocument()

    switchNonMajor()
    fireEvent.change(search, { target: { value: '' } })
    fireEvent.change(search, { target: { value: 'ぴすこ' } })
    expect(screen.getByRole('checkbox', { name: 'ピスコ・アチョラード' })).toBeInTheDocument()
  })

  test('TC-306-10: 表示の状態は保存せず、欄を出し直すと隠した状態から始まる', () => {
    const { unmount } = render(<Harness categories={buildNonMajorCategories()} />)
    switchNonMajor()
    unmount()

    render(<Harness categories={buildNonMajorCategories()} />)
    expect(screen.getByRole('button', { name: 'メジャーでない項目の表示／非表示' })).toHaveAttribute('aria-pressed', 'false')
  })
})
