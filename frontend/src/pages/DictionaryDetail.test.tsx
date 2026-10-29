import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { describe, expect, test } from 'vitest'
import type { components, paths } from '../api/schema'
import { buildCocktailRow as row } from '../components/CocktailTable/buildCocktailRow'
import { apiRequests, jsonResponse, mockApiHandler } from '../test/api'
import DictionaryDetail from './DictionaryDetail'

type DictionaryDetailData = components['schemas']['DictionaryDetailResource']
type RandomResponse = paths['/cocktails/random']['get']['responses'][200]['content']['application/json']

const SITE_TITLE = '宅飲みカクテルデータベース(RC)'
const COCKTAILS_HEADING = 'これを使ったカクテル(ランダム7種)'

/** 用語の詳細。既定は分類「製法」の、技法に結び付いた用語(用語の ID 12 と技法の ID 3 はわざと変える) */
const term = (overrides: Partial<DictionaryDetailData> = {}): DictionaryDetailData => ({
  id: 12,
  name: 'シェイク',
  name_eng: 'Shake',
  category: { id: 1, name: '製法', name_eng: 'Method' },
  kind: 'method',
  method_id: 3,
  description: [{ type: 'text', text: 'シェイカーで振って混ぜる。' }],
  youtube: null,
  youtube_start_seconds: null,
  image: null,
  image_txt: null,
  image_url: null,
  ...overrides,
})

const GLASS = term({
  id: 21,
  name: 'カクテル・グラス',
  name_eng: 'Cocktail glass',
  category: { id: 2, name: 'グラス', name_eng: 'Glass' },
  kind: 'glass',
  method_id: null,
})

const UNIT = term({
  id: 31,
  name: 'ダッシュ',
  name_eng: 'Dash',
  category: { id: 6, name: '単位', name_eng: 'Unit' },
  kind: 'other',
  method_id: null,
})

/** 用語の ID ごとに詳細を返す。無い ID は 404(本文はスキーマに無いので型を当てない) */
const mockTerms = (...terms: DictionaryDetailData[]) =>
  mockApiHandler('get', '/dictionaries/{dictionary}', (request) => {
    const id = Number(new URL(request.url).pathname.split('/').pop())
    const found = terms.find((t) => t.id === id)
    return found ? jsonResponse({ data: found }, 200) : jsonResponse({ message: 'Not Found' }, 404)
  })

/** ランダムのカクテルを、送ったクエリの起点ごとに返す(キーは `method_id=3` の形) */
const mockRandom = (byOrigin: Record<string, RandomResponse>) =>
  mockApiHandler('get', '/cocktails/random', (request) => {
    const params = new URL(request.url).searchParams
    const key = [...params].find(([name]) => name !== 'limit')?.join('=') ?? ''
    return jsonResponse(byOrigin[key] ?? { data: [] }, 200)
  })

/** 送ったリクエストの、パスとクエリ(/api から後ろ) */
const sentUrls = () =>
  apiRequests().map((request) => {
    const url = new URL(request.url)
    return `${url.pathname.replace(/^\/api/, '')}${url.search}`
  })

// 一覧へのパンくず・説明文のリンクで画面を移るので、用語辞典の 2 つのルートを置く(数字でない ID の 404 は App.test の TC-97-12)
const renderAt = (path: string) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/dictionary" element={<h1>一覧の画面</h1>} />
        <Route path="/dictionary/:id" element={<DictionaryDetail />} />
      </Routes>
    </MemoryRouter>,
  )

