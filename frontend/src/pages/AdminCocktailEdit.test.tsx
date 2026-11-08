import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router'
import { afterEach, describe, expect, test, vi } from 'vitest'
import App from '../App'
import type { components, paths } from '../api/schema'
import { apiRequests, jsonResponse, mockApi, mockApiHandler } from '../test/api'

type CocktailResource = components['schemas']['CocktailResource']
type Duplicate = components['schemas']['CocktailDuplicateResource']
type SearchFormNode = components['schemas']['SearchFormNodeResource']
type SearchForm = paths['/search-form']['get']['responses'][200]['content']['application/json']
type FormOptions = paths['/admin/cocktail-form-options']['get']['responses'][200]['content']['application/json']
type CocktailIndex = paths['/admin/cocktails']['get']['responses'][200]['content']['application/json']
type Saved = paths['/admin/cocktails']['post']['responses'][201]['content']['application/json']
type Conflict = paths['/admin/cocktails/{cocktail}']['delete']['responses'][409]['content']['application/json']
type CategoryDetail = paths['/categories/{category}']['get']['responses'][200]['content']['application/json']
type Brand = components['schemas']['BrandSummaryResource']

const loggedIn = () => mockApi('get', '/admin/me', 200, { id: 1, name: '管理者' })

const treeNode = (id: number, name: string, children: SearchFormNode[] = []): SearchFormNode => ({
  id,
  name,
  is_major: true,
  root_role: null,
  children,
})

/** ベースを選ぶ木: 蒸留酒(根) > ジン > ドライジン、蒸留酒 > ウォッカ、その他(根) > ミント */
const TREE: SearchFormNode[] = [
  treeNode(1, '蒸留酒', [treeNode(2, 'ジン', [treeNode(3, 'ドライジン')]), treeNode(4, 'ウォッカ')]),
  { ...treeNode(5, 'その他', [treeNode(6, 'ミント')]), root_role: 'other' },
]

const mockTree = () => mockApi('get', '/search-form', 200, { categories: TREE, methods: [] } satisfies SearchForm)

/** 銘柄。所属はどちらもドライジン(パンくずは根から所属ノードまで) */
const brand = (id: number, name: string, nameEng: string): Brand => ({
  id,
  name,
  name_eng: nameEng,
  strength: 40,
  youtube: null,
  path: [
    { id: 1, name: '蒸留酒', name_eng: 'Liquor' },
    { id: 2, name: 'ジン', name_eng: 'Gin' },
    { id: 3, name: 'ドライジン', name_eng: 'Dry Gin' },
  ],
})
const BEEFEATER = brand(8, 'ビーフィーター', 'Beefeater')
const TANQUERAY = brand(9, 'タンカレー', 'Tanqueray')

/** ノードの詳細(GET /api/categories/{id})が返す、そのノードと配下の銘柄。ジン・ドライジンの配下にだけ銘柄がある */
const BRANDS_BY_NODE: Readonly<Record<number, Brand[]>> = { 2: [BEEFEATER, TANQUERAY], 3: [BEEFEATER, TANQUERAY] }

const categoryDetail = (id: number, brands: Brand[]): CategoryDetail => ({
  data: {
    id,
    name: '',
    name_eng: '',
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
    path: [],
    children: [],
    famous_brand: null,
    brands,
  },
})

/** 材料の行の銘柄の選択肢に使う、ノードの詳細の API。ノードの ID ごとに BRANDS_BY_NODE の銘柄を返す */
const mockCategories = () =>
  mockApiHandler('get', '/categories/{category}', (request) => {
    const id = Number(new URL(request.url).pathname.split('/').at(-1))
    return jsonResponse(categoryDetail(id, BRANDS_BY_NODE[id] ?? []), 200)
  })

/** 選択肢。製法 2 つ・グラス 1 つ・氷 1 つ(型は API の定義に合わせる) */
const OPTIONS: FormOptions = {
  methods: [
    { id: 1, name: 'ビルド' },
    { id: 2, name: 'シェイク' },
  ],
  glasses: [{ id: 10, name: 'タンブラー' }],
  ices: [{ id: 20, name: 'キューブアイス' }],
}

const mockOptions = () => mockApi('get', '/admin/cocktail-form-options', 200, OPTIONS)

/** 重複チェックの API。応答を登録し、送られた問い合わせ(クエリ)を返す関数を返す */
const mockDuplicates = (sameName: Duplicate[] = [], sameRecipe: Duplicate[] = []) => {
  const queries: URLSearchParams[] = []
  mockApiHandler('get', '/admin/cocktails/duplicates', (request) => {
    queries.push(new URL(request.url).searchParams)
    return jsonResponse({ same_name: sameName, same_recipe: sameRecipe }, 200)
  })
  return () => queries
}

const COCKTAIL: CocktailResource = {
  id: 7,
  base_category_id: 2,
  name: 'ジントニック',
  name_eng: 'Gin and Tonic',
  method_id: 1,
  glass_dictionary_id: 10,
  ice_id: 20,
  strength: 12.5,
  rate: 3,
  sweet_degree: 0,
  is_major: true,
  is_draft: false,
  description: '定番の@n2@のカクテル',
  note: 'ライムを添える。',
  youtube: 'abcdefghijk',
  youtube_start_hour: null,
  youtube_start_minute: 1,
  youtube_start_second: 30,
  youtube_created_at: null,
  image: 'gin.png',
  image_txt: '公式',
  image_url: 'https://example.com/',
  ingredients: [
    { category_id: 3, brand_id: 8, amount: '45ml', is_optional: false },
    { category_id: 6, brand_id: null, amount: '適量', is_optional: true },
  ],
  aliases: [],
  updated_at: null,
}

