import { render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { describe, expect, test } from 'vitest'
import type { components } from '../api/schema'
import { buildCocktailRow } from '../components/CocktailTable/buildCocktailRow'
import { SITE_TITLE } from '../components/SiteLayout/usePageTitle'
import { apiRequests, jsonResponse, mockApi, mockApiHandler } from '../test/api'
import BrandDetail from './BrandDetail'

type BrandDetailResource = components['schemas']['BrandDetailResource']
type BrandSummary = components['schemas']['BrandSummaryResource']
type NodeRef = components['schemas']['NodeRefResource']
type AffiliateResource = components['schemas']['AffiliateResource']

// E2eSeeder と同じ形の木(蒸留酒 > ジン > ドライジン > ロンドンドライジン)
const LIQUOR: NodeRef = { id: 1, name: '蒸留酒', name_eng: 'Spirits' }
const GIN: NodeRef = { id: 2, name: 'ジン', name_eng: 'Gin' }
const DRY_GIN: NodeRef = { id: 3, name: 'ドライジン', name_eng: 'Dry Gin' }
const LONDON_DRY_GIN: NodeRef = { id: 4, name: 'ロンドンドライジン', name_eng: 'London Dry Gin' }

/** 銘柄の詳細の応答。path は根から所属ノードまで(所属ノードが末尾)。既定は所属ノードがドライジン(深さ 3)で、一覧はどちらも 0 件 */
const buildBrand = (overrides: Partial<BrandDetailResource> = {}): BrandDetailResource => ({
  id: 10,
  name: 'タンカレー',
  name_eng: 'Tanqueray',
  strength: 47.3,
  description: null,
  is_major: true,
  is_like: false,
  youtube: null,
  youtube_created_at: null,
  youtube_start_seconds: null,
  image: null,
  image_txt: null,
  image_url: null,
  created_at: null,
  updated_at: null,
  path: [LIQUOR, GIN, DRY_GIN],
  same_node_brands: [],
  parent_subtree_brands: [],
  ...overrides,
})

const summary = (id: number, name: string, path: NodeRef[]): BrandSummary => ({
  id,
  name,
  name_eng: `${name} (eng)`,
  strength: 40,
  youtube: null,
  path,
})

/** 商品の欄の応答。手で入れた Amazon の商品のページだけを持つ(欄が出る最小の形) */
const AFFILIATE: AffiliateResource = {
  category_id: DRY_GIN.id,
  brand_id: 10,
  name: 'タンカレー',
  display: null,
  amazon: { title: null, price: null, image_url: null, detail_url: null, search_url: null, fetched_at: null },
  rakuten: { title: null, price: null, image_url: null, detail_url: null, search_url: null, fetched_at: null },
  human: {
    title: null,
    price: null,
    image_url: null,
    amazon_detail_url: 'https://www.amazon.co.jp/dp/B000000099?tag=example-22',
    amazon_search_url: null,
  },
}

/**
 * /detail/brand/{id} を開く。詳細のほかに画面が呼ぶ、これを使ったカクテル(GET /api/cocktails/random)と
 * 商品の欄(GET /api/affiliates)にも応答を返す。商品の欄は、affiliate を渡さなければ行が無い(404)
 */
const openBrand = async (
  brand: BrandDetailResource,
  { cocktails = [], affiliate = null }: { cocktails?: ReturnType<typeof buildCocktailRow>[]; affiliate?: AffiliateResource | null } = {},
) => {
  mockApi('get', '/brands/{brand}', 200, { data: brand })
  mockApi('get', '/cocktails/random', 200, { data: cocktails })
  if (affiliate) {
    mockApi('get', '/affiliates', 200, { data: affiliate })
  } else {
    mockApiHandler('get', '/affiliates', () => jsonResponse({ message: 'Not Found' }, 404))
  }
  render(
    <MemoryRouter initialEntries={[`/detail/brand/${brand.id}`]}>
      <Routes>
        <Route path="/detail/brand/:id" element={<BrandDetail />} />
      </Routes>
    </MemoryRouter>,
  )
  await screen.findByRole('heading', { level: 1 })
}

const breadcrumb = () => screen.queryByRole('navigation', { name: 'パンくず' })
const requestPaths = () => apiRequests().map((request) => `${request.method} ${new URL(request.url).pathname}`)
const requestOf = (pathname: string) => apiRequests().find((request) => new URL(request.url).pathname === pathname)

describe('銘柄の詳細の画面', () => {
  test('TC-54-01: GET /api/brands/{URL の ID} を呼び、見出しとブラウザのタイトルに「名前(英名)」を出す', async () => {
    await openBrand(buildBrand({ id: 10, name: 'タンカレー', name_eng: 'Tanqueray' }))

    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(/^タンカレー\(Tanqueray\)$/)
    expect(requestPaths()).toContain('GET /api/brands/10')
    await waitFor(() => expect(document.title).toBe(`タンカレー(Tanqueray) ｜ ${SITE_TITLE}`))
  })

  test('TC-54-02: 所属ノードが根(深さ 1)なら、パンくずは根の 1 段だけで、リンクにしない', async () => {
    await openBrand(buildBrand({ path: [LIQUOR] }))

    expect(breadcrumb()).toHaveTextContent(/^蒸留酒$/)
    expect(within(breadcrumb()!).queryAllByRole('link')).toHaveLength(0)
  })

  test('TC-54-03: 所属ノードが深さ 3 なら、パンくずは根から所属ノードまでの 3 段で、根の下の段はノードの詳細へのリンク', async () => {
    await openBrand(buildBrand({ path: [LIQUOR, GIN, DRY_GIN] }))

    expect(breadcrumb()).toHaveTextContent(/^蒸留酒 \/ ジン \/ ドライジン$/)
    const links = within(breadcrumb()!).getAllByRole('link')
    expect(links.map((link) => [link.textContent, link.getAttribute('href')])).toEqual([
      ['ジン', '/category/2'],
      ['ドライジン', '/category/3'],
    ])
  })

  test('TC-54-04: Data の欄に「Strength:〈度数〉%」を出し、度数を太字にする', async () => {
    await openBrand(buildBrand({ strength: 47.3 }))

    const data = screen.getByRole('region', { name: 'Data' })
    expect(data).toHaveTextContent(/^DataStrength:47\.3%$/)
    expect(within(data).getByText('47.3').tagName).toBe('B')
  })

  test('TC-54-05: 度数が 0 の銘柄は「Strength:0%」と出す', async () => {
    await openBrand(buildBrand({ strength: 0 }))

    expect(screen.getByRole('region', { name: 'Data' })).toHaveTextContent(/Strength:0%$/)
  })

  test('TC-54-06: 説明文と、開始位置つきの YouTube を出す', async () => {
    await openBrand(
      buildBrand({
        description: [{ type: 'text', text: 'ロンドンの辛口のジン。' }],
        youtube: 'abcdefghijk',
        youtube_start_seconds: 90,
      }),
    )

    expect(screen.getByText('ロンドンの辛口のジン。')).toBeInTheDocument()
    expect(screen.getByTitle('YouTube の動画')).toHaveAttribute('src', 'https://www.youtube.com/embed/abcdefghijk?start=90')
  })

  test('TC-54-07: 動画の無い銘柄は YouTube を出さない', async () => {
    await openBrand(buildBrand({ youtube: null }))

    expect(screen.queryByTitle('YouTube の動画')).not.toBeInTheDocument()
  })

  test('TC-54-08: 画像のある銘柄は、画像と出典を出し、商品の欄は下にだけ出す', async () => {
    await openBrand(
      buildBrand({ image: 'tanqueray.png', image_txt: '出典のサイト', image_url: 'https://example.com/tanqueray' }),
      { affiliate: AFFILIATE },
    )

    expect(screen.getByRole('img', { name: 'タンカレー' })).toHaveAttribute('src', '/storage/tanqueray.png')
    expect(screen.getByRole('link', { name: '出典のサイト' })).toHaveAttribute('href', 'https://example.com/tanqueray')
    const bottom = screen.getByTestId('affiliate-bottom')
    expect(await within(bottom).findByRole('complementary', { name: '商品' })).toBeInTheDocument()
    expect(screen.getAllByRole('complementary', { name: '商品' })).toHaveLength(1)
    expect(screen.queryByTestId('affiliate-in-image')).not.toBeInTheDocument()
  })

  test('TC-54-09: 画像の無い銘柄は、画像の場所と下の両方に商品の欄を置く(どちらを見せるかは幅で決める)', async () => {
    await openBrand(buildBrand({ image: null }), { affiliate: AFFILIATE })

    expect(await within(screen.getByTestId('affiliate-in-image')).findByRole('complementary', { name: '商品' })).toBeInTheDocument()
    expect(await within(screen.getByTestId('affiliate-bottom')).findByRole('complementary', { name: '商品' })).toBeInTheDocument()
    expect(screen.queryByRole('img', { name: 'タンカレー' })).not.toBeInTheDocument()
  })

  test('TC-54-10: 商品の欄は、所属ノードと銘柄の ID で GET /api/affiliates を呼ぶ', async () => {
    await openBrand(buildBrand({ id: 10, path: [LIQUOR, GIN, DRY_GIN] }), { affiliate: AFFILIATE })
    await screen.findAllByRole('complementary', { name: '商品' })

    const query = new URL(requestOf('/api/affiliates')!.url).searchParams
    expect(query.get('category_id')).toBe(String(DRY_GIN.id))
    expect(query.get('brand_id')).toBe('10')
  })

  test('TC-54-11: 商品の行が無い(404)銘柄は、商品の欄をどこにも出さない', async () => {
    await openBrand(buildBrand({ image: null }))
    await waitFor(() => expect(requestPaths()).toContain('GET /api/affiliates'))

    expect(screen.queryByRole('complementary', { name: '商品' })).not.toBeInTheDocument()
  })

  test('TC-54-12: 「これを使ったカクテル(ランダム5種)」を、銘柄の ID で GET /api/cocktails/random から取って出す', async () => {
    await openBrand(buildBrand({ id: 10 }), { cocktails: [buildCocktailRow({ id: 1, name: 'ジントニック' })] })

    const section = screen.getByRole('region', { name: /これを使ったカクテル\(ランダム5種\)/ })
    expect(await within(section).findByRole('link', { name: 'ジントニック' })).toBeInTheDocument()
    const query = new URL(requestOf('/api/cocktails/random')!.url).searchParams
    expect(query.get('brand_id')).toBe('10')
    expect(query.get('limit')).toBe('5')
  })

  test('TC-54-13: 深さ 3 以上の銘柄は、同じノードの一覧を「〈所属ノード〉の銘柄の一覧」、親ノードの配下の一覧を「〈親ノード〉銘柄の一覧」で出す', async () => {
    await openBrand(
      buildBrand({
        path: [LIQUOR, GIN, DRY_GIN],
        same_node_brands: [summary(11, 'ゴードン', [LIQUOR, GIN, DRY_GIN])],
        parent_subtree_brands: [summary(12, 'ビーフィーター 40度', [LIQUOR, GIN, DRY_GIN, LONDON_DRY_GIN])],
      }),
    )

    const sameNode = screen.getByRole('region', { name: 'ドライジンの銘柄の一覧' })
    expect(within(sameNode).getByRole('link', { name: 'ゴードン' })).toHaveAttribute('href', '/detail/brand/11')
    const parent = screen.getByRole('region', { name: 'ジン銘柄の一覧' })
    expect(within(parent).getByRole('link', { name: 'ビーフィーター 40度' })).toHaveAttribute('href', '/detail/brand/12')
    // 旧と同じ並び: 同じノードの一覧が先
    expect(screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent)).toEqual([
      'これを使ったカクテル(ランダム5種)',
      'ドライジンの銘柄の一覧',
      'ジン銘柄の一覧',
    ])
  })

  test('TC-54-14: 親が根(いちばん上の段)の銘柄は、旧と同じく同じノードと配下の銘柄を ID の順に 1 つにまとめ、「〈所属ノード〉銘柄の一覧」で出す', async () => {
    await openBrand(
      buildBrand({
        path: [LIQUOR, GIN],
        same_node_brands: [summary(11, 'ゴードン', [LIQUOR, GIN]), summary(14, 'ボンベイ', [LIQUOR, GIN])],
        parent_subtree_brands: [summary(12, 'タンカレー', [LIQUOR, GIN, DRY_GIN])],
      }),
    )

    expect(screen.queryByRole('region', { name: 'ジンの銘柄の一覧' })).not.toBeInTheDocument()
    const list = screen.getByRole('region', { name: 'ジン銘柄の一覧' })
    const names = within(list)
      .getAllByRole('row')
      .slice(1)
      .map((row) => within(row).getAllByRole('cell')[0].textContent)
    expect(names).toEqual(['ゴードン', 'タンカレー', 'ボンベイ'])
  })

  test('TC-54-24: 所属ノードが根の銘柄は、同じノードのほかの銘柄を「〈根〉の銘柄の一覧」で出す', async () => {
    await openBrand(
      buildBrand({ path: [LIQUOR], same_node_brands: [summary(11, 'スピリタス', [LIQUOR])], parent_subtree_brands: [] }),
    )

    const list = screen.getByRole('region', { name: '蒸留酒の銘柄の一覧' })
    expect(within(list).getByRole('link', { name: 'スピリタス' })).toHaveAttribute('href', '/detail/brand/11')
    expect(screen.getAllByRole('heading', { level: 2 })).toHaveLength(2)
  })

  test('TC-54-15: 同じノードのほかの銘柄が無ければ、同じノードの一覧を出さず、親ノードの配下の一覧だけを出す', async () => {
    await openBrand(
      buildBrand({
        path: [LIQUOR, GIN, DRY_GIN],
        same_node_brands: [],
        parent_subtree_brands: [summary(12, 'ビーフィーター 40度', [LIQUOR, GIN, DRY_GIN, LONDON_DRY_GIN])],
      }),
    )

    expect(screen.queryByRole('region', { name: 'ドライジンの銘柄の一覧' })).not.toBeInTheDocument()
    expect(screen.getByRole('region', { name: 'ジン銘柄の一覧' })).toBeInTheDocument()
  })

  test('TC-54-16: どちらの一覧も 0 件なら、銘柄の一覧の欄を出さない', async () => {
    await openBrand(buildBrand({ same_node_brands: [], parent_subtree_brands: [] }))

    expect(screen.queryAllByRole('table', { name: /銘柄の一覧$/ })).toHaveLength(0)
    expect(screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent)).toEqual(['これを使ったカクテル(ランダム5種)'])
  })

  test('TC-54-17: 無い ID(API が 404)は 404 の画面を出す', async () => {
    mockApiHandler('get', '/brands/{brand}', () => jsonResponse({ message: 'Not Found' }, 404))
    render(
      <MemoryRouter initialEntries={['/detail/brand/999999']}>
        <Routes>
          <Route path="/detail/brand/:id" element={<BrandDetail />} />
        </Routes>
      </MemoryRouter>,
    )

    expect(await screen.findByRole('heading', { level: 1, name: 'ページが見つかりません' })).toBeInTheDocument()
    await waitFor(() => expect(document.title).toBe(`ページが見つかりません ｜ ${SITE_TITLE}`))
  })
})
