import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, test } from 'vitest'
import { api } from '../../api/client'
import type { paths } from '../../api/schema'
import { jsonResponse, mockApiHandler } from '../../test/api'
import AdminList, { type AdminListQuery } from './AdminList'
import AdminListLink from './AdminListLink'

type CocktailIndex = paths['/admin/cocktails']['get']['responses'][200]['content']['application/json']
type CocktailItem = CocktailIndex['data'][number]

/** API の 1 ページの件数(#284 の 6) */
const PER_PAGE = 50

const cocktail = (id: number, name: string, nameEng: string | null): CocktailItem => ({
  id,
  name,
  name_eng: nameEng,
  is_draft: false,
  updated_at: null,
})

/** `count` 件の見本。名前は「カクテル001」のように番号の順 */
const numbered = (count: number) =>
  Array.from({ length: count }, (_, i) => cocktail(i + 1, `カクテル${String(i + 1).padStart(3, '0')}`, `Cocktail ${i + 1}`))

/**
 * 管理画面のカクテルの一覧の API(#289)の代わり。受け取った q をそのまま(前後の空白も含めて)名前の部分一致に使い、
 * 50 件ずつに切って返す。並びは渡した順のまま(並べ替えは API の仕事)
 */
const mockCocktailIndex = (cocktails: CocktailItem[]) =>
  mockApiHandler('get', '/admin/cocktails', (request) => {
    const params = new URL(request.url).searchParams
    const q = params.get('q')
    const page = Number(params.get('page') ?? '1')
    const hits = q === null ? cocktails : cocktails.filter((c) => c.name.includes(q) || (c.name_eng ?? '').includes(q))
    const lastPage = Math.max(1, Math.ceil(hits.length / PER_PAGE))
    const data = hits.slice((page - 1) * PER_PAGE, page * PER_PAGE)
    const from = data.length === 0 ? null : (page - 1) * PER_PAGE + 1
    const body: CocktailIndex = {
      data,
      links: { first: null, last: null, prev: null, next: null },
      meta: {
        current_page: page,
        from,
        last_page: lastPage,
        links: [],
        path: '/api/admin/cocktails',
        per_page: PER_PAGE,
        to: from === null ? null : from + data.length - 1,
        total: hits.length,
      },
    }
    return jsonResponse(body, 200)
  })

const loadCocktails = ({ q, page }: AdminListQuery) =>
  api.GET('/admin/cocktails', { params: { query: { status: 'published', q, page } } })

const renderList = (searchable?: boolean) =>
  render(
    <MemoryRouter>
      <AdminList
        load={loadCocktails}
        itemKey={(c) => c.id}
        renderItem={(c) => <AdminListLink to={`/admin/cocktails/${c.id}`} name={c.name} nameEng={c.name_eng} />}
        searchable={searchable}
      />
    </MemoryRouter>,
  )

/** 出ている行の文字(上から) */
const rowTexts = () => screen.getAllByRole('listitem').map((li) => li.textContent)

const searchBox = () => screen.getByRole('searchbox', { name: '絞り込み' })

const pager = () => screen.queryByRole('navigation', { name: 'ページ送り' })

describe('行の並び', () => {
  test('TC-120-01: 1 行は「名前(英名)」の編集画面へのリンクで、API が返した順に並ぶ', async () => {
    mockCocktailIndex([cocktail(3, 'ジントニック', 'Gin & Tonic'), cocktail(1, 'モヒート', 'Mojito')])
    renderList()

    const links = await screen.findAllByRole('link')
    expect(links.map((a) => a.textContent)).toEqual(['ジントニック(Gin & Tonic)', 'モヒート(Mojito)'])
    expect(links.map((a) => a.getAttribute('href'))).toEqual(['/admin/cocktails/3', '/admin/cocktails/1'])
  })

  test('TC-120-02: 英名が無い行は、括弧の中を空にして「名前()」と出す', async () => {
    mockCocktailIndex([cocktail(1, '自家製のカクテル', null)])
    renderList()

    expect(await screen.findByRole('link', { name: '自家製のカクテル()' })).toBeInTheDocument()
  })
})

