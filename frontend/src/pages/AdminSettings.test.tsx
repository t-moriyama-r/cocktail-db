import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, test } from 'vitest'
import App from '../App'
import type { paths } from '../api/schema'
import { apiRequests, jsonResponse, mockApi, mockApiHandler } from '../test/api'

type Settings = paths['/admin/settings']['get']['responses'][200]['content']['application/json']
type Setting = Settings['data'][number]
type Updated = paths['/admin/settings']['put']['responses'][200]['content']['application/json']
type Invalid = paths['/admin/settings']['put']['responses'][422]['content']['application/json']

const ADMIN = { id: 1, name: '管理者の太郎' }

/** 秘密の項目。画面で保存した値があり、末尾 4 文字が返る */
const SECRET_SAVED: Setting = {
  key: 'amazon.credential_secret',
  label: 'Amazon Creators API の Credential Secret',
  description: 'Amazon の商品を取る API の認証情報(Client Secret)',
  secret: true,
  is_set: true,
  value: null,
  last_four: 'wxyz',
  source: 'saved',
  updated_at: '2026-11-03T00:05:00.000000Z',
  updated_by: { id: 1, name: '管理者の太郎' },
}

/** 秘密の項目。値が無い */
const SECRET_UNSET: Setting = {
  key: 'amazon.credential_id',
  label: 'Amazon Creators API の Credential ID',
  description: 'Amazon の商品を取る API の認証情報(Client ID)',
  secret: true,
  is_set: false,
  value: null,
  last_four: null,
  source: null,
  updated_at: null,
  updated_by: null,
}

/** 秘密でない項目。.env の値が効いていて、画面で変えたことは無い */
const PLAIN_ENV: Setting = {
  key: 'amazon.partner_tag',
  label: 'Amazon のパートナータグ',
  description: 'Amazon へのリンクと API の呼び出しに付けるトラッキング ID',
  secret: false,
  is_set: true,
  value: 'env-tag-22',
  last_four: null,
  source: 'env',
  updated_at: null,
  updated_by: null,
}

/** 秘密でない項目。値が無く、前に変えた人のユーザーは消されている */
const PLAIN_UNSET: Setting = {
  key: 'rakuten.affiliate_id',
  label: '楽天のアフィリエイト ID',
  description: '楽天市場へのリンクに付ける ID',
  secret: false,
  is_set: false,
  value: null,
  last_four: null,
  source: null,
  updated_at: '2026-10-31T15:30:00.000000Z',
  updated_by: null,
}

const ALL = [SECRET_UNSET, SECRET_SAVED, PLAIN_ENV, PLAIN_UNSET]

const loggedIn = () => mockApi('get', '/admin/me', 200, ADMIN)
const mockSettings = (data: Setting[]) => mockApi('get', '/admin/settings', 200, { data })

/** PUT の本文を送った順に記録し、respond の応答を返す */
const mockUpdate = (respond: (body: { key: string; value: string | null }) => Response | Promise<Response>) => {
  const bodies: { key: string; value: string | null }[] = []
  mockApiHandler('put', '/admin/settings', async (request) => {
    const body = (await request.clone().json()) as { key: string; value: string | null }
    bodies.push(body)
    return respond(body)
  })
  return bodies
}

const updated = (item: Setting): Response => jsonResponse({ data: item } satisfies Updated, 200)

const renderSettings = async (data: Setting[] = ALL) => {
  loggedIn()
  mockSettings(data)
  const view = render(
    <MemoryRouter initialEntries={['/admin/settings']}>
      <App />
    </MemoryRouter>,
  )
  await screen.findByRole('heading', { level: 1, name: '設定' })
  return view
}

/** 項目の枠(見出しの名前で探す) */
const itemSection = (label: string) => screen.getByRole('region', { name: label })

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

