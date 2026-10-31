import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter, useLocation, useNavigate } from 'react-router'
import { beforeEach, describe, expect, test } from 'vitest'
import App from '../App'
import type { components, paths } from '../api/schema'
import { jsonResponse, mockApi, mockApiHandler } from '../test/api'

type SearchResponse = paths['/cocktails/search']['post']['responses'][200]['content']['application/json']
type SearchRequest = components['schemas']['CocktailSearchRequest']
type SearchFormNode = components['schemas']['SearchFormNodeResource']

const node = (id: number, name: string, children: SearchFormNode[] = []): SearchFormNode => ({
  id,
  name,
  is_major: true,
  root_role: null,
  children,
})

/** 蒸留酒(1) > ジン(10)・ラム(11) > ホワイトラム(110)・ダークラム(111)。画面の並び(名前の順)では ジン → ラム */
const searchForm: paths['/search-form']['get']['responses'][200]['content']['application/json'] = {
  categories: [node(1, '蒸留酒', [node(11, 'ラム', [node(110, 'ホワイトラム'), node(111, 'ダークラム')]), node(10, 'ジン')])],
  methods: [
    { id: 1, name: 'ビルド' },
    { id: 2, name: 'ステア' },
    { id: 3, name: 'シェイク' },
  ],
}

const cocktail = (id: number, name: string): components['schemas']['CocktailSummaryResource'] => ({
  id,
  name,
  name_eng: null,
  is_major: false,
  base: { id: 10, name: 'ジン', name_eng: 'Gin' },
  method: { id: 1, name: 'ビルド' },
  recipe_count: 2,
  strength: 10,
  sweet_degree: null,
  rate: null,
  youtube: null,
  updated_at: null,
})

/** 1 ページ分の応答。件数(total)は表の行の数と別に渡せる */
const pageOf = (cocktails: components['schemas']['CocktailSummaryResource'][], total = cocktails.length): SearchResponse => ({
  data: cocktails,
  links: { first: null, last: null, prev: null, next: null },
  meta: { current_page: 1, from: 1, last_page: 1, links: [], path: null, per_page: 50, to: cocktails.length, total },
})

/** 送られた検索の本文(送った順) */
let sentBodies: SearchRequest[] = []

/** 今の URL を出し、戻るボタンの代わりを置く */
function Probe() {
  const { pathname, search } = useLocation()
  const navigate = useNavigate()
  return (
    <>
      <div data-testid="location">{`${pathname}${search}`}</div>
      <button type="button" onClick={() => void navigate(-1)}>
        ブラウザの戻る
      </button>
    </>
  )
}

const renderAt = (path: string) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <App />
      <Probe />
    </MemoryRouter>,
  )

const location = () => screen.getByTestId('location')
const results = () => within(screen.getByRole('region', { name: 'カクテル検索結果' }))
const openModal = () => {
  fireEvent.click(within(screen.getByRole('banner')).getByRole('button', { name: '検索をはじめる' }))
  return within(screen.getByRole('dialog', { name: 'カクテル検索' }))
}

