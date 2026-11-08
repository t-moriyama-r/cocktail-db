import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router'
import { afterEach, describe, expect, test, vi } from 'vitest'
import App from '../App'
import type { components, paths } from '../api/schema'
import { apiRequests, jsonResponse, mockApi, mockApiHandler } from '../test/api'

type CategoryResource = components['schemas']['CategoryResource']
type TreeItem = components['schemas']['CategoryTreeItemResource']
type Saved = paths['/admin/categories/{category}']['put']['responses'][200]['content']['application/json']

const loggedIn = () => mockApi('get', '/admin/me', 200, { id: 1, name: '管理者' })

const item = (id: number, name: string, nameEng: string, children: TreeItem[] = [], role: TreeItem['role'] = null): TreeItem => ({
  id,
  name,
  name_eng: nameEng,
  role,
  root_role: role,
  is_major: true,
  children,
})

/** 根の役割(root_role)を、根の下のノードすべてに入れる(API と同じく、部分木のノードは根の役割を持つ) */
const withRootRole = (node: TreeItem, rootRole: TreeItem['root_role']): TreeItem => ({
  ...node,
  root_role: rootRole,
  children: node.children.map((child) => withRootRole(child, rootRole)),
})

/** 管理 API の木: 蒸留酒(根) > ジン > ドライジン、その他(根。役割: その他) > ミント */
const TREE: TreeItem[] = [
  item(1, '蒸留酒', 'Spirits', [item(2, 'ジン', 'Gin', [item(3, 'ドライジン', 'Dry Gin')])]),
  item(5, 'その他', 'Other', [item(6, 'ミント', 'Mint')], 'other'),
].map((root) => withRootRole(root, root.role))

const mockTree = () => mockApi('get', '/admin/categories', 200, { data: TREE })

/** 編集で開くノード: 蒸留酒 > ジン(有名な銘柄はビーフィーター。子にドライジン、直下の銘柄にゴードン) */
const GIN: CategoryResource = {
  id: 2,
  parent_id: 1,
  path: [{ id: 1, name: '蒸留酒' }],
  role: null,
  root_role: null,
  famous_brand_requirement: 'required',
  name: 'ジン',
  name_eng: 'Gin',
  description: '杜松の実で香りを付ける。@b8[ビーフィーター]@が有名',
  is_major: true,
  is_like: false,
  is_powder: false,
  famous_brand_id: 8,
  famous_brand_candidates: [{ id: 8, name: 'ビーフィーター', name_eng: 'Beefeater' }],
  children: [{ id: 3, name: 'ドライジン', name_eng: 'Dry Gin' }],
  brands: [{ id: 9, name: 'ゴードン', name_eng: "Gordon's" }],
  youtube: 'abcdefghijk',
  youtube_start_hour: 1,
  youtube_start_minute: 2,
  youtube_start_second: 3,
  youtube_created_at: null,
  image: 'gin.png',
  image_txt: '公式',
  image_url: 'https://example.com/',
  delete_blockers: [],
  updated_at: null,
}

/** 根のノード(役割: その他)。子も銘柄も無く、画像も無い */
const OTHER_ROOT: CategoryResource = {
  ...GIN,
  id: 5,
  parent_id: null,
  path: [],
  role: 'other',
  root_role: 'other',
  famous_brand_requirement: 'none',
  name: 'その他',
  name_eng: 'Other',
  famous_brand_id: null,
  famous_brand_candidates: [],
  children: [],
  brands: [],
  image: null,
}

const saved = (node: CategoryResource, warnings: Saved['warnings'] = []): Saved => ({ data: node, warnings })

/** いまの URL を画面に出す(保存の後に移った先を確かめるため) */
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

/** 保存の API に応答を登録し、送られた本文を返す関数を返す */
const captureSave = (method: 'post' | 'put', response: () => Response) => {
  let sent: FormData | null = null
  const handler = async (request: Request) => {
    sent = await request.formData()
    return response()
  }
  if (method === 'post') {
    mockApiHandler('post', '/admin/categories', handler)
  } else {
    mockApiHandler('put', '/admin/categories/{category}', handler)
  }
  return () => sent
}

