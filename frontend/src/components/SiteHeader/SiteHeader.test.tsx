import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router'
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest'
import App from '../../App'
import type { components, paths } from '../../api/schema'
import { apiRequests, jsonResponse, mockApi, mockApiHandler } from '../../test/api'

type NameSearchResponse = paths['/search']['get']['responses'][200]['content']['application/json']
type RandomWalk = paths['/random-walk']['get']['responses'][200]['content']['application/json']

const node: components['schemas']['NodeRefResource'] = { id: 10, name: 'ジン', name_eng: 'Gin' }

const cocktail = (id: number): components['schemas']['CocktailSummaryResource'] => ({
  id,
  name: `ジンのカクテル${id}`,
  name_eng: null,
  is_major: false,
  base: node,
  method: { id: 1, name: 'ビルド' },
  recipe_count: 2,
  strength: 10,
  sweet_degree: null,
  rate: null,
  youtube: null,
  updated_at: null,
})

const nodeHit = (id: number): components['schemas']['NameSearchNodeResource'] => ({
  id,
  name: `ジン${id}`,
  name_eng: `Gin${id}`,
  parent: node,
})

const brandHit = (id: number): components['schemas']['NameSearchBrandResource'] => ({
  id,
  name: `銘柄${id}`,
  name_eng: `Brand${id}`,
  volume: 40,
  youtube: null,
  category: node,
})

const hits = (cocktails: number, nodes = 0, brands = 0): NameSearchResponse => ({
  cocktails: Array.from({ length: cocktails }, (_, i) => cocktail(i + 1)),
  nodes: Array.from({ length: nodes }, (_, i) => nodeHit(i + 1)),
  brands: Array.from({ length: brands }, (_, i) => brandHit(i + 1)),
  ingredient_matches: [],
})

/** 今の URL を出す(移った先を確かめるため) */
function LocationProbe() {
  const { pathname } = useLocation()
  return <output data-testid="location">{pathname}</output>
}

const renderAt = (path: string) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <App />
      <LocationProbe />
    </MemoryRouter>,
  )

const header = () => within(screen.getByRole('banner'))
const nameInput = () => header().getByRole('searchbox', { name: '名前から検索' })
const location = () => screen.getByTestId('location')
const searchRequests = () => apiRequests().filter((request) => new URL(request.url).pathname === '/api/search')
const sentWords = () => searchRequests().map((request) => new URL(request.url).searchParams.get('q'))

/** 入力して、500ms 待つ(時計を進める)。応答を待つため、進めた後は本物の時計に戻す */
const typeAndWait = async (value: string) => {
  vi.useFakeTimers()
  fireEvent.change(nameInput(), { target: { value } })
  await act(async () => {
    await vi.advanceTimersByTimeAsync(500)
  })
  vi.useRealTimers()
}

