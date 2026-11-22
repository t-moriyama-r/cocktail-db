import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest'
import App from '../../App'
import { buildCocktailDetail } from '../../features/cocktail-detail/buildCocktailDetail'
import { mockApi } from '../../test/api'

const renderAt = (path: string) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <App />
    </MemoryRouter>,
  )

const footer = () => within(screen.getByRole('contentinfo'))

describe('共通の枠(SiteLayout)', () => {
  beforeEach(() => {
    // 用語辞典の一覧の画面が GET /api/dictionaries を呼ぶ
    mockApi('get', '/dictionaries', 200, { data: [] })
    // カクテルの詳細の画面が GET /api/cocktails/{id} を呼ぶ(見出しは「マティーニ(Martini)」)
    mockApi('get', '/cocktails/{cocktail}', 200, { data: buildCocktailDetail() })
    // 詳細の画面は、同じ名前・材料が似た・同じベースのカクテルの API も呼ぶ。どれも 0 件を返す。
    // 差し替えは `{cocktail}` を任意の 1 区切りに当て、後から登録したものを先に使うので、
    // /cocktails/random は上の登録より後に登録する(登録しないと詳細の本文が返り、一覧が配列でなく落ちる。#569)
    mockApi('get', '/cocktails/{cocktail}/same-name', 200, { data: [] })
    mockApi('get', '/cocktails/{cocktail}/related', 200, { data: [] })
    mockApi('get', '/cocktails/random', 200, { data: [] })
  })

  test('TC-296-01: フッターに著作の表示と、作者のサイトへのリンク(新しいタブ)がある', async () => {
    renderAt('/')

    expect(screen.getByRole('contentinfo')).toHaveTextContent('宅飲みカクテルデータベース ©ろんぐ')
    const link = footer().getByRole('link', { name: 'ろんぐ' })
    expect(link).toHaveAttribute('href', 'https://trc-vlackhistory.net/')
    expect(link).toHaveAttribute('target', '_blank')
    expect(link).toHaveAttribute('rel', 'noopener noreferrer')
  })

  test('TC-296-02: フッターに作者の X へのアイコンのリンク(新しいタブ)がある', () => {
    renderAt('/')

    const link = footer().getByRole('link', { name: '作者の X' })
    expect(link).toHaveAttribute('href', 'https://twitter.com/virtualDollym')
    expect(link).toHaveAttribute('target', '_blank')
    expect(link).toHaveAttribute('rel', 'noopener noreferrer')
  })

  test.each([
    ['/', '宅飲みカクテルデータベース'],
    ['/detail/cocktail/1', 'マティーニ(Martini)'],
    ['/nope', 'ページが見つかりません'],
  ])('TC-296-04: %s でも、ヘッダーの場所とフッターの中に画面を出す', async (path, heading) => {
    renderAt(path)

    expect(await screen.findByRole('heading', { level: 1, name: heading })).toBeInTheDocument()
    expect(screen.getByRole('banner')).toBeInTheDocument()
    expect(footer().getByRole('link', { name: 'ろんぐ' })).toBeInTheDocument()
  })

  test.each([['/'], ['/detail/cocktail/1'], ['/nope']])(
    'TC-641-02: %s のフッターに、更新ボランティア募集へのリンクが無い',
    async (path) => {
      renderAt(path)

      await screen.findByRole('heading', { level: 1 })
      // 右には、プライバシーポリシーへのリンクを置いた(#59)
      expect(footer().getAllByRole('link').map((link) => link.getAttribute('href'))).toEqual([
        'https://trc-vlackhistory.net/',
        'https://twitter.com/virtualDollym',
        '/privacy',
      ])
      expect(screen.getByRole('contentinfo')).not.toHaveTextContent('ボランティア')
    },
  )

  test.each([
    ['/', 'トップページ ｜ 宅飲みカクテルデータベース(RC)'],
    ['/index', 'トップページ ｜ 宅飲みカクテルデータベース(RC)'],
    ['/result/Cocktails', 'カクテル検索結果 ｜ 宅飲みカクテルデータベース(RC)'],
    ['/dictionary', '用語辞典 ｜ 宅飲みカクテルデータベース(RC)'],
    ['/nope', 'ページが見つかりません ｜ 宅飲みカクテルデータベース(RC)'],
  ])('TC-296-05: ページ名を渡す画面 %s のタイトルは「%s」', async (path, title) => {
    renderAt(path)

    await screen.findByRole('heading', { level: 1 })
    await waitFor(() => expect(document.title).toBe(title))
  })

  test.each([['/detail/cocktail/1']])(
    'TC-296-06: ページ名を渡さない画面 %s のタイトルはサイト名だけ',
    async (path) => {
      document.title = '前のタイトル'
      renderAt(path)

      await screen.findByRole('heading', { level: 1 })
      await waitFor(() => expect(document.title).toBe('宅飲みカクテルデータベース(RC)'))
    },
  )

  test('TC-296-07: 画面を移るとタイトルが変わり、ページ名の無い画面ではサイト名だけに戻る', async () => {
    // ヘッダーの登録数のリンク(ランダムウォーク)で、ページ名を渡さないカクテルの詳細へ移る。
    // /cocktails/count は `{cocktail}` の差し替えより後に登録して先に当てる
    mockApi('get', '/cocktails/count', 200, { count: 1 })
    mockApi('get', '/random-walk', 200, { type: 'cocktail', id: 1 })
    renderAt('/')
    await screen.findByRole('heading', { level: 1, name: '宅飲みカクテルデータベース' })
    await waitFor(() => expect(document.title).toBe('トップページ ｜ 宅飲みカクテルデータベース(RC)'))

    fireEvent.click(await within(screen.getByRole('banner')).findByRole('link', { name: '現在の登録数：1' }))

    await screen.findByRole('heading', { level: 1, name: 'マティーニ(Martini)' })
    await waitFor(() => expect(document.title).toBe('宅飲みカクテルデータベース(RC)'))
  })
})

