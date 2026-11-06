import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, test } from 'vitest'
import App from '../App'
import type { components, paths } from '../api/schema'
import { jsonResponse, mockApi, mockApiHandler } from '../test/api'

type TreeItem = components['schemas']['CategoryTreeItemResource']
type CurrentAdmin = paths['/admin/me']['get']['responses'][200]['content']['application/json']

const ADMIN: CurrentAdmin = { id: 1, name: '管理者の太郎' }

const item = (overrides: Partial<TreeItem> & Pick<TreeItem, 'id' | 'name' | 'name_eng'>): TreeItem => ({
  role: null,
  root_role: null,
  is_major: true,
  children: [],
  ...overrides,
})

/**
 * 管理 API の木(兄弟は API が sort_order の順で返す。名前の順ではない)。
 *
 * 蒸留酒(役割なし) > ラム > ホワイトラム
 *                  > ジン > ドライジン > ロンドンドライジン
 * ソフトドリンク(soft_drink) > ジュース(メジャーでない)
 * その他(other) > ミント
 */
const TREE: TreeItem[] = [
  item({
    id: 1,
    name: '蒸留酒',
    name_eng: 'Spirits',
    children: [
      item({ id: 10, name: 'ラム', name_eng: 'Rum', children: [item({ id: 100, name: 'ホワイトラム', name_eng: 'White Rum' })] }),
      item({
        id: 11,
        name: 'ジン',
        name_eng: 'Gin',
        children: [
          item({
            id: 110,
            name: 'ドライジン',
            name_eng: 'Dry Gin',
            children: [item({ id: 1100, name: 'ロンドンドライジン', name_eng: 'London Dry Gin' })],
          }),
        ],
      }),
    ],
  }),
  item({
    id: 2,
    name: 'ソフトドリンク',
    name_eng: 'Soft Drink',
    role: 'soft_drink',
    root_role: 'soft_drink',
    children: [item({ id: 20, name: 'ジュース', name_eng: 'Juice', root_role: 'soft_drink', is_major: false })],
  }),
  item({
    id: 3,
    name: 'その他',
    name_eng: 'Other',
    role: 'other',
    root_role: 'other',
    children: [item({ id: 30, name: 'ミント', name_eng: 'Mint', root_role: 'other' })],
  }),
]

const renderAt = (path: string) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <App />
    </MemoryRouter>,
  )

const openTreePage = async (tree: TreeItem[] = TREE) => {
  mockApi('get', '/admin/me', 200, ADMIN)
  mockApi('get', '/admin/categories', 200, { data: tree })
  renderAt('/admin/categories')
  return screen.findByRole('list', { name: 'カテゴリ木' })
}

/** 木の行(li)の先頭の文字(子の行を含まない、名前と注記だけ)を、上から順に返す */
const rowTexts = (tree: HTMLElement) =>
  Array.from(tree.querySelectorAll('li')).map((row) =>
    Array.from(row.childNodes)
      .filter((child) => child.nodeName !== 'UL' && child.nodeName !== 'BUTTON')
      .map((child) => child.textContent)
      .join(''),
  )

