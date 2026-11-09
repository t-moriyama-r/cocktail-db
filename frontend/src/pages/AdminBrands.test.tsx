import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router'
import { describe, expect, test } from 'vitest'
import App from '../App'
import type { components, paths } from '../api/schema'
import { apiRequests, jsonResponse, mockApi, mockApiHandler } from '../test/api'

type BrandIndex = paths['/admin/brands']['get']['responses'][200]['content']['application/json']
type BrandItem = BrandIndex['data'][number]
type BrandResource = components['schemas']['BrandResource']
type SearchForm = paths['/search-form']['get']['responses'][200]['content']['application/json']
type SearchFormNode = components['schemas']['SearchFormNodeResource']
type Saved = paths['/admin/brands']['post']['responses'][201]['content']['application/json']
type Duplicates = paths['/admin/brands/duplicates']['get']['responses'][200]['content']['application/json']

const loggedIn = () => mockApi('get', '/admin/me', 200, { id: 1, name: '管理者' })

const pathNode = (id: number, name: string, isFamousBrand = false) => ({ id, name, name_eng: name, is_famous_brand: isFamousBrand })

const brandItem = (id: number, name: string, nameEng: string, path: BrandItem['category_path']): BrandItem => ({
  id,
  name,
  name_eng: nameEng,
  category_path: path,
  is_famous_brand: path.some((node) => node.is_famous_brand),
})

const mockBrandIndex = (brands: BrandItem[]) =>
  mockApi('get', '/admin/brands', 200, {
    data: brands,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: 1,
      from: brands.length === 0 ? null : 1,
      last_page: 1,
      links: [],
      path: '/api/admin/brands',
      per_page: 50,
      to: brands.length === 0 ? null : brands.length,
      total: brands.length,
    },
  })

const treeNode = (id: number, name: string, children: SearchFormNode[] = []): SearchFormNode => ({
  id,
  name,
  is_major: true,
  root_role: null,
  children,
})

/** 所属を選ぶ木: 蒸留酒(根) > ジン > ドライジン、蒸留酒 > ウォッカ、その他(根) > ミント */
const TREE: SearchFormNode[] = [
  treeNode(1, '蒸留酒', [treeNode(2, 'ジン', [treeNode(3, 'ドライジン')]), treeNode(4, 'ウォッカ')]),
  { ...treeNode(5, 'その他', [treeNode(6, 'ミント')]), root_role: 'other' },
]

const mockTree = () => mockApi('get', '/search-form', 200, { categories: TREE, methods: [] } satisfies SearchForm)

const BRAND: BrandResource = {
  id: 7,
  category_id: 3,
  category_path: [pathNode(2, 'ジン'), pathNode(3, 'ドライジン')],
  is_famous_brand: false,
  name: 'ビーフィーター',
  name_eng: 'Beefeater',
  strength: 47,
  description: 'ロンドンの@n2@',
  youtube: 'abcdefghijk',
  youtube_start_hour: null,
  youtube_start_minute: 1,
  youtube_start_second: 30,
  youtube_created_at: null,
  is_major: true,
  is_like: false,
  image: 'beefeater.png',
  image_txt: '公式',
  image_url: 'https://example.com/',
  updated_at: null,
}

const savedBody = (brand: BrandResource): Saved => ({ data: brand, warnings: [] })

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
    mockApiHandler('post', '/admin/brands', handler)
  } else {
    mockApiHandler('put', '/admin/brands/{brand}', handler)
  }
  return () => sent
}

/** 送られた multipart の項目と値 */
const formEntries = (body: FormData) => {
  const entries: Record<string, FormDataEntryValue> = {}
  body.forEach((value, key) => {
    entries[key] = value
  })
  return entries
}

const form = () => screen.getByRole('button', { name: '送信' }).closest('form')!