describe('一覧', () => {
  test('TC-133-01: API が返した項目を、API の順に見出し・説明と一緒に並べ、画面から変えられない設定の説明を最後に出す', async () => {
    const extra: Setting = { ...PLAIN_UNSET, key: 'example.new_key', label: '画面に書いていない項目', description: '足した項目の説明' }
    await renderSettings([PLAIN_ENV, SECRET_SAVED, extra])

    const titles = screen.getAllByRole('heading', { level: 2 }).map((heading) => heading.textContent)
    expect(titles).toEqual(['Amazon のパートナータグ', 'Amazon Creators API の Credential Secret', '画面に書いていない項目', '画面から変えられない設定'])
    expect(within(itemSection('画面に書いていない項目')).getByText('足した項目の説明')).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Amazon Creators API の Credential ID' })).not.toBeInTheDocument()
    expect(document.title).toBe('設定 ｜ 宅飲みカクテルデータベース(RC)')
  })

  test('TC-133-02: 秘密の項目は「設定済み(末尾 4 文字)」/「設定済み」/「未設定」を出し、値の欄はパスワードの欄で空にする', async () => {
    const short: Setting = { ...SECRET_SAVED, key: 'example.short', label: '短い秘密の値', last_four: null, source: 'env' }
    await renderSettings([SECRET_SAVED, short, SECRET_UNSET])

    const saved = itemSection(SECRET_SAVED.label)
    expect(within(saved).getByText('設定済み(末尾 wxyz)')).toBeInTheDocument()
    const input = within(saved).getByLabelText('新しい値')
    expect(input).toHaveAttribute('type', 'password')
    expect(input).toHaveValue('')
    expect(input).toHaveAttribute('placeholder', '上書きするときだけ入れる')
    expect(within(itemSection('短い秘密の値')).getByText('設定済み')).toBeInTheDocument()
    const unset = itemSection(SECRET_UNSET.label)
    expect(within(unset).getByText('未設定')).toBeInTheDocument()
    expect(within(unset).getByLabelText('新しい値')).toHaveAttribute('placeholder', '値を入れる')
  })

  test('TC-133-03: 秘密でない項目は、いまの値を欄に入れて出し、値の出どころを出す', async () => {
    const saved: Setting = { ...PLAIN_ENV, key: 'example.saved', label: '保存した値', value: 'saved-tag', source: 'saved' }
    await renderSettings([PLAIN_ENV, saved, PLAIN_UNSET])

    const env = itemSection(PLAIN_ENV.label)
    expect(within(env).getByLabelText('値')).toHaveValue('env-tag-22')
    expect(within(env).getByText('サーバーの .env の値')).toBeInTheDocument()
    expect(within(itemSection('保存した値')).getByLabelText('値')).toHaveValue('saved-tag')
    expect(within(itemSection('保存した値')).getByText('この画面で保存した値')).toBeInTheDocument()
    const unset = itemSection(PLAIN_UNSET.label)
    expect(within(unset).getByLabelText('値')).toHaveValue('')
    expect(within(unset).getByText('どこにも値がありません')).toBeInTheDocument()
  })

  test('TC-133-04: 項目ごとに、最後に変えた人と日時(日本時間)を出す。変えたことが無い・変えた人が消されたときもそう出す', async () => {
    await renderSettings()

    expect(within(itemSection(SECRET_SAVED.label)).getByText('管理者の太郎(2026年11月3日 09:05)')).toBeInTheDocument()
    expect(within(itemSection(PLAIN_ENV.label)).getByText('この画面で変えたことはありません')).toBeInTheDocument()
    expect(within(itemSection(PLAIN_UNSET.label)).getByText('削除されたユーザー(2026年11月1日 00:30)')).toBeInTheDocument()
  })

  test('TC-133-12: 画面から変えられないもの(APP_KEY・DB の接続・APP_URL)の説明を出す', async () => {
    await renderSettings()

    const fixed = screen.getByRole('region', { name: '画面から変えられない設定' })
    expect(fixed).toHaveTextContent('次のものは、サーバーの .env で変えてください。この画面では扱いません。')
    const items = within(fixed)
      .getAllByRole('listitem')
      .map((item) => item.textContent)
    expect(items).toEqual([
      'APP_KEY: この画面で保存した値を暗号化する鍵。変えると、保存した値が読めなくなります',
      'DB の接続(DB_ で始まるもの): 設定を読む前に要るため',
      'APP_URL: サイトの URL',
    ])
    expect(screen.queryByRole('textbox', { name: /APP_KEY/ })).not.toBeInTheDocument()
  })

  test('TC-133-16: 一覧の応答を待つあいだは、読み込み中を出して項目を出さない', async () => {
    loggedIn()
    mockApiHandler('get', '/admin/settings', () => new Promise<Response>(() => {}))
    render(
      <MemoryRouter initialEntries={['/admin/settings']}>
        <App />
      </MemoryRouter>,
    )

    expect(await screen.findByRole('status')).toHaveTextContent('読み込み中…')
    expect(screen.queryByRole('heading', { level: 1, name: '設定' })).not.toBeInTheDocument()
  })
})

describe('一覧の読み込みの失敗', () => {
  test('TC-133-13: 一覧が 500 ならエラーを出し、もう一度読み込むと項目が出る', async () => {
    loggedIn()
    mockApiHandler('get', '/admin/settings', () => jsonResponse({ message: 'Server Error' }, 500))
    render(
      <MemoryRouter initialEntries={['/admin/settings']}>
        <App />
      </MemoryRouter>,
    )

    expect(await screen.findByRole('alert')).toHaveTextContent('データを読み込めませんでした(エラー 500)')
    expect(screen.getByRole('navigation', { name: '管理画面のメニュー' })).toBeInTheDocument()

    mockSettings(ALL)
    fireEvent.click(screen.getByRole('button', { name: 'もう一度読み込む' }))

    expect(await screen.findByRole('heading', { level: 1, name: '設定' })).toBeInTheDocument()
    expect(screen.getByRole('region', { name: SECRET_SAVED.label })).toBeInTheDocument()
  })
})

