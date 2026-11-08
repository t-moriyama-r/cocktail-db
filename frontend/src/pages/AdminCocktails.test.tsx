import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router'
import { describe, expect, test } from 'vitest'
import App from '../App'
import type { paths } from '../api/schema'
import { jsonResponse, mockApi, mockApiHandler } from '../test/api'

type CocktailIndex = paths['/admin/cocktails']['get']['responses'][200]['content']['application/json']
type CocktailItem = CocktailIndex['data'][number]

/** API の 1 ページの件数(#284 の 6) */
const PER_PAGE = 50

const cocktail = (id: number, name: string, nameEng: string | null, isDraft: boolean): CocktailItem => ({
  id,
  name,
  name_eng: nameEng,
  is_draft: isDraft,
  updated_at: null,
})

/** 公開済み 2 件・下書き 2 件(下書きの 1 件は英名が無い) */
const COCKTAILS: CocktailItem[] = [
  cocktail(1, 'ジントニック', 'Gin and Tonic', false),
  cocktail(2, 'モヒート', 'Mojito', false),
  cocktail(3, '下書きのカクテル', 'Draft', true),
  cocktail(4, '作りかけのカクテル', null, true),
]

/**
 * 管理画面のカクテルの一覧の API(#289)の代わり。サーバーと同じく、status で下書き・公開済みに分け、
 * q を名前・英名の部分一致に使い、50 件ずつに切って返す
 */
const mockCocktailIndex = (cocktails: CocktailItem[]) =>
  mockApiHandler('get', '/admin/cocktails', (request) => {
    const params = new URL(request.url).searchParams
    const status = params.get('status')
    if (status !== 'published' && status !== 'draft') {
      return jsonResponse({ message: 'The status field is required.' }, 422)
    }
    const q = params.get('q')
    const page = Number(params.get('page') ?? '1')
    const hits = cocktails
      .filter((c) => c.is_draft === (status === 'draft'))
      .filter((c) => q === null || c.name.includes(q) || (c.name_eng ?? '').includes(q))
    const data = hits.slice((page - 1) * PER_PAGE, page * PER_PAGE)
    const from = data.length === 0 ? null : (page - 1) * PER_PAGE + 1
    const body: CocktailIndex = {
      data,
      links: { first: null, last: null, prev: null, next: null },
      meta: {
        current_page: page,
        from,
        last_page: Math.max(1, Math.ceil(hits.length / PER_PAGE)),
        links: [],
        path: '/api/admin/cocktails',
        per_page: PER_PAGE,
        to: from === null ? null : from + data.length - 1,
        total: hits.length,
      },
    }
    return jsonResponse(body, 200)
  })

/** いまの URL(パス・クエリ)を画面に出す。切り替えで URL が変わるかを確かめるため */
function CurrentUrl() {
  const { pathname, search } = useLocation()
  return <div data-testid="current-url">{`${pathname}${search}`}</div>
}

const renderAt = (path: string) => {
  mockApi('get', '/admin/me', 200, { id: 1, name: '管理者の太郎' })
  mockCocktailIndex(COCKTAILS)
  return render(
    <MemoryRouter initialEntries={[path]}>
      <App />
      <CurrentUrl />
    </MemoryRouter>,
  )
}

const currentUrl = () => screen.getByTestId('current-url').textContent

const switcher = () => screen.getByRole('navigation', { name: 'カクテルの一覧の切り替え' })

/** 切り替えのうち、いま選ばれている(aria-current="page")ものの名前 */
const currentSwitch = () =>
  within(switcher())
    .getAllByRole('link')
    .filter((link) => link.getAttribute('aria-current') === 'page')
    .map((link) => link.textContent)

/** 一覧に出ている行の文字(上から) */
const rowTexts = () =>
  screen
    .getAllByRole('listitem')
    .filter((li) => li.closest('nav') === null)
    .map((li) => li.textContent)

describe('メニュー', () => {
  test('TC-126-01: メニューの「カクテル」を押すと、カクテルの一覧(/admin/cocktails)を開く', async () => {
    renderAt('/admin')

    const menu = await screen.findByRole('navigation', { name: '管理画面のメニュー' })
    fireEvent.click(within(menu).getByRole('link', { name: 'カクテル' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'カクテル' })).toBeInTheDocument()
    expect(currentUrl()).toBe('/admin/cocktails')
    expect(within(menu).getByRole('link', { name: 'カクテル' })).toHaveAttribute('aria-current', 'page')
  })
})