describe('一覧', () => {
  test('TC-64-07: メニューの「銘柄」から一覧を開ける', async () => {
    loggedIn()
    mockBrandIndex([])
    renderAt('/admin')

    const menu = await screen.findByRole('navigation', { name: '管理画面のメニュー' })
    fireEvent.click(within(menu).getByRole('link', { name: '銘柄' }))

    expect(await screen.findByRole('heading', { level: 1, name: '銘柄' })).toBeInTheDocument()
    expect(currentUrl()).toBe('/admin/brands')
  })

  test('TC-64-08: 1 行は「名前(英名)」の編集画面へのリンクと、括弧で所属(★ 付き)を出す', async () => {
    loggedIn()
    mockBrandIndex([
      brandItem(7, 'ビーフィーター', 'Beefeater', [pathNode(2, 'ジン', true), pathNode(3, 'ドライジン'), pathNode(8, 'ロンドンドライジン')]),
      brandItem(9, 'スミノフ', 'Smirnoff', [pathNode(4, 'ウォッカ')]),
    ])
    renderAt('/admin/brands')

    const link = await screen.findByRole('link', { name: 'ビーフィーター(Beefeater)' })
    expect(link).toHaveAttribute('href', '/admin/brands/7')
    expect(screen.getAllByRole('listitem').filter((li) => li.closest('nav') === null).map((li) => li.textContent)).toEqual([
      'ビーフィーター(Beefeater)(★ジン/ロンドンドライジン)',
      'スミノフ(Smirnoff)(ウォッカ)',
    ])
  })

  test('TC-64-09: 一覧の上の「新規」から新規の画面を開ける', async () => {
    loggedIn()
    mockBrandIndex([])
    mockTree()
    renderAt('/admin/brands')

    fireEvent.click(await screen.findByRole('link', { name: '新規' }))

    expect(await screen.findByRole('heading', { level: 1, name: '銘柄の新規' })).toBeInTheDocument()
    expect(currentUrl()).toBe('/admin/brands/new')
  })
})