describe('秘密の項目の保存', () => {
  test('TC-133-05: 値を入れて保存すると、CSRF のトークンを付けてその項目のキーと値を送り、新しい状態・変えた人と日時を出して欄を空に戻す', async () => {
    document.cookie = 'XSRF-TOKEN=token'
    const bodies = mockUpdate(() =>
      updated({ ...SECRET_SAVED, last_four: '9876', updated_at: '2026-11-03T03:00:00.000000Z', updated_by: { id: 2, name: '管理者の花子' } }),
    )
    await renderSettings()
    const section = itemSection(SECRET_SAVED.label)

    fireEvent.change(within(section).getByLabelText('新しい値'), { target: { value: 'new-secret-9876' } })
    fireEvent.click(within(section).getByRole('button', { name: '保存' }))

    expect(await within(section).findByRole('status')).toHaveTextContent('保存しました。')
    expect(bodies).toEqual([{ key: 'amazon.credential_secret', value: 'new-secret-9876' }])
    const put = apiRequests().find((request) => request.method === 'PUT')
    expect(new URL(put!.url).pathname).toBe('/api/admin/settings')
    expect(put!.headers.get('X-XSRF-TOKEN')).toBe('token')
    expect(within(section).getByText('設定済み(末尾 9876)')).toBeInTheDocument()
    expect(within(section).getByText('管理者の花子(2026年11月3日 12:00)')).toBeInTheDocument()
    expect(within(section).getByLabelText('新しい値')).toHaveValue('')
  })

  test('TC-133-06: 値を入れずに保存すると、API を呼ばず、値を消さずにそう出す', async () => {
    mockUpdate(() => updated(SECRET_SAVED))
    await renderSettings()
    const section = itemSection(SECRET_SAVED.label)

    fireEvent.click(within(section).getByRole('button', { name: '保存' }))

    expect(await within(section).findByRole('status')).toHaveTextContent('値が空なので、変えていません。')
    expect(apiRequests().filter((request) => request.method === 'PUT')).toHaveLength(0)
    expect(within(section).getByText('設定済み(末尾 wxyz)')).toBeInTheDocument()
  })

  test('TC-133-14: 入れた秘密の値は、保存した後の画面の HTML に出ない', async () => {
    mockUpdate(() => updated({ ...SECRET_SAVED, last_four: 'ABCD' }))
    const { container } = await renderSettings()
    const section = itemSection(SECRET_SAVED.label)

    const input = within(section).getByLabelText('新しい値')
    fireEvent.change(input, { target: { value: 'plain-secret-ABCD' } })
    // 入れているあいだも、値は欄の属性(HTML)に書かない
    expect(input).not.toHaveAttribute('value')
    expect(container.innerHTML).not.toContain('plain-secret-ABCD')
    fireEvent.click(within(section).getByRole('button', { name: '保存' }))

    expect(await within(section).findByRole('status')).toHaveTextContent('保存しました。')
    expect(container.innerHTML).not.toContain('plain-secret-ABCD')
    expect(within(section).getByLabelText('新しい値')).toHaveValue('')
  })
})

describe('秘密でない項目の保存', () => {
  test('TC-133-07: 値を書き換えて保存すると、その値を送り、応答の値と出どころを出す', async () => {
    const bodies = mockUpdate(({ value }) =>
      updated({ ...PLAIN_ENV, value, source: 'saved', updated_at: '2026-11-03T03:00:00.000000Z', updated_by: { id: 1, name: '管理者の太郎' } }),
    )
    await renderSettings()
    const section = itemSection(PLAIN_ENV.label)

    fireEvent.change(within(section).getByLabelText('値'), { target: { value: 'new-tag-22' } })
    fireEvent.click(within(section).getByRole('button', { name: '保存' }))

    expect(await within(section).findByRole('status')).toHaveTextContent('保存しました。')
    expect(bodies).toEqual([{ key: 'amazon.partner_tag', value: 'new-tag-22' }])
    expect(within(section).getByLabelText('値')).toHaveValue('new-tag-22')
    expect(within(section).getByText('この画面で保存した値')).toBeInTheDocument()
    expect(within(section).getByText('管理者の太郎(2026年11月3日 12:00)')).toBeInTheDocument()
  })

  test('TC-133-08: 欄を空にして保存すると null を送り、応答の値(.env の値)を欄に出す', async () => {
    const saved: Setting = { ...PLAIN_ENV, value: 'saved-tag', source: 'saved' }
    const bodies = mockUpdate(() => updated({ ...PLAIN_ENV, updated_at: '2026-11-03T03:00:00.000000Z' }))
    await renderSettings([saved])
    const section = itemSection(PLAIN_ENV.label)

    fireEvent.change(within(section).getByLabelText('値'), { target: { value: '' } })
    fireEvent.click(within(section).getByRole('button', { name: '保存' }))

    expect(await within(section).findByRole('status')).toHaveTextContent('保存しました。')
    expect(bodies).toEqual([{ key: 'amazon.partner_tag', value: null }])
    expect(within(section).getByLabelText('値')).toHaveValue('env-tag-22')
    expect(within(section).getByText('サーバーの .env の値')).toBeInTheDocument()
  })
})

