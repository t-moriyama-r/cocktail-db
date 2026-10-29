import { act, fireEvent, render, screen, within } from '@testing-library/react'
import type { ReactElement } from 'react'
import { MemoryRouter } from 'react-router'
import { describe, expect, test } from 'vitest'
import type { paths } from '../../api/schema'
import { apiRequests, jsonResponse, mockApi, mockApiHandler } from '../../test/api'
import { buildCocktailRow as row } from '../CocktailTable/buildCocktailRow'
import RandomCocktails from './RandomCocktails'
import type { RandomCocktailsOrigin } from './RandomCocktails.types'

type RandomResponse = paths['/cocktails/random']['get']['responses'][200]['content']['application/json']
type RelatedResponse = paths['/cocktails/{cocktail}/related']['get']['responses'][200]['content']['application/json']

const MARTINI = row({ id: 1, name: 'マティーニ' })
const GIMLET = row({ id: 2, name: 'ギムレット' })
const NEGRONI = row({ id: 3, name: 'ネグローニ' })

const RETRY = '再取得'

// カクテルの表の行が、カクテルの詳細へリンクするので、ルーターの中で描く
const inRouter = (element: ReactElement) => <MemoryRouter>{element}</MemoryRouter>
const renderList = (element: ReactElement) => render(inRouter(element))

/** 応答を、テストから返すまで止めておく */
const deferred = () => {
  let resolve: (response: Response) => void = () => {}
  const promise = new Promise<Response>((r) => {
    resolve = r
  })
  return { promise, resolve }
}

/** 送ったリクエストの、パスとクエリ(/api から後ろ) */
const sentUrls = () =>
  apiRequests().map((request) => {
    const url = new URL(request.url)
    return `${url.pathname.replace(/^\/api/, '')}${url.search}`
  })

/** 表の体の行の、名前の列の文字を上から順に */
const tableNames = () =>
  within(screen.getByRole('table'))
    .getAllByRole('row')
    .slice(1)
    .map((tr) => within(tr).getAllByRole('cell')[1].textContent)

describe('RandomCocktails の見出しと呼ぶ API', () => {
  test('TC-300-01: カクテルが起点なら、見出しは「材料が似たカクテル」で、材料が似たカクテルの API を 5 件で呼ぶ', async () => {
    const body: RelatedResponse = { data: [MARTINI] }
    mockApi('get', '/cocktails/{cocktail}/related', 200, body)

    renderList(<RandomCocktails origin={{ kind: 'cocktail', id: 59 }} />)

    expect(screen.getByRole('heading', { level: 2, name: '材料が似たカクテル' })).toBeInTheDocument()
    expect(await screen.findByRole('link', { name: 'マティーニ' })).toBeInTheDocument()
    expect(sentUrls()).toEqual(['/cocktails/59/related?limit=5'])
  })

  test('TC-300-02: ベースが起点なら、見出しは「同じベースのカクテル」で、ランダムの API に base_id を送る', async () => {
    const body: RandomResponse = { data: [MARTINI] }
    mockApi('get', '/cocktails/random', 200, body)

    renderList(<RandomCocktails origin={{ kind: 'base', id: 10 }} />)

    expect(screen.getByRole('heading', { level: 2, name: '同じベースのカクテル' })).toBeInTheDocument()
    expect(await screen.findByRole('link', { name: 'マティーニ' })).toBeInTheDocument()
    expect(sentUrls()).toEqual(['/cocktails/random?limit=5&base_id=10'])
  })

  test.each([
    ['category', 'category_id'],
    ['brand', 'brand_id'],
    ['method', 'method_id'],
    ['glass', 'glass_id'],
  ] as const)(
    'TC-300-03: %s が起点なら、見出しは「これを使ったカクテル(ランダム5種)」で、ランダムの API に %s を送る',
    async (kind, key) => {
      const body: RandomResponse = { data: [MARTINI] }
      mockApi('get', '/cocktails/random', 200, body)

      renderList(<RandomCocktails origin={{ kind, id: 7 }} />)

      expect(screen.getByRole('heading', { level: 2, name: 'これを使ったカクテル(ランダム5種)' })).toBeInTheDocument()
      expect(await screen.findByRole('link', { name: 'マティーニ' })).toBeInTheDocument()
      expect(sentUrls()).toEqual([`/cocktails/random?limit=5&${key}=7`])
    },
  )

  test('TC-300-04: 件数を 7 にすると、見出しの件数と API に送る limit が 7 になる', async () => {
    const body: RandomResponse = { data: [MARTINI] }
    mockApi('get', '/cocktails/random', 200, body)

    renderList(<RandomCocktails origin={{ kind: 'method', id: 3 }} limit={7} />)

    expect(screen.getByRole('heading', { level: 2, name: 'これを使ったカクテル(ランダム7種)' })).toBeInTheDocument()
    expect(await screen.findByRole('link', { name: 'マティーニ' })).toBeInTheDocument()
    expect(sentUrls()).toEqual(['/cocktails/random?limit=7&method_id=3'])
  })
})

