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