describe('DictionaryDetail', () => {
  test('TC-56-06: 題は「名前(英名)」で、ページのタイトルは「名前(英名) ｜ サイト名」', async () => {
    mockTerms(UNIT)

    renderAt('/dictionary/31')

    expect(await screen.findByRole('heading', { level: 1, name: 'ダッシュ(Dash)' })).toBeInTheDocument()
    expect(document.title).toBe(`ダッシュ(Dash) ｜ ${SITE_TITLE}`)
  })

  test('TC-56-07: パンくずに、コンパスのアイコンと、分類の名前の一覧へのリンクを出す', async () => {
    mockTerms(UNIT)
    renderAt('/dictionary/31')

    const breadcrumb = await screen.findByRole('navigation', { name: 'パンくずリスト' })
    expect(breadcrumb.querySelector('svg[data-icon="compass"]')).not.toBeNull()
    const link = within(breadcrumb).getByRole('link', { name: '単位' })
    expect(link).toHaveAttribute('href', '/dictionary')

    fireEvent.click(link)
    expect(await screen.findByRole('heading', { level: 1, name: '一覧の画面' })).toBeInTheDocument()
  })

  test('TC-56-08: 説明文を、文字とリンク(用語へのリンクは用語の詳細)で出す', async () => {
    mockTerms(
      term({
        description: [
          { type: 'text', text: '振って混ぜる。' },
          { type: 'dictionary', id: 11, text: 'ステア', has_youtube: false },
          { type: 'text', text: 'とちがう。' },
        ],
      }),
    )
    mockRandom({})

    renderAt('/dictionary/12')

    const link = await screen.findByRole('link', { name: 'ステア' })
    expect(link).toHaveAttribute('href', '/dictionary/11')
    expect(link.parentElement).toHaveTextContent('振って混ぜる。ステアとちがう。')
  })

  test('TC-56-09: 技法の用語は「これを使ったカクテル(ランダム7種)」と再取得を出し、技法の ID で 7 件を取る', async () => {
    mockTerms(term())
    mockRandom({ 'method_id=3': { data: [row({ id: 1, name: 'ダイキリ' })] } })

    renderAt('/dictionary/12')

    const section = await screen.findByRole('region', { name: COCKTAILS_HEADING })
    expect(await within(section).findByRole('link', { name: 'ダイキリ' })).toBeInTheDocument()
    expect(within(section).getByRole('button', { name: '再取得' })).toBeInTheDocument()
    // 用語の ID(12)ではなく、技法の ID(3)で探す
    expect(sentUrls()).toContain('/cocktails/random?limit=7&method_id=3')
  })

  test('TC-56-10: グラスの用語は同じ見出しで、グラス(用語の ID)で 7 件を取る', async () => {
    mockTerms(GLASS)
    mockRandom({ 'glass_id=21': { data: [row({ id: 2, name: 'マティーニ' })] } })

    renderAt('/dictionary/21')

    const section = await screen.findByRole('region', { name: COCKTAILS_HEADING })
    expect(await within(section).findByRole('link', { name: 'マティーニ' })).toBeInTheDocument()
    expect(sentUrls()).toContain('/cocktails/random?limit=7&glass_id=21')
  })

  test.each([
    ['単位の用語', UNIT],
    ['分類が製法でも、技法に結び付いていない用語', term({ id: 13, name: 'ブレンド', name_eng: 'Blend', kind: 'other', method_id: null })],
  ])('TC-56-11: %s には、カクテルの欄を出さず、カクテルの API も呼ばない', async (_label, dictionary) => {
    mockTerms(dictionary)

    renderAt(`/dictionary/${dictionary.id}`)

    expect(await screen.findByRole('heading', { level: 1, name: `${dictionary.name}(${dictionary.name_eng})` })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: COCKTAILS_HEADING })).not.toBeInTheDocument()
    expect(sentUrls().filter((url) => url.startsWith('/cocktails/'))).toEqual([])
  })

  test('TC-56-12: 無い ID は 404 の画面を出す', async () => {
    mockTerms(UNIT)

    renderAt('/dictionary/999')

    expect(await screen.findByRole('heading', { level: 1, name: 'ページが見つかりません' })).toBeInTheDocument()
  })

  test('TC-56-13: 画像・動画があっても出さない(旧と同じ)', async () => {
    mockTerms(
      term({
        kind: 'other',
        method_id: null,
        youtube: 'abcdefghijk',
        youtube_start_seconds: 10,
        image: 'shake.jpg',
        image_txt: '出典',
        image_url: 'https://example.com/',
      }),
    )

    const { container } = renderAt('/dictionary/12')

    await screen.findByRole('heading', { level: 1, name: 'シェイク(Shake)' })
    expect(container.querySelector('img, iframe')).toBeNull()
    expect(screen.queryByText('出典')).not.toBeInTheDocument()
  })

  test('TC-56-14: 説明文のリンクで別の技法の用語へ移ると、用語とカクテルの欄を移った先で取り直す', async () => {
    const shake = term({ description: [{ type: 'dictionary', id: 11, text: 'ステア', has_youtube: false }] })
    const stir = term({ id: 11, name: 'ステア', name_eng: 'Stir', method_id: 4, description: null })
    mockTerms(shake, stir)
    mockRandom({
      'method_id=3': { data: [row({ id: 1, name: 'ダイキリ' })] },
      'method_id=4': { data: [row({ id: 2, name: 'マティーニ' })] },
    })
    renderAt('/dictionary/12')
    const section = await screen.findByRole('region', { name: COCKTAILS_HEADING })
    await within(section).findByRole('link', { name: 'ダイキリ' })

    fireEvent.click(screen.getByRole('link', { name: 'ステア' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'ステア(Stir)' })).toBeInTheDocument()
    const moved = screen.getByRole('region', { name: COCKTAILS_HEADING })
    expect(await within(moved).findByRole('link', { name: 'マティーニ' })).toBeInTheDocument()
    expect(within(moved).queryByRole('link', { name: 'ダイキリ' })).not.toBeInTheDocument()
    expect(sentUrls()).toContain('/cocktails/random?limit=7&method_id=4')
    expect(document.title).toBe(`ステア(Stir) ｜ ${SITE_TITLE}`)
  })

  test('TC-56-15: 技法の用語から、技法・グラスでない用語へ移ると、カクテルの欄が消える', async () => {
    mockTerms(term({ description: [{ type: 'dictionary', id: 31, text: 'ダッシュ', has_youtube: false }] }), UNIT)
    mockRandom({ 'method_id=3': { data: [row({ id: 1, name: 'ダイキリ' })] } })
    renderAt('/dictionary/12')
    await screen.findByRole('region', { name: COCKTAILS_HEADING })

    fireEvent.click(screen.getByRole('link', { name: 'ダッシュ' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'ダッシュ(Dash)' })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: COCKTAILS_HEADING })).not.toBeInTheDocument()
  })

  test('TC-56-16: 用語の API がエラーを返したら共通のエラーの表示を出し、もう一度読み込むと用語を出す', async () => {
    let failing = true
    mockApiHandler('get', '/dictionaries/{dictionary}', () =>
      failing ? jsonResponse({ message: 'Server Error' }, 500) : jsonResponse({ data: UNIT }, 200),
    )
    renderAt('/dictionary/31')

    expect(await screen.findByRole('alert')).toHaveTextContent('データを読み込めませんでした(エラー 500)')
    failing = false
    fireEvent.click(screen.getByRole('button', { name: 'もう一度読み込む' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'ダッシュ(Dash)' })).toBeInTheDocument()
  })
})