/** 送られた multipart の項目と値(ファイルは除く) */
const textEntries = (body: FormData) => {
  const entries: Record<string, string> = {}
  body.forEach((value, key) => {
    if (typeof value === 'string') entries[key] = value
  })
  return entries
}

const openEdit = async (node: CategoryResource = GIN) => {
  loggedIn()
  mockTree()
  mockApi('get', '/admin/categories/{category}', 200, { data: node })
  renderAt(`/admin/categories/${node.id}`)
  await screen.findByRole('heading', { level: 1, name: 'ノードの編集' })
}

const openNew = async () => {
  loggedIn()
  mockTree()
  renderAt('/admin/categories/new')
  await screen.findByRole('list', { name: '親' })
}

const submit = () => fireEvent.click(screen.getByRole('button', { name: '送信' }))

describe('カテゴリ木からの行き来', () => {
  test('TC-308-01: カテゴリ木の画面でノードを選ぶと、そのノードの編集フォームが開く', async () => {
    loggedIn()
    mockTree()
    mockApi('get', '/admin/categories/{category}', 200, { data: GIN })
    renderAt('/admin/categories')

    fireEvent.click(await screen.findByRole('radio', { name: 'ジン' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'ノードの編集' })).toBeInTheDocument()
    expect(currentUrl()).toBe('/admin/categories/2')
    expect(screen.getByLabelText('名前')).toHaveValue('ジン')
    await waitFor(() => expect(document.title).toBe('ノードの編集 ｜ 宅飲みカクテルデータベース(RC)'))
  })

  test('TC-308-02: カテゴリ木の画面の「新規」から、空のフォームの新規の画面が開く', async () => {
    loggedIn()
    mockTree()
    renderAt('/admin/categories')

    fireEvent.click(await screen.findByRole('link', { name: '新規' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'ノードの新規' })).toBeInTheDocument()
    expect(currentUrl()).toBe('/admin/categories/new')
    expect(await screen.findByText('選んでいる親: (未選択)')).toBeInTheDocument()
    expect(screen.getByLabelText('名前')).toHaveValue('')
    expect(screen.getByRole('checkbox', { name: 'メジャー' })).not.toBeChecked()
  })
})