describe('条件検索の結果の画面(CocktailSearchResult)', () => {
  beforeEach(() => {
    sentBodies = []
    mockApi('get', '/cocktails/count', 200, { count: 3 })
    mockApi('get', '/random-walk', 200, { type: 'cocktail', id: 7 })
    mockApi('get', '/search-form', 200, searchForm)
    // 送った材料が分かるよう、材料の ID を名前にしたカクテルを 1 件返す。件数は 120 件とする
    mockApiHandler('post', '/cocktails/search', async (request) => {
      const body = (await request.json()) as SearchRequest
      sentBodies.push(body)
      const ids = body.category_ids ?? body.category_groups?.flat() ?? []
      return jsonResponse(pageOf([cocktail(1, `材料${ids.join('・')}のカクテル`)], 120), 200)
    })
  })

  test('TC-102-14: URL を直接開くと、その条件で検索して件数と表を出し、ヘッダーのモーダルのフォームにも条件が戻る', async () => {
    renderAt('/result/Cocktails?n=110,10&mode=having&easy=1&m=1,3')

    expect(await screen.findByRole('link', { name: '材料10・110のカクテル' })).toBeInTheDocument()
    expect(screen.getByText(/カクテル検索結果 120件ヒットしました。/)).toBeInTheDocument()
    expect(sentBodies).toEqual([
      { mode: 'having', category_ids: [10, 110], method_ids: [1, 3], is_easy: true, page: 1 },
    ])

    const modal = openModal()
    expect(await modal.findByRole('radio', { name: 'あるもの検索' })).toBeChecked()
    expect(modal.getByRole('checkbox', { name: 'かんたん検索' })).toBeChecked()
    expect(modal.getByRole('checkbox', { name: 'ジン' })).toBeChecked()
    fireEvent.click(modal.getByRole('button', { name: 'ラムを開く' }))
    expect(modal.getByRole('checkbox', { name: 'ホワイトラム' })).toBeChecked()
    expect(modal.getByRole('checkbox', { name: 'ダークラム' })).not.toBeChecked()
    expect(within(modal.getByRole('group', { name: '製法' })).getByRole('checkbox', { name: 'ステア' })).not.toBeChecked()
    expect(within(modal.getByRole('group', { name: '製法' })).getByRole('checkbox', { name: 'シェイク' })).toBeChecked()
  })

  test('TC-102-15: 不正な値(数字でない ID・知らない mode・負のページ・知らない並び)は無視し、既定値で検索する', async () => {
    renderAt('/result/Cocktails?n=abc,10,999&mode=xor&page=-1&sort=price&m=zz')

    expect(await screen.findByRole('link', { name: '材料10のカクテル' })).toBeInTheDocument()
    expect(sentBodies).toEqual([{ mode: 'or', category_ids: [10], method_ids: [1, 2, 3], is_easy: false, page: 1 }])
  })

  test.each([
    ['クエリ無し', '/result/Cocktails'],
    ['材料の ID がどれも不正', '/result/Cocktails?n=abc,999&mode=and'],
  ])('TC-102-16: %s のときは検索せず、条件の無い検索の画面を出す。材料を選んで検索すると URL に条件が入る', async (_, path) => {
    renderAt(path)

    const form = within(await screen.findByRole('form', { name: 'カクテル検索' }))
    expect(screen.queryByRole('region', { name: 'カクテル検索結果' })).not.toBeInTheDocument()
    expect(form.getByText('材料は必須です。')).toBeInTheDocument()
    expect(sentBodies).toEqual([])

    fireEvent.click(form.getByRole('checkbox', { name: 'ジン' }))
    fireEvent.click(form.getByRole('button', { name: '検索' }))

    expect(location()).toHaveTextContent(/^\/result\/Cocktails\?n=10(&mode=and)?$/)
    expect(await screen.findByRole('link', { name: '材料10のカクテル' })).toBeInTheDocument()
  })

  test('TC-102-17: 結果の画面でモーダルから検索し直すと URL が変わり(結果も入れ替わり)、戻るボタンで前の条件と結果に戻る', async () => {
    renderAt('/result/Cocktails?n=10')
    expect(await screen.findByRole('link', { name: '材料10のカクテル' })).toBeInTheDocument()

    const modal = openModal()
    fireEvent.click(await modal.findByRole('checkbox', { name: 'ラム' }))
    fireEvent.click(modal.getByRole('radio', { name: 'AND検索' }))
    fireEvent.click(modal.getByRole('button', { name: '検索' }))

    // ジンとラムで蒸留酒の子がすべて選ばれたので、蒸留酒 1 つに畳む
    expect(location()).toHaveTextContent(/^\/result\/Cocktails\?n=1&mode=and$/)
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(await screen.findByRole('link', { name: '材料1のカクテル' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'ブラウザの戻る' }))

    expect(location()).toHaveTextContent(/^\/result\/Cocktails\?n=10$/)
    expect(await screen.findByRole('link', { name: '材料10のカクテル' })).toBeInTheDocument()
    const restored = openModal()
    expect(await restored.findByRole('radio', { name: 'OR検索' })).toBeChecked()
    expect(restored.getByRole('checkbox', { name: 'ラム' })).not.toBeChecked()
    expect(sentBodies.map((body) => body.mode)).toEqual(['or', 'and', 'or'])
  })

  test('TC-102-18: 列の見出しで並べ替えると、並びを URL に入れ、同じ条件の 1 ページ目をその並びで取り直す', async () => {
    renderAt('/result/Cocktails?n=10&page=3')
    await screen.findByRole('link', { name: '材料10のカクテル' })

    fireEvent.click(results().getByRole('button', { name: '度数' }))

    expect(location()).toHaveTextContent(/^\/result\/Cocktails\?n=10&sort=strength&order=desc$/)
    await screen.findByRole('link', { name: '材料10のカクテル' })
    expect(sentBodies.map(({ sort, order, page }) => ({ sort, order, page }))).toEqual([
      { sort: undefined, order: undefined, page: 3 },
      { sort: 'strength', order: 'desc', page: 1 },
    ])
  })

  test('TC-102-23: 検索が失敗したらエラーを出し、もう一度読み込むと結果を出す', async () => {
    let failing = true
    mockApiHandler('post', '/cocktails/search', () =>
      failing ? jsonResponse({ message: 'Server Error' }, 500) : jsonResponse(pageOf([cocktail(1, 'ジントニック')]), 200),
    )
    renderAt('/result/Cocktails?n=10')

    expect(await screen.findByRole('alert')).toHaveTextContent('データを読み込めませんでした(エラー 500)')
    failing = false
    fireEvent.click(screen.getByRole('button', { name: 'もう一度読み込む' }))
    expect(await screen.findByRole('link', { name: 'ジントニック' })).toBeInTheDocument()
  })
})