const duplicate = (id: number, name: string, overrides: Partial<Duplicate> = {}): Duplicate => ({
  id,
  name,
  name_eng: 'Gin and Tonic',
  method_id: 2,
  is_draft: false,
  match: 'same_amount',
  description: null,
  note: null,
  ingredients: [],
  ...overrides,
})

const emptyIndex = (): CocktailIndex => ({
  data: [],
  links: { first: null, last: null, prev: null, next: null },
  meta: { current_page: 1, from: null, last_page: 1, links: [], path: '/api/admin/cocktails', per_page: 50, to: null, total: 0 },
})

/** いまの URL(パスとクエリ)を画面に出す(保存の後に移った先を確かめるため) */
function CurrentUrl() {
  const { pathname, search } = useLocation()
  return <div data-testid="current-url">{`${pathname}${search}`}</div>
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
    mockApiHandler('post', '/admin/cocktails', handler)
  } else {
    mockApiHandler('put', '/admin/cocktails/{cocktail}', handler)
  }
  return () => sent
}

const savedBody = (cocktail: CocktailResource): Saved => ({ data: cocktail, warnings: [] })

const form = () => screen.getByRole('button', { name: '公開する' }).closest('form')!

/** 新規の画面を開き、フォームが出るまで待つ */
const openNew = async () => {
  loggedIn()
  mockTree()
  mockOptions()
  mockCategories()
  renderAt('/admin/cocktails/new')
  await screen.findByRole('button', { name: '公開する' })
}

/**
 * 編集の画面を開き、フォームが出るまで待つ。重複チェックの API の応答も登録し、送られた問い合わせを返す関数を返す
 * (`/admin/cocktails/duplicates` は `/admin/cocktails/{cocktail}` の形にも当たるので、カクテルの後に登録して先に当てる)
 */
const openEdit = async ({
  sameName = [],
  sameRecipe = [],
  cocktail = COCKTAIL,
}: { sameName?: Duplicate[]; sameRecipe?: Duplicate[]; cocktail?: CocktailResource } = {}) => {
  loggedIn()
  mockTree()
  mockOptions()
  mockCategories()
  mockApi('get', '/admin/cocktails/{cocktail}', 200, { data: cocktail })
  const queries = mockDuplicates(sameName, sameRecipe)
  renderAt(`/admin/cocktails/${cocktail.id}`)
  await screen.findByRole('button', { name: '公開する' })
  return queries
}

const optionLabels = (label: string) =>
  within(screen.getByLabelText(label)).getAllByRole('option').map((option) => option.textContent)

afterEach(() => {
  vi.restoreAllMocks()
})

describe('入口', () => {
  test('TC-127-06: カクテルの一覧の「新規」から、新規の画面(/admin/cocktails/new)を開く', async () => {
    loggedIn()
    mockApi('get', '/admin/cocktails', 200, emptyIndex())
    mockTree()
    mockOptions()
    renderAt('/admin/cocktails')

    fireEvent.click(await screen.findByRole('link', { name: '新規' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'カクテルの新規' })).toBeInTheDocument()
    expect(currentUrl()).toBe('/admin/cocktails/new')
  })

  test('TC-127-21: 新規の画面には、保存の 2 つのボタンを出し、削除のボタンは出さない', async () => {
    await openNew()

    expect(screen.getByRole('button', { name: '下書きとして保存' })).toBeEnabled()
    expect(screen.getByRole('button', { name: '公開する' })).toBeEnabled()
    expect(screen.queryByRole('button', { name: '削除' })).not.toBeInTheDocument()
  })

  test('TC-127-22: 無い ID のカクテルを開くと 404 の画面を出す', async () => {
    loggedIn()
    mockTree()
    mockOptions()
    mockApiHandler('get', '/admin/cocktails/{cocktail}', () => jsonResponse({ message: 'Not Found' }, 404))
    renderAt('/admin/cocktails/999')

    expect(await screen.findByRole('heading', { level: 1, name: 'ページが見つかりません' })).toBeInTheDocument()
  })
})

