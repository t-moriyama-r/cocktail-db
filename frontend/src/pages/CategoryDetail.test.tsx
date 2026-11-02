import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { beforeEach, describe, expect, test } from 'vitest'
import type { components, paths } from '../api/schema'
import { buildCocktailRow } from '../components/CocktailTable/buildCocktailRow'
import { SITE_TITLE } from '../components/SiteLayout/usePageTitle'
import { apiRequests, jsonResponse, mockApi, mockApiHandler } from '../test/api'
import CategoryDetail from './CategoryDetail'

type CategoryDetailResource = components['schemas']['CategoryDetailResource']
type NodeRef = components['schemas']['NodeRefResource']
type BrandSummary = components['schemas']['BrandSummaryResource']
type AffiliateResource = components['schemas']['AffiliateResource']
type RandomResponse = paths['/cocktails/random']['get']['responses'][200]['content']['application/json']

// E2eSeeder と同じ形の、深さ 5 の木(蒸留酒 > ジン > ドライジン > ロンドンドライジン > ビーフィーター)
const LIQUOR: NodeRef = { id: 1, name: '蒸留酒', name_eng: 'Spirits' }
const GIN: NodeRef = { id: 2, name: 'ジン', name_eng: 'Gin' }
const DRY_GIN: NodeRef = { id: 3, name: 'ドライジン', name_eng: 'Dry Gin' }
const LONDON_DRY_GIN: NodeRef = { id: 4, name: 'ロンドンドライジン', name_eng: 'London Dry Gin' }
const BEEFEATER: NodeRef = { id: 5, name: 'ビーフィーター', name_eng: 'Beefeater' }
const VODKA: NodeRef = { id: 6, name: 'ウォッカ', name_eng: 'Vodka' }

const FAMOUS: BrandSummary = {
  id: 40,
  name: 'ビーフィーター 40度',
  name_eng: 'Beefeater 40',
  strength: 40,
  youtube: null,
  path: [LIQUOR, GIN, DRY_GIN, LONDON_DRY_GIN, BEEFEATER],
}

/** ノードの詳細の応答。path は根からこのノードまで(このノードが末尾) */
const buildNode = (path: NodeRef[], overrides: Partial<CategoryDetailResource> = {}): CategoryDetailResource => {
  const self = path[path.length - 1]
  return {
    ...self,
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
    path,
    children: [],
    famous_brand: null,
    brands: [],
    ...overrides,
  }
}

const respondWith = (node: CategoryDetailResource) => mockApi('get', '/categories/{category}', 200, { data: node })

/** ランダムのカクテルの応答。送った category_id ごとに返す(登録の無い ID は 0 件) */
const mockRandom = (byCategoryId: Record<number, RandomResponse['data']>) =>
  mockApiHandler('get', '/cocktails/random', (request) => {
    const id = Number(new URL(request.url).searchParams.get('category_id'))
    return jsonResponse({ data: byCategoryId[id] ?? [] } satisfies RandomResponse, 200)
  })

/** 商品の欄の 1 件。検索結果のページの URL だけがある(期限切れの)行 */
const AFFILIATE: AffiliateResource = {
  category_id: 2,
  brand_id: null,
  name: 'ジン',
  display: null,
  amazon: {
    title: null,
    price: null,
    image_url: null,
    detail_url: null,
    search_url: 'https://www.amazon.co.jp/s?k=gin',
    fetched_at: null,
  },
  rakuten: { title: null, price: null, image_url: null, detail_url: null, search_url: null, fetched_at: null },
  human: { title: null, price: null, image_url: null, amazon_detail_url: null, amazon_search_url: null },
}

/** 送ったリクエストの、パスとクエリ(/api から後ろ) */
const sentUrls = () =>
  apiRequests().map((request) => {
    const url = new URL(request.url)
    return `${url.pathname.replace(/^\/api/, '')}${url.search}`
  })

const BRAND_LIST = '登録されている銘柄の一覧'
const COCKTAILS = 'これを使ったカクテル(ランダム5種)'

const renderAt = (id: number) =>
  render(
    <MemoryRouter initialEntries={[`/category/${id}`]}>
      <Routes>
        <Route path="/category/:id" element={<CategoryDetail />} />
      </Routes>
    </MemoryRouter>,
  )

const findTitle = () => screen.findByRole('heading', { level: 1 })
const breadcrumb = () => screen.queryByRole('navigation', { name: 'パンくず' })

