import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest'
import App from '../../App'
import type { components, paths } from '../../api/schema'
import { apiRequests, jsonResponse, mockApi, mockApiHandler } from '../../test/api'
import { LEGACY_SEARCH_STORAGE_KEYS } from './legacySearchStorage'
import { SEARCH_STORAGE_KEYS } from './searchStorage'

type ConvertResponse = paths['/legacy/search-condition']['post']['responses'][200]['content']['application/json']
type SearchFormNode = components['schemas']['SearchFormNodeResource']

const HELPME_HEADING = '当DBの更新ボランティア募集について'
const PATH = '/legacy/search-condition'

const renderAt = (path: string) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <App />
    </MemoryRouter>,
  )

/** 送った POST /api/legacy/search-condition の本文を、送った順に */
const convertBodies = async (): Promise<unknown[]> =>
  Promise.all(
    apiRequests()
      .filter((request) => request.method === 'POST' && new URL(request.url).pathname === `/api${PATH}`)
      .map((request) => request.clone().json() as Promise<unknown>),
  )

const convertCount = () =>
  apiRequests().filter((request) => request.method === 'POST' && new URL(request.url).pathname === `/api${PATH}`).length

/** 移し替えの Promise が終わるまで待つ(応答の後の localStorage の書き込みと state の更新まで) */
const settle = async () => {
  for (let i = 0; i < 5; i += 1) {
    await act(async () => {
      await Promise.resolve()
    })
  }
}

const legacyJson = (value: Record<string, unknown>) => JSON.stringify(value)

/** 旧の画面が保存していた形(旧 localStorageHelper.js の props) */
const LEGACY_USER = legacyJson({ searchMethod: 'having', methods: [1, 3], categories: [5, 6], middles: [51], is_easily: true })
const LEGACY_INSTANT = legacyJson({ searchMethod: 'and', methods: [1, 2, 3, 4, 5, 6], categories: [7], middles: [], is_easily: false })

const noticeWith = (text: string) => screen.findByText(text, { exact: false })

beforeEach(() => localStorage.clear())
afterEach(() => {
  vi.restoreAllMocks()
  localStorage.clear()
})