describe('編集', () => {
  test('TC-308-03: 開くと、親のパンくずと、API の値が各欄に出る(開始位置は時・分・秒、説明は名前を添えた記法のまま)', async () => {
    await openEdit()

    expect(screen.getByText('蒸留酒')).toBeInTheDocument()
    expect(screen.getByLabelText('名前')).toHaveValue('ジン')
    expect(screen.getByLabelText('英名')).toHaveValue('Gin')
    expect(screen.getByRole('checkbox', { name: 'メジャー' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'お気に入り' })).not.toBeChecked()
    expect(screen.getByRole('checkbox', { name: '粉もの' })).not.toBeChecked()
    expect(screen.getByLabelText('説明')).toHaveValue('杜松の実で香りを付ける。@b8[ビーフィーター]@が有名')
    expect(screen.getByLabelText('YouTube')).toHaveValue('abcdefghijk')
    expect([screen.getByLabelText('時'), screen.getByLabelText('分'), screen.getByLabelText('秒')].map((input) => (input as HTMLInputElement).value)).toEqual([
      '1',
      '2',
      '3',
    ])
    expect(screen.getByLabelText('リンクテキスト')).toHaveValue('公式')
    expect(screen.getByLabelText('リンクURL')).toHaveValue('https://example.com/')
    expect(screen.getByRole('img', { name: 'ノードの画像' })).toHaveAttribute('src', '/storage/gin.png')
  })

  test('TC-308-04: 送信すると全項目を更新で送り、今の親・有名な銘柄も送り直し、カテゴリ木へ戻って保存したことを出す', async () => {
    await openEdit()
    const sent = captureSave('put', () => jsonResponse(saved(GIN), 200))

    fireEvent.change(screen.getByLabelText('名前'), { target: { value: 'ジン(改)' } })
    fireEvent.click(screen.getByRole('checkbox', { name: 'お気に入り' }))
    submit()

    expect(await screen.findByRole('status')).toHaveTextContent('「ジン(改)」を保存しました')
    expect(currentUrl()).toBe('/admin/categories')
    expect(textEntries(sent()!)).toEqual({
      _method: 'PUT',
      parent_id: '1',
      famous_brand_id: '8',
      name: 'ジン(改)',
      name_eng: 'Gin',
      description: '杜松の実で香りを付ける。@b8[ビーフィーター]@が有名',
      youtube: 'abcdefghijk',
      youtube_start_hour: '1',
      youtube_start_minute: '2',
      youtube_start_second: '3',
      image_txt: '公式',
      image_url: 'https://example.com/',
      is_major: '1',
      is_like: '1',
      is_powder: '0',
    })
  })

  test('TC-308-05: 保存の後に警告が返ったら、カテゴリ木の知らせに欄の名前を添えて出す', async () => {
    await openEdit()
    captureSave('put', () => jsonResponse(saved(GIN, [{ field: 'name_eng', message: '同じ名前が存在しています' }]), 200))

    submit()

    expect(await screen.findByRole('status')).toHaveTextContent('「ジン」を保存しました。英名: 同じ名前が存在しています')
  })

  test('TC-308-06: 保存が 422 なら、欄の下にメッセージを出し、画面を移らず入力を残す', async () => {
    await openEdit()
    captureSave('put', () =>
      jsonResponse({ message: 'invalid', errors: { youtube_start_second: ['秒は 59 以下にしてください'] } }, 422),
    )

    fireEvent.change(screen.getByLabelText('秒'), { target: { value: '60' } })
    submit()

    expect(await screen.findByRole('alert')).toHaveTextContent('保存できませんでした。入力を確かめてください。')
    const second = screen.getByLabelText('秒')
    expect(second).toHaveAttribute('aria-invalid', 'true')
    expect(second).toHaveAccessibleDescription('秒は 59 以下にしてください')
    expect(second).toHaveValue('60')
    expect(currentUrl()).toBe('/admin/categories/2')
  })

  test('TC-308-07: 422 のうち、このフォームに欄の無い項目のメッセージは、送信の下にまとめて出す', async () => {
    await openEdit()
    captureSave('put', () =>
      jsonResponse({ message: 'invalid', errors: { brand_name: ['有名な銘柄を選ぶか、作ってください'] } }, 422),
    )

    submit()

    expect(await screen.findByRole('alert')).toHaveTextContent('有名な銘柄を選ぶか、作ってください')
  })

  test('TC-308-08: 根を保存すると、親を空で、役割を今の値で送り、有名な銘柄は送らない。親の欄は「(根のノード)」', async () => {
    await openEdit(OTHER_ROOT)
    const sent = captureSave('put', () => jsonResponse(saved(OTHER_ROOT), 200))

    expect(screen.getByText('(根のノード)')).toBeInTheDocument()
    submit()

    await screen.findByRole('status')
    const entries = textEntries(sent()!)
    expect([entries.parent_id, entries.role, entries.famous_brand_id]).toEqual(['', 'other', undefined])
  })

  test('TC-308-09: 開始位置の欄は数字だけを、時は 1 桁、分・秒は 2 桁まで受け付ける', async () => {
    await openEdit()

    fireEvent.change(screen.getByLabelText('時'), { target: { value: '１2' } })
    fireEvent.change(screen.getByLabelText('分'), { target: { value: '5a9' } })
    fireEvent.change(screen.getByLabelText('秒'), { target: { value: '605' } })

    expect([screen.getByLabelText('時'), screen.getByLabelText('分'), screen.getByLabelText('秒')].map((input) => (input as HTMLInputElement).value)).toEqual([
      '1',
      '59',
      '60',
    ])
  })

  test('TC-308-10: 英名の全角の英数字は、欄を離れると半角になる', async () => {
    await openEdit()
    const nameEng = screen.getByLabelText('英名')

    fireEvent.change(nameEng, { target: { value: 'Ｇｉｎ１' } })
    fireEvent.blur(nameEng)

    expect(nameEng).toHaveValue('Gin1')
  })

  test('TC-308-11: メジャー・お気に入り・粉ものは、押すたびに入れ・外しができる', async () => {
    await openEdit()

    for (const name of ['メジャー', 'お気に入り', '粉もの']) {
      const checkbox = screen.getByRole('checkbox', { name })
      const before = (checkbox as HTMLInputElement).checked
      fireEvent.click(checkbox)
      expect(checkbox).toHaveProperty('checked', !before)
      fireEvent.click(checkbox)
      expect(checkbox).toHaveProperty('checked', before)
    }
  })

  test('TC-308-12: 画像を選ぶと、その画像をその場で出し、送信で image として送る(差し替え)', async () => {
    await openEdit()
    const sent = captureSave('put', () => jsonResponse(saved(GIN), 200))

    const file = new File([new Uint8Array([0x89, 0x50, 0x4e, 0x47])], 'new.png', { type: 'image/png' })
    fireEvent.change(screen.getByLabelText('画像'), { target: { files: [file] } })
    await waitFor(() => expect(screen.getByRole('img', { name: 'ノードの画像' }).getAttribute('src')).toMatch(/^data:image\/png;base64,/))

    submit()
    await screen.findByRole('status')
    // テストの環境では、読み直すとファイル名が落ちるので、種類と大きさで確かめる(ファイル名はサーバーが付け直す)
    const image = sent()!.get('image') as File
    expect([image.type, image.size]).toEqual(['image/png', 4])
    expect(sent()!.get('remove_image')).toBeNull()
  })

  test('TC-308-13: 「画像を消す」を入れると、画像を出さず、送信で remove_image を送る', async () => {
    await openEdit()
    const sent = captureSave('put', () => jsonResponse(saved(GIN), 200))

    fireEvent.click(screen.getByRole('checkbox', { name: '画像を消す' }))
    expect(screen.queryByRole('img', { name: 'ノードの画像' })).not.toBeInTheDocument()

    submit()
    await screen.findByRole('status')
    expect(sent()!.get('remove_image')).toBe('1')
    expect(sent()!.get('image')).toBeNull()
  })

  test('TC-308-14: 画像の無いノードには「画像を消す」を出さない', async () => {
    await openEdit(OTHER_ROOT)

    expect(screen.queryByRole('checkbox', { name: '画像を消す' })).not.toBeInTheDocument()
    expect(screen.queryByRole('img', { name: 'ノードの画像' })).not.toBeInTheDocument()
  })

  test('TC-308-15: フォームの下に、子のノードと直下の銘柄の一覧を「名前(英名)」の編集画面へのリンクで出す', async () => {
    await openEdit()

    const children = screen.getByRole('region', { name: '登録されている子のノードの一覧' })
    expect(within(children).getByRole('link', { name: 'ドライジン(Dry Gin)' })).toHaveAttribute('href', '/admin/categories/3')
    const brands = screen.getByRole('region', { name: '登録されている銘柄の一覧' })
    expect(within(brands).getByRole('link', { name: "ゴードン(Gordon's)" })).toHaveAttribute('href', '/admin/brands/9')
  })

  test('TC-308-16: 子のノード・直下の銘柄が無ければ、一覧に「ありません」と出す', async () => {
    await openEdit(OTHER_ROOT)

    expect(screen.getByRole('region', { name: '登録されている子のノードの一覧' })).toHaveTextContent('子のノードはありません')
    expect(screen.getByRole('region', { name: '登録されている銘柄の一覧' })).toHaveTextContent('銘柄はありません')
  })

  test('TC-308-17: 無い ID のノードを開くと 404 の画面を出す', async () => {
    loggedIn()
    mockApiHandler('get', '/admin/categories/{category}', () => jsonResponse({ message: 'Not Found' }, 404))
    renderAt('/admin/categories/999')

    expect(await screen.findByRole('heading', { level: 1, name: 'ページが見つかりません' })).toBeInTheDocument()
  })

  test('TC-308-18: 保存が 422 以外のエラーなら、エラーの番号を出して画面に残る', async () => {
    await openEdit()
    captureSave('put', () => jsonResponse({ message: 'Server Error' }, 500))

    submit()

    expect(await screen.findByRole('alert')).toHaveTextContent('保存できませんでした(エラー 500)')
    expect(currentUrl()).toBe('/admin/categories/2')
  })
})