describe('RandomCocktails の一覧', () => {
  test('TC-300-05: 取れたカクテルを、カクテルの表に API の順で出す', async () => {
    const body: RandomResponse = { data: [NEGRONI, MARTINI, GIMLET] }
    mockApi('get', '/cocktails/random', 200, body)

    renderList(<RandomCocktails origin={{ kind: 'category', id: 7 }} />)

    expect(await screen.findByRole('table', { name: 'これを使ったカクテル(ランダム5種)' })).toBeInTheDocument()
    expect(tableNames()).toEqual(['ネグローニ', 'マティーニ', 'ギムレット'])
  })

  test('TC-300-06: 応答を待つあいだは、見出しと「再取得」だけを出し、表を出さない', () => {
    mockApiHandler('get', '/cocktails/random', () => new Promise<Response>(() => {}))

    renderList(<RandomCocktails origin={{ kind: 'category', id: 7 }} />)

    expect(screen.getByRole('heading', { level: 2, name: 'これを使ったカクテル(ランダム5種)' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: RETRY })).toBeInTheDocument()
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
  })

  test('TC-300-07: 0 件でも見出しを出し、表は列の見出しの行だけになる', async () => {
    const body: RandomResponse = { data: [] }
    mockApi('get', '/cocktails/random', 200, body)

    renderList(<RandomCocktails origin={{ kind: 'brand', id: 999 }} />)

    expect(screen.getByRole('heading', { level: 2, name: 'これを使ったカクテル(ランダム5種)' })).toBeInTheDocument()
    const table = await screen.findByRole('table')
    expect(within(table).getAllByRole('row')).toHaveLength(1)
  })

  test('TC-300-08: 「再取得」を押すと、同じ起点で API をもう一度呼び、届いた一覧に替える', async () => {
    const responses: RandomResponse[] = [{ data: [MARTINI] }, { data: [GIMLET, NEGRONI] }]
    let count = 0
    mockApiHandler('get', '/cocktails/random', () => jsonResponse(responses[count++], 200))
    renderList(<RandomCocktails origin={{ kind: 'category', id: 7 }} />)
    expect(await screen.findByRole('link', { name: 'マティーニ' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: RETRY }))

    expect(await screen.findByRole('link', { name: 'ギムレット' })).toBeInTheDocument()
    expect(tableNames()).toEqual(['ギムレット', 'ネグローニ'])
    expect(sentUrls()).toEqual(['/cocktails/random?limit=5&category_id=7', '/cocktails/random?limit=5&category_id=7'])
  })

  test('TC-300-09: 「再取得」の応答を待つあいだは、前の一覧を出したままにする', async () => {
    const retried = deferred()
    let count = 0
    mockApiHandler('get', '/cocktails/random', () =>
      count++ === 0 ? jsonResponse({ data: [MARTINI] } satisfies RandomResponse, 200) : retried.promise,
    )
    renderList(<RandomCocktails origin={{ kind: 'category', id: 7 }} />)
    expect(await screen.findByRole('link', { name: 'マティーニ' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: RETRY }))

    expect(tableNames()).toEqual(['マティーニ'])
    await act(async () => retried.resolve(jsonResponse({ data: [GIMLET] } satisfies RandomResponse, 200)))
    expect(tableNames()).toEqual(['ギムレット'])
  })

  test.each([
    ['TC-300-10', { kind: 'category', id: 7 }, { kind: 'category', id: 8 }, '/cocktails/random?limit=5&category_id=8'],
    ['TC-300-11', { kind: 'method', id: 1 }, { kind: 'method', id: 2 }, '/cocktails/random?limit=5&method_id=2'],
    ['TC-300-12', { kind: 'cocktail', id: 1 }, { kind: 'base', id: 10 }, '/cocktails/random?limit=5&base_id=10'],
  ] as const)(
    '%s: 起点が %j から %j に変わると、前の一覧を消して、新しい起点で取り直す',
    async (_id, before: RandomCocktailsOrigin, after: RandomCocktailsOrigin, afterUrl) => {
      const next = deferred()
      mockApi('get', '/cocktails/{cocktail}/related', 200, { data: [MARTINI] })
      mockApiHandler('get', '/cocktails/random', (request) =>
        // 新しい起点の応答だけを止め、前の一覧が消えたかを待つあいだに確かめる
        request.url.endsWith(afterUrl)
          ? next.promise
          : jsonResponse({ data: [MARTINI] } satisfies RandomResponse, 200),
      )
      const { rerender } = renderList(<RandomCocktails origin={before} />)
      expect(await screen.findByRole('link', { name: 'マティーニ' })).toBeInTheDocument()

      rerender(inRouter(<RandomCocktails origin={after} />))

      expect(screen.queryByRole('table')).not.toBeInTheDocument()
      await act(async () => next.resolve(jsonResponse({ data: [GIMLET] } satisfies RandomResponse, 200)))
      expect(tableNames()).toEqual(['ギムレット'])
      expect(sentUrls().at(-1)).toBe(afterUrl)
    },
  )
})