describe('旧サイトが保存した検索条件の移し替え(LegacySearchMigration)', () => {
  test('TC-175-01: 旧の searchUser があれば、旧の ID を API で直して新しいキーに保存し、旧のキーを消す。検索方法・かんたん検索はそのまま移す', async () => {
    localStorage.setItem(LEGACY_SEARCH_STORAGE_KEYS.user, LEGACY_USER)
    mockApi('post', PATH, 200, { category_ids: [12, 45], method_ids: [1, 3], dropped_count: 0 } satisfies ConvertResponse)

    renderAt('/helpme')

    await waitFor(() => expect(localStorage.getItem(SEARCH_STORAGE_KEYS.user)).toBe('n=12,45&mode=having&easy=1&m=1,3'))
    expect(localStorage.getItem(LEGACY_SEARCH_STORAGE_KEYS.user)).toBeNull()
    expect(await convertBodies()).toEqual([{ categories: [5, 6], middles: [51], methods: [1, 3] }])
    await settle()
    // 戻せなかったものが無ければ、何も出さない
    expect(screen.queryByText('旧サイトで保存した検索条件', { exact: false })).not.toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 1, name: HELPME_HEADING })).toBeInTheDocument()
  })

  test('TC-175-02: searchInstant と searchUser の両方があれば、それぞれを「リロード」用・「読み込み」用の新しいキーに移す', async () => {
    localStorage.setItem(LEGACY_SEARCH_STORAGE_KEYS.user, LEGACY_USER)
    localStorage.setItem(LEGACY_SEARCH_STORAGE_KEYS.instant, LEGACY_INSTANT)
    mockApiHandler('post', PATH, async (request) => {
      const body = (await request.json()) as { categories: number[] }
      const response: ConvertResponse =
        body.categories[0] === 7
          ? { category_ids: [70], method_ids: [1, 2, 3, 4, 5, 6], dropped_count: 0 }
          : { category_ids: [12, 45], method_ids: [1, 3], dropped_count: 0 }
      return jsonResponse(response, 200)
    })

    renderAt('/helpme')

    await waitFor(() => expect(localStorage.getItem(SEARCH_STORAGE_KEYS.user)).toBe('n=12,45&mode=having&easy=1&m=1,3'))
    expect(localStorage.getItem(SEARCH_STORAGE_KEYS.instant)).toBe('n=70&mode=and&m=1,2,3,4,5,6')
    expect(localStorage.getItem(LEGACY_SEARCH_STORAGE_KEYS.user)).toBeNull()
    expect(localStorage.getItem(LEGACY_SEARCH_STORAGE_KEYS.instant)).toBeNull()
    expect(convertCount()).toBe(2)
  })

  test('TC-175-03: 戻せなかった ID があれば、2 つの保存先の合計の件数を本文の上に出し、「閉じる」で消す。画面を移っても出したまま', async () => {
    localStorage.setItem(LEGACY_SEARCH_STORAGE_KEYS.user, LEGACY_USER)
    localStorage.setItem(LEGACY_SEARCH_STORAGE_KEYS.instant, LEGACY_INSTANT)
    mockApiHandler('post', PATH, async (request) => {
      const body = (await request.json()) as { categories: number[] }
      const response: ConvertResponse =
        body.categories[0] === 7
          ? { category_ids: [], method_ids: [1, 2, 3, 4, 5, 6], dropped_count: 1 }
          : { category_ids: [12], method_ids: [1, 3], dropped_count: 2 }
      return jsonResponse(response, 200)
    })

    renderAt('/helpme')

    const message = await noticeWith('戻せなかった材料・製法 3 件は、条件から外しています。')
    const box = message.closest('[role="status"]') as HTMLElement
    expect(box).toHaveTextContent(
      '旧サイトで保存した検索条件(「読み込み」「リロード」で戻す条件)を、今のサイトの材料に直しました。戻せなかった材料・製法 3 件は、条件から外しています。',
    )

    // 画面を移っても出したまま(枠に置いている)
    fireEvent.click(within(screen.getByRole('contentinfo')).getByRole('link', { name: '更新ボランティア募集中...' }))
    expect(screen.getByText('戻せなかった材料・製法 3 件は、条件から外しています。', { exact: false })).toBeInTheDocument()

    fireEvent.click(within(box).getByRole('button', { name: '閉じる' }))
    expect(screen.queryByText('戻せなかった材料・製法', { exact: false })).not.toBeInTheDocument()
  })

  test.each([
    ['500 の応答', () => mockApiHandler('post', PATH, () => jsonResponse({ message: 'Server Error' }, 500))],
    [
      '通信の失敗',
      () =>
        mockApiHandler('post', PATH, () => {
          throw new TypeError('Failed to fetch')
        }),
    ],
  ])('TC-175-04: API が失敗したら(%s)旧のキーを消さず、新しいキーにも書かない。次に開いたときにやり直して移す', async (_, mockFailure) => {
    localStorage.setItem(LEGACY_SEARCH_STORAGE_KEYS.user, LEGACY_USER)
    mockFailure()

    const { unmount } = renderAt('/helpme')
    await waitFor(() => expect(convertCount()).toBe(1))
    await settle()

    expect(localStorage.getItem(LEGACY_SEARCH_STORAGE_KEYS.user)).toBe(LEGACY_USER)
    expect(localStorage.getItem(SEARCH_STORAGE_KEYS.user)).toBeNull()
    expect(screen.queryByText('旧サイトで保存した検索条件', { exact: false })).not.toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 1, name: HELPME_HEADING })).toBeInTheDocument()
    unmount()

    // 次に開いたとき(API が戻った)
    mockApi('post', PATH, 200, { category_ids: [12, 45], method_ids: [1, 3], dropped_count: 0 })
    renderAt('/helpme')
    await waitFor(() => expect(localStorage.getItem(SEARCH_STORAGE_KEYS.user)).toBe('n=12,45&mode=having&easy=1&m=1,3'))
    expect(localStorage.getItem(LEGACY_SEARCH_STORAGE_KEYS.user)).toBeNull()
    expect(convertCount()).toBe(2)
  })

  test.each([
    ['JSON でない', '{"searchMethod":'],
    ['null', 'null'],
    ['配列', '[1,2]'],
    ['文字列', '"or"'],
  ])('TC-175-05: 旧の値が壊れている(%s)ときは、画面を壊さず、API を呼ばずに旧のキーを消し、消したことを出す', async (_, broken) => {
    localStorage.setItem(LEGACY_SEARCH_STORAGE_KEYS.instant, broken)

    renderAt('/helpme')

    expect(await noticeWith('旧サイトで保存した検索条件が壊れていて読めなかったため、消しました。')).toBeInTheDocument()
    expect(localStorage.getItem(LEGACY_SEARCH_STORAGE_KEYS.instant)).toBeNull()
    expect(localStorage.getItem(SEARCH_STORAGE_KEYS.instant)).toBeNull()
    expect(convertCount()).toBe(0)
    expect(screen.getByRole('heading', { level: 1, name: HELPME_HEADING })).toBeInTheDocument()
  })

  test('TC-175-06: 新しいキーにもう保存があれば、新しい方を残して旧のキーを消す(API は呼ばない)', async () => {
    localStorage.setItem(LEGACY_SEARCH_STORAGE_KEYS.user, LEGACY_USER)
    localStorage.setItem(SEARCH_STORAGE_KEYS.user, 'n=99')

    renderAt('/helpme')

    await waitFor(() => expect(localStorage.getItem(LEGACY_SEARCH_STORAGE_KEYS.user)).toBeNull())
    await settle()
    expect(localStorage.getItem(SEARCH_STORAGE_KEYS.user)).toBe('n=99')
    expect(convertCount()).toBe(0)
  })

  test('TC-175-07: 旧のキーが無ければ、API を呼ばず、何も出さない', async () => {
    renderAt('/helpme')
    await settle()

    expect(convertCount()).toBe(0)
    expect(screen.queryByText('旧サイトで保存した検索条件', { exact: false })).not.toBeInTheDocument()
    expect(localStorage.length).toBe(0)
  })

  test('TC-175-08: 旧の値に技法の配列が無ければ、技法を送らず「すべての技法」で保存する。空の配列なら、技法を 1 つも選ばない条件で保存する', async () => {
    localStorage.setItem(LEGACY_SEARCH_STORAGE_KEYS.user, legacyJson({ searchMethod: 'or', categories: [5], middles: [] }))
    localStorage.setItem(LEGACY_SEARCH_STORAGE_KEYS.instant, legacyJson({ searchMethod: 'or', methods: [], categories: [5], middles: [] }))
    mockApi('post', PATH, 200, { category_ids: [12], method_ids: [], dropped_count: 0 })

    renderAt('/helpme')

    await waitFor(() => expect(localStorage.getItem(SEARCH_STORAGE_KEYS.user)).toBe('n=12'))
    await waitFor(() => expect(localStorage.getItem(SEARCH_STORAGE_KEYS.instant)).toBe('n=12&m='))
    expect(await convertBodies()).toEqual([
      { categories: [5], middles: [], methods: [] },
      { categories: [5], middles: [] },
    ])
  })

  test('TC-175-09: ID として読めない値(数字でない・0 以下・小数・真偽値)は送らずに戻せなかった数に足し、数字の文字列は ID として送る。知らない検索方法は OR 検索、真偽値でないかんたん検索はオフにする', async () => {
    localStorage.setItem(
      LEGACY_SEARCH_STORAGE_KEYS.user,
      legacyJson({ searchMethod: 'xor', methods: ['1', 'x'], categories: [5, 'abc', -1, 0, 1.5, true, '6'], middles: [null], is_easily: 'true' }),
    )
    mockApi('post', PATH, 200, { category_ids: [12, 45], method_ids: [1], dropped_count: 0 })

    renderAt('/helpme')

    expect(await noticeWith('戻せなかった材料・製法 7 件は、条件から外しています。')).toBeInTheDocument()
    expect(await convertBodies()).toEqual([{ categories: [5, 6], middles: [], methods: [1] }])
    expect(localStorage.getItem(SEARCH_STORAGE_KEYS.user)).toBe('n=12,45&m=1')
  })

  test('TC-175-10: トップのフォームを開いた後に移し終えたら、「読み込み」を押せるようになり、押すと直した条件に戻る(開いただけでは戻さない)', async () => {
    const node = (id: number, name: string, children: SearchFormNode[] = []): SearchFormNode => ({
      id,
      name,
      is_major: true,
      root_role: null,
      children,
    })
    mockApi('get', '/search-form', 200, {
      categories: [node(1, '蒸留酒', [node(12, 'ジン'), node(45, 'ウォッカ'), node(46, 'ラム')])],
      methods: [
        { id: 1, name: 'ビルド' },
        { id: 3, name: 'シェイク' },
        { id: 4, name: 'ステア' },
      ],
    })
    let respond: (() => void) | null = null
    mockApiHandler(
      'post',
      PATH,
      () =>
        new Promise<Response>((resolve) => {
          respond = () => resolve(jsonResponse({ category_ids: [12, 45], method_ids: [1, 3], dropped_count: 0 } satisfies ConvertResponse, 200))
        }),
    )
    localStorage.setItem(LEGACY_SEARCH_STORAGE_KEYS.user, LEGACY_USER)

    renderAt('/')

    const form = within(await screen.findByRole('region', { name: 'カクテル検索' }))
    const load = await form.findByRole('button', { name: '読み込み' })
    expect(load).toBeDisabled()

    await waitFor(() => expect(respond).not.toBeNull())
    await act(async () => respond?.())
    await waitFor(() => expect(load).toBeEnabled())
    expect(form.getByRole('checkbox', { name: 'ジン' })).not.toBeChecked()
    expect(form.getByRole('radio', { name: 'OR検索' })).toBeChecked()

    fireEvent.click(load)
    expect(form.getByRole('checkbox', { name: 'ジン' })).toBeChecked()
    expect(form.getByRole('checkbox', { name: 'ウォッカ' })).toBeChecked()
    expect(form.getByRole('checkbox', { name: 'ラム' })).not.toBeChecked()
    expect(form.getByRole('radio', { name: 'あるもの検索' })).toBeChecked()
    expect(form.getByRole('checkbox', { name: 'かんたん検索' })).toBeChecked()
    const methods = within(form.getByRole('group', { name: '製法' }))
    expect(methods.getByRole('checkbox', { name: 'ビルド' })).toBeChecked()
    expect(methods.getByRole('checkbox', { name: 'シェイク' })).toBeChecked()
    expect(methods.getByRole('checkbox', { name: 'ステア' })).not.toBeChecked()
  })

  test('TC-175-11: localStorage が例外を投げても画面を出し、API を呼ばない。新しいキーに書けないときは旧のキーを残す', async () => {
    const getItem = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('SecurityError')
    })
    const first = renderAt('/helpme')
    await settle()
    expect(screen.getByRole('heading', { level: 1, name: HELPME_HEADING })).toBeInTheDocument()
    expect(convertCount()).toBe(0)
    first.unmount()
    getItem.mockRestore()

    // 書くときだけ容量を超える
    localStorage.setItem(LEGACY_SEARCH_STORAGE_KEYS.user, LEGACY_USER)
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('QuotaExceededError')
    })
    mockApi('post', PATH, 200, { category_ids: [12], method_ids: [1, 3], dropped_count: 1 })
    renderAt('/helpme')
    await waitFor(() => expect(convertCount()).toBe(1))
    await settle()

    expect(localStorage.getItem(LEGACY_SEARCH_STORAGE_KEYS.user)).toBe(LEGACY_USER)
    expect(localStorage.getItem(SEARCH_STORAGE_KEYS.user)).toBeNull()
    // 移せていないので、戻せなかった件数も出さない(次に開いたときに出す)
    expect(screen.queryByText('旧サイトで保存した検索条件', { exact: false })).not.toBeInTheDocument()
  })
})