describe('新規', () => {
  test('TC-308-19: 木から親を選ぶと選んだ親のパンくずが出て、送信で新規として parent_id を送る', async () => {
    await openNew()
    const sent = captureSave('post', () => jsonResponse(saved({ ...GIN, id: 10, name: 'レモン' }), 201))

    fireEvent.click(within(screen.getByRole('list', { name: '親' })).getByRole('radio', { name: 'ミント' }))
    expect(screen.getByText('選んでいる親: その他 / ミント')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('名前'), { target: { value: 'レモン' } })
    submit()

    expect(await screen.findByRole('status')).toHaveTextContent('「レモン」を保存しました')
    const entries = textEntries(sent()!)
    expect([entries._method, entries.parent_id, entries.name, entries.famous_brand_id]).toEqual([undefined, '6', 'レモン', undefined])
  })

  test('TC-308-20: 親を選ばずに送ると parent_id を送らず、422 のメッセージを親の欄に出す', async () => {
    await openNew()
    const sent = captureSave('post', () =>
      jsonResponse({ message: 'invalid', errors: { parent_id: ['The parent id field must be present.'] } }, 422),
    )

    submit()

    const parent = screen.getByRole('group', { name: '親' })
    expect(await within(parent).findByText('The parent id field must be present.')).toBeInTheDocument()
    expect(sent()!.has('parent_id')).toBe(false)
    expect(currentUrl()).toBe('/admin/categories/new')
  })
})