describe('0 件', () => {
  test('TC-120-03: 一覧が 0 件なら「該当するものがありません」と出し、行もページ送りも出さない', async () => {
    mockCocktailIndex([])
    renderList()

    expect(await screen.findByText('該当するものがありません')).toBeInTheDocument()
    expect(screen.queryAllByRole('listitem')).toHaveLength(0)
    expect(pager()).not.toBeInTheDocument()
  })

  test('TC-120-04: 絞り込んで 0 件になったら「該当するものがありません」と出し、絞り込みの欄は残る', async () => {
    mockCocktailIndex([cocktail(1, 'モヒート', 'Mojito')])
    renderList()
    await screen.findByRole('link', { name: 'モヒート(Mojito)' })

    fireEvent.change(searchBox(), { target: { value: 'ジン' } })

    expect(await screen.findByText('該当するものがありません')).toBeInTheDocument()
    expect(searchBox()).toHaveValue('ジン')
  })
})

describe('ページ送り', () => {
  test('TC-120-05: ちょうど 1 ページ(50 件)なら、50 行を出し、ページ送りを出さない', async () => {
    mockCocktailIndex(numbered(50))
    renderList()

    expect(await screen.findByRole('link', { name: 'カクテル050(Cocktail 50)' })).toBeInTheDocument()
    expect(screen.getAllByRole('listitem')).toHaveLength(50)
    expect(pager()).not.toBeInTheDocument()
  })

  test('TC-120-06: 51 件なら 1 ページ目に 50 行と「1 / 2 ページ」を出し、前のページは押せず次のページは押せる', async () => {
    mockCocktailIndex(numbered(51))
    renderList()

    await screen.findByRole('link', { name: 'カクテル050(Cocktail 50)' })
    expect(screen.getAllByRole('listitem')).toHaveLength(50)
    const nav = pager()!
    expect(within(nav).getByText('1 / 2 ページ')).toBeInTheDocument()
    expect(within(nav).getByRole('button', { name: '前のページ' })).toBeDisabled()
    expect(within(nav).getByRole('button', { name: '次のページ' })).toBeEnabled()
  })

  test('TC-120-07: 次のページを押すと 2 ページ目(51 件目の 1 行)を出し、次のページは押せず前のページは押せる', async () => {
    mockCocktailIndex(numbered(51))
    renderList()
    await screen.findByRole('link', { name: 'カクテル050(Cocktail 50)' })

    fireEvent.click(screen.getByRole('button', { name: '次のページ' }))

    expect(await screen.findByText('2 / 2 ページ')).toBeInTheDocument()
    expect(rowTexts()).toEqual(['カクテル051(Cocktail 51)'])
    expect(screen.getByRole('button', { name: '次のページ' })).toBeDisabled()
    expect(screen.getByRole('button', { name: '前のページ' })).toBeEnabled()
  })

  test('TC-120-08: 2 ページ目で前のページを押すと 1 ページ目に戻る', async () => {
    mockCocktailIndex(numbered(51))
    renderList()
    await screen.findByRole('link', { name: 'カクテル050(Cocktail 50)' })
    fireEvent.click(screen.getByRole('button', { name: '次のページ' }))
    await screen.findByText('2 / 2 ページ')

    fireEvent.click(screen.getByRole('button', { name: '前のページ' }))

    expect(await screen.findByText('1 / 2 ページ')).toBeInTheDocument()
    expect(screen.getAllByRole('listitem')).toHaveLength(50)
  })

  test('TC-120-09: 2 ページ目で絞り込みの語を変えると、1 ページ目から出し直す', async () => {
    // 「カクテル」で絞っても 51 件で 2 ページある。2 ページ目にいるまま語を変える
    mockCocktailIndex(numbered(51))
    renderList()
    await screen.findByRole('link', { name: 'カクテル050(Cocktail 50)' })
    fireEvent.click(screen.getByRole('button', { name: '次のページ' }))
    await screen.findByText('2 / 2 ページ')

    fireEvent.change(searchBox(), { target: { value: 'カクテル' } })

    expect(await screen.findByText('1 / 2 ページ')).toBeInTheDocument()
    expect(rowTexts()[0]).toBe('カクテル001(Cocktail 1)')
  })
})

