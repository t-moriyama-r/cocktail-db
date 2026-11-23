import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router'
import { afterEach, describe, expect, test, vi } from 'vitest'
import App from '../App'
import type { paths } from '../api/schema'
import { apiRequests, jsonResponse, mockApi, mockApiHandler } from '../test/api'

// 管理画面の用語辞典の一覧・新規・編集・削除(#66)。BE の API は fetch の層で差し替える

type Categories = paths['/admin/dictionaries']['get']['responses'][200]['content']['application/json']['data']
type Dictionary = paths['/admin/dictionaries/{dictionary}']['get']['responses'][200]['content']['application/json']['data']

const CATEGORIES: Categories = [
  {
    id: 1,
    name: '製法',
    name_eng: 'Method',
    dictionaries: [
      { id: 11, name: 'シェイク', name_eng: 'Shake' },
      { id: 10, name: 'ビルド', name_eng: 'Build' },
    ],
  },
  { id: 2, name: 'グラス', name_eng: 'Glass', dictionaries: [{ id: 20, name: 'タンブラー', name_eng: 'Tumbler' }] },
  { id: 5, name: 'スタイル', name_eng: 'Style', dictionaries: [] },
]

const TUMBLER: Dictionary = {
  id: 20,
  dictionary_category_id: 2,
  name: 'タンブラー',
  name_eng: 'Tumbler',
  description: '脚の無い、筒の形のグラス。',
  youtube: 'abcdefghijk',
  youtube_start_hour: 0,
  youtube_start_minute: 1,
  youtube_start_second: 30,
  image: 'glass.png',
  image_txt: '写真の出典',
  image_url: 'https://example.com/photo',
  updated_at: '2026-11-01T00:00:00.000000Z',
}

function CurrentUrl() {
  const { pathname } = useLocation()
  return <div data-testid="current-url">{pathname}</div>
}

const renderAt = (path: string) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <App />
      <CurrentUrl />
    </MemoryRouter>,
  )

const currentUrl = () => screen.getByTestId('current-url').textContent

const setUp = () => {
  mockApi('get', '/admin/me', 200, { id: 1, name: '管理者の太郎' })
  mockApi('get', '/admin/dictionaries', 200, { data: CATEGORIES })
  mockApi('get', '/admin/dictionaries/{dictionary}', 200, { data: TUMBLER })
}

/** 送ったリクエストのうち、メソッドとパスが合うもの */
const sent = (method: string, path: string) =>
  apiRequests().filter((request) => request.method === method && new URL(request.url).pathname === `/api${path}`)

/** 送った multipart の本文を、項目の名前と値(ファイルは file)の組にする */
const formEntries = async (request: Request) => {
  const entries: Record<string, string> = {}
  ;(await request.clone().formData()).forEach((value, key) => {
    entries[key] = typeof value === 'string' ? value : 'file'
  })
  return entries
}

const textbox = (name: string) => screen.getByRole('textbox', { name })

afterEach(() => {
  vi.restoreAllMocks()
})

describe('一覧', () => {
  test('TC-66-01: 用語のある分類だけを API の順に見出しで出し、用語を API の順に「名前(英名)」で出す', async () => {
    setUp()
    renderAt('/admin/dictionaries')

    expect(await screen.findByRole('heading', { level: 1, name: '用語辞典' })).toBeInTheDocument()
    await waitFor(() => expect(screen.getAllByRole('heading', { level: 2 })).toHaveLength(2))
    expect(screen.getAllByRole('heading', { level: 2 }).map((heading) => heading.textContent)).toEqual([
      '製法(Method)',
      'グラス(Glass)',
    ])
    const method = screen.getByRole('region', { name: '製法(Method)' })
    expect(within(method).getAllByRole('link').map((link) => link.textContent)).toEqual(['シェイク(Shake)', 'ビルド(Build)'])
    expect(screen.queryByText('スタイル(Style)')).not.toBeInTheDocument()
    await waitFor(() => expect(document.title).toBe('用語辞典 ｜ 宅飲みカクテルデータベース(RC)'))
  })

  test('TC-66-02: 用語のリンクは用語の編集の画面へ、「新規」は新規の画面へ', async () => {
    setUp()
    renderAt('/admin/dictionaries')

    expect(await screen.findByRole('link', { name: 'タンブラー(Tumbler)' })).toHaveAttribute('href', '/admin/dictionaries/20')
    expect(screen.getByRole('link', { name: '新規' })).toHaveAttribute('href', '/admin/dictionaries/new')
  })

  test('TC-66-03: メニューの「用語辞典」から一覧を開ける', async () => {
    setUp()
    renderAt('/admin')

    fireEvent.click(await screen.findByRole('link', { name: '用語辞典' }))

    expect(await screen.findByRole('heading', { level: 1, name: '用語辞典' })).toBeInTheDocument()
    expect(currentUrl()).toBe('/admin/dictionaries')
  })
})