describe('公開済み・下書きの切り替え', () => {
  test('TC-126-02: /admin/cocktails を開くと、公開済みが選ばれ、公開済みのカクテルだけを出す', async () => {
    renderAt('/admin/cocktails')

    expect(await screen.findByRole('link', { name: 'ジントニック(Gin and Tonic)' })).toBeInTheDocument()
    expect(rowTexts()).toEqual(['ジントニック(Gin and Tonic)', 'モヒート(Mojito)'])
    expect(currentSwitch()).toEqual(['公開済み'])
    await waitFor(() => expect(document.title).toBe('カクテル ｜ 宅飲みカクテルデータベース(RC)'))
  })

  test('TC-126-03: 「下書き」を押すと、URL が ?status=draft になり、下書きのカクテルだけを出す', async () => {
    renderAt('/admin/cocktails')
    await screen.findByRole('link', { name: 'ジントニック(Gin and Tonic)' })

    fireEvent.click(within(switcher()).getByRole('link', { name: '下書き' }))

    expect(await screen.findByRole('link', { name: '下書きのカクテル(Draft)' })).toBeInTheDocument()
    expect(rowTexts()).toEqual(['下書きのカクテル(Draft)', '作りかけのカクテル()'])
    expect(currentSwitch()).toEqual(['下書き'])
    expect(currentUrl()).toBe('/admin/cocktails?status=draft')
  })

  test('TC-126-04: /admin/cocktails?status=draft を開くと、下書きが選ばれ、下書きのカクテルだけを出す', async () => {
    renderAt('/admin/cocktails?status=draft')

    expect(await screen.findByRole('link', { name: '下書きのカクテル(Draft)' })).toBeInTheDocument()
    expect(rowTexts()).toEqual(['下書きのカクテル(Draft)', '作りかけのカクテル()'])
    expect(currentSwitch()).toEqual(['下書き'])
  })

  test('TC-126-05: 下書きの一覧で「公開済み」を押すと、クエリの無い URL に戻り、公開済みのカクテルだけを出す', async () => {
    renderAt('/admin/cocktails?status=draft')
    await screen.findByRole('link', { name: '下書きのカクテル(Draft)' })

    fireEvent.click(within(switcher()).getByRole('link', { name: '公開済み' }))

    expect(await screen.findByRole('link', { name: 'ジントニック(Gin and Tonic)' })).toBeInTheDocument()
    expect(rowTexts()).toEqual(['ジントニック(Gin and Tonic)', 'モヒート(Mojito)'])
    expect(currentSwitch()).toEqual(['公開済み'])
    expect(currentUrl()).toBe('/admin/cocktails')
  })

  test.each(['foo', '', 'DRAFT'])('TC-126-06: status が知らない値(%j)なら、公開済みとして出す', async (value) => {
    renderAt(`/admin/cocktails?status=${value}`)

    expect(await screen.findByRole('link', { name: 'ジントニック(Gin and Tonic)' })).toBeInTheDocument()
    expect(rowTexts()).toEqual(['ジントニック(Gin and Tonic)', 'モヒート(Mojito)'])
    expect(currentSwitch()).toEqual(['公開済み'])
  })

  test('TC-126-07: 絞り込んでから種類を切り替えると、絞り込みの語を消し、切り替えた先の一覧を全件で出す', async () => {
    renderAt('/admin/cocktails')
    await screen.findByRole('link', { name: 'ジントニック(Gin and Tonic)' })
    fireEvent.change(screen.getByRole('searchbox', { name: '絞り込み' }), { target: { value: 'モヒート' } })
    await screen.findByText('モヒート(Mojito)')
    expect(screen.queryByRole('link', { name: 'ジントニック(Gin and Tonic)' })).not.toBeInTheDocument()

    fireEvent.click(within(switcher()).getByRole('link', { name: '下書き' }))

    expect(await screen.findByRole('link', { name: '下書きのカクテル(Draft)' })).toBeInTheDocument()
    expect(rowTexts()).toEqual(['下書きのカクテル(Draft)', '作りかけのカクテル()'])
    expect(screen.getByRole('searchbox', { name: '絞り込み' })).toHaveValue('')
  })
})

describe('行', () => {
  test('TC-126-08: 1 行は「名前(英名)」で、そのカクテルの編集画面(/admin/cocktails/{id})へのリンク', async () => {
    renderAt('/admin/cocktails?status=draft')

    expect(await screen.findByRole('link', { name: '下書きのカクテル(Draft)' })).toHaveAttribute('href', '/admin/cocktails/3')
    expect(screen.getByRole('link', { name: '作りかけのカクテル()' })).toHaveAttribute('href', '/admin/cocktails/4')
  })
})