// 詳細の画面は、ランダムのカクテルと商品の欄の API も呼ぶ。テストごとに既定(カクテル 0 件・商品の行が無い 404)を置き、
// 確かめるテストだけが上書きする(後に登録したものが勝つ)
beforeEach(() => {
  mockRandom({})
  mockApiHandler('get', '/affiliates', () => jsonResponse({ message: 'Not Found' }, 404))
})

describe('カテゴリの詳細の画面', () => {
  test('TC-105-01: タイトルを「名前(英名)」で出し、ブラウザのタイトルにも同じものを出す', async () => {
    respondWith(buildNode([LIQUOR, GIN]))
    renderAt(GIN.id)

    expect(await findTitle()).toHaveTextContent('ジン(Gin)')
    // タイトルは usePageTitle の useEffect で付くので、見出しが出た直後にはまだ付いていないことがある。付くまで待つ
    await waitFor(() => expect(document.title).toBe(`ジン(Gin) ｜ ${SITE_TITLE}`))
  })

  test('TC-105-02: 根のノードは祖先が無いので、パンくずを出さない', async () => {
    respondWith(buildNode([LIQUOR], { children: [GIN, VODKA] }))
    renderAt(LIQUOR.id)

    expect(await findTitle()).toHaveTextContent('蒸留酒(Spirits)')
    expect(breadcrumb()).not.toBeInTheDocument()
  })

  test('TC-105-03: 根の直下のノードは、パンくずに根だけを出し、根はリンクにしない', async () => {
    respondWith(buildNode([LIQUOR, GIN]))
    renderAt(GIN.id)
    await findTitle()

    const nav = breadcrumb()!
    expect(nav).toHaveTextContent(/^蒸留酒$/)
    expect(within(nav).queryAllByRole('link')).toHaveLength(0)
  })

  test('TC-105-04: 中間のノード(深さ 3)は、パンくずに根と親を「/」で区切って出し、親だけをリンクにする', async () => {
    respondWith(buildNode([LIQUOR, GIN, DRY_GIN]))
    renderAt(DRY_GIN.id)
    await findTitle()

    const nav = breadcrumb()!
    expect(nav).toHaveTextContent(/^蒸留酒 \/ ジン$/)
    const links = within(nav).getAllByRole('link')
    expect(links.map((link) => [link.textContent, link.getAttribute('href')])).toEqual([['ジン', '/category/2']])
  })

  test('TC-105-05: 葉のノード(深さ 5)は、パンくずに祖先の 4 段を出し、自分は入れない', async () => {
    respondWith(buildNode([LIQUOR, GIN, DRY_GIN, LONDON_DRY_GIN, BEEFEATER]))
    renderAt(BEEFEATER.id)
    await findTitle()

    const nav = breadcrumb()!
    expect(nav).toHaveTextContent(/^蒸留酒 \/ ジン \/ ドライジン \/ ロンドンドライジン$/)
    expect(within(nav).getAllByRole('link').map((link) => link.getAttribute('href'))).toEqual([
      '/category/2',
      '/category/3',
      '/category/4',
    ])
  })

  test('TC-105-06: 説明文を、リンクの区切りをリンクにして出す', async () => {
    respondWith(
      buildNode([LIQUOR, GIN], {
        description: [
          { type: 'text', text: '杜松の実で香りを付けた蒸留酒。' },
          { type: 'node', id: 3, text: 'ドライジン', has_youtube: false },
        ],
      }),
    )
    renderAt(GIN.id)
    await findTitle()

    expect(screen.getByText('杜松の実で香りを付けた蒸留酒。', { exact: false })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'ドライジン' })).toHaveAttribute('href', '/category/3')
  })

  test('TC-105-07: 動画があれば開始位置つきで埋め込み、無ければ埋め込まない', async () => {
    respondWith(buildNode([LIQUOR, GIN], { youtube: 'abcdefghijk', youtube_start_seconds: 75 }))
    const { unmount } = renderAt(GIN.id)
    await findTitle()
    expect(screen.getByTitle('YouTube の動画')).toHaveAttribute('src', 'https://www.youtube.com/embed/abcdefghijk?start=75')
    unmount()

    respondWith(buildNode([LIQUOR, GIN]))
    renderAt(GIN.id)
    await findTitle()
    expect(screen.queryByTitle('YouTube の動画')).not.toBeInTheDocument()
  })

  test('TC-105-08: 画像があれば画像と出典を出し、アフィリエイトの場所は作らない', async () => {
    respondWith(
      buildNode([LIQUOR, GIN], { image: 'gin.jpg', image_txt: '出典のサイト', image_url: 'https://example.com/gin' }),
    )
    renderAt(GIN.id)
    await findTitle()

    // 画像の列はファイル名で返る(API は保存した値のまま返す)。画面は /storage/ の下を指す(#585)
    expect(screen.getByRole('img', { name: 'ジン' })).toHaveAttribute('src', '/storage/gin.jpg')
    expect(screen.getByRole('link', { name: '出典のサイト' })).toHaveAttribute('href', 'https://example.com/gin')
    expect(screen.queryByTestId('affiliate-in-image')).not.toBeInTheDocument()
  })

  test('TC-105-09: 画像が無ければ、画像の場所にアフィリエイトを出す場所を作る', async () => {
    respondWith(buildNode([LIQUOR, GIN]))
    renderAt(GIN.id)
    await findTitle()

    expect(screen.queryByRole('img')).not.toBeInTheDocument()
    expect(screen.getByTestId('affiliate-in-image')).toBeInTheDocument()
  })

  test('TC-105-10: 有名な銘柄があれば、欄を出して「名前(英名)」を銘柄の詳細へのリンクにする', async () => {
    respondWith(buildNode([LIQUOR, GIN], { famous_brand: FAMOUS }))
    renderAt(GIN.id)
    await findTitle()

    const section = screen.getByRole('region', { name: '有名な銘柄' })
    expect(within(section).getByRole('link', { name: 'ビーフィーター 40度(Beefeater 40)' })).toHaveAttribute(
      'href',
      '/detail/brand/40',
    )
  })

  test('TC-105-11: 有名な銘柄が無ければ、欄ごと出さない', async () => {
    respondWith(buildNode([LIQUOR, GIN]))
    renderAt(GIN.id)
    await findTitle()

    expect(screen.queryByRole('region', { name: '有名な銘柄' })).not.toBeInTheDocument()
    expect(screen.queryByText('有名な銘柄')).not.toBeInTheDocument()
  })

  test('TC-105-12: 子のノードを、返った順に「名前(英名)」でノードの詳細へのリンクにして出す', async () => {
    respondWith(buildNode([LIQUOR], { children: [GIN, VODKA] }))
    renderAt(LIQUOR.id)
    await findTitle()

    const section = screen.getByRole('region', { name: '子のノード' })
    expect(within(section).getAllByRole('link').map((link) => [link.textContent, link.getAttribute('href')])).toEqual([
      ['ジン(Gin)', '/category/2'],
      ['ウォッカ(Vodka)', '/category/6'],
    ])
    expect(within(section).queryByText('子のノードの登録なし')).not.toBeInTheDocument()
  })

  test('TC-105-13: 子のノードが 0 件でも欄を出し、「子のノードの登録なし」と出す', async () => {
    respondWith(buildNode([LIQUOR, GIN, DRY_GIN, LONDON_DRY_GIN, BEEFEATER]))
    renderAt(BEEFEATER.id)
    await findTitle()

    const section = screen.getByRole('region', { name: '子のノード' })
    expect(section).toHaveTextContent('子のノードの登録なし')
    expect(within(section).queryAllByRole('link')).toHaveLength(0)
  })

  test('TC-105-14: 無いノードの ID(404)は 404 の画面を出す', async () => {
    mockApiHandler('get', '/categories/{category}', () => jsonResponse({ message: 'Not Found' }, 404))
    renderAt(999)

    expect(await findTitle()).toHaveTextContent('ページが見つかりません')
    // タイトルは usePageTitle の useEffect で付くので、見出しが出た直後にはまだ付いていないことがある。付くまで待つ
    await waitFor(() => expect(document.title).toBe(`ページが見つかりません ｜ ${SITE_TITLE}`))
  })

  test('TC-105-15: URL の ID で API を呼び、子のノードのリンクで移ると、移った先のノードを出す', async () => {
    mockApiHandler('get', '/categories/{category}', (request) => {
      const id = Number(new URL(request.url).pathname.split('/').pop())
      const node = id === GIN.id ? buildNode([LIQUOR, GIN], { children: [DRY_GIN] }) : buildNode([LIQUOR, GIN, DRY_GIN])
      return jsonResponse({ data: node }, 200)
    })
    renderAt(GIN.id)
    expect(await findTitle()).toHaveTextContent('ジン(Gin)')

    fireEvent.click(screen.getByRole('link', { name: 'ドライジン(Dry Gin)' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'ドライジン(Dry Gin)' })).toBeInTheDocument()
    // ノードの詳細のほかに、ランダムのカクテル・商品の欄の API も呼ぶ(#106)ので、ノードの詳細の API だけを数える
    expect(
      apiRequests()
        .map((request) => new URL(request.url).pathname)
        .filter((path) => path.startsWith('/api/categories/')),
    ).toEqual(['/api/categories/2', '/api/categories/3'])
  })
})

