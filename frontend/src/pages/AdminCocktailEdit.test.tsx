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

/** 選択肢。製法 2 つ・グラス 1 つ・氷 1 つ(型は API の定義に合わせ、ID は文字にしている) */
const OPTIONS: FormOptions = {
  methods: [
    { id: '1', name: 'ビルド' },
    { id: '2', name: 'シェイク' },
  ],
  glasses: [{ id: '10', name: 'タンブラー' }],
  ices: [{ id: '20', name: 'キューブアイス' }],
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
  renderAt('/admin/cocktails/new')
  await screen.findByRole('button', { name: '公開する' })
}

/**
 * 編集の画面を開き、フォームが出るまで待つ。重複チェックの API の応答も登録し、送られた問い合わせを返す関数を返す
 * (`/admin/cocktails/duplicates` は `/admin/cocktails/{cocktail}` の形にも当たるので、カクテルの後に登録して先に当てる)
 */
const openEdit = async ({ sameName = [], sameRecipe = [] }: { sameName?: Duplicate[]; sameRecipe?: Duplicate[] } = {}) => {
  loggedIn()
  mockTree()
  mockOptions()
  mockApi('get', '/admin/cocktails/{cocktail}', 200, { data: COCKTAIL })
  const queries = mockDuplicates(sameName, sameRecipe)
  renderAt(`/admin/cocktails/${COCKTAIL.id}`)
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

    // ベースの木の中(ノードのラベル・開閉のボタン)は除き、欄の名前・リンク・見出し・ボタンを上から並べる
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

  test('TC-127-12: 編集で開くと、開いたカクテルの値を欄に出し、今のベース・画像・材料の件数を出す', async () => {
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
    expect(screen.getByText('材料はまだこの画面で変えられません。保存すると今の材料(2 件)のまま残ります。')).toBeInTheDocument()
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