describe('RandomCocktails の失敗', () => {
  test.each([
    ['TC-300-13', '500', () => jsonResponse({ message: 'Server Error' }, 500), 'データを読み込めませんでした(エラー 500)'],
    [
      'TC-300-14',
      '通信の失敗',
      () => {
        throw new TypeError('Failed to fetch')
      },
      '通信に失敗しました',
    ],
  ])('%s: %s なら、見出しの下にエラーの表示を出し、もう一度読み込むで取り直す', async (_id, _label, fail, message) => {
    let failing = true
    mockApiHandler('get', '/cocktails/random', () =>
      failing ? fail() : jsonResponse({ data: [MARTINI] } satisfies RandomResponse, 200),
    )
    renderList(<RandomCocktails origin={{ kind: 'glass', id: 2 }} limit={7} />)

    expect(await screen.findByRole('alert')).toHaveTextContent(message)
    expect(screen.getByRole('heading', { level: 2, name: 'これを使ったカクテル(ランダム7種)' })).toBeInTheDocument()
    expect(screen.queryByRole('table')).not.toBeInTheDocument()

    failing = false
    fireEvent.click(screen.getByRole('button', { name: 'もう一度読み込む' }))

    expect(await screen.findByRole('link', { name: 'マティーニ' })).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  test('TC-300-15: 起点のカクテルが 404(下書き・無い ID)なら、エラーにせず 0 件の表を出す', async () => {
    // 404 の本文はスキーマに無いので、型を当てずに返す
    mockApiHandler('get', '/cocktails/{cocktail}/related', () => jsonResponse({ message: 'Not Found' }, 404))

    renderList(<RandomCocktails origin={{ kind: 'cocktail', id: 999 }} />)

    const table = await screen.findByRole('table', { name: '材料が似たカクテル' })
    expect(within(table).getAllByRole('row')).toHaveLength(1)
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  test('TC-300-17: カクテル以外が起点で 404 なら、0 件の表にせず、エラーと「もう一度読み込む」を出す', async () => {
    mockApiHandler('get', '/cocktails/random', () => jsonResponse({ message: 'Not Found' }, 404))

    renderList(<RandomCocktails origin={{ kind: 'category', id: 999 }} />)

    expect(await screen.findByRole('alert')).toHaveTextContent('エラー 404')
    expect(screen.getByRole('button', { name: 'もう一度読み込む' })).toBeInTheDocument()
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
  })
})

describe('RandomCocktails の「再取得」', () => {
  test('TC-300-16: 「再取得」はボタンで、読み上げる名前は「再取得」だけ(矢印のアイコンは読み上げない)', async () => {
    mockApi('get', '/cocktails/random', 200, { data: [] })

    renderList(<RandomCocktails origin={{ kind: 'category', id: 7 }} />)

    const button = screen.getByRole('button', { name: RETRY })
    expect(button.querySelector('svg')).toHaveAttribute('aria-hidden', 'true')
    expect(await screen.findByRole('table')).toBeInTheDocument()
  })
})