describe('新規', () => {
  test('TC-64-10: 欄は 所属 / 名前・英名・度数 / メジャー・お気に入り / 説明 / YouTube と開始位置 / 画像 / リンクテキスト・リンクURL / 送信 の順', async () => {
    loggedIn()
    mockTree()
    renderAt('/admin/brands/new')

    await screen.findByRole('button', { name: '送信' })
    const labels = Array.from(form().querySelectorAll('legend, label')).map((element) => element.textContent)
    expect(labels).toEqual([
      '所属',
      '名前',
      '英名',
      '度数(%)',
      'メジャー',
      'お気に入り',
      '説明',
      'YouTube',
      '時',
      '分',
      '秒',
      '画像',
      'リンクテキスト',
      'リンクURL',
    ])
  })

  test('TC-64-11: 所属は根の下のどの深さでも選べ、根は選べない。選んだ所属を根を除いたパンくずで出す', async () => {
    loggedIn()
    mockTree()
    renderAt('/admin/brands/new')

    const tree = await screen.findByRole('list', { name: '所属' })
    expect(screen.getByText('選んでいる所属: (未選択)')).toBeInTheDocument()
    expect(within(tree).queryByRole('radio', { name: '蒸留酒' })).not.toBeInTheDocument()
    expect(within(tree).queryByRole('radio', { name: 'その他' })).not.toBeInTheDocument()

    fireEvent.click(within(tree).getByRole('button', { name: 'ジンを開く' }))
    fireEvent.click(within(tree).getByRole('radio', { name: 'ドライジン' }))
    expect(screen.getByText('選んでいる所属: ジン / ドライジン')).toBeInTheDocument()

    fireEvent.click(within(tree).getByRole('radio', { name: 'ジン' }))
    expect(screen.getByText('選んでいる所属: ジン')).toBeInTheDocument()
    expect(within(tree).getByRole('radio', { name: 'ドライジン' })).not.toBeChecked()
  })

  test('TC-654-02: 新規の画面の木は、根だけを開き、根の子の枝は畳んだまま', async () => {
    loggedIn()
    mockTree()
    renderAt('/admin/brands/new')

    const tree = await screen.findByRole('list', { name: '所属' })
    expect(within(tree).getByRole('button', { name: '蒸留酒を閉じる' })).toHaveAttribute('aria-expanded', 'true')
    expect(within(tree).getByRole('button', { name: 'その他を閉じる' })).toHaveAttribute('aria-expanded', 'true')
    expect(within(tree).getByRole('button', { name: 'ジンを開く' })).toHaveAttribute('aria-expanded', 'false')
    expect(within(tree).queryByRole('radio', { name: 'ドライジン' })).not.toBeInTheDocument()
  })

  test('TC-64-12: 度数の欄は数字と「.」だけを 4 文字まで、開始位置は数字だけを時 1 桁・分秒 2 桁まで受け付ける', async () => {
    loggedIn()
    mockTree()
    renderAt('/admin/brands/new')

    const strength = await screen.findByLabelText('度数(%)')
    fireEvent.change(strength, { target: { value: '4a0.５%' } })
    expect(strength).toHaveValue('40.5')

    fireEvent.change(screen.getByLabelText('時'), { target: { value: '１２' } })
    fireEvent.change(screen.getByLabelText('分'), { target: { value: '6a7' } })
    fireEvent.change(screen.getByLabelText('秒'), { target: { value: '5.9' } })
    expect(screen.getByLabelText('時')).toHaveValue('1')
    expect(screen.getByLabelText('分')).toHaveValue('67')
    expect(screen.getByLabelText('秒')).toHaveValue('59')
  })

  test('TC-64-25: メジャー・お気に入りは、押すたびに入れ・外しができる', async () => {
    loggedIn()
    mockTree()
    renderAt('/admin/brands/new')

    const major = await screen.findByLabelText('メジャー')
    fireEvent.click(major)
    expect(major).toBeChecked()
    fireEvent.click(major)
    expect(major).not.toBeChecked()
    const like = screen.getByLabelText('お気に入り')
    fireEvent.click(like)
    fireEvent.click(like)
    expect(like).not.toBeChecked()
  })

  test('TC-64-13: 英名は欄を離れると、全角の英数字を半角にする', async () => {
    loggedIn()
    mockTree()
    renderAt('/admin/brands/new')

    const nameEng = await screen.findByLabelText('英名')
    fireEvent.change(nameEng, { target: { value: 'ＧＩＮ４０' } })
    expect(nameEng).toHaveValue('ＧＩＮ４０')
    fireEvent.blur(nameEng)
    expect(nameEng).toHaveValue('GIN40')
  })

  test('TC-64-14: 送信すると入力を multipart で POST /api/admin/brands に送り、保存できたら一覧へ戻る', async () => {
    loggedIn()
    mockTree()
    mockBrandIndex([])
    const sent = captureSave('post', () => jsonResponse(savedBody(BRAND), 201))
    renderAt('/admin/brands/new')

    const tree = await screen.findByRole('list', { name: '所属' })
    // 根は最初から開いている
    fireEvent.click(within(tree).getByRole('radio', { name: 'ミント' }))
    fireEvent.change(screen.getByLabelText('名前'), { target: { value: 'ミントの銘柄' } })
    fireEvent.change(screen.getByLabelText('英名'), { target: { value: 'Mint Brand' } })
    fireEvent.change(screen.getByLabelText('度数(%)'), { target: { value: '0' } })
    fireEvent.change(screen.getByLabelText('説明'), { target: { value: '説明の文' } })
    fireEvent.click(screen.getByLabelText('メジャー'))
    fireEvent.click(screen.getByRole('button', { name: '送信' }))

    expect(await screen.findByRole('heading', { level: 1, name: '銘柄' })).toBeInTheDocument()
    expect(currentUrl()).toBe('/admin/brands')
    const body = sent()!
    expect(body.get('_method')).toBeNull()
    expect(formEntries(body)).toEqual({
      category_id: '6',
      name: 'ミントの銘柄',
      name_eng: 'Mint Brand',
      strength: '0',
      description: '説明の文',
      youtube: '',
      youtube_start_hour: '',
      youtube_start_minute: '',
      youtube_start_second: '',
      image_txt: '',
      image_url: '',
      is_major: '1',
      is_like: '0',
    })
  })

  test('TC-64-15: 422 なら項目ごとのメッセージを欄の下に出し、画面に残って入力も残る', async () => {
    loggedIn()
    mockTree()
    captureSave('post', () =>
      jsonResponse(
        {
          message: '入力に誤りがあります。',
          errors: { category_id: ['所属は必須です。'], name: ['名前は必須です。'], strength: ['度数は数字で入れてください。'] },
        },
        422,
      ),
    )
    renderAt('/admin/brands/new')

    fireEvent.change(await screen.findByLabelText('英名'), { target: { value: 'No Name' } })
    fireEvent.click(screen.getByRole('button', { name: '送信' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('保存できませんでした。入力を確かめてください。')
    expect(screen.getByLabelText('名前')).toHaveAccessibleDescription('名前は必須です。')
    expect(screen.getByLabelText('名前')).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByLabelText('度数(%)')).toHaveAccessibleDescription('度数は数字で入れてください。')
    expect(screen.getByLabelText('英名')).toHaveAttribute('aria-invalid', 'false')
    expect(within(screen.getByRole('group', { name: '所属' })).getByText('所属は必須です。')).toBeInTheDocument()
    expect(screen.getByLabelText('英名')).toHaveValue('No Name')
    expect(currentUrl()).toBe('/admin/brands/new')
  })

  test('TC-64-16: 422 以外のエラーや通信の失敗なら、そのことを出して画面に残る', async () => {
    loggedIn()
    mockTree()
    captureSave('post', () => jsonResponse({ message: 'Server Error' }, 500))
    renderAt('/admin/brands/new')

    fireEvent.click(await screen.findByRole('button', { name: '送信' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('保存できませんでした(エラー 500)。時間をおいて、もう一度お試しください。')
    expect(currentUrl()).toBe('/admin/brands/new')

    mockApiHandler('post', '/admin/brands', () => Promise.reject(new TypeError('Failed to fetch')))
    fireEvent.click(screen.getByRole('button', { name: '送信' }))
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('通信に失敗しました'))
    expect(currentUrl()).toBe('/admin/brands/new')
  })

  test('TC-715-02: 保存が 401 なら、ログインの期限切れとログインし直す手順を出し、入力を残して画面に残る', async () => {
    loggedIn()
    mockTree()
    captureSave('post', () => jsonResponse({ message: 'Unauthenticated.' }, 401))
    renderAt('/admin/brands/new')

    fireEvent.change(await screen.findByLabelText('名前'), { target: { value: 'ビーフィーター' } })
    fireEvent.click(screen.getByRole('button', { name: '送信' }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('ログインの期限が切れていて保存できませんでした。入力を控えてから、ページを開き直してログインし直してください。')
    expect(alert).not.toHaveTextContent('時間をおいて')
    expect(screen.getByLabelText('名前')).toHaveValue('ビーフィーター')
    expect(currentUrl()).toBe('/admin/brands/new')
  })
})

describe('編集', () => {
  test('TC-64-17: 開いた銘柄の値を欄に出し、今の所属と画像を出す', async () => {
    loggedIn()
    mockTree()
    mockApi('get', '/admin/brands/{brand}', 200, { data: BRAND })
    renderAt('/admin/brands/7')

    expect(await screen.findByRole('heading', { level: 1, name: '銘柄の編集' })).toBeInTheDocument()
    expect(await screen.findByLabelText('名前')).toHaveValue('ビーフィーター')
    expect(screen.getByLabelText('英名')).toHaveValue('Beefeater')
    expect(screen.getByLabelText('度数(%)')).toHaveValue('47')
    expect(screen.getByLabelText('説明')).toHaveValue('ロンドンの@n2@')
    expect(screen.getByLabelText('YouTube')).toHaveValue('abcdefghijk')
    expect(screen.getByLabelText('時')).toHaveValue('')
    expect(screen.getByLabelText('分')).toHaveValue('1')
    expect(screen.getByLabelText('秒')).toHaveValue('30')
    expect(screen.getByLabelText('リンクテキスト')).toHaveValue('公式')
    expect(screen.getByLabelText('リンクURL')).toHaveValue('https://example.com/')
    expect(screen.getByLabelText('メジャー')).toBeChecked()
    expect(screen.getByLabelText('お気に入り')).not.toBeChecked()
    expect(screen.getByText('選んでいる所属: ジン / ドライジン')).toBeInTheDocument()
    expect(screen.getByRole('img', { name: '銘柄の画像' })).toHaveAttribute('src', '/storage/beefeater.png')
  })

  test('TC-654-01: 深いノードを所属に持つ銘柄を開くと、そこまでの枝が開いていて、そのラジオがチェックされた状態で見えている', async () => {
    loggedIn()
    mockTree()
    mockApi('get', '/admin/brands/{brand}', 200, { data: BRAND })
    renderAt('/admin/brands/7')

    const tree = await screen.findByRole('list', { name: '所属' })
    expect(within(tree).getByRole('radio', { name: 'ドライジン' })).toBeChecked()
    expect(within(tree).getByRole('button', { name: 'ジンを閉じる' })).toHaveAttribute('aria-expanded', 'true')
    expect(within(tree).getByRole('radio', { name: 'ジン' })).not.toBeChecked()
  })

  test('TC-64-18: 所属を変えて送信すると、POST に _method=PUT を付けて /api/admin/brands/{id} に送り、保存できたら一覧へ戻る', async () => {
    loggedIn()
    mockTree()
    mockBrandIndex([])
    mockApi('get', '/admin/brands/{brand}', 200, { data: BRAND })
    const sent = captureSave('put', () => jsonResponse(savedBody({ ...BRAND, category_id: 4 }), 200))
    renderAt('/admin/brands/7')

    const tree = await screen.findByRole('list', { name: '所属' })
    fireEvent.click(within(tree).getByRole('radio', { name: 'ウォッカ' }))
    fireEvent.click(screen.getByRole('button', { name: '送信' }))

    expect(await screen.findByRole('heading', { level: 1, name: '銘柄' })).toBeInTheDocument()
    expect(currentUrl()).toBe('/admin/brands')
    const body = sent()!
    expect(body.get('_method')).toBe('PUT')
    expect(body.get('category_id')).toBe('4')
    expect(body.get('name')).toBe('ビーフィーター')
    expect(body.get('strength')).toBe('47')
    expect(body.get('youtube_start_hour')).toBe('')
    expect(body.get('youtube_start_minute')).toBe('1')
    expect(body.get('is_major')).toBe('1')
    expect(body.get('is_like')).toBe('0')
    // 画像を選んでいなければ送らない(今の画像のまま)
    expect(body.has('image')).toBe(false)
  })

  test('TC-64-19: 有名な銘柄を部分木の外へ移そうとして 422 になったら、理由を所属の欄に出して画面に残る', async () => {
    loggedIn()
    mockTree()
    mockApi('get', '/admin/brands/{brand}', 200, { data: { ...BRAND, is_famous_brand: true } })
    const reason = 'この銘柄は「ジン」の有名な銘柄なので、その配下の外へは移せません。'
    captureSave('put', () => jsonResponse({ message: reason, errors: { category_id: [reason] } }, 422))
    renderAt('/admin/brands/7')

    const tree = await screen.findByRole('list', { name: '所属' })
    fireEvent.click(within(tree).getByRole('radio', { name: 'ウォッカ' }))
    fireEvent.click(screen.getByRole('button', { name: '送信' }))

    expect(await within(screen.getByRole('group', { name: '所属' })).findByText(reason)).toBeInTheDocument()
    expect(screen.getByText('選んでいる所属: ウォッカ')).toBeInTheDocument()
    expect(currentUrl()).toBe('/admin/brands/7')
  })

  test('TC-64-20: 画像を選ぶと、その画像をその場で出し、送信で image として送る', async () => {
    loggedIn()
    mockTree()
    mockBrandIndex([])
    mockApi('get', '/admin/brands/{brand}', 200, { data: BRAND })
    const sent = captureSave('put', () => jsonResponse(savedBody(BRAND), 200))
    renderAt('/admin/brands/7')

    const file = new File([new Uint8Array([0x89, 0x50, 0x4e, 0x47])], 'new.png', { type: 'image/png' })
    fireEvent.change(await screen.findByLabelText('画像'), { target: { files: [file] } })
    await waitFor(() => expect(screen.getByRole('img', { name: '銘柄の画像' }).getAttribute('src')).toMatch(/^data:image\/png;base64,/))

    fireEvent.click(screen.getByRole('button', { name: '送信' }))
    await screen.findByRole('heading', { level: 1, name: '銘柄' })
    // テストの環境(jsdom の FormData を Node の Request で送る)では、読み直すとファイル名が落ちるので、種類と大きさで確かめる
    // (ファイル名はサーバーが付け直すので、API の結果には効かない。AdminImageStore)
    const image = sent()!.get('image') as File
    expect([image.type, image.size]).toEqual(['image/png', 4])
  })

  test('TC-64-21: 無い ID の銘柄を開くと 404 の画面を出す', async () => {
    loggedIn()
    mockTree()
    mockApiHandler('get', '/admin/brands/{brand}', () => jsonResponse({ message: 'Not Found' }, 404))
    renderAt('/admin/brands/999')

    expect(await screen.findByRole('heading', { level: 1, name: 'ページが見つかりません' })).toBeInTheDocument()
  })
})

describe('名前・英名の重なり(#633)', () => {
  const DUPLICATE = '同じ名前が存在しています'

  /**
   * 重なりの問い合わせに、既にある名前・英名と同じ値なら重なりありを返す(比べ方の細かいところは BE の #632 のテスト)。
   * respond を渡すと、その応答を返す(エラー・通信の失敗・遅れて返る応答を作るため)
   */
  const mockDuplicates = (existing: { name?: string[]; name_eng?: string[] }) =>
    mockApiHandler('get', '/admin/brands/duplicates', (request) => {
      const query = new URL(request.url).searchParams
      const warnings: Duplicates['warnings'] = []
      const name = query.get('name')
      const nameEng = query.get('name_eng')
      if (name !== null && existing.name?.includes(name)) warnings.push({ field: 'name', message: DUPLICATE })
      if (nameEng !== null && existing.name_eng?.includes(nameEng)) warnings.push({ field: 'name_eng', message: DUPLICATE })
      return jsonResponse({ warnings } satisfies Duplicates, 200)
    })

  /** 送った重なりの問い合わせのクエリ(送った順) */
  const duplicateQueries = () =>
    apiRequests()
      .map((request) => new URL(request.url))
      .filter((url) => url.pathname === '/api/admin/brands/duplicates')
      .map((url) => Object.fromEntries(url.searchParams))

  /** 欄に値を入れて離れる(旧の @change と同じく、値を変えて離れたときに問い合わせる) */
  const enter = (label: string, value: string) => {
    const field = screen.getByLabelText(label)
    fireEvent.focus(field)
    fireEvent.change(field, { target: { value } })
    fireEvent.blur(field)
  }

  test('TC-633-01: 重なる名前を入れて欄を離れると、名前の欄の下に「同じ名前が存在しています」が出て、欄の説明にもなる', async () => {
    loggedIn()
    mockTree()
    mockDuplicates({ name: ['ビーフィーター'] })
    renderAt('/admin/brands/new')

    await screen.findByLabelText('名前')
    enter('名前', 'ビーフィーター')

    expect(await screen.findByText(DUPLICATE)).toBeInTheDocument()
    expect(screen.getByLabelText('名前')).toHaveAccessibleDescription(DUPLICATE)
    // 保存は止めないので、検証の誤りの印は付けない
    expect(screen.getByLabelText('名前')).toHaveAttribute('aria-invalid', 'false')
    expect(screen.getByLabelText('英名')).not.toHaveAccessibleDescription(DUPLICATE)
    expect(duplicateQueries()).toEqual([{ name: 'ビーフィーター' }])
  })

  test('TC-633-02: 重ならない値に直して欄を離れると、出ていた知らせが消える', async () => {
    loggedIn()
    mockTree()
    mockDuplicates({ name: ['ビーフィーター'] })
    renderAt('/admin/brands/new')

    await screen.findByLabelText('名前')
    enter('名前', 'ビーフィーター')
    expect(await screen.findByText(DUPLICATE)).toBeInTheDocument()

    enter('名前', 'タンカレー')
    await waitFor(() => expect(screen.queryByText(DUPLICATE)).not.toBeInTheDocument())
    expect(screen.getByLabelText('名前')).not.toHaveAccessibleDescription(DUPLICATE)
    expect(duplicateQueries()).toEqual([{ name: 'ビーフィーター' }, { name: 'タンカレー' }])
  })

  test('TC-633-03: 英名は、全角の英数字を半角にした値で問い合わせ、重なれば英名の欄の下に出る。直すと消える', async () => {
    loggedIn()
    mockTree()
    mockDuplicates({ name_eng: ['Beefeater'] })
    renderAt('/admin/brands/new')

    await screen.findByLabelText('英名')
    enter('英名', 'Ｂｅｅｆｅａｔｅｒ')

    await waitFor(() => expect(screen.getByLabelText('英名')).toHaveAccessibleDescription(DUPLICATE))
    expect(screen.getByLabelText('英名')).toHaveValue('Beefeater')
    expect(screen.getByLabelText('名前')).not.toHaveAccessibleDescription(DUPLICATE)
    expect(duplicateQueries()).toEqual([{ name_eng: 'Beefeater' }])

    enter('英名', 'Tanqueray')
    await waitFor(() => expect(screen.queryByText(DUPLICATE)).not.toBeInTheDocument())
  })

  test('TC-633-04: 編集では、編集している銘柄の ID を exclude_id で送る(自身とは比べない)', async () => {
    loggedIn()
    mockTree()
    mockApi('get', '/admin/brands/{brand}', 200, { data: BRAND })
    mockDuplicates({})
    renderAt('/admin/brands/7')

    await screen.findByRole('list', { name: '所属' })
    enter('名前', 'ビーフィーター ロンドン')
    enter('英名', 'Beefeater London')

    await waitFor(() => expect(duplicateQueries()).toHaveLength(2))
    expect(duplicateQueries()).toEqual([
      { name: 'ビーフィーター ロンドン', exclude_id: '7' },
      { name_eng: 'Beefeater London', exclude_id: '7' },
    ])
    expect(screen.queryByText(DUPLICATE)).not.toBeInTheDocument()
  })

  test('TC-633-05: 値を変えずに欄を離れたときは、問い合わせない', async () => {
    loggedIn()
    mockTree()
    mockApi('get', '/admin/brands/{brand}', 200, { data: BRAND })
    mockDuplicates({ name: ['ビーフィーター'] })
    renderAt('/admin/brands/7')

    await screen.findByRole('list', { name: '所属' })
    // 開いたときの値のまま
    fireEvent.focus(screen.getByLabelText('名前'))
    fireEvent.blur(screen.getByLabelText('名前'))
    fireEvent.focus(screen.getByLabelText('英名'))
    fireEvent.blur(screen.getByLabelText('英名'))
    // 入れている途中で一度変えても、元の値に戻してから離れる
    const name = screen.getByLabelText('名前')
    fireEvent.focus(name)
    fireEvent.change(name, { target: { value: 'ビーフィーター ロンドン' } })
    fireEvent.change(name, { target: { value: 'ビーフィーター' } })
    fireEvent.blur(name)

    expect(duplicateQueries()).toEqual([])
  })

  test('TC-633-06: 空にして欄を離れると、問い合わせずに、出ていた知らせを消す', async () => {
    loggedIn()
    mockTree()
    mockDuplicates({ name: ['ビーフィーター'] })
    renderAt('/admin/brands/new')

    await screen.findByLabelText('名前')
    enter('名前', 'ビーフィーター')
    expect(await screen.findByText(DUPLICATE)).toBeInTheDocument()

    enter('名前', '')
    expect(screen.queryByText(DUPLICATE)).not.toBeInTheDocument()
    expect(duplicateQueries()).toEqual([{ name: 'ビーフィーター' }])
  })

  test('TC-633-07: 重なりが出ていても送信でき、保存できたら一覧へ戻る', async () => {
    loggedIn()
    mockTree()
    mockBrandIndex([])
    mockDuplicates({ name: ['ビーフィーター'], name_eng: ['Beefeater'] })
    const sent = captureSave('post', () => jsonResponse(savedBody(BRAND), 201))
    renderAt('/admin/brands/new')

    const tree = await screen.findByRole('list', { name: '所属' })
    fireEvent.click(within(tree).getByRole('radio', { name: 'ミント' }))
    enter('名前', 'ビーフィーター')
    enter('英名', 'Beefeater')
    await waitFor(() => expect(screen.getAllByText(DUPLICATE)).toHaveLength(2))

    fireEvent.click(screen.getByRole('button', { name: '送信' }))

    expect(await screen.findByRole('heading', { level: 1, name: '銘柄' })).toBeInTheDocument()
    expect(currentUrl()).toBe('/admin/brands')
    expect(sent()!.get('name')).toBe('ビーフィーター')
    expect(sent()!.get('name_eng')).toBe('Beefeater')
  })

  test('TC-633-08: 問い合わせがエラーや通信の失敗になったら、知らせを出さず、送信も止めない', async () => {
    loggedIn()
    mockTree()
    mockBrandIndex([])
    mockApiHandler('get', '/admin/brands/duplicates', () => jsonResponse({ message: 'Server Error' }, 500))
    captureSave('post', () => jsonResponse(savedBody(BRAND), 201))
    renderAt('/admin/brands/new')

    await screen.findByLabelText('名前')
    enter('名前', 'ビーフィーター')
    await waitFor(() => expect(duplicateQueries()).toHaveLength(1))
    mockApiHandler('get', '/admin/brands/duplicates', () => Promise.reject(new TypeError('Failed to fetch')))
    enter('英名', 'Beefeater')
    await waitFor(() => expect(duplicateQueries()).toHaveLength(2))

    expect(screen.queryByText(DUPLICATE)).not.toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: '送信' }))
    expect(await screen.findByRole('heading', { level: 1, name: '銘柄' })).toBeInTheDocument()
  })

  test('TC-633-09: 続けて問い合わせ、先の応答が後から返っても、最後に入れた値の結果を出す', async () => {
    loggedIn()
    mockTree()
    // 1 つめ(重なる値)の応答は、2 つめ(重ならない値)の応答より後に返す
    let releaseFirst: () => void = () => {}
    const firstReleased = new Promise<void>((resolve) => {
      releaseFirst = resolve
    })
    mockApiHandler('get', '/admin/brands/duplicates', async (request) => {
      const name = new URL(request.url).searchParams.get('name')
      if (name === 'ビーフィーター') {
        await firstReleased
        return jsonResponse({ warnings: [{ field: 'name', message: DUPLICATE }] } satisfies Duplicates, 200)
      }
      return jsonResponse({ warnings: [] } satisfies Duplicates, 200)
    })
    renderAt('/admin/brands/new')

    await screen.findByLabelText('名前')
    enter('名前', 'ビーフィーター')
    enter('名前', 'タンカレー')
    await waitFor(() => expect(duplicateQueries()).toHaveLength(2))
    releaseFirst()
    await firstReleased
    // 遅れて返った 1 つめの応答の結果を出さない
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(screen.queryByText(DUPLICATE)).not.toBeInTheDocument()
    expect(screen.getByLabelText('名前')).toHaveValue('タンカレー')
  })
})