/** 編集で開くノード: 蒸留酒 > ジン > ドライジン(子も銘柄も無い葉) */
const DRY_GIN: CategoryResource = {
  ...GIN,
  id: 3,
  parent_id: 2,
  path: [
    { id: 1, name: '蒸留酒' },
    { id: 2, name: 'ジン' },
  ],
  name: 'ドライジン',
  name_eng: 'Dry Gin',
  description: '辛口のジン',
  famous_brand_id: null,
  famous_brand_candidates: [],
  children: [],
  brands: [],
  image: null,
}

/** 送ったリクエストのうち、メソッドとパスが合うもの */
const sentTo = (method: string, path: string) =>
  apiRequests().filter((request) => request.method === method && new URL(request.url).pathname === `/api${path}`)

const moveSection = () => screen.getByRole('region', { name: '親の付け替え' })
const deleteSection = () => screen.getByRole('region', { name: 'ノードの削除' })

/** 親の付け替えの欄で「移す先を選ぶ」を押し、移す先の木が出るまで待つ */
const openMoveTree = async () => {
  fireEvent.click(within(moveSection()).getByRole('button', { name: '移す先を選ぶ' }))
  return within(await within(moveSection()).findByRole('list', { name: '移す先' }))
}

const pickTarget = async (name: string) => {
  const tree = await openMoveTree()
  fireEvent.click(tree.getByRole('radio', { name }))
}

const moveButton = () => within(moveSection()).getByRole('button', { name: '移す' })
const deleteButton = () => within(deleteSection()).getByRole('button', { name: 'このノードを消す' })