describe('新規', () => {
  test('TC-66-04: 分類の選択肢に、用語の無い分類も出る', async () => {
    setUp()
    renderAt('/admin/dictionaries/new')

    const select = await screen.findByRole('combobox', { name: '分類' })
    expect(within(select).getAllByRole('option').map((option) => option.textContent)).toEqual([
      '選んでください',
      '製法(Method)',
      'グラス(Glass)',
      'スタイル(Style)',
    ])
  })

  test('TC-66-05: 入れて保存すると multipart で送り、一覧へ移って保存したことを出す', async () => {
    setUp()
    mockApi('post', '/admin/dictionaries', 201, { data: { ...TUMBLER, id: 30, name: 'ロック', name_eng: 'Rock' } })
    renderAt('/admin/dictionaries/new')

    fireEvent.change(await screen.findByRole('combobox', { name: '分類' }), { target: { value: '5' } })
    fireEvent.change(textbox('名前'), { target: { value: 'ロック' } })
    fireEvent.change(textbox('英名'), { target: { value: 'Rock' } })
    fireEvent.change(textbox('説明'), { target: { value: '氷を入れて飲む。' } })
    fireEvent.change(screen.getByRole('spinbutton', { name: '開始位置(分)' }), { target: { value: '2' } })
    fireEvent.click(screen.getByRole('button', { name: '保存する' }))

    expect(await screen.findByRole('status')).toHaveTextContent('「ロック(Rock)」を保存しました。')
    expect(currentUrl()).toBe('/admin/dictionaries')
    const [request] = sent('POST', '/admin/dictionaries')
    expect(request.headers.get('Content-Type')).toMatch(/^multipart\/form-data/)
    expect(await formEntries(request)).toEqual({
      dictionary_category_id: '5',
      name: 'ロック',
      name_eng: 'Rock',
      description: '氷を入れて飲む。',
      youtube_start_minute: '2',
    })
  })

  test('TC-66-06: 分類を選ばずに保存すると、分類の欄に誤りを出して送らない', async () => {
    setUp()
    renderAt('/admin/dictionaries/new')

    const select = await screen.findByRole('combobox', { name: '分類' })
    fireEvent.change(textbox('名前'), { target: { value: 'ロック' } })
    fireEvent.click(screen.getByRole('button', { name: '保存する' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('保存できませんでした。入力を確かめてください。')
    expect(select).toHaveAccessibleDescription('分類を選んでください。')
    expect(select).toHaveAttribute('aria-invalid', 'true')
    expect(sent('POST', '/admin/dictionaries')).toHaveLength(0)
    expect(currentUrl()).toBe('/admin/dictionaries/new')
  })

  test('TC-66-07: 保存が 422 のときは、欄ごとに誤りを出し、画面を移らず入力を残す', async () => {
    setUp()
    mockApiHandler('post', '/admin/dictionaries', () =>
      jsonResponse(
        {
          message: '名前は必ず指定してください。 (and 1 more error)',
          errors: { name: ['名前は必ず指定してください。'], youtube: ['YouTube の URL が読めません。'] },
        },
        422,
      ),
    )
    renderAt('/admin/dictionaries/new')

    fireEvent.change(await screen.findByRole('combobox', { name: '分類' }), { target: { value: '1' } })
    fireEvent.change(textbox('英名'), { target: { value: 'Rock' } })
    fireEvent.change(textbox('YouTube'), { target: { value: 'nope' } })
    fireEvent.click(screen.getByRole('button', { name: '保存する' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('保存できませんでした。入力を確かめてください。')
    expect(textbox('名前')).toHaveAccessibleDescription('名前は必ず指定してください。')
    expect(textbox('YouTube')).toHaveAccessibleDescription('YouTube の URL が読めません。')
    expect(textbox('英名')).not.toHaveAttribute('aria-invalid')
    expect(textbox('英名')).toHaveValue('Rock')
    expect(screen.getByRole('combobox', { name: '分類' })).toHaveValue('1')
    expect(currentUrl()).toBe('/admin/dictionaries/new')
  })

  test.each([
    [
      '500 のとき',
      () => jsonResponse({ message: 'Server Error' }, 500),
      '保存できませんでした(エラー 500)。時間をおいて、もう一度お試しください。',
    ],
    [
      '通信に失敗したとき',
      () => Promise.reject(new TypeError('Failed to fetch')),
      '保存できませんでした。通信に失敗しました。接続を確かめて、もう一度お試しください。',
    ],
  ])('TC-66-08: 保存が %s は、何が起きたかを出し、画面を移らない', async (_, respond, message) => {
    setUp()
    mockApiHandler('post', '/admin/dictionaries', respond)
    renderAt('/admin/dictionaries/new')

    fireEvent.change(await screen.findByRole('combobox', { name: '分類' }), { target: { value: '1' } })
    fireEvent.change(textbox('名前'), { target: { value: 'ロック' } })
    fireEvent.click(screen.getByRole('button', { name: '保存する' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(message)
    expect(textbox('名前')).toHaveValue('ロック')
    expect(currentUrl()).toBe('/admin/dictionaries/new')
  })

  test('TC-715-03: 保存が 401 なら、ログインの期限切れとログインし直す手順を出し、入力を残して画面を移らない', async () => {
    setUp()
    mockApiHandler('post', '/admin/dictionaries', () => jsonResponse({ message: 'Unauthenticated.' }, 401))
    renderAt('/admin/dictionaries/new')

    fireEvent.change(await screen.findByRole('combobox', { name: '分類' }), { target: { value: '1' } })
    fireEvent.change(textbox('名前'), { target: { value: 'ロック' } })
    fireEvent.click(screen.getByRole('button', { name: '保存する' }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('ログインの期限が切れていて保存できませんでした。入力を控えてから、ページを開き直してログインし直してください。')
    expect(alert).not.toHaveTextContent('時間をおいて')
    expect(textbox('名前')).toHaveValue('ロック')
    expect(currentUrl()).toBe('/admin/dictionaries/new')
  })
})

describe('新規の冪等キー(#791)', () => {
  const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/
  const KEY_REJECTED =
    '保存できませんでした。通信に失敗した前の送信が、保存されているかもしれません。一覧で確かめてから、要るときだけもう一度送信してください。'

  /**
   * BE の冪等キーの扱い(docs/decisions/admin-idempotency.md)をまねた POST /api/admin/dictionaries。
   * 成功した送信だけをキーごとに覚え、同じキー・同じ中身(分類・名前・画像の中身)の再送には 1 回目の応答を `Idempotent-Replayed: true` を
   * 付けて返し、同じキー・違う中身の再送は 422(errors.idempotency_key)で断る。loseResponses の回は、保存したうえで応答を失わせる。
   * created には、作った用語を「名前 / 画像の中身(無ければ -)」で残す
   */
  const fakeDictionaryStore = (options: { loseResponses?: number[]; invalidNames?: string[] } = {}) => {
    const created: string[] = []
    const keys: (string | null)[] = []
    const savedContents = new Map<string, string>()
    mockApiHandler('post', '/admin/dictionaries', async (request) => {
      const attempt = keys.length + 1
      const key = request.headers.get('Idempotency-Key')
      keys.push(key)
      const body = await request.formData()
      const name = String(body.get('name'))
      const image = body.get('image')
      const imageName = image === null ? '-' : typeof image === 'string' ? image : await image.text()
      const content = `${String(body.get('dictionary_category_id'))}/${name}/${imageName}`
      const response = () => jsonResponse({ data: { ...TUMBLER, id: 30, name, name_eng: 'Rock' } }, 201)
      if (key !== null && savedContents.has(key)) {
        if (savedContents.get(key) !== content) {
          return jsonResponse(
            {
              message: '冪等キー(Idempotency-Key)は、別の内容の送信に使われています。',
              errors: { idempotency_key: ['冪等キー(Idempotency-Key)は、別の内容の送信に使われています。'] },
            },
            422,
          )
        }
        const replayed = response()
        replayed.headers.set('Idempotent-Replayed', 'true')
        return replayed
      }
      if (options.invalidNames?.includes(name)) {
        return jsonResponse({ message: '入力に誤りがあります。', errors: { name: ['名前が正しくありません。'] } }, 422)
      }
      created.push(`${name} / ${imageName}`)
      if (key !== null) savedContents.set(key, content)
      if (options.loseResponses?.includes(attempt)) throw new TypeError('Failed to fetch')
      return response()
    })
    return { created, keys }
  }

  /** 新規の画面を開き、分類にグラスを選ぶ */
  const openNewWithStore = async (options: Parameters<typeof fakeDictionaryStore>[0] = {}) => {
    setUp()
    const store = fakeDictionaryStore(options)
    renderAt('/admin/dictionaries/new')
    fireEvent.change(await screen.findByRole('combobox', { name: '分類' }), { target: { value: '2' } })
    return store
  }

  const fillName = (name: string) => fireEvent.change(textbox('名前'), { target: { value: name } })
  const save = () => fireEvent.click(screen.getByRole('button', { name: '保存する' }))

  test('TC-791-01: 新規の送信に、UUID の Idempotency-Key ヘッダを付けて送る', async () => {
    const store = await openNewWithStore()

    fillName('ロック')
    save()

    expect(await screen.findByRole('status')).toHaveTextContent('「ロック(Rock)」を保存しました。')
    expect(currentUrl()).toBe('/admin/dictionaries')
    expect(store.keys).toHaveLength(1)
    expect(store.keys[0]).toMatch(UUID)
  })

  test('TC-791-02: 画像を付けた 1 回目の応答が失われて送り直すと、同じキーで送り、用語は 1 件だけできて一覧へ戻る', async () => {
    const store = await openNewWithStore({ loseResponses: [1] })

    fillName('ロック')
    fireEvent.change(screen.getByLabelText('画像'), { target: { files: [new File(['rock-png'], 'rock.png', { type: 'image/png' })] } })
    save()
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('通信に失敗しました'))
    expect(currentUrl()).toBe('/admin/dictionaries/new')

    save()

    expect(await screen.findByRole('status')).toHaveTextContent('「ロック(Rock)」を保存しました。')
    expect(currentUrl()).toBe('/admin/dictionaries')
    expect(store.keys).toHaveLength(2)
    expect(store.keys[1]).toBe(store.keys[0])
    expect(store.created).toEqual(['ロック / rock-png'])
  })

  test('TC-791-03: 422 で画面に残った後、入力を直して送り直すと、同じキーで送って保存できる', async () => {
    const store = await openNewWithStore({ invalidNames: ['誤り'] })

    fillName('誤り')
    save()
    expect(await screen.findByRole('alert')).toHaveTextContent('入力を確かめてください')

    fillName('ロック')
    save()

    expect(await screen.findByRole('status')).toHaveTextContent('「ロック(Rock)」を保存しました。')
    expect(currentUrl()).toBe('/admin/dictionaries')
    expect(store.keys[1]).toBe(store.keys[0])
    expect(store.created).toEqual(['ロック / -'])
  })

  test('TC-791-04: 応答が失われた後に入力を変えて送り直し、キーを断られたら、前の送信が保存されているかもしれないことを出し、次は新しいキーで送る', async () => {
    const store = await openNewWithStore({ loseResponses: [1] })

    fillName('ロック')
    save()
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('通信に失敗しました'))

    fillName('オンザロック')
    save()
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent(KEY_REJECTED))
    expect(currentUrl()).toBe('/admin/dictionaries/new')
    expect(textbox('名前')).toHaveValue('オンザロック')
    expect(textbox('名前')).not.toHaveAttribute('aria-invalid')
    expect(screen.getByRole('button', { name: '保存する' })).toBeEnabled()
    expect(store.keys[1]).toBe(store.keys[0])
    expect(store.created).toEqual(['ロック / -'])

    save()

    expect(await screen.findByRole('status')).toHaveTextContent('「オンザロック(Rock)」を保存しました。')
    expect(currentUrl()).toBe('/admin/dictionaries')
    expect(store.keys[2]).toMatch(UUID)
    expect(store.keys[2]).not.toBe(store.keys[0])
    expect(store.created).toEqual(['ロック / -', 'オンザロック / -'])
  })

  test('TC-791-05: 保存できた後に開き直した別の新規では、新しいキーで送る', async () => {
    const store = await openNewWithStore()

    fillName('ロック')
    save()
    expect(await screen.findByRole('status')).toHaveTextContent('「ロック(Rock)」を保存しました。')

    fireEvent.click(screen.getByRole('link', { name: '新規' }))
    fireEvent.change(await screen.findByRole('combobox', { name: '分類' }), { target: { value: '2' } })
    fillName('オンザロック')
    save()

    await waitFor(() => expect(store.keys).toHaveLength(2))
    expect(await screen.findByRole('status')).toHaveTextContent('「オンザロック(Rock)」を保存しました。')
    expect(store.keys[1]).toMatch(UUID)
    expect(store.keys[1]).not.toBe(store.keys[0])
    expect(store.created).toEqual(['ロック / -', 'オンザロック / -'])
  })

  test('TC-791-06: 更新の送信には Idempotency-Key ヘッダを付けない', async () => {
    setUp()
    mockApi('put', '/admin/dictionaries/{dictionary}', 200, { data: TUMBLER })
    renderAt('/admin/dictionaries/20')

    expect(await screen.findByRole('textbox', { name: '名前' })).toHaveValue('タンブラー')
    save()

    expect(await screen.findByRole('status')).toHaveTextContent('「タンブラー(Tumbler)」を保存しました。')
    const [update] = sent('POST', '/admin/dictionaries/20')
    expect(update.headers.has('Idempotency-Key')).toBe(false)
  })
})

describe('編集', () => {
  test('TC-66-09: 開くと、今の値が欄に入り、今の画像を出す', async () => {
    setUp()
    renderAt('/admin/dictionaries/20')

    expect(await screen.findByRole('heading', { level: 1, name: '用語の編集' })).toBeInTheDocument()
    expect(screen.getByRole('combobox', { name: '分類' })).toHaveValue('2')
    expect(textbox('名前')).toHaveValue('タンブラー')
    expect(textbox('英名')).toHaveValue('Tumbler')
    expect(textbox('説明')).toHaveValue('脚の無い、筒の形のグラス。')
    expect(textbox('YouTube')).toHaveValue('abcdefghijk')
    expect(screen.getByRole('spinbutton', { name: '開始位置(時)' })).toHaveValue(0)
    expect(screen.getByRole('spinbutton', { name: '開始位置(分)' })).toHaveValue(1)
    expect(screen.getByRole('spinbutton', { name: '開始位置(秒)' })).toHaveValue(30)
    expect(textbox('リンクテキスト')).toHaveValue('写真の出典')
    expect(textbox('リンク URL')).toHaveValue('https://example.com/photo')
    expect(screen.getByRole('img', { name: '今の画像' })).toHaveAttribute('src', '/storage/glass.png')
    expect(screen.getByRole('checkbox', { name: '画像を消す' })).not.toBeChecked()
  })

  test('TC-66-10: 保存すると、その用語の URL に POST と _method=PUT で送り(PUT として受けられる)、一覧へ移る', async () => {
    setUp()
    mockApi('put', '/admin/dictionaries/{dictionary}', 200, { data: { ...TUMBLER, name: 'ハイボールグラス' } })
    renderAt('/admin/dictionaries/20')

    fireEvent.change(await screen.findByRole('textbox', { name: '名前' }), { target: { value: 'ハイボールグラス' } })
    fireEvent.change(textbox('リンクテキスト'), { target: { value: '' } })
    fireEvent.click(screen.getByRole('button', { name: '保存する' }))

    expect(await screen.findByRole('status')).toHaveTextContent('「ハイボールグラス(Tumbler)」を保存しました。')
    expect(currentUrl()).toBe('/admin/dictionaries')
    const [request] = sent('POST', '/admin/dictionaries/20')
    expect(await formEntries(request)).toEqual({
      dictionary_category_id: '2',
      name: 'ハイボールグラス',
      name_eng: 'Tumbler',
      description: '脚の無い、筒の形のグラス。',
      youtube: 'abcdefghijk',
      youtube_start_hour: '0',
      youtube_start_minute: '1',
      youtube_start_second: '30',
      image_url: 'https://example.com/photo',
      _method: 'PUT',
    })
  })

  test('TC-66-11: 画像を選んで保存すると、画像のファイルを送る', async () => {
    setUp()
    mockApi('put', '/admin/dictionaries/{dictionary}', 200, { data: TUMBLER })
    renderAt('/admin/dictionaries/20')

    const file = new File(['png'], 'new.png', { type: 'image/png' })
    fireEvent.change(await screen.findByLabelText('画像'), { target: { files: [file] } })
    fireEvent.click(screen.getByRole('button', { name: '保存する' }))

    await screen.findByRole('status')
    const entries = await formEntries(sent('POST', '/admin/dictionaries/20')[0])
    expect(entries).toMatchObject({ image: 'file' })
    expect(entries).not.toHaveProperty('remove_image')
  })

  test('TC-66-22: 「画像を消す」にして保存すると、remove_image を送り、画像のファイルは送らない', async () => {
    setUp()
    mockApi('put', '/admin/dictionaries/{dictionary}', 200, { data: { ...TUMBLER, image: null } })
    renderAt('/admin/dictionaries/20')

    fireEvent.click(await screen.findByRole('checkbox', { name: '画像を消す' }))
    fireEvent.click(screen.getByRole('button', { name: '保存する' }))

    await screen.findByRole('status')
    const entries = await formEntries(sent('POST', '/admin/dictionaries/20')[0])
    expect(entries).toMatchObject({ remove_image: '1' })
    expect(entries).not.toHaveProperty('image')
  })

  test('TC-66-12: 削除は確かめを出し、やめると送らない', async () => {
    setUp()
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    renderAt('/admin/dictionaries/20')

    fireEvent.click(await screen.findByRole('button', { name: '削除する' }))

    expect(confirm).toHaveBeenCalledWith('「タンブラー(Tumbler)」を消します。よろしいですか?')
    expect(sent('DELETE', '/admin/dictionaries/20')).toHaveLength(0)
    expect(currentUrl()).toBe('/admin/dictionaries/20')
  })

  test('TC-66-13: 確かめて消すと DELETE を送り、一覧へ移って消したことを出す', async () => {
    setUp()
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    mockApiHandler('delete', '/admin/dictionaries/{dictionary}', () => new Response(null, { status: 204 }))
    renderAt('/admin/dictionaries/20')

    fireEvent.click(await screen.findByRole('button', { name: '削除する' }))

    expect(await screen.findByRole('status')).toHaveTextContent('「タンブラー(Tumbler)」を消しました。')
    expect(currentUrl()).toBe('/admin/dictionaries')
    expect(sent('DELETE', '/admin/dictionaries/20')).toHaveLength(1)
  })

  test('TC-66-14: 消せない(409)ときは、API が返す理由をそのまま出し、画面に残る', async () => {
    setUp()
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    mockApi('delete', '/admin/dictionaries/{dictionary}', 409, {
      message: 'この用語は消せません',
      reasons: [{ code: 'glass', message: 'カクテルのグラスに使われています' }],
    })
    renderAt('/admin/dictionaries/20')

    fireEvent.click(await screen.findByRole('button', { name: '削除する' }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('この用語は消せません。')
    expect(within(alert).getAllByRole('listitem').map((item) => item.textContent)).toEqual([
      'カクテルのグラスに使われています',
    ])
    expect(currentUrl()).toBe('/admin/dictionaries/20')
    expect(textbox('名前')).toHaveValue('タンブラー')
  })

  test('TC-715-04: 削除が 401 なら、ログインの期限切れとログインし直す手順を出し、画面に残る', async () => {
    setUp()
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    mockApiHandler('delete', '/admin/dictionaries/{dictionary}', () => jsonResponse({ message: 'Unauthenticated.' }, 401))
    renderAt('/admin/dictionaries/20')

    fireEvent.click(await screen.findByRole('button', { name: '削除する' }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('ログインの期限が切れていて削除できませんでした。ページを開き直して、ログインし直してください。')
    expect(alert).not.toHaveTextContent('時間をおいて')
    expect(currentUrl()).toBe('/admin/dictionaries/20')
    expect(textbox('名前')).toHaveValue('タンブラー')
  })

  test.each([
    ['無い ID', '/admin/dictionaries/999'],
    ['数字でない ID', '/admin/dictionaries/abc'],
  ])('TC-66-15: %s は、メニューを出したまま 404 の画面を出す', async (_, path) => {
    setUp()
    mockApiHandler('get', '/admin/dictionaries/{dictionary}', () => jsonResponse({ message: 'Not Found' }, 404))
    renderAt(path)

    expect(await screen.findByRole('heading', { level: 1, name: 'ページが見つかりません' })).toBeInTheDocument()
    expect(screen.getByRole('navigation', { name: '管理画面のメニュー' })).toBeInTheDocument()
  })
})