describe('絞り込み', () => {
  test('TC-120-10: 入れたそばから、語で絞り込んだ一覧に変わる', async () => {
    mockCocktailIndex([cocktail(1, 'ジントニック', 'Gin & Tonic'), cocktail(2, 'モヒート', 'Mojito')])
    renderList()
    await screen.findByRole('link', { name: 'モヒート(Mojito)' })

    fireEvent.change(searchBox(), { target: { value: 'トニック' } })

    expect(await screen.findByRole('link', { name: 'ジントニック(Gin & Tonic)' })).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'モヒート(Mojito)' })).not.toBeInTheDocument()
  })

  test.each([
    { label: '半角の空白', value: '  トニック  ' },
    { label: '全角の空白', value: '　トニック　' },
    { label: 'タブ', value: '\tトニック' },
  ])('TC-120-11: 語の前後の空白($label)を除いて絞り込む', async ({ value }) => {
    mockCocktailIndex([cocktail(1, 'ジントニック', 'Gin & Tonic'), cocktail(2, 'モヒート', 'Mojito')])
    renderList()
    await screen.findByRole('link', { name: 'モヒート(Mojito)' })

    fireEvent.change(searchBox(), { target: { value } })

    expect(await screen.findByRole('link', { name: 'ジントニック(Gin & Tonic)' })).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'モヒート(Mojito)' })).not.toBeInTheDocument()
    expect(searchBox()).toHaveValue(value)
  })

  test('TC-120-12: 空白だけを入れたときは絞り込まず、全件の一覧のまま出す', async () => {
    mockCocktailIndex([cocktail(1, 'ジントニック', 'Gin & Tonic'), cocktail(2, 'モヒート', 'Mojito')])
    renderList()
    await screen.findByRole('link', { name: 'モヒート(Mojito)' })

    fireEvent.change(searchBox(), { target: { value: ' 　 ' } })

    expect(rowTexts()).toEqual(['ジントニック(Gin & Tonic)', 'モヒート(Mojito)'])
    expect(screen.queryByText('該当するものがありません')).not.toBeInTheDocument()
  })

  test('TC-120-13: 語の中の空白は除かない(「ジン トニック」は「ジントニック」に当たらない)', async () => {
    mockCocktailIndex([cocktail(1, 'ジントニック', 'Gin & Tonic')])
    renderList()
    await screen.findByRole('link', { name: 'ジントニック(Gin & Tonic)' })

    fireEvent.change(searchBox(), { target: { value: 'ジン トニック' } })

    expect(await screen.findByText('該当するものがありません')).toBeInTheDocument()
  })

  test('TC-120-14: 絞り込みの欄を出さない使い方では、欄を出さずに全件の一覧を出す', async () => {
    mockCocktailIndex([cocktail(1, 'ジントニック', 'Gin & Tonic'), cocktail(2, 'モヒート', 'Mojito')])
    renderList(false)

    await screen.findByRole('link', { name: 'モヒート(Mojito)' })
    expect(rowTexts()).toEqual(['ジントニック(Gin & Tonic)', 'モヒート(Mojito)'])
    expect(screen.queryByRole('searchbox')).not.toBeInTheDocument()
  })
})

describe('読み込み中・失敗', () => {
  test('TC-120-15: 応答を待つあいだは「読み込み中…」を出し、0 件の文言は出さない', async () => {
    mockApiHandler('get', '/admin/cocktails', () => new Promise<Response>(() => {}))
    renderList()

    expect(await screen.findByRole('status')).toHaveTextContent('読み込み中…')
    expect(screen.queryByText('該当するものがありません')).not.toBeInTheDocument()
  })

  test('TC-120-16: API が失敗したらエラーと「もう一度読み込む」を出し、押すと読み直して一覧を出す', async () => {
    mockApiHandler('get', '/admin/cocktails', () => jsonResponse({ message: 'Server Error' }, 500))
    renderList()

    expect(await screen.findByRole('alert')).toHaveTextContent('データを読み込めませんでした(エラー 500)')
    expect(searchBox()).toBeInTheDocument()

    mockCocktailIndex([cocktail(1, 'モヒート', 'Mojito')])
    fireEvent.click(screen.getByRole('button', { name: 'もう一度読み込む' }))

    expect(await screen.findByRole('link', { name: 'モヒート(Mojito)' })).toBeInTheDocument()
  })
})