describe('親の付け替え(#125)', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  test('TC-125-01: 移す先の木では、自分・自分の下のノード・今の親は選べず、選ぶまでは「移す」を押せない', async () => {
    await openEdit(GIN)

    expect(within(moveSection()).getByText('今の親: 蒸留酒')).toBeInTheDocument()
    const tree = await openMoveTree()

    expect(tree.getByRole('radio', { name: 'ジン' })).toBeDisabled()
    expect(tree.getByRole('radio', { name: '蒸留酒' })).toBeDisabled()
    expect(tree.getByRole('radio', { name: 'その他' })).toBeEnabled()
    // 自分の下のノードは、開いても選べない
    fireEvent.click(tree.getByRole('button', { name: 'ジンを開く' }))
    expect(tree.getByRole('radio', { name: 'ドライジン' })).toBeDisabled()
    expect(within(moveSection()).getByText('移す先: (未選択)')).toBeInTheDocument()
    expect(moveButton()).toBeDisabled()
  })

  test('TC-125-02: 同じ根の中で移すと、役割の注意を出さずに確かめ、開いたときの値に新しい親を入れて送り、カテゴリ木へ戻って移したことを出す', async () => {
    await openEdit(DRY_GIN)
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const sent = captureSave('put', () => jsonResponse(saved({ ...DRY_GIN, parent_id: 1 }), 200))

    await pickTarget('蒸留酒')
    expect(within(moveSection()).getByText('移す先: 蒸留酒')).toBeInTheDocument()
    expect(within(moveSection()).queryByText(/役割が/)).not.toBeInTheDocument()
    fireEvent.click(moveButton())

    expect(await screen.findByRole('status')).toHaveTextContent('「ドライジン」を「蒸留酒」の下へ移しました')
    expect(confirm).toHaveBeenCalledWith('「ドライジン」を「蒸留酒」の下へ移します。よろしいですか?')
    expect(currentUrl()).toBe('/admin/categories')
    expect(textEntries(sent()!)).toEqual({
      _method: 'PUT',
      parent_id: '1',
      name: 'ドライジン',
      name_eng: 'Dry Gin',
      description: '辛口のジン',
      youtube: 'abcdefghijk',
      youtube_start_hour: '1',
      youtube_start_minute: '2',
      youtube_start_second: '3',
      image_txt: '公式',
      image_url: 'https://example.com/',
      is_major: '1',
      is_like: '0',
      is_powder: '0',
    })
  })

  test('TC-125-03: 根をまたいで移すときは、選んだところと確かめに「役割が〈なし〉から〈その他〉に変わります」と出す', async () => {
    await openEdit(GIN)
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const sent = captureSave('put', () => jsonResponse(saved({ ...GIN, parent_id: 6, root_role: 'other' }), 200))

    await pickTarget('ミント')
    expect(within(moveSection()).getByText('移す先: その他 / ミント')).toBeInTheDocument()
    expect(within(moveSection()).getByText('役割が〈なし〉から〈その他〉に変わります')).toBeInTheDocument()
    fireEvent.click(moveButton())

    expect(await screen.findByRole('status')).toHaveTextContent('「ジン」を「その他 / ミント」の下へ移しました')
    expect(confirm).toHaveBeenCalledWith(
      '「ジン」を「その他 / ミント」の下へ移します。役割が〈なし〉から〈その他〉に変わります。よろしいですか?',
    )
    expect(textEntries(sent()!)).toMatchObject({ parent_id: '6', famous_brand_id: '8' })
  })

  test('TC-125-04: 確かめでやめると送らず、画面に残る', async () => {
    await openEdit(DRY_GIN)
    vi.spyOn(window, 'confirm').mockReturnValue(false)

    await pickTarget('蒸留酒')
    fireEvent.click(moveButton())

    expect(sentTo('POST', '/admin/categories/3')).toHaveLength(0)
    expect(currentUrl()).toBe('/admin/categories/3')
  })

  test('TC-125-05: 移せない(422)ときは、API が返す理由をそのまま出し、画面に残る', async () => {
    await openEdit(GIN)
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const reason = 'このノードの下の銘柄が「ゴードン」の有名な銘柄なので、そのノードの外へは移せません'
    captureSave('put', () => jsonResponse({ message: reason, errors: { parent_id: [reason] } }, 422))

    await pickTarget('その他')
    fireEvent.click(moveButton())

    const alert = await within(moveSection()).findByRole('alert')
    expect(alert).toHaveTextContent('移せませんでした。')
    expect(within(alert).getAllByRole('listitem').map((item) => item.textContent)).toEqual([reason])
    expect(currentUrl()).toBe('/admin/categories/2')
  })

  test('TC-125-06: 422 以外の失敗(500)は、エラーの番号を出して画面に残る', async () => {
    await openEdit(GIN)
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    captureSave('put', () => jsonResponse({ message: 'Server Error' }, 500))

    await pickTarget('その他')
    fireEvent.click(moveButton())

    expect(await within(moveSection()).findByRole('alert')).toHaveTextContent(
      '移せませんでした(エラー 500)。時間をおいて、もう一度お試しください。',
    )
    expect(currentUrl()).toBe('/admin/categories/2')
  })

  test('TC-125-07: 根のノードには親の付け替えの欄を出さない(削除の欄は出す)', async () => {
    await openEdit(OTHER_ROOT)

    expect(screen.queryByRole('region', { name: '親の付け替え' })).not.toBeInTheDocument()
    expect(deleteSection()).toBeInTheDocument()
  })
})