describe('ヘッダー(SiteHeader)', () => {
  beforeEach(() => {
    mockApi('get', '/cocktails/count', 200, { count: 1234 })
    mockApi('get', '/random-walk', 200, { type: 'cocktail', id: 7 })
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  test('TC-99-01: サイト名(アイコンと名前)のリンクで / へ移る', async () => {
    renderAt('/helpme')

    fireEvent.click(header().getByRole('link', { name: '宅飲みカクテルデータベース' }))

    expect(location()).toHaveTextContent(/^\/$/)
  })

  describe('名前検索', () => {
    test('TC-99-02: 入力が空のとき(開いたとき・打った文字を消したとき)、ボタンは「入力して下さい」で押せず、API を呼ばない', async () => {
      renderAt('/helpme')
      expect(header().getByRole('button', { name: '入力して下さい' })).toBeDisabled()

      fireEvent.change(nameInput(), { target: { value: 'ジ' } })
      await typeAndWait('')

      expect(header().getByRole('button', { name: '入力して下さい' })).toBeDisabled()
      expect(searchRequests()).toHaveLength(0)
    })

    test.each([['半角の空白', ' '], ['全角の空白', '　'], ['混ぜた空白', ' 　 ']])(
      'TC-99-03: %s だけの入力は空と同じに扱い、API を呼ばず、Enter でも移らない',
      async (_, value) => {
        renderAt('/helpme')
        await typeAndWait(value)

        expect(header().getByRole('button', { name: '入力して下さい' })).toBeDisabled()
        fireEvent.submit(header().getByRole('search'))
        expect(location()).toHaveTextContent('/helpme')
        expect(searchRequests()).toHaveLength(0)
      },
    )

    test.each(['!', '！', '？', '?', ';', '；', '<', '>', '_', '＿', '＜', '＞', '\\', '|', '/', '"', "'", '*', '{', '}', '^', '[', ']'])(
      'TC-99-04: 禁止文字 %s を 1 文字含むと、ボタンは「Error」になり、API を呼ばない',
      async (character) => {
        renderAt('/helpme')
        await typeAndWait(`ジン${character}`)

        expect(header().getByRole('button', { name: 'Error' })).toBeEnabled()
        expect(searchRequests()).toHaveLength(0)
      },
    )

    test('TC-99-05: 「Error」を押すと入力が空になり、「入力して下さい」に戻る', async () => {
      renderAt('/helpme')
      await typeAndWait('?')

      fireEvent.click(header().getByRole('button', { name: 'Error' }))

      expect(nameInput()).toHaveValue('')
      expect(header().getByRole('button', { name: '入力して下さい' })).toBeDisabled()
    })

    test.each(['%', '#', '・'])('TC-99-06: 禁止文字でない記号 %s は「Error」にせず、API を呼ぶ', async (character) => {
      mockApi('get', '/search', 200, hits(1))
      renderAt('/helpme')
      await typeAndWait(character)

      expect(await header().findByRole('button', { name: '1件ヒット' })).toBeEnabled()
      expect(sentWords()).toEqual([character])
    })

    test('TC-99-07: 入力が止まってから 500ms 後に 1 回だけ呼ぶ。499ms では呼ばず、打っているあいだは呼ばない', async () => {
      mockApi('get', '/search', 200, hits(1))
      renderAt('/helpme')
      vi.useFakeTimers()

      fireEvent.change(nameInput(), { target: { value: 'ジ' } })
      await act(async () => {
        await vi.advanceTimersByTimeAsync(400)
      })
      fireEvent.change(nameInput(), { target: { value: 'ジン' } })
      await act(async () => {
        await vi.advanceTimersByTimeAsync(499)
      })
      expect(searchRequests()).toHaveLength(0)

      await act(async () => {
        await vi.advanceTimersByTimeAsync(1)
      })
      vi.useRealTimers()

      expect(await header().findByRole('button', { name: '1件ヒット' })).toBeEnabled()
      expect(sentWords()).toEqual(['ジン'])
    })

    test('TC-99-08: 応答を待っているあいだは「検索中...」で押せない', async () => {
      mockApiHandler('get', '/search', () => new Promise<Response>(() => {}))
      renderAt('/helpme')

      fireEvent.change(nameInput(), { target: { value: 'ジン' } })

      expect(header().getByRole('button', { name: '検索中...' })).toBeDisabled()
    })

    test('TC-99-09: 0 件なら「0件ヒット」で押せず、Enter でも移らない', async () => {
      mockApi('get', '/search', 200, hits(0))
      renderAt('/helpme')
      await typeAndWait('ジン')

      expect(await header().findByRole('button', { name: '0件ヒット' })).toBeDisabled()
      fireEvent.submit(header().getByRole('search'))
      expect(location()).toHaveTextContent('/helpme')
    })

    test('TC-99-10: 1 件なら「1件ヒット」で押せ、押すと /result/FromNameResult/〈語〉へ移る', async () => {
      mockApi('get', '/search', 200, hits(1))
      renderAt('/helpme')
      await typeAndWait('ジン')

      fireEvent.click(await header().findByRole('button', { name: '1件ヒット' }))

      expect(location()).toHaveTextContent(`/result/FromNameResult/${encodeURIComponent('ジン')}`)
      expect(await screen.findByRole('heading', { level: 1, name: '名前検索の結果' })).toBeInTheDocument()
    })

    test('TC-99-11: 件数は、カクテル・ノード・銘柄の合計', async () => {
      mockApi('get', '/search', 200, hits(2, 1, 3))
      renderAt('/helpme')
      await typeAndWait('ジン')

      expect(await header().findByRole('button', { name: '6件ヒット' })).toBeEnabled()
    })

    test('TC-99-12: 1 件以上なら、Enter でもボタンと同じに移る', async () => {
      mockApi('get', '/search', 200, hits(1))
      renderAt('/helpme')
      await typeAndWait('ジン')
      await header().findByRole('button', { name: '1件ヒット' })

      fireEvent.submit(header().getByRole('search'))

      expect(location()).toHaveTextContent(`/result/FromNameResult/${encodeURIComponent('ジン')}`)
    })

    test.each([
      ['500 の応答', () => jsonResponse({ message: 'Server Error' }, 500)],
      ['422 の応答', () => jsonResponse({ message: 'q に探せる文字がありません。', errors: {} }, 422)],
      ['通信の失敗', () => Promise.reject(new TypeError('Failed to fetch'))],
    ])('TC-99-13: %s なら「サーバーエラー」で押せない', async (_, handler) => {
      mockApiHandler('get', '/search', handler)
      renderAt('/helpme')
      await typeAndWait('ジン')

      expect(await header().findByRole('button', { name: 'サーバーエラー' })).toBeDisabled()
    })

    test('TC-99-14: 前後の空白(半角・全角)は除いて送り、移る先の URL の語にも含めない', async () => {
      mockApi('get', '/search', 200, hits(1))
      renderAt('/helpme')
      await typeAndWait(' 　ジン トニック　 ')

      fireEvent.click(await header().findByRole('button', { name: '1件ヒット' }))

      expect(sentWords()).toEqual(['ジン トニック'])
      expect(location()).toHaveTextContent(`/result/FromNameResult/${encodeURIComponent('ジン トニック')}`)
    })

    test('TC-99-15: 前の語の応答が後から返っても、今の語の件数に使わない', async () => {
      let resolveFirst: (response: Response) => void = () => {}
      mockApiHandler('get', '/search', (request) =>
        new URL(request.url).searchParams.get('q') === 'ジ'
          ? new Promise<Response>((resolve) => {
              resolveFirst = resolve
            })
          : jsonResponse(hits(2), 200),
      )
      renderAt('/helpme')
      await typeAndWait('ジ')
      await typeAndWait('ジン')
      expect(await header().findByRole('button', { name: '2件ヒット' })).toBeEnabled()

      await act(async () => {
        resolveFirst(jsonResponse(hits(9), 200))
      })

      expect(header().getByRole('button', { name: '2件ヒット' })).toBeEnabled()
    })
  })

  describe('条件検索のモーダル', () => {
    test('TC-99-16: 「検索をはじめる」を押すとモーダルが開き、Close で閉じる', () => {
      renderAt('/helpme')
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()

      // fireEvent.click はフォーカスを移さないので、押す前にブラウザと同じくボタンにフォーカスを置く
      const start = header().getByRole('button', { name: '検索をはじめる' })
      start.focus()
      fireEvent.click(start)
      const dialog = screen.getByRole('dialog', { name: 'カクテル検索' })
      expect(within(dialog).getByRole('button', { name: 'Close' })).toHaveFocus()

      fireEvent.click(within(dialog).getByRole('button', { name: 'Close' }))
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
      expect(start).toHaveFocus()
    })

    test('TC-99-17: Esc・外側を押すと閉じ、枠の中を押しても閉じない', () => {
      renderAt('/helpme')

      fireEvent.click(header().getByRole('button', { name: '検索をはじめる' }))
      fireEvent.keyDown(document, { key: 'Escape' })
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()

      fireEvent.click(header().getByRole('button', { name: '検索をはじめる' }))
      fireEvent.click(screen.getByRole('dialog'))
      expect(screen.getByRole('dialog')).toBeInTheDocument()
      fireEvent.click(screen.getByRole('dialog').parentElement!)
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })

    test('TC-99-18: モーダルを開いたまま画面を移ると閉じ、戻っても開き直さない', async () => {
      renderAt('/helpme')
      fireEvent.click(header().getByRole('button', { name: '検索をはじめる' }))

      fireEvent.click(within(screen.getByRole('contentinfo')).getByRole('link', { name: '更新ボランティア募集中...' }))
      fireEvent.click(header().getByRole('link', { name: '宅飲みカクテルデータベース' }))

      expect(location()).toHaveTextContent(/^\/$/)
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    })
  })

  describe('登録数とランダムウォーク', () => {
    test.each<[RandomWalk, string]>([
      [{ type: 'cocktail', id: 7 }, '/detail/cocktail/7'],
      [{ type: 'category', id: 8 }, '/category/8'],
      [{ type: 'brand', id: 9 }, '/detail/brand/9'],
    ])('TC-99-19: 「現在の登録数：〈件数〉」のリンク先は、ランダムウォークの行き先(%j → %s)', async (walk, path) => {
      mockApi('get', '/random-walk', 200, walk)
      renderAt('/helpme')

      expect(await header().findByRole('link', { name: '現在の登録数：1234' })).toHaveAttribute('href', path)
    })

    test.each([
      ['500 の応答', () => jsonResponse({ message: 'Server Error' }, 500)],
      ['通信の失敗', () => Promise.reject(new TypeError('Failed to fetch'))],
    ])('TC-99-20: 件数が %s で取れないときは、登録数を出さない', async (_, handler) => {
      mockApiHandler('get', '/cocktails/count', handler)
      renderAt('/helpme')

      await screen.findByRole('heading', { level: 1, name: '更新ボランティア募集' })
      await act(async () => {})
      expect(header().queryByText(/現在の登録数/)).not.toBeInTheDocument()
    })

    test('TC-99-21: ランダムウォークが取れないときは、件数だけを出し、リンクにしない', async () => {
      mockApiHandler('get', '/random-walk', () => jsonResponse({ message: 'No query results' }, 404))
      renderAt('/helpme')

      expect(await header().findByText('現在の登録数：1234')).toBeInTheDocument()
      expect(header().queryByRole('link', { name: /現在の登録数/ })).not.toBeInTheDocument()
    })

    test('TC-99-22: 画面を移るたびにランダムウォークを取り直し、リンク先が変わる', async () => {
      const walks: RandomWalk[] = [
        { type: 'cocktail', id: 7 },
        { type: 'brand', id: 9 },
      ]
      mockApiHandler('get', '/random-walk', () => jsonResponse(walks.shift() ?? { type: 'category', id: 8 }, 200))
      renderAt('/helpme')
      const link = await header().findByRole('link', { name: '現在の登録数：1234' })
      expect(link).toHaveAttribute('href', '/detail/cocktail/7')

      fireEvent.click(link)

      expect(location()).toHaveTextContent('/detail/cocktail/7')
      await vi.waitFor(() =>
        expect(header().getByRole('link', { name: '現在の登録数：1234' })).toHaveAttribute('href', '/detail/brand/9'),
      )
    })
  })
})
