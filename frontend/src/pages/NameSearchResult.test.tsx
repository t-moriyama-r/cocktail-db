import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { describe, expect, test } from 'vitest'
import type { components, paths } from '../api/schema'
import { apiRequests, jsonResponse, mockApi, mockApiHandler } from '../test/api'
import NameSearchResult from './NameSearchResult'

// 名前検索の結果の画面(#52)。GET /api/search の応答を fetch の層で差し替え、画面に出た文字・役割を確かめる

type NameSearchResponse = paths['/search']['get']['responses'][200]['content']['application/json']
type NodeRef = components['schemas']['NodeRefResource']

const root: NodeRef = { id: 1, name: '蒸留酒', name_eng: 'Spirits' }
const rum: NodeRef = { id: 7, name: 'ラム', name_eng: 'Rum' }
const white: NodeRef = { id: 70, name: 'ホワイトラム', name_eng: 'White Rum' }
const cuban: NodeRef = { id: 700, name: 'キューバンホワイトラム', name_eng: 'Cuban White Rum' }

const cocktail = (id: number, name: string): components['schemas']['CocktailSummaryResource'] => ({
  id,
  name,
  name_eng: null,
  is_major: false,
  base: rum,
  method: { id: 1, name: 'ビルド' },
  recipe_count: 2,
  strength: 10,
  sweet_degree: null,
  rate: null,
  youtube: null,
  updated_at: null,
})

const nodeHit = (path: NodeRef[]): components['schemas']['NameSearchNodeResource'] => {
  const self = path[path.length - 1]
  return { ...self, parent: path[path.length - 2], path }
}

const brandHit = (
  id: number,
  name: string,
  path: NodeRef[],
  volume = 40,
): components['schemas']['NameSearchBrandResource'] => ({
  id,
  name,
  name_eng: `Brand${id}`,
  volume,
  youtube: null,
  category: path[path.length - 1],
  path,
})

const EMPTY: NameSearchResponse = { cocktails: [], nodes: [], brands: [], ingredient_matches: [] }

/** 4 つの欄すべてに 1 件ずつ当たった応答 */
const ALL_KINDS: NameSearchResponse = {
  ingredient_matches: [cocktail(101, 'ダイキリ')],
  cocktails: [{ ...cocktail(1, 'ラム・コーク'), matched_alias: null }],
  nodes: [nodeHit([root, rum])],
  brands: [brandHit(5, 'バカルディ', [root, rum, white])],
}

const renderAt = (path: string) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/result/FromNameResult/:word" element={<NameSearchResult />} />
        <Route path="/result/FromNameResultEx/:word" element={<NameSearchResult />} />
      </Routes>
    </MemoryRouter>,
  )

const resultPath = (word: string) => `/result/FromNameResult/${encodeURIComponent(word)}`

const SECTIONS = ['インテリジェンスサーチ結果', 'カクテル検索結果', 'カテゴリー検索結果', '銘柄検索結果'] as const

/** 「結果」の見出しが出る(応答を受けて描き終えた)まで待つ */
const waitForResult = async () => {
  expect(await screen.findByRole('heading', { level: 1, name: '結果' })).toBeInTheDocument()
}