describe('欄', () => {
  test('TC-127-07: 欄は ベース / 氷・グラス / 名前・英名 / 検索のリンク / 製法・評価・甘さ・メジャー / 説明 / 補足 / YouTube と開始位置 / 画像 / リンク / 材料 / 保存の順', async () => {
    await openNew()

    // ベースの木・材料の行の中(ノードのラベル・開閉のボタン・行の欄)は除き、欄の名前・リンク・見出し・ボタンと、
    // 枠の名前(ベース・材料の行)を上から並べる
    const labels = Array.from(form().querySelectorAll('legend, label, a, h2, button'))
      .filter((element) => element.tagName === 'LEGEND' || element.closest('fieldset') === null)
      .map((element) => element.textContent)
    expect(labels).toEqual([
      'ベース',
      '氷の有無',
      'グラス',
      '名前',
      '英名',
      '意味検索',
      'カクテル言葉検索',
      '製法',
      '評価',
      '甘さ',
      'メジャー',
      '説明',
      '補足',
      'YouTube',
      '時',
      '分',
      '秒',
      '画像',
      'リンクテキスト',
      'リンクURL',
      '材料',
      '1 行目',
      '2 行目',
      '行を足す',
      '下書きとして保存',
      '公開する',
    ])
  })

  test('TC-127-08: ベースは根の下のどの深さでも選べ、根は選べない。選んだベースを根を除いたパンくずで出す', async () => {
    await openNew()

    const tree = screen.getByRole('list', { name: 'ベース' })
    expect(screen.getByText('選んでいるベース: (未選択)')).toBeInTheDocument()
    expect(within(tree).queryByRole('radio', { name: '蒸留酒' })).not.toBeInTheDocument()
    expect(within(tree).queryByRole('radio', { name: 'その他' })).not.toBeInTheDocument()

    fireEvent.click(within(tree).getByRole('radio', { name: 'ジン' }))

    expect(screen.getByText('選んでいるベース: ジン')).toBeInTheDocument()
  })

  test('TC-127-09: 製法は空の選択肢が無く、氷・グラス・評価・甘さは先頭に空の選択肢を置く。評価は 5〜1、甘さは -10〜10', async () => {
    await openNew()

    // 製法は、まだ選んでいないあいだだけ、選べない「(選んでください)」を出す
    expect(optionLabels('製法')).toEqual(['(選んでください)', 'ビルド', 'シェイク'])
    expect(within(screen.getByLabelText('製法')).getByRole('option', { name: '(選んでください)' })).toBeDisabled()
    expect(optionLabels('氷の有無')).toEqual(['', 'キューブアイス'])
    expect(optionLabels('グラス')).toEqual(['', 'タンブラー'])
    expect(optionLabels('評価')).toEqual(['', '5', '4', '3', '2', '1'])
    const sweet = optionLabels('甘さ')
    expect(sweet).toHaveLength(22)
    expect(sweet.slice(0, 3)).toEqual(['', '-10', '-9'])
    expect(sweet.at(-1)).toBe('10')

    fireEvent.change(screen.getByLabelText('製法'), { target: { value: '2' } })

    expect(optionLabels('製法')).toEqual(['ビルド', 'シェイク'])
  })

  test('TC-127-23: メジャーは押すたびに入れ・外しができる', async () => {
    await openNew()
    const major = screen.getByRole('checkbox', { name: 'メジャー' })

    fireEvent.click(major)
    expect(major).toBeChecked()
    fireEvent.click(major)
    expect(major).not.toBeChecked()
  })

  test('TC-127-24: 英名は欄を離れると全角の英数字を半角にし、開始位置は数字だけを時 1 桁・分秒 2 桁まで受け付ける', async () => {
    await openNew()
    mockDuplicates()

    const nameEng = screen.getByLabelText('英名')
    fireEvent.change(nameEng, { target: { value: 'Ｇｉｎ　１' } })
    fireEvent.blur(nameEng)
    expect(nameEng).toHaveValue('Gin　1')

    fireEvent.change(screen.getByLabelText('時'), { target: { value: '12' } })
    fireEvent.change(screen.getByLabelText('分'), { target: { value: '6a7' } })
    expect(screen.getByLabelText('時')).toHaveValue('1')
    expect(screen.getByLabelText('分')).toHaveValue('67')
  })

  test('TC-127-25: 検索のリンクは、入れた英名・名前で外の検索サイトを別のタブで開く', async () => {
    await openNew()
    mockDuplicates()

    fireEvent.change(screen.getByLabelText('名前'), { target: { value: 'ジントニック' } })
    fireEvent.change(screen.getByLabelText('英名'), { target: { value: 'Gin and Tonic' } })

    const meaning = screen.getByRole('link', { name: '意味検索' })
    expect(new URL(meaning.getAttribute('href')!).searchParams.get('q')).toBe('Gin and Tonic 意味')
    expect(meaning).toHaveAttribute('target', '_blank')
    const word = screen.getByRole('link', { name: 'カクテル言葉検索' })
    expect(new URL(word.getAttribute('href')!).searchParams.get('q')).toBe('ジントニック カクテル言葉')
  })

  test('TC-127-12: 編集で開くと、開いたカクテルの値を欄に出し、今のベース・画像を出す', async () => {
    await openEdit()

    expect(screen.getByRole('heading', { level: 1, name: 'カクテルの編集' })).toBeInTheDocument()
    expect(screen.getByText('選んでいるベース: ジン')).toBeInTheDocument()
    expect(screen.getByLabelText('氷の有無')).toHaveValue('20')
    expect(screen.getByLabelText('グラス')).toHaveValue('10')
    expect(screen.getByLabelText('名前')).toHaveValue('ジントニック')
    expect(screen.getByLabelText('英名')).toHaveValue('Gin and Tonic')
    expect(screen.getByLabelText('製法')).toHaveValue('1')
    expect(screen.getByLabelText('評価')).toHaveValue('3')
    expect(screen.getByLabelText('甘さ')).toHaveValue('0')
    expect(screen.getByRole('checkbox', { name: 'メジャー' })).toBeChecked()
    expect(screen.getByLabelText('説明')).toHaveValue('定番の@n2@のカクテル')
    expect(screen.getByLabelText('補足')).toHaveValue('ライムを添える。')
    expect(screen.getByLabelText('YouTube')).toHaveValue('abcdefghijk')
    expect(screen.getByLabelText('時')).toHaveValue('')
    expect(screen.getByLabelText('分')).toHaveValue('1')
    expect(screen.getByLabelText('秒')).toHaveValue('30')
    expect(screen.getByLabelText('リンクテキスト')).toHaveValue('公式')
    expect(screen.getByLabelText('リンクURL')).toHaveValue('https://example.com/')
    expect(screen.getByRole('img', { name: 'カクテルの画像' })).toHaveAttribute('src', '/storage/gin.png')
    expect(screen.getByRole('button', { name: '削除' })).toBeInTheDocument()
  })
})