/** total 件のカクテル(ID 1〜total。名前は「カクテル<ID>」)を、API と同じく 50 件ずつに分けて、送られたページを返す */
const mockSearchOf = (total: number) => {
  mockApiHandler('post', '/cocktails/search', async (request) => {
    const body = (await request.json()) as SearchRequest
    sentBodies.push(body)
    const page = body.page ?? 1
    const perPage = 50
    const lastPage = Math.max(1, Math.ceil(total / perPage))
    const from = (page - 1) * perPage + 1
    const ids = Array.from({ length: Math.max(0, Math.min(perPage, total - from + 1)) }, (_, i) => from + i)
    const response: SearchResponse = {
      data: ids.map((id) => cocktail(id, `カクテル${id}`)),
      links: { first: null, last: null, prev: null, next: null },
      meta: {
        current_page: page,
        from: ids.length > 0 ? from : null,
        last_page: lastPage,
        links: [],
        path: null,
        per_page: perPage,
        to: ids.length > 0 ? from + ids.length - 1 : null,
        total,
      },
    }
    return jsonResponse(response, 200)
  })
}

/** 表の本体の行(見出しの行を除く) */
const bodyRows = () => {
  const table = results().getByRole('table', { name: 'カクテル検索結果' })
  return within(table).getAllByRole('row').slice(1)
}
const pager = () => screen.queryByRole('navigation', { name: 'カクテル検索結果のページ' })

