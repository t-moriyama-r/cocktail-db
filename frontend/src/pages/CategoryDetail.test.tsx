import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { describe, expect, test } from 'vitest'
import type { components } from '../api/schema'
import { SITE_TITLE } from '../components/SiteLayout/usePageTitle'
import { apiRequests, jsonResponse, mockApi, mockApiHandler } from '../test/api'
import CategoryDetail from './CategoryDetail'

type CategoryDetailResource = components['schemas']['CategoryDetailResource']
type NodeRef = components['schemas']['NodeRefResource']
type BrandSummary = components['schemas']['BrandSummaryResource']

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

describe('カテゴリの詳細の画面', () => {
  test('TC-105-01: タイトルを「名前(英名)」で出し、ブラウザのタイトルにも同じものを出す', async () => {
    respondWith(buildNode([LIQUOR, GIN]))
    renderAt(GIN.id)

    expect(await findTitle()).toHaveTextContent('ジン(Gin)')
    expect(document.title).toBe(`ジン(Gin) ｜ ${SITE_TITLE}`)
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
      buildNode([LIQUOR, GIN], { image: '/images/gin.jpg', image_txt: '出典のサイト', image_url: 'https://example.com/gin' }),
    )
    renderAt(GIN.id)
    await findTitle()

    expect(screen.getByRole('img', { name: 'ジン' })).toHaveAttribute('src', '/images/gin.jpg')
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
    expect(document.title).toBe(`ページが見つかりません ｜ ${SITE_TITLE}`)
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
    expect(apiRequests().map((request) => new URL(request.url).pathname)).toEqual(['/api/categories/2', '/api/categories/3'])
  })
})