describe('保存の失敗', () => {
  test('TC-133-09: 規則に合わない値(422)は、その項目の欄の下にメッセージを出し、入れた値と前の状態を残す', async () => {
    mockApiHandler('put', '/admin/settings', () =>
      jsonResponse(
        { message: 'The value field format is invalid.', errors: { value: ['The value field format is invalid.'] } } satisfies Invalid,
        422,
      ),
    )
    await renderSettings()
    const section = itemSection(PLAIN_ENV.label)

    const input = within(section).getByLabelText('値')
    fireEvent.change(input, { target: { value: 'bad tag' } })
    fireEvent.click(within(section).getByRole('button', { name: '保存' }))

    const message = await within(section).findByRole('alert')
    expect(message).toHaveTextContent('この値は保存できません。The value field format is invalid.')
    expect(input).toHaveAttribute('aria-invalid', 'true')
    expect(input).toHaveAccessibleDescription(expect.stringContaining('この値は保存できません。'))
    expect(input).toHaveValue('bad tag')
    expect(within(section).getByText('サーバーの .env の値')).toBeInTheDocument()
    // ほかの項目には出さない
    expect(within(itemSection(SECRET_SAVED.label)).queryByRole('alert')).not.toBeInTheDocument()
  })

  test('TC-133-10: 保存が 500・通信の失敗なら、メッセージを出し、もう一度押せる', async () => {
    mockApiHandler('put', '/admin/settings', () => jsonResponse({ message: 'Server Error' }, 500))
    await renderSettings()
    const section = itemSection(PLAIN_ENV.label)

    fireEvent.click(within(section).getByRole('button', { name: '保存' }))
    expect(await within(section).findByRole('alert')).toHaveTextContent(
      '保存できませんでした(エラー 500)。時間をおいて、もう一度お試しください。',
    )
    expect(within(section).getByRole('button', { name: '保存' })).toBeEnabled()

    mockApiHandler('put', '/admin/settings', () => Promise.reject(new TypeError('Failed to fetch')))
    fireEvent.click(within(section).getByRole('button', { name: '保存' }))
    await waitFor(() =>
      expect(within(section).getByRole('alert')).toHaveTextContent(
        '保存できませんでした。通信に失敗しました。接続を確かめて、もう一度お試しください。',
      ),
    )
    expect(within(section).getByRole('button', { name: '保存' })).toBeEnabled()
  })

  test('TC-133-11: 保存の応答を待つあいだは、その項目の保存を押せない', async () => {
    let respond: ((response: Response) => void) | undefined
    mockApiHandler('put', '/admin/settings', () => new Promise<Response>((resolve) => (respond = resolve)))
    await renderSettings()
    const section = itemSection(PLAIN_ENV.label)

    fireEvent.click(within(section).getByRole('button', { name: '保存' }))

    expect(within(section).getByRole('button', { name: '保存' })).toBeDisabled()
    expect(within(itemSection(SECRET_SAVED.label)).getByRole('button', { name: '保存' })).toBeEnabled()
    await waitFor(() => expect(respond).toBeDefined())
    await act(async () => respond!(updated(PLAIN_ENV)))
    expect(await within(section).findByRole('status')).toHaveTextContent('保存しました。')
    expect(within(section).getByRole('button', { name: '保存' })).toBeEnabled()
  })
})

describe('メニュー', () => {
  test('TC-133-15: メニューの「設定」から設定の画面を開け、開いているあいだは「設定」がいまの画面になる', async () => {
    loggedIn()
    mockSettings(ALL)
    render(
      <MemoryRouter initialEntries={['/admin']}>
        <App />
      </MemoryRouter>,
    )
    const menu = await screen.findByRole('navigation', { name: '管理画面のメニュー' })

    fireEvent.click(within(menu).getByRole('link', { name: '設定' }))

    expect(await screen.findByRole('heading', { level: 1, name: '設定' })).toBeInTheDocument()
    expect(within(menu).getByRole('link', { name: '設定' })).toHaveAttribute('aria-current', 'page')
  })
})
