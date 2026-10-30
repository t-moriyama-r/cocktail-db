import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { beforeEach, describe, expect, test } from 'vitest'
import App from '../../App'
import type { paths } from '../../api/schema'
import { buildCocktailDetail } from '../../features/cocktail-detail/buildCocktailDetail'
import { mockApi } from '../../test/api'

type HelloResponse = paths['/hello']['get']['responses'][200]['content']['application/json']

const HELLO: HelloResponse = { message: 'Hello World from Laravel' }

const renderAt = (path: string) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <App />
    </MemoryRouter>,
  )

const footer = () => within(screen.getByRole('contentinfo'))

describe('共通の枠(SiteLayout)', () => {
  beforeEach(() => {
    // トップの画面が GET /api/hello を、用語辞典の一覧の画面が GET /api/dictionaries を呼ぶ
    mockApi('get', '/hello', 200, HELLO)
    mockApi('get', '/dictionaries', 200, { data: [] })
    // カクテルの詳細の画面が GET /api/cocktails/{id} を呼ぶ(見出しは「マティーニ(Martini)」)
    mockApi('get', '/cocktails/{cocktail}', 200, { data: buildCocktailDetail() })
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

  test('TC-296-03: フッターの「更新ボランティア募集中...」で /helpme の画面に移る', async () => {
    renderAt('/')

    fireEvent.click(footer().getByRole('link', { name: '更新ボランティア募集中...' }))

    expect(await screen.findByRole('heading', { level: 1, name: '更新ボランティア募集' })).toBeInTheDocument()
  })

  test.each([
    ['/', 'Hello World from Laravel'],
    ['/helpme', '更新ボランティア募集'],
    ['/detail/cocktail/1', 'マティーニ(Martini)'],
    ['/nope', 'ページが見つかりません'],
  ])('TC-296-04: %s でも、ヘッダーの場所とフッターの中に画面を出す', async (path, heading) => {
    renderAt(path)

    expect(await screen.findByRole('heading', { level: 1, name: heading })).toBeInTheDocument()
    expect(screen.getByRole('banner')).toBeInTheDocument()
    expect(footer().getByRole('link', { name: '更新ボランティア募集中...' })).toBeInTheDocument()
  })

  test.each([
    ['/', 'トップページ ｜ 宅飲みカクテルデータベース(RC)'],
    ['/index', 'トップページ ｜ 宅飲みカクテルデータベース(RC)'],
    ['/result/Cocktails', 'カクテル検索結果 ｜ 宅飲みカクテルデータベース(RC)'],
    ['/dictionary', '用語辞典 ｜ 宅飲みカクテルデータベース(RC)'],
    ['/nope', 'ページが見つかりません ｜ 宅飲みカクテルデータベース(RC)'],
  ])('TC-296-05: ページ名を渡す画面 %s のタイトルは「%s」', async (path, title) => {
    renderAt(path)

    await screen.findByRole('heading', { level: 1 })
    expect(document.title).toBe(title)
  })

  test.each([['/helpme'], ['/detail/cocktail/1']])(
    'TC-296-06: ページ名を渡さない画面 %s のタイトルはサイト名だけ',
    async (path) => {
      document.title = '前のタイトル'
      renderAt(path)

      await screen.findByRole('heading', { level: 1 })
      expect(document.title).toBe('宅飲みカクテルデータベース(RC)')
    },
  )

  test('TC-296-07: 画面を移るとタイトルが変わり、ページ名の無い画面ではサイト名だけに戻る', async () => {
    renderAt('/')
    await screen.findByRole('heading', { level: 1, name: HELLO.message })
    expect(document.title).toBe('トップページ ｜ 宅飲みカクテルデータベース(RC)')

    fireEvent.click(footer().getByRole('link', { name: '更新ボランティア募集中...' }))

    await screen.findByRole('heading', { level: 1, name: '更新ボランティア募集' })
    expect(document.title).toBe('宅飲みカクテルデータベース(RC)')
  })
})