describe('条件検索の結果の画面: 件数とページ(#103)', () => {
  beforeEach(() => {
    sentBodies = []
    mockApi('get', '/cocktails/count', 200, { count: 3 })
    mockApi('get', '/random-walk', 200, { type: 'cocktail', id: 7 })
    mockApi('get', '/search-form', 200, searchForm)
  })

  test('TC-103-02: 見出しは「結果」、ページの題は「カクテル検索結果」', async () => {
    mockSearchOf(1)
    renderAt('/result/Cocktails?n=10')

    expect(await screen.findByRole('heading', { level: 1, name: '結果' })).toBeInTheDocument()
    expect(document.title).toMatch(/^カクテル検索結果 ｜ /)
  })

  test.each([
    ['0 件', 0, 0],
    ['1 件', 1, 1],
    ['ちょうど 1 ページ(50 件)', 50, 50],
  ])('TC-103-03: %s は、件数と %i 行の表を出し、ページを選ぶ部品は出さない', async (_, total, rows) => {
    mockSearchOf(total)
    renderAt('/result/Cocktails?n=10')

    expect(await screen.findByText(`カクテル検索結果 ${total}件ヒットしました。`, { exact: false })).toBeInTheDocument()
    expect(results().getByText(/列名をクリックするとソートします。/)).toBeInTheDocument()
    expect(bodyRows()).toHaveLength(rows)
    expect(results().getByRole('columnheader', { name: '名前' })).toBeInTheDocument()
    expect(pager()).not.toBeInTheDocument()
  })

  test('TC-103-04: 1 ページ + 1 件(51 件)の 1 ページ目は、50 行と、いまのページ 1・2 ページ目・「次へ」を出す(「前へ」は無い)', async () => {
    mockSearchOf(51)
    renderAt('/result/Cocktails?n=10')

    expect(await screen.findByText(/カクテル検索結果 51件ヒットしました。/)).toBeInTheDocument()
    expect(bodyRows()).toHaveLength(50)
    expect(results().getByRole('link', { name: 'カクテル50' })).toBeInTheDocument()
    const nav = within(pager()!)
    expect(nav.getByText('1')).toHaveAttribute('aria-current', 'page')
    expect(nav.getByRole('link', { name: '2 ページ目' })).toHaveAttribute('href', '/result/Cocktails?n=10&page=2')
    expect(nav.getByRole('link', { name: '次へ' })).toHaveAttribute('href', '/result/Cocktails?n=10&page=2')
    expect(nav.queryByRole('link', { name: '前へ' })).not.toBeInTheDocument()
  })

  test('TC-103-05: 51 件の 2 ページ目は、51 件目の 1 行と、「前へ」・1 ページ目・いまのページ 2 を出す(「次へ」は無い)', async () => {
    mockSearchOf(51)
    renderAt('/result/Cocktails?n=10&page=2')

    expect(await screen.findByRole('link', { name: 'カクテル51' })).toBeInTheDocument()
    expect(screen.getByText(/カクテル検索結果 51件ヒットしました。/)).toBeInTheDocument()
    expect(bodyRows()).toHaveLength(1)
    const nav = within(pager()!)
    expect(nav.getByText('2')).toHaveAttribute('aria-current', 'page')
    // 1 ページ目は page を書かない
    expect(nav.getByRole('link', { name: '前へ' })).toHaveAttribute('href', '/result/Cocktails?n=10')
    expect(nav.getByRole('link', { name: '1 ページ目' })).toHaveAttribute('href', '/result/Cocktails?n=10')
    expect(nav.queryByRole('link', { name: '次へ' })).not.toBeInTheDocument()
    expect(sentBodies.map((body) => body.page)).toEqual([2])
  })

  test('TC-103-06: ページを移ると、条件と並びはそのままで URL の page が変わってそのページを取り直し、戻るボタンで前のページに戻る', async () => {
    mockSearchOf(120)
    renderAt('/result/Cocktails?n=10&mode=having&sort=strength&order=desc')
    await screen.findByRole('link', { name: 'カクテル1' })

    fireEvent.click(within(pager()!).getByRole('link', { name: '次へ' }))

    expect(location()).toHaveTextContent(/^\/result\/Cocktails\?n=10&mode=having&sort=strength&order=desc&page=2$/)
    expect(await screen.findByRole('link', { name: 'カクテル51' })).toBeInTheDocument()

    fireEvent.click(within(pager()!).getByRole('link', { name: '3 ページ目' }))
    expect(await screen.findByRole('link', { name: 'カクテル101' })).toBeInTheDocument()
    expect(location()).toHaveTextContent(/&page=3$/)

    fireEvent.click(screen.getByRole('button', { name: 'ブラウザの戻る' }))

    expect(location()).toHaveTextContent(/^\/result\/Cocktails\?n=10&mode=having&sort=strength&order=desc&page=2$/)
    expect(await screen.findByRole('link', { name: 'カクテル51' })).toBeInTheDocument()
    expect(sentBodies.map(({ mode, sort, order, page }) => ({ mode, sort, order, page }))).toEqual([
      { mode: 'having', sort: 'strength', order: 'desc', page: 1 },
      { mode: 'having', sort: 'strength', order: 'desc', page: 2 },
      { mode: 'having', sort: 'strength', order: 'desc', page: 3 },
      { mode: 'having', sort: 'strength', order: 'desc', page: 2 },
    ])
  })

  test('TC-103-07: 最後のページより後(51 件で 5 ページ目)は、件数と見出しの行だけの表を出し、「前へ」で最後のページへ移れる', async () => {
    mockSearchOf(51)
    renderAt('/result/Cocktails?n=10&page=5')

    expect(await screen.findByText(/カクテル検索結果 51件ヒットしました。/)).toBeInTheDocument()
    expect(bodyRows()).toHaveLength(0)
    const nav = within(pager()!)
    expect(nav.getByRole('link', { name: '前へ' })).toHaveAttribute('href', '/result/Cocktails?n=10&page=2')
    expect(nav.getByRole('link', { name: '1 ページ目' })).toBeInTheDocument()
    expect(nav.getByRole('link', { name: '2 ページ目' })).toBeInTheDocument()
    expect(nav.queryByRole('link', { name: '次へ' })).not.toBeInTheDocument()
    expect(nav.queryByText('5')).not.toBeInTheDocument()
  })
})