describe('名前検索の結果の画面', () => {
  test('TC-52-05: 4 つの欄が、インテリジェンス・カクテル・カテゴリー・銘柄の順に、件数と検索語句付きで出る', async () => {
    mockApi('get', '/search', 200, ALL_KINDS)
    renderAt(resultPath('ラム'))
    await waitForResult()

    const regions = screen.getAllByRole('region')
    expect(regions.map((r) => r.getAttribute('aria-label'))).toEqual([...SECTIONS])
    expect(regions[0]).toHaveTextContent('インテリジェンスサーチ結果 1件ヒットしました。(検索語句：ラム)')
    expect(regions[0]).toHaveTextContent('列名をクリックするとソートします。')
    expect(regions[1]).toHaveTextContent('カクテル検索結果 1件ヒットしました。(検索語句：ラム)')
    expect(regions[2]).toHaveTextContent('カテゴリー検索結果 1件ヒットしました。(検索語句：ラム)')
    expect(regions[3]).toHaveTextContent('銘柄検索結果 1件ヒットしました。(検索語句：ラム)')
    // 欄ごとの表に、その種類の行が出る
    expect(within(regions[0]).getByRole('link', { name: 'ダイキリ' })).toHaveAttribute('href', '/detail/cocktail/101')
    expect(within(regions[1]).getByRole('link', { name: 'ラム・コーク' })).toHaveAttribute('href', '/detail/cocktail/1')
    expect(within(regions[2]).getAllByRole('link', { name: 'ラム' })[0]).toHaveAttribute('href', '/category/7')
    expect(within(regions[3]).getByRole('link', { name: 'バカルディ' })).toHaveAttribute('href', '/detail/brand/5')
  })

  test.each<[string, NameSearchResponse]>([
    ['インテリジェンスサーチ結果', { ...ALL_KINDS, ingredient_matches: [] }],
    ['カクテル検索結果', { ...ALL_KINDS, cocktails: [] }],
    ['カテゴリー検索結果', { ...ALL_KINDS, nodes: [] }],
    ['銘柄検索結果', { ...ALL_KINDS, brands: [] }],
  ])('TC-52-06: 「%s」が 0 件なら、その欄だけを出さない', async (section, response) => {
    mockApi('get', '/search', 200, response)
    renderAt(resultPath('ラム'))
    await waitForResult()

    const labels = screen.getAllByRole('region').map((r) => r.getAttribute('aria-label'))
    expect(labels).toEqual(SECTIONS.filter((s) => s !== section))
    expect(screen.queryByText(new RegExp(`${section} 0件`))).not.toBeInTheDocument()
  })

  test('TC-52-07: どの欄も 0 件なら、「結果」の見出しだけを出す', async () => {
    mockApi('get', '/search', 200, EMPTY)
    renderAt(resultPath('該当なし'))
    await waitForResult()

    expect(screen.queryAllByRole('region')).toEqual([])
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
  })

  test('TC-52-08: URL を直に開くと、URL の語(空白を含む)で検索する', async () => {
    mockApi('get', '/search', 200, ALL_KINDS)
    renderAt(resultPath('ラム コーク'))
    await waitForResult()

    const searches = apiRequests().filter((r) => new URL(r.url).pathname.endsWith('/search'))
    expect(searches).toHaveLength(1)
    expect(new URL(searches[0].url).searchParams.get('q')).toBe('ラム コーク')
    expect(screen.getByRole('region', { name: 'カクテル検索結果' })).toHaveTextContent('(検索語句：ラム コーク)')
  })

  test('TC-52-09: /result/FromNameResultEx/〈語〉も同じ画面で、URL の語で検索する', async () => {
    mockApi('get', '/search', 200, ALL_KINDS)
    renderAt(`/result/FromNameResultEx/${encodeURIComponent('ラム')}`)
    await waitForResult()

    const search = apiRequests().find((r) => new URL(r.url).pathname.endsWith('/search'))
    expect(new URL(search!.url).searchParams.get('q')).toBe('ラム')
    expect(screen.getAllByRole('region')).toHaveLength(4)
  })

  test('TC-52-10: 別名で当たったカクテルには、当たった別名を名前の後ろに添える。名前で当たったカクテルには添えない', async () => {
    mockApi('get', '/search', 200, {
      ...EMPTY,
      cocktails: [
        { ...cocktail(1, 'ピーチ・ウーロン'), matched_alias: null },
        { ...cocktail(2, 'レゲエ・パンチ'), matched_alias: 'ピーチウーロン' },
      ],
    })
    renderAt(resultPath('ピーチウーロン'))
    await waitForResult()

    const rows = within(screen.getByRole('table', { name: 'カクテル検索結果' })).getAllByRole('row')
    // 1 行目は見出し
    expect(rows[1]).toHaveTextContent('ピーチ・ウーロン')
    expect(rows[1]).not.toHaveTextContent('別名')
    expect(rows[2]).toHaveTextContent('レゲエ・パンチ(別名: ピーチウーロン)')
  })

  test('TC-52-11: カテゴリーの表は、名前・Name をノードの詳細へのリンクにし、親は根を除いた祖先を「いちばん上 / すぐ上」の形で出す', async () => {
    mockApi('get', '/search', 200, {
      ...EMPTY,
      nodes: [nodeHit([root, rum]), nodeHit([root, rum, white]), nodeHit([root, rum, white, cuban])],
    })
    renderAt(resultPath('ラム'))
    await waitForResult()

    const table = screen.getByRole('table', { name: 'カテゴリー検索結果' })
    expect(within(table).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['名前', 'Name', '親'])
    const [, top, second, deep] = within(table).getAllByRole('row')
    const cells = (row: HTMLElement) => within(row).getAllByRole('cell')

    // いちばん上の段(親が根)は、親を出さない
    expect(within(cells(top)[0]).getByRole('link', { name: 'ラム' })).toHaveAttribute('href', '/category/7')
    expect(within(cells(top)[1]).getByRole('link', { name: 'Rum' })).toHaveAttribute('href', '/category/7')
    expect(cells(top)[2]).toHaveTextContent(/^$/)
    // 2 段目は、いちばん上の段だけ
    expect(cells(second)[2]).toHaveTextContent(/^ラム$/)
    expect(within(cells(second)[2]).getByRole('link', { name: 'ラム' })).toHaveAttribute('href', '/category/7')
    // 3 段目より深いノードは、いちばん上の段とすぐ上の親(間は省く)
    expect(cells(deep)[2]).toHaveTextContent(/^ラム \/ ホワイトラム$/)
    expect(within(cells(deep)[2]).getByRole('link', { name: 'ホワイトラム' })).toHaveAttribute('href', '/category/70')
  })

  test('TC-52-12: 銘柄の表は、所属をいちばん上の段と所属するノードで出し、度数は volume を出す', async () => {
    mockApi('get', '/search', 200, {
      ...EMPTY,
      brands: [brandHit(5, 'バカルディ', [root, rum, white, cuban], 37.5)],
    })
    renderAt(resultPath('バカルディ'))
    await waitForResult()

    const table = screen.getByRole('table', { name: '銘柄検索結果' })
    const [, row] = within(table).getAllByRole('row')
    expect(row).toHaveTextContent('ラム(Rum) / キューバンホワイトラム(Cuban White Rum)')
    expect(row).toHaveTextContent('37.5')
  })

  test('TC-52-13: 検索が失敗したらエラーを出し、「もう一度読み込む」で検索し直す', async () => {
    mockApiHandler('get', '/search', () => jsonResponse({ message: 'Server Error' }, 500))
    renderAt(resultPath('ラム'))

    expect(await screen.findByRole('alert')).toHaveTextContent('エラー 500')

    mockApi('get', '/search', 200, ALL_KINDS)
    fireEvent.click(screen.getByRole('button', { name: 'もう一度読み込む' }))

    await waitForResult()
    expect(screen.getAllByRole('region')).toHaveLength(4)
  })
})