describe('保存', () => {
  test('TC-127-10: 「下書きとして保存」を押すと is_draft=1 で POST /api/admin/cocktails に送り、保存できたら下書きの一覧へ戻る', async () => {
    await openNew()
    mockDuplicates()
    mockApi('get', '/admin/cocktails', 200, emptyIndex())
    const sent = captureSave('post', () => jsonResponse(savedBody({ ...COCKTAIL, is_draft: true }), 201))

    fireEvent.click(within(screen.getByRole('list', { name: 'ベース' })).getByRole('radio', { name: 'ウォッカ' }))
    fireEvent.change(screen.getByLabelText('名前'), { target: { value: 'モスコミュール' } })
    fireEvent.change(screen.getByLabelText('英名'), { target: { value: 'Moscow Mule' } })
    fireEvent.change(screen.getByLabelText('製法'), { target: { value: '1' } })
    fireEvent.change(screen.getByLabelText('評価'), { target: { value: '5' } })
    fireEvent.change(screen.getByLabelText('甘さ'), { target: { value: '-10' } })
    fireEvent.click(screen.getByRole('button', { name: '下書きとして保存' }))

    await waitFor(() => expect(currentUrl()).toBe('/admin/cocktails?status=draft'))
    const body = sent()!
    expect(body.has('_method')).toBe(false)
    expect(body.get('is_draft')).toBe('1')
    expect(body.get('base_category_id')).toBe('4')
    expect(body.get('name')).toBe('モスコミュール')
    expect(body.get('name_eng')).toBe('Moscow Mule')
    expect(body.get('method_id')).toBe('1')
    expect(body.get('rate')).toBe('5')
    expect(body.get('sweet_degree')).toBe('-10')
    expect(body.get('ice_id')).toBe('')
  })

  test('TC-127-11: 「公開する」を押すと is_draft=0 で送り、保存できたら公開済みの一覧へ戻る', async () => {
    await openNew()
    mockApi('get', '/admin/cocktails', 200, emptyIndex())
    const sent = captureSave('post', () => jsonResponse(savedBody(COCKTAIL), 201))

    fireEvent.click(screen.getByRole('button', { name: '公開する' }))

    await waitFor(() => expect(currentUrl()).toBe('/admin/cocktails'))
    expect(sent()!.get('is_draft')).toBe('0')
  })

  test('TC-127-13: 編集で保存すると、POST に _method=PUT を付けて /api/admin/cocktails/{id} に送り、開いたときの材料をそのまま送り返す', async () => {
    await openEdit()
    mockApi('get', '/admin/cocktails', 200, emptyIndex())
    const sent = captureSave('put', () => jsonResponse({ data: COCKTAIL, warnings: [] }, 200))

    fireEvent.change(screen.getByLabelText('補足'), { target: { value: 'レモンでもよい。' } })
    fireEvent.click(screen.getByRole('button', { name: '公開する' }))

    await waitFor(() => expect(currentUrl()).toBe('/admin/cocktails'))
    // 開いたときの GET の後の、保存のリクエスト
    const save = apiRequests().findLast((request) => request.url.endsWith('/api/admin/cocktails/7'))!
    expect(save.method).toBe('POST')
    const body = sent()!
    expect(body.get('_method')).toBe('PUT')
    expect(body.get('note')).toBe('レモンでもよい。')
    expect(body.get('ingredients[0][category_id]')).toBe('3')
    expect(body.get('ingredients[0][brand_id]')).toBe('8')
    expect(body.get('ingredients[0][amount]')).toBe('45ml')
    expect(body.get('ingredients[1][category_id]')).toBe('6')
    expect(body.has('ingredients[1][brand_id]')).toBe(false)
    expect(body.get('ingredients[1][is_optional]')).toBe('1')
  })

  test('TC-127-14: 422 なら項目ごとのメッセージを欄の下に、材料のメッセージを材料の欄に出し、画面に残って入力も残る', async () => {
    await openNew()
    mockDuplicates()
    captureSave('post', () =>
      jsonResponse(
        {
          message: 'The given data was invalid.',
          errors: {
            base_category_id: ['ベースは必須です。'],
            name: ['名前は255文字以下にしてください。'],
            name_eng: ['英名は必須です。'],
            method_id: ['製法は必須です。'],
            ingredients: ['材料は2件以上にしてください。'],
          },
        },
        422,
      ),
    )

    fireEvent.change(screen.getByLabelText('名前'), { target: { value: 'あ'.repeat(256) } })
    fireEvent.click(screen.getByRole('button', { name: '下書きとして保存' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('保存できませんでした。入力を確かめてください。')
    expect(screen.getByRole('group', { name: 'ベース' })).toHaveAccessibleDescription('ベースは必須です。')
    expect(screen.getByLabelText('名前')).toHaveAccessibleDescription('名前は255文字以下にしてください。')
    expect(screen.getByLabelText('名前')).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByLabelText('英名')).toHaveAccessibleDescription('英名は必須です。')
    expect(screen.getByLabelText('製法')).toHaveAccessibleDescription('製法は必須です。')
    expect(within(screen.getByRole('region', { name: '材料' })).getByText('材料は2件以上にしてください。')).toBeInTheDocument()
    expect(screen.getByLabelText('名前')).toHaveValue('あ'.repeat(256))
    expect(currentUrl()).toBe('/admin/cocktails/new')
    expect(screen.getByRole('button', { name: '公開する' })).toBeEnabled()
  })

  test('TC-127-15: 422 で欄の無い項目(別名など)のメッセージは、保存のボタンの下にまとめて出す', async () => {
    await openNew()
    captureSave('post', () =>
      jsonResponse({ message: 'invalid', errors: { 'aliases.0': ['別名と同じ名前です'], is_draft: ['is_draft は必須です。'] } }, 422),
    )

    fireEvent.click(screen.getByRole('button', { name: '公開する' }))

    expect(await screen.findByText('別名と同じ名前です')).toBeInTheDocument()
    expect(screen.getByText('is_draft は必須です。')).toBeInTheDocument()
  })

  test.each([
    ['500 の応答', () => jsonResponse({ message: 'Server Error' }, 500), '保存できませんでした(エラー 500)。'],
    [
      '通信の失敗',
      () => {
        throw new TypeError('Failed to fetch')
      },
      '保存できませんでした。通信に失敗しました。',
    ],
  ])('TC-127-16: %s なら、そのことを出して画面に残る', async (_, response, message) => {
    await openNew()
    mockApiHandler('post', '/admin/cocktails', response)

    fireEvent.click(screen.getByRole('button', { name: '公開する' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(message)
    expect(currentUrl()).toBe('/admin/cocktails/new')
  })
})

describe('同じ名前・同じレシピのカクテル', () => {
  test('TC-127-17: 名前を入れると問い合わせ、同じ名前・同じレシピのカクテルを見出しを分けて出す(編集画面へのリンク・製法・材料・説明・補足)', async () => {
    await openNew()
    const queries = mockDuplicates(
      [
        duplicate(11, 'ジントニック', {
          description: 'ライムを搾る。',
          note: '辛口。',
          ingredients: [
            { category_id: 3, category_name: 'ドライジン', brand_id: 8, brand_name: 'ビーフィーター', amount: '45ml', is_optional: false },
            { category_id: 6, category_name: 'ミント', brand_id: null, brand_name: null, amount: '適量', is_optional: true },
          ],
        }),
      ],
      [duplicate(12, 'ジン・トニック', { method_id: 1 })],
    )
    expect(screen.queryByRole('complementary', { name: '同じ名前・同じレシピのカクテル' })).not.toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('名前'), { target: { value: 'ジントニック' } })

    const aside = await screen.findByRole('complementary', { name: '同じ名前・同じレシピのカクテル' })
    expect(queries().at(-1)!.get('name')).toBe('ジントニック')
    expect(queries().at(-1)!.has('name_eng')).toBe(false)
    expect(queries().at(-1)!.has('exclude_id')).toBe(false)
    const sameName = within(aside).getByRole('region', { name: '同じ名前のカクテル' })
    expect(within(sameName).getByRole('link', { name: 'ジントニック' })).toHaveAttribute('href', '/admin/cocktails/11')
    expect(within(sameName).getByText('シェイク')).toBeInTheDocument()
    expect(within(sameName).getAllByRole('row').map((row) => row.textContent)).toEqual(['ドライジンビーフィーター45ml', 'ミント適量'])
    expect(within(sameName).getByText('ライムを搾る。')).toBeInTheDocument()
    expect(within(sameName).getByText('辛口。')).toBeInTheDocument()
    const sameRecipe = within(aside).getByRole('region', { name: '同じレシピのカクテル' })
    expect(within(sameRecipe).getByRole('link', { name: 'ジン・トニック' })).toHaveAttribute('href', '/admin/cocktails/12')
    expect(within(sameRecipe).getByText('ビルド')).toBeInTheDocument()
  })

  test('TC-127-18: 編集で開くと、自分を除く(exclude_id)問い合わせをし、どちらも 0 件なら一覧を出さない', async () => {
    const queries = await openEdit()

    await waitFor(() => expect(queries()).toHaveLength(1))
    expect(queries()[0].get('name')).toBe('ジントニック')
    expect(queries()[0].get('name_eng')).toBe('Gin and Tonic')
    expect(queries()[0].get('exclude_id')).toBe('7')
    expect(screen.queryByRole('complementary', { name: '同じ名前・同じレシピのカクテル' })).not.toBeInTheDocument()
  })

  test('TC-127-26: 同じ名前のカクテルだけがあれば、同じレシピの見出しは出さない', async () => {
    await openEdit({ sameName: [duplicate(11, 'ジントニック')] })

    const aside = await screen.findByRole('complementary', { name: '同じ名前・同じレシピのカクテル' })
    expect(within(aside).getByRole('region', { name: '同じ名前のカクテル' })).toBeInTheDocument()
    expect(within(aside).queryByRole('region', { name: '同じレシピのカクテル' })).not.toBeInTheDocument()
  })
})

describe('削除', () => {
  test('TC-127-19: 「削除」で確かめ、取り消せば消さず、確かめれば DELETE を送ってメニューへ移る', async () => {
    await openEdit()
    let deleted = 0
    mockApiHandler('delete', '/admin/cocktails/{cocktail}', () => {
      deleted += 1
      return new Response(null, { status: 204 })
    })
    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true)

    fireEvent.click(screen.getByRole('button', { name: '削除' }))
    expect(confirm).toHaveBeenLastCalledWith('「ジントニック」を削除します。よろしいですか?')
    expect(deleted).toBe(0)
    expect(currentUrl()).toBe('/admin/cocktails/7')

    fireEvent.click(screen.getByRole('button', { name: '削除' }))

    await waitFor(() => expect(currentUrl()).toBe('/admin'))
    expect(deleted).toBe(1)
  })

  test('TC-127-20: ほかの説明文から参照されていて消せない(409)なら、理由と参照している行を出して画面に残る', async () => {
    await openEdit()
    const conflict: Conflict = {
      message: 'このカクテルは消せません',
      reasons: [
        { code: 'cocktail', message: 'ほかのカクテルの説明文から参照されています', referrers: [{ id: 3, name: 'ギムレット' }, { id: 4, name: 'マティーニ' }] },
      ],
    }
    mockApi('delete', '/admin/cocktails/{cocktail}', 409, conflict)
    vi.spyOn(window, 'confirm').mockReturnValue(true)

    fireEvent.click(screen.getByRole('button', { name: '削除' }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('削除できませんでした。')
    expect(alert).toHaveTextContent('ほかのカクテルの説明文から参照されています: ギムレット、マティーニ')
    expect(currentUrl()).toBe('/admin/cocktails/7')
  })
})

describe('材料の行', () => {
  const ingredients = () => screen.getByRole('region', { name: '材料' })
  const row = (position: number) => within(ingredients()).getByRole('group', { name: `${position} 行目` })
  const baseText = () => screen.getByText(/^選んでいるベース: /).textContent

  /** 行の「ノードを選ぶ」で木を開き、名前のノードを選ぶ(根の直下か、開いている枝のノード) */
  const pickNode = (position: number, name: string) => {
    fireEvent.click(within(row(position)).getByRole('button', { name: `${position} 行目のノードを選ぶ` }))
    fireEvent.click(within(screen.getByRole('list', { name: `${position} 行目のノード` })).getByRole('radio', { name }))
  }
  const pickBase = (name: string) => fireEvent.click(within(screen.getByRole('list', { name: 'ベース' })).getByRole('radio', { name }))
  const brandSelect = (position: number) => within(row(position)).getByLabelText('銘柄')
  const amount = (position: number, value: string) => fireEvent.change(within(row(position)).getByLabelText('量'), { target: { value } })
  const fillRequired = () => {
    fireEvent.change(screen.getByLabelText('名前'), { target: { value: 'モスコミュール' } })
    fireEvent.change(screen.getByLabelText('英名'), { target: { value: 'Moscow Mule' } })
    fireEvent.change(screen.getByLabelText('製法'), { target: { value: '1' } })
  }
  const savePosts = () => apiRequests().filter((request) => request.method === 'POST' && request.url.endsWith('/api/admin/cocktails'))

  test('TC-128-10: 新規は空の行を 2 つ出し、「行を足す」で末尾に足せ、20 行になると足せない', async () => {
    await openNew()

    expect(within(row(1)).getByText('ノード: (未選択)')).toBeInTheDocument()
    expect(brandSelect(1)).toBeDisabled()
    expect(within(row(2)).getByLabelText('量')).toHaveValue('')
    expect(within(ingredients()).queryByRole('group', { name: '3 行目' })).not.toBeInTheDocument()

    const add = within(ingredients()).getByRole('button', { name: '行を足す' })
    for (let rows = 2; rows < 19; rows += 1) fireEvent.click(add)
    expect(row(19)).toBeInTheDocument()
    expect(add).toBeEnabled()
    fireEvent.click(add)

    expect(row(20)).toBeInTheDocument()
    expect(add).toBeDisabled()
  })

  test('TC-128-11: ノードは木で選び、根は選べない。選ぶとパンくずを出して木を閉じ、木を開くのは 1 行だけ', async () => {
    await openNew()

    fireEvent.click(within(row(1)).getByRole('button', { name: '1 行目のノードを選ぶ' }))
    const tree = screen.getByRole('list', { name: '1 行目のノード' })
    expect(within(tree).queryByRole('radio', { name: '蒸留酒' })).not.toBeInTheDocument()
    expect(within(row(1)).getByRole('button', { name: '1 行目のノードを選ぶ' })).toHaveAttribute('aria-expanded', 'true')

    // 2 行目の木を開くと、1 行目の木は閉じる
    fireEvent.click(within(row(2)).getByRole('button', { name: '2 行目のノードを選ぶ' }))
    expect(screen.queryByRole('list', { name: '1 行目のノード' })).not.toBeInTheDocument()
    fireEvent.click(within(screen.getByRole('list', { name: '2 行目のノード' })).getByRole('radio', { name: 'ジン' }))

    expect(within(row(2)).getByText('ノード: ジン')).toBeInTheDocument()
    expect(screen.queryByRole('list', { name: '2 行目のノード' })).not.toBeInTheDocument()
    expect(within(row(2)).getByRole('button', { name: '2 行目のノードを選ぶ' })).toHaveAttribute('aria-expanded', 'false')
  })

  test('TC-128-12: 銘柄の選択肢は、行のノードとその配下の銘柄を「名前(英名)」で出し、銘柄を選ぶとノードを銘柄の所属ノードにする', async () => {
    await openNew()

    pickNode(1, 'ジン')
    await within(row(1)).findByRole('option', { name: 'タンカレー(Tanqueray)' })
    expect(within(brandSelect(1)).getAllByRole('option').map((option) => option.textContent)).toEqual([
      '',
      'ビーフィーター(Beefeater)',
      'タンカレー(Tanqueray)',
    ])
    fireEvent.change(brandSelect(1), { target: { value: '9' } })

    expect(within(row(1)).getByText('ノード: ジン / ドライジン')).toBeInTheDocument()
    await waitFor(() => expect(brandSelect(1)).toBeEnabled())
    expect(brandSelect(1)).toHaveDisplayValue('タンカレー(Tanqueray)')
  })

  test('TC-128-13: ノードを木で選び直すと、銘柄を空にする', async () => {
    await openNew()
    pickNode(1, 'ジン')
    await within(row(1)).findByRole('option', { name: 'タンカレー(Tanqueray)' })
    fireEvent.change(brandSelect(1), { target: { value: '9' } })
    await waitFor(() => expect(brandSelect(1)).toHaveDisplayValue('タンカレー(Tanqueray)'))

    pickNode(1, 'ウォッカ')

    expect(within(row(1)).getByText('ノード: ウォッカ')).toBeInTheDocument()
    await waitFor(() => expect(brandSelect(1)).toBeEnabled())
    expect(brandSelect(1)).toHaveValue('')
  })

  test('TC-128-14: ベースが空のときに、空の 1 行目のノードを選ぶとベースにも入れる。2 行目・ノードのある 1 行目を選んでも入れない', async () => {
    await openNew()

    pickNode(2, 'ミント')
    expect(baseText()).toBe('選んでいるベース: (未選択)')

    pickNode(1, 'ウォッカ')
    expect(baseText()).toBe('選んでいるベース: ウォッカ')

    pickNode(1, 'ジン')
    expect(baseText()).toBe('選んでいるベース: ウォッカ')
    expect(within(row(1)).getByText('ノード: ジン')).toBeInTheDocument()
  })

  test('TC-128-15: ベースを空から選ぶと、空の 1 行目にも入れる。ベースを選び直したとき・1 行目にノードがあるときは 1 行目を変えない', async () => {
    await openNew()

    pickBase('ジン')
    expect(within(row(1)).getByText('ノード: ジン')).toBeInTheDocument()
    pickBase('ウォッカ')
    expect(within(row(1)).getByText('ノード: ジン')).toBeInTheDocument()
    expect(baseText()).toBe('選んでいるベース: ウォッカ')
  })

  test('TC-128-24: 2 行目に入れてから 1 行目へ上げ、空のベースを選んでも、1 行目のノードは変えない', async () => {
    await openNew()
    pickNode(2, 'ミント')
    fireEvent.click(within(row(2)).getByRole('button', { name: '2 行目を上へ' }))
    expect(within(row(1)).getByText('ノード: ミント')).toBeInTheDocument()

    pickBase('ウォッカ')

    expect(within(row(1)).getByText('ノード: ミント')).toBeInTheDocument()
    expect(within(row(2)).getByText('ノード: (未選択)')).toBeInTheDocument()
    expect(baseText()).toBe('選んでいるベース: ウォッカ')
  })

  test('TC-128-16: 並べ替え・消すと、その並びで送る。先頭の「上へ」と末尾の「下へ」は押せない', async () => {
    await openNew()
    mockDuplicates()
    mockApi('get', '/admin/cocktails', 200, emptyIndex())
    const sent = captureSave('post', () => jsonResponse(savedBody({ ...COCKTAIL, is_draft: true }), 201))
    fillRequired()
    pickNode(1, 'ウォッカ')
    amount(1, '45')
    pickNode(2, 'ミント')
    amount(2, '適量')
    fireEvent.click(within(row(2)).getByRole('checkbox', { name: '任意' }))
    fireEvent.click(within(ingredients()).getByRole('button', { name: '行を足す' }))
    pickNode(3, 'ジン')
    amount(3, '10')

    expect(within(row(1)).getByRole('button', { name: '1 行目を上へ' })).toBeDisabled()
    expect(within(row(3)).getByRole('button', { name: '3 行目を下へ' })).toBeDisabled()
    fireEvent.click(within(row(3)).getByRole('button', { name: '3 行目を上へ' }))
    expect(within(row(2)).getByText('ノード: ジン')).toBeInTheDocument()
    expect(within(row(3)).getByText('ノード: ミント')).toBeInTheDocument()
    fireEvent.click(within(row(2)).getByRole('button', { name: '2 行目を消す' }))
    expect(within(row(2)).getByText('ノード: ミント')).toBeInTheDocument()
    expect(within(ingredients()).queryByRole('group', { name: '3 行目' })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: '下書きとして保存' }))

    await waitFor(() => expect(currentUrl()).toBe('/admin/cocktails?status=draft'))
    const body = sent()!
    expect(body.get('base_category_id')).toBe('4')
    const keys: string[] = []
    body.forEach((_, key) => keys.push(key))
    expect(keys.filter((key) => key.startsWith('ingredients'))).toEqual([
      'ingredients[0][category_id]',
      'ingredients[0][amount]',
      'ingredients[0][is_optional]',
      'ingredients[1][category_id]',
      'ingredients[1][amount]',
      'ingredients[1][is_optional]',
    ])
    expect(body.get('ingredients[0][category_id]')).toBe('4')
    expect(body.get('ingredients[0][amount]')).toBe('45')
    expect(body.get('ingredients[0][is_optional]')).toBe('0')
    expect(body.get('ingredients[1][category_id]')).toBe('6')
    expect(body.get('ingredients[1][amount]')).toBe('適量')
    expect(body.get('ingredients[1][is_optional]')).toBe('1')
  })

  test('TC-128-17: 同じノード・同じ銘柄(どちらも銘柄なし)の行が 2 つあると、送らずに「レシピが重複しています！」を材料の欄に出し、行を直すと消す', async () => {
    await openNew()
    pickBase('ウォッカ')
    pickNode(2, 'ウォッカ')

    fireEvent.click(screen.getByRole('button', { name: '下書きとして保存' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('保存できませんでした。入力を確かめてください。')
    expect(within(ingredients()).getByText('レシピが重複しています！')).toBeInTheDocument()
    expect(savePosts()).toHaveLength(0)
    expect(currentUrl()).toBe('/admin/cocktails/new')

    pickNode(2, 'ミント')
    expect(within(ingredients()).queryByText('レシピが重複しています！')).not.toBeInTheDocument()
  })

  test('TC-128-18: ベースが、どの行のノードとも同じでなく祖先でもなければ、送らずに「ベースが見つかりません！」を出す', async () => {
    await openNew()
    pickNode(1, 'ウォッカ')
    pickNode(2, 'ミント')
    // 1 行目でベースに入ったウォッカを、行に無いジンに選び直す
    pickBase('ジン')

    fireEvent.click(screen.getByRole('button', { name: '公開する' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('保存できませんでした。入力を確かめてください。')
    expect(within(ingredients()).getByText('ベースが見つかりません！')).toBeInTheDocument()
    expect(within(ingredients()).queryByText('レシピが重複しています！')).not.toBeInTheDocument()
    expect(savePosts()).toHaveLength(0)
  })

  test('TC-128-19: 422 の行のメッセージは、その行の欄の下に出し、並べ替えても行に付いたまま動き、欄を直すと消す', async () => {
    await openNew()
    mockDuplicates()
    captureSave('post', () =>
      jsonResponse({ message: 'invalid', errors: { 'ingredients.1.amount': ['材料 2 行目の量を入力してください。'] } }, 422),
    )
    pickNode(1, 'ウォッカ')
    amount(1, '45')
    pickNode(2, 'ミント')

    fireEvent.click(screen.getByRole('button', { name: '下書きとして保存' }))

    const mintAmount = () => within(row(2)).getByLabelText('量')
    await waitFor(() => expect(mintAmount()).toHaveAccessibleDescription('材料 2 行目の量を入力してください。'))
    expect(within(row(1)).getByLabelText('量')).toHaveAttribute('aria-invalid', 'false')

    fireEvent.click(within(row(2)).getByRole('button', { name: '2 行目を上へ' }))
    expect(within(row(1)).getByText('ノード: ミント')).toBeInTheDocument()
    expect(within(row(1)).getByLabelText('量')).toHaveAccessibleDescription('材料 2 行目の量を入力してください。')
    expect(within(row(2)).getByLabelText('量')).toHaveAttribute('aria-invalid', 'false')

    amount(1, '適量')
    expect(within(row(1)).getByLabelText('量')).toHaveAttribute('aria-invalid', 'false')
    expect(within(row(1)).getByLabelText('量')).toHaveAccessibleDescription('')
  })

  test('TC-128-30: 1 行目のノードの 422 は、ベースを空から選んで 1 行目にノードが入ったときにも消える', async () => {
    await openNew()
    captureSave('post', () =>
      jsonResponse({ message: 'invalid', errors: { 'ingredients.0.category_id': ['材料 1 行目のノードを入力してください。'] } }, 422),
    )

    fireEvent.click(screen.getByRole('button', { name: '下書きとして保存' }))

    const nodeButton = () => within(row(1)).getByRole('button', { name: '1 行目のノードを選ぶ' })
    await waitFor(() => expect(nodeButton()).toHaveAccessibleDescription('材料 1 行目のノードを入力してください。'))

    pickBase('ウォッカ')

    expect(within(row(1)).getByText('ノード: ウォッカ')).toBeInTheDocument()
    expect(nodeButton()).toHaveAccessibleDescription('')
  })

  test('TC-128-20: 新規で材料を 2 行入れて保存すると、行の並びのまま ingredients[行][項目] でノード・銘柄・量・任意を送る', async () => {
    await openNew()
    mockDuplicates()
    mockApi('get', '/admin/cocktails', 200, emptyIndex())
    const sent = captureSave('post', () => jsonResponse(savedBody(COCKTAIL), 201))
    fillRequired()
    pickNode(1, 'ジン')
    await within(row(1)).findByRole('option', { name: 'タンカレー(Tanqueray)' })
    fireEvent.change(brandSelect(1), { target: { value: '9' } })
    amount(1, '45')
    pickNode(2, 'ミント')
    amount(2, '適量')
    fireEvent.click(within(row(2)).getByRole('checkbox', { name: '任意' }))
    fireEvent.change(screen.getByLabelText('説明'), { target: { value: '辛口。' } })

    fireEvent.click(screen.getByRole('button', { name: '公開する' }))

    await waitFor(() => expect(currentUrl()).toBe('/admin/cocktails'))
    const body = sent()!
    // 1 行目のノード(ジン)を選んだときに、空のベースにも入った
    expect(body.get('base_category_id')).toBe('2')
    expect(body.get('ingredients[0][category_id]')).toBe('3')
    expect(body.get('ingredients[0][brand_id]')).toBe('9')
    expect(body.get('ingredients[0][amount]')).toBe('45')
    expect(body.get('ingredients[0][is_optional]')).toBe('0')
    expect(body.get('ingredients[1][category_id]')).toBe('6')
    expect(body.has('ingredients[1][brand_id]')).toBe(false)
    expect(body.get('ingredients[1][amount]')).toBe('適量')
    expect(body.get('ingredients[1][is_optional]')).toBe('1')
  })

  test('TC-128-21: 編集で開くと、今の材料を並びのまま行に出す(ノードのパンくず・銘柄・量・任意)', async () => {
    await openEdit()

    expect(within(row(1)).getByText('ノード: ジン / ドライジン')).toBeInTheDocument()
    await waitFor(() => expect(brandSelect(1)).toHaveDisplayValue('ビーフィーター(Beefeater)'))
    expect(within(row(1)).getByLabelText('量')).toHaveValue('45ml')
    expect(within(row(1)).getByRole('checkbox', { name: '任意' })).not.toBeChecked()
    expect(within(row(2)).getByText('ノード: ミント')).toBeInTheDocument()
    await waitFor(() => expect(brandSelect(2)).toBeEnabled())
    expect(brandSelect(2)).toHaveValue('')
    expect(within(row(2)).getByLabelText('量')).toHaveValue('適量')
    expect(within(row(2)).getByRole('checkbox', { name: '任意' })).toBeChecked()
    expect(within(ingredients()).queryByRole('group', { name: '3 行目' })).not.toBeInTheDocument()
  })

  test('TC-128-22: 今の銘柄が行のノードの配下に無いときは、そのことを出す選択肢で出す', async () => {
    await openEdit({
      cocktail: {
        ...COCKTAIL,
        ingredients: [
          { category_id: 4, brand_id: 8, amount: '45ml', is_optional: false },
          { category_id: 6, brand_id: null, amount: '適量', is_optional: true },
        ],
      },
    })

    await waitFor(() => expect(brandSelect(1)).toBeEnabled())
    expect(brandSelect(1)).toHaveValue('8')
    expect(brandSelect(1)).toHaveDisplayValue('(このノードの配下に無い銘柄)')
  })

  test('TC-128-23: 銘柄の選択肢を読み込めなければそのことを出し、「銘柄を読み込み直す」で読み直す', async () => {
    await openNew()
    let failures = 1
    mockApiHandler('get', '/categories/{category}', () => {
      if (failures > 0) {
        failures -= 1
        return jsonResponse({ message: 'Server Error' }, 500)
      }
      return jsonResponse(categoryDetail(2, [BEEFEATER]), 200)
    })

    pickNode(1, 'ジン')

    expect(await within(row(1)).findByText('銘柄を読み込めませんでした。')).toBeInTheDocument()
    expect(brandSelect(1)).toBeDisabled()
    fireEvent.click(within(row(1)).getByRole('button', { name: '銘柄を読み込み直す' }))
    expect(await within(row(1)).findByRole('option', { name: 'ビーフィーター(Beefeater)' })).toBeInTheDocument()
    expect(within(row(1)).queryByText('銘柄を読み込めませんでした。')).not.toBeInTheDocument()
  })
})