describe('AdminCategoryTree', () => {
  test('TC-123-01: メニューに「カテゴリ木」が出て、押すとカテゴリ木の画面が開く', async () => {
    mockApi('get', '/admin/me', 200, ADMIN)
    mockApi('get', '/admin/categories', 200, { data: TREE })
    renderAt('/admin')

    const menu = await screen.findByRole('navigation', { name: '管理画面のメニュー' })
    fireEvent.click(within(menu).getByRole('link', { name: 'カテゴリ木' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'カテゴリ木' })).toBeInTheDocument()
    expect(await screen.findByRole('list', { name: 'カテゴリ木' })).toBeInTheDocument()
    expect(document.title).toBe('カテゴリ木 ｜ 宅飲みカクテルデータベース(RC)')
  })

  test('TC-123-02: 開いたときは根だけが開き、行に名前(英名)・根なら役割・メジャーかを出す', async () => {
    const tree = await openTreePage()

    expect(rowTexts(tree)).toEqual([
      '蒸留酒(Spirits)・役割: なし・メジャー',
      'ラム(Rum)・メジャー',
      'ジン(Gin)・メジャー',
      'ソフトドリンク(Soft Drink)・役割: ソフトドリンク・メジャー',
      'ジュース(Juice)・メジャーでない',
      'その他(Other)・役割: その他・メジャー',
      'ミント(Mint)・メジャー',
    ])
  })

  test('TC-123-03: 兄弟は API の順(sort_order の順)のまま出し、名前の順に並べ替えない', async () => {
    // API が「ラム」より「ジン」を先に返したら、名前の順(ジン < ラム)ではなく API の順に出る
    const reordered = [{ ...TREE[0], children: [TREE[0].children[1], TREE[0].children[0]] }, TREE[1], TREE[2]]
    const tree = await openTreePage(reordered)

    const names = rowTexts(tree).map((text) => text.split('(')[0])
    expect(names.slice(0, 3)).toEqual(['蒸留酒', 'ジン', 'ラム'])
  })

  test('TC-123-04: 600 ノードの木を「すべて開く」で全部開き、「すべて閉じる」で根だけにできる', async () => {
    const tree = await openTreePage(buildLargeTree())

    fireEvent.click(screen.getByRole('button', { name: 'すべて開く' }))
    // getAllByRole は 600 個の役割を計算して遅いので、DOM から直接数える
    expect(tree.querySelectorAll('li')).toHaveLength(600)

    fireEvent.click(screen.getByRole('button', { name: 'すべて閉じる' }))
    expect(tree.querySelectorAll('li')).toHaveLength(6)
  })

  test('TC-123-05: ノードの開閉のボタンで、子を出したり隠したりできる', async () => {
    await openTreePage()

    fireEvent.click(screen.getByRole('button', { name: 'ジンを開く' }))
    expect(screen.getByRole('radio', { name: 'ドライジン' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'ジンを閉じる' }))
    expect(screen.queryByRole('radio', { name: 'ドライジン' })).not.toBeInTheDocument()
  })

  test('TC-123-06: 名前で絞ると、当たったノードと祖先だけが出て、祖先は開く', async () => {
    const tree = await openTreePage()

    fireEvent.change(screen.getByRole('searchbox', { name: '名前で絞り込む' }), { target: { value: 'ろんどん' } })

    expect(rowTexts(tree).map((text) => text.split('(')[0])).toEqual(['蒸留酒', 'ジン', 'ドライジン', 'ロンドンドライジン'])
  })

  test('TC-123-07: 英名で絞っても、当たったノードと祖先が出る', async () => {
    const tree = await openTreePage()

    fireEvent.change(screen.getByRole('searchbox', { name: '名前で絞り込む' }), { target: { value: 'juice' } })

    expect(rowTexts(tree).map((text) => text.split('(')[0])).toEqual(['ソフトドリンク', 'ジュース'])
  })

  test('TC-123-08: ノードを 1 つ選べ、別のノードを選ぶと前の選択は外れる', async () => {
    await openTreePage()

    fireEvent.click(screen.getByRole('radio', { name: 'ジン' }))
    expect(screen.getByRole('radio', { name: 'ジン' })).toBeChecked()

    fireEvent.click(screen.getByRole('radio', { name: 'ミント' }))
    expect(screen.getByRole('radio', { name: 'ミント' })).toBeChecked()
    expect(screen.getByRole('radio', { name: 'ジン' })).not.toBeChecked()
    expect(screen.getAllByRole('radio').filter((radio) => (radio as HTMLInputElement).checked)).toHaveLength(1)
  })

  test('TC-123-09: 木を読み込めなかったらエラーを出し、もう一度読み込むと木が出る', async () => {
    mockApi('get', '/admin/me', 200, ADMIN)
    mockApiHandler('get', '/admin/categories', () => jsonResponse({ message: 'Server Error' }, 500))
    renderAt('/admin/categories')

    expect(await screen.findByRole('alert')).toHaveTextContent('データを読み込めませんでした(エラー 500)')
    expect(screen.getByRole('navigation', { name: '管理画面のメニュー' })).toBeInTheDocument()

    mockApi('get', '/admin/categories', 200, { data: TREE })
    fireEvent.click(screen.getByRole('button', { name: 'もう一度読み込む' }))

    expect(await screen.findByRole('list', { name: 'カテゴリ木' })).toBeInTheDocument()
  })

  test('TC-123-10: 木の応答を待つあいだは、読み込み中を出す', async () => {
    mockApi('get', '/admin/me', 200, ADMIN)
    mockApiHandler('get', '/admin/categories', () => new Promise<Response>(() => {}))
    renderAt('/admin/categories')

    expect(await screen.findByRole('heading', { level: 1, name: 'カテゴリ木' })).toBeInTheDocument()
    expect(screen.getByRole('status')).toHaveTextContent('読み込み中…')
    expect(screen.queryByRole('list', { name: 'カテゴリ木' })).not.toBeInTheDocument()
  })
})

/** 根 6・各 9 の子・各 10 の孫で、ちょうど 600 ノードの木(実データと同じくらいの大きさ) */
function buildLargeTree(): TreeItem[] {
  return Array.from({ length: 6 }, (_, r) =>
    item({
      id: r + 1,
      name: `根${r}`,
      name_eng: `Root ${r}`,
      children: Array.from({ length: 9 }, (_, c) =>
        item({
          id: 100 + r * 10 + c,
          name: `子${r}-${c}`,
          name_eng: `Child ${r}-${c}`,
          children: Array.from({ length: 10 }, (_, g) =>
            item({ id: 1000 + r * 100 + c * 10 + g, name: `孫${r}-${c}-${g}`, name_eng: `Grandchild ${r}-${c}-${g}` }),
          ),
        }),
      ),
    }),
  )
}