describe('削除(#125)', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  test('TC-125-08: 消せるノードは、確かめてから DELETE を送り、カテゴリ木へ戻って消したことを出す', async () => {
    await openEdit(DRY_GIN)
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    mockApiHandler('delete', '/admin/categories/{category}', () => new Response(null, { status: 204 }))

    expect(within(deleteSection()).getByText('消すと元に戻せません。')).toBeInTheDocument()
    fireEvent.click(deleteButton())

    expect(await screen.findByRole('status')).toHaveTextContent('「ドライジン」を消しました')
    expect(confirm).toHaveBeenCalledWith('「ドライジン」を消します。よろしいですか?')
    expect(currentUrl()).toBe('/admin/categories')
    expect(sentTo('DELETE', '/admin/categories/3')).toHaveLength(1)
  })

  test('TC-125-09: 消せないノードは、開いたときの理由をすべて出し、消すボタンを押せない', async () => {
    await openEdit({
      ...GIN,
      delete_blockers: [
        { code: 'children', message: '子のノードがあります' },
        { code: 'brands', message: 'このノードの銘柄があります' },
        { code: 'base_cocktails', message: 'カクテルのベースに使われています' },
      ],
    })

    expect(within(deleteSection()).getByText('このノードは消せません')).toBeInTheDocument()
    expect(within(deleteSection()).getAllByRole('listitem').map((item) => item.textContent)).toEqual([
      '子のノードがあります',
      'このノードの銘柄があります',
      'カクテルのベースに使われています',
    ])
    expect(deleteButton()).toBeDisabled()
  })

  test('TC-125-10: 開いた後に消せなくなっていた(409)ときは、API が返す理由をそのまま出し、画面に残る', async () => {
    await openEdit(DRY_GIN)
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    mockApi('delete', '/admin/categories/{category}', 409, {
      message: 'このノードは消せません',
      reasons: [{ code: 'ingredients', message: 'カクテルの材料に使われています' }],
    })

    fireEvent.click(deleteButton())

    const alert = await within(deleteSection()).findByRole('alert')
    expect(alert).toHaveTextContent('このノードは消せません')
    expect(within(alert).getAllByRole('listitem').map((item) => item.textContent)).toEqual(['カクテルの材料に使われています'])
    expect(deleteButton()).toBeDisabled()
    expect(currentUrl()).toBe('/admin/categories/3')
  })

  test('TC-125-11: 確かめでやめると DELETE を送らない', async () => {
    await openEdit(DRY_GIN)
    vi.spyOn(window, 'confirm').mockReturnValue(false)

    fireEvent.click(deleteButton())

    expect(sentTo('DELETE', '/admin/categories/3')).toHaveLength(0)
    expect(currentUrl()).toBe('/admin/categories/3')
  })

  test('TC-125-12: 409 以外の失敗(404)は、エラーの番号を出して画面に残る', async () => {
    await openEdit(DRY_GIN)
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    mockApiHandler('delete', '/admin/categories/{category}', () => jsonResponse({ message: 'Not Found' }, 404))

    fireEvent.click(deleteButton())

    expect(await within(deleteSection()).findByRole('alert')).toHaveTextContent(
      '消せませんでした(エラー 404)。時間をおいて、もう一度お試しください。',
    )
    expect(currentUrl()).toBe('/admin/categories/3')
  })
})