describe('カテゴリの詳細の、銘柄の一覧・これを使ったカクテル・商品の欄', () => {
  const BRAND_IN_GIN: BrandSummary = {
    id: 41,
    name: 'ゴードン',
    name_eng: "Gordon's",
    strength: 37.5,
    youtube: 'abcdefghijk',
    path: [LIQUOR, GIN],
  }

  test('TC-106-01: 配下すべての銘柄を、返った順に銘柄の表で出し、所属はいちばん上の段と所属するノードを出す', async () => {
    respondWith(buildNode([LIQUOR, GIN], { brands: [BRAND_IN_GIN, FAMOUS] }))
    renderAt(GIN.id)
    await findTitle()

    const section = screen.getByRole('region', { name: BRAND_LIST })
    const table = within(section).getByRole('table', { name: BRAND_LIST })
    const rows = within(table).getAllByRole('row').slice(1)
    expect(rows.map((row) => within(row).getAllByRole('cell').map((cell) => cell.textContent))).toEqual([
      ['ゴードン', "Gordon's", 'ジン(Gin)', '37.5', ''],
      ['ビーフィーター 40度', 'Beefeater 40', 'ジン(Gin) / ビーフィーター(Beefeater)', '40', '-'],
    ])
    expect(within(rows[1]).getByRole('link', { name: 'ビーフィーター 40度' })).toHaveAttribute('href', '/detail/brand/40')
    expect(within(rows[1]).getByRole('link', { name: 'ビーフィーター(Beefeater)' })).toHaveAttribute('href', '/category/5')
  })

  test('TC-106-02: 銘柄が 0 件なら、銘柄の一覧の欄も見出しも出さない', async () => {
    respondWith(buildNode([LIQUOR, GIN]))
    renderAt(GIN.id)
    await findTitle()

    expect(screen.queryByRole('region', { name: BRAND_LIST })).not.toBeInTheDocument()
    expect(screen.queryByText(BRAND_LIST)).not.toBeInTheDocument()
    expect(screen.queryByRole('table', { name: BRAND_LIST })).not.toBeInTheDocument()
  })

  test('TC-106-03: これを使ったカクテルを、ノードの ID を起点にランダムの API で 5 件取って出す', async () => {
    mockRandom({
      [GIN.id]: [buildCocktailRow({ id: 11, name: 'ジントニック' }), buildCocktailRow({ id: 12, name: 'マティーニ' })],
    })
    respondWith(buildNode([LIQUOR, GIN]))
    renderAt(GIN.id)
    await findTitle()

    const section = screen.getByRole('region', { name: COCKTAILS })
    expect(await within(section).findByRole('link', { name: 'ジントニック' })).toHaveAttribute('href', '/detail/cocktail/11')
    expect(within(section).getByRole('link', { name: 'マティーニ' })).toHaveAttribute('href', '/detail/cocktail/12')
    expect(sentUrls()).toContain('/cocktails/random?limit=5&category_id=2')
  })

  test('TC-106-04: これを使ったカクテルが 0 件でも、見出しと「再取得」を出し、表は列の見出しの行だけにする', async () => {
    respondWith(buildNode([LIQUOR, GIN]))
    renderAt(GIN.id)
    await findTitle()

    const section = screen.getByRole('region', { name: COCKTAILS })
    const table = await within(section).findByRole('table', { name: COCKTAILS })
    expect(within(table).getAllByRole('row')).toHaveLength(1)
    expect(within(section).getByRole('button', { name: '再取得' })).toBeInTheDocument()
  })

  test('TC-106-05: 「再取得」を押すと、同じノードを起点に取り直し、届いた一覧に替える', async () => {
    let call = 0
    mockApiHandler('get', '/cocktails/random', () => {
      call += 1
      const name = call === 1 ? 'ジントニック' : 'ギムレット'
      return jsonResponse({ data: [buildCocktailRow({ id: call, name })] } satisfies RandomResponse, 200)
    })
    respondWith(buildNode([LIQUOR, GIN]))
    renderAt(GIN.id)
    await findTitle()

    const section = screen.getByRole('region', { name: COCKTAILS })
    expect(await within(section).findByRole('link', { name: 'ジントニック' })).toBeInTheDocument()
    fireEvent.click(within(section).getByRole('button', { name: '再取得' }))

    expect(await within(section).findByRole('link', { name: 'ギムレット' })).toBeInTheDocument()
    expect(within(section).queryByRole('link', { name: 'ジントニック' })).not.toBeInTheDocument()
    expect(sentUrls().filter((url) => url.startsWith('/cocktails/random'))).toEqual([
      '/cocktails/random?limit=5&category_id=2',
      '/cocktails/random?limit=5&category_id=2',
    ])
  })

  test('TC-106-06: 子のノードの欄の後に、これを使ったカクテル → 銘柄の一覧の順に並べる', async () => {
    respondWith(buildNode([LIQUOR, GIN], { brands: [FAMOUS] }))
    renderAt(GIN.id)
    await findTitle()

    const children = screen.getByRole('region', { name: '子のノード' })
    const brands = screen.getByRole('region', { name: BRAND_LIST })
    const cocktails = screen.getByRole('region', { name: COCKTAILS })
    expect(children.compareDocumentPosition(cocktails) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(cocktails.compareDocumentPosition(brands) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
  })

  test('TC-106-07: 画像が無ければ、商品の欄を画像の場所といちばん下の両方に置く(どちらを見せるかは幅で決める)', async () => {
    mockApi('get', '/affiliates', 200, { data: AFFILIATE })
    respondWith(buildNode([LIQUOR, GIN]))
    renderAt(GIN.id)
    await findTitle()

    expect(await within(screen.getByTestId('affiliate-in-image')).findByRole('complementary', { name: '商品' })).toBeInTheDocument()
    expect(await within(screen.getByTestId('affiliate-bottom')).findByRole('complementary', { name: '商品' })).toBeInTheDocument()
    // 2 か所とも、このノードの ID で商品の欄を取る
    expect(sentUrls().filter((url) => url.startsWith('/affiliates'))).toEqual([
      '/affiliates?category_id=2',
      '/affiliates?category_id=2',
    ])
  })

  test('TC-106-08: 画像があれば、商品の欄はいちばん下にだけ置く', async () => {
    mockApi('get', '/affiliates', 200, { data: AFFILIATE })
    respondWith(buildNode([LIQUOR, GIN], { image: 'gin.jpg' }))
    renderAt(GIN.id)
    await findTitle()

    expect(await within(screen.getByTestId('affiliate-bottom')).findByRole('complementary', { name: '商品' })).toBeInTheDocument()
    expect(screen.queryByTestId('affiliate-in-image')).not.toBeInTheDocument()
    expect(screen.getAllByRole('complementary', { name: '商品' })).toHaveLength(1)
  })

  test('TC-106-09: 商品の行が無い(404)ノードは、商品の欄を出さない', async () => {
    respondWith(buildNode([LIQUOR, GIN]))
    renderAt(GIN.id)
    await findTitle()

    await waitFor(() => expect(sentUrls().filter((url) => url.startsWith('/affiliates'))).toHaveLength(2))
    expect(screen.queryByRole('complementary', { name: '商品' })).not.toBeInTheDocument()
  })

  test('TC-106-10: 子のノードのリンクで移ると、移った先のノードを起点にカクテルを取り直す', async () => {
    mockApiHandler('get', '/categories/{category}', (request) => {
      const id = Number(new URL(request.url).pathname.split('/').pop())
      const node = id === GIN.id ? buildNode([LIQUOR, GIN], { children: [DRY_GIN] }) : buildNode([LIQUOR, GIN, DRY_GIN])
      return jsonResponse({ data: node }, 200)
    })
    mockRandom({
      [GIN.id]: [buildCocktailRow({ id: 11, name: 'ジントニック' })],
      [DRY_GIN.id]: [buildCocktailRow({ id: 13, name: 'ギブソン' })],
    })
    renderAt(GIN.id)
    expect(await screen.findByRole('link', { name: 'ジントニック' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('link', { name: 'ドライジン(Dry Gin)' }))

    const section = await screen.findByRole('region', { name: COCKTAILS })
    expect(await within(section).findByRole('link', { name: 'ギブソン' })).toBeInTheDocument()
    expect(within(section).queryByRole('link', { name: 'ジントニック' })).not.toBeInTheDocument()
    expect(sentUrls()).toContain('/cocktails/random?limit=5&category_id=3')
  })
})