describe('フッターのプライバシーポリシーへのリンク(#59)', () => {
  beforeEach(() => {
    mockApi('get', '/dictionaries', 200, { data: [] })
  })

  test.each([['/'], ['/dictionary'], ['/nope']])(
    'TC-59-11: %s のフッターのリンクから、同じタブでプライバシーポリシーを開ける',
    async (path) => {
      renderAt(path)
      await screen.findByRole('heading', { level: 1 })

      const link = footer().getByRole('link', { name: 'プライバシーポリシー' })
      expect(link).toHaveAttribute('href', '/privacy')
      expect(link).not.toHaveAttribute('target')
      fireEvent.click(link)

      expect(await screen.findByRole('heading', { level: 1, name: 'プライバシーポリシー' })).toBeInTheDocument()
    },
  )
})

// GA4 の page_view(#59)。gtag はサーバーが HTML に入れる外のスクリプト(gtag.js)の関数なので、境界として差し替え、
// 送った中身(外への送信そのものが仕様)を確かめる。差し替えない(測定 ID が未設定でタグが無い)ときは window.gtag が無い
describe('アクセス解析(GA4)の page_view(#59)', () => {
  const gtag = vi.fn()
  const pageViews = () =>
    gtag.mock.calls
      .filter(([command, eventName]) => command === 'event' && eventName === 'page_view')
      .map(([, , params]) => params as { page_location: string; page_title: string })

  beforeEach(() => {
    mockApi('get', '/dictionaries', 200, { data: [] })
    window.gtag = gtag
  })

  afterEach(() => {
    delete window.gtag
    gtag.mockReset()
  })

  test('TC-59-12: 公開ページを開くと、page_view を 1 回送る(URL とページ名は開いた画面のもの)', async () => {
    renderAt('/dictionary?x=1')
    await screen.findByRole('heading', { level: 1, name: '用語辞典' })

    await waitFor(() =>
      expect(pageViews()).toEqual([
        { page_location: `${window.location.origin}/dictionary?x=1`, page_title: '用語辞典 ｜ 宅飲みカクテルデータベース(RC)' },
      ]),
    )
  })

  test('TC-59-13: 画面を移るたびに、移った先の page_view を送る。同じ URL へのリンクを押しても送らない', async () => {
    renderAt('/nope')
    await screen.findByRole('heading', { level: 1, name: 'ページが見つかりません' })

    fireEvent.click(footer().getByRole('link', { name: 'プライバシーポリシー' }))
    await screen.findByRole('heading', { level: 1, name: 'プライバシーポリシー' })
    fireEvent.click(footer().getByRole('link', { name: 'プライバシーポリシー' }))

    await waitFor(() =>
      expect(pageViews()).toEqual([
        { page_location: `${window.location.origin}/nope`, page_title: 'ページが見つかりません ｜ 宅飲みカクテルデータベース(RC)' },
        { page_location: `${window.location.origin}/privacy`, page_title: 'プライバシーポリシー ｜ 宅飲みカクテルデータベース(RC)' },
      ]),
    )
  })

  test('TC-59-14: タグが無い(測定 ID が未設定)ときは、送らずにそのまま画面を出し、移れる', async () => {
    delete window.gtag
    renderAt('/nope')
    await screen.findByRole('heading', { level: 1, name: 'ページが見つかりません' })

    fireEvent.click(footer().getByRole('link', { name: 'プライバシーポリシー' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'プライバシーポリシー' })).toBeInTheDocument()
    expect(gtag).not.toHaveBeenCalled()
  })
})
