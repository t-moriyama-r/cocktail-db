import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, test, vi } from 'vitest'
import App from '../App'
import type { components, paths } from '../api/schema'
import { apiRequests, jsonResponse, mockApi, mockApiHandler } from '../test/api'

type ParaphraseResource = components['schemas']['ParaphraseResource']
type SkipWordResource = components['schemas']['SkipWordResource']
type SearchFormNode = components['schemas']['SearchFormNodeResource']
type NodeRef = components['schemas']['NodeRefResource']
type BrandSummary = components['schemas']['BrandSummaryResource']
type CategoryDetailResource = components['schemas']['CategoryDetailResource']
type ParaphrasePage = paths['/admin/paraphrases']['get']['responses'][200]['content']['application/json']

// E2eSeeder と同じ形の木(蒸留酒 > ジン > ドライジン、蒸留酒 > ウォッカ、その他 > ミント)
const LIQUOR: NodeRef = { id: 1, name: '蒸留酒', name_eng: 'Spirits' }
const GIN: NodeRef = { id: 2, name: 'ジン', name_eng: 'Gin' }
const DRY_GIN: NodeRef = { id: 3, name: 'ドライジン', name_eng: 'Dry Gin' }
const VODKA: NodeRef = { id: 4, name: 'ウォッカ', name_eng: 'Vodka' }

const treeNode = (node: NodeRef, children: SearchFormNode[] = []): SearchFormNode => ({
  id: node.id,
  name: node.name,
  is_major: true,
  root_role: null,
  children,
})

const TREE: SearchFormNode[] = [
  { ...treeNode(LIQUOR, [treeNode(GIN, [treeNode(DRY_GIN)]), treeNode(VODKA)]), is_major: false },
  {
    id: 10,
    name: 'その他',
    is_major: false,
    root_role: 'other',
    children: [{ ...treeNode({ id: 11, name: 'ミント', name_eng: 'Mint' }), root_role: 'other' }],
  },
]

const BEEFEATER: BrandSummary = {
  id: 40,
  name: 'ビーフィーター 40度',
  name_eng: 'Beefeater 40',
  strength: 40,
  youtube: null,
  path: [LIQUOR, GIN, DRY_GIN],
}

const paraphrase = (
  overrides: Partial<ParaphraseResource> & Pick<ParaphraseResource, 'id' | 'name'>,
): ParaphraseResource => ({
  converted: 'ジン',
  middle: null,
  command: null,
  note: null,
  resolved: { path: [LIQUOR, GIN], brand: null },
  ...overrides,
})

const page = (data: ParaphraseResource[], meta: Partial<ParaphrasePage['meta']> = {}): ParaphrasePage => ({
  data,
  links: { first: null, last: null, prev: null, next: null },
  meta: {
    current_page: 1,
    from: 1,
    last_page: 1,
    links: [],
    path: null,
    per_page: 50,
    to: data.length,
    total: data.length,
    ...meta,
  },
})

const nodeDetail = (path: NodeRef[], brands: BrandSummary[]): CategoryDetailResource => ({
  ...path[path.length - 1],
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
  path,
  children: [],
  famous_brand: null,
  brands,
})

/** 画面を開くのに要る応答(ログイン・木・2 つの一覧)を登録する */
const mockScreen = ({ paraphrases = [] as ParaphraseResource[], skipWords = [] as SkipWordResource[] } = {}) => {
  mockApi('get', '/admin/me', 200, { id: 1, name: '管理者の太郎' })
  mockApi('get', '/search-form', 200, { categories: TREE, methods: [] })
  mockApi('get', '/admin/paraphrases', 200, page(paraphrases))
  mockApi('get', '/admin/skip-words', 200, { data: skipWords })
  // 木でノードを選ぶ・語を読み込むと、そのノードの配下の銘柄を読む。銘柄を見るテストは、後から登録し直す
  mockApi('get', '/categories/{category}', 200, { data: nodeDetail([LIQUOR, GIN], []) })
}

const renderScreen = () =>
  render(
    <MemoryRouter initialEntries={['/admin/paraphrases']}>
      <App />
    </MemoryRouter>,
  )

const paraphraseSection = () => screen.getByRole('region', { name: '言い換え辞書' })
const skipWordSection = () => screen.getByRole('region', { name: 'スキップ語' })
const paraphraseForm = () => screen.getByRole('form', { name: '言い換えのフォーム' })
const skipWordForm = () => screen.getByRole('form', { name: 'スキップ語のフォーム' })

/** 送ったリクエストのうち、メソッドとパスが合うもの */
const sent = (method: string, pathname: string) =>
  apiRequests().filter((request) => request.method === method && new URL(request.url).pathname === `/api${pathname}`)

const typeInto = (field: HTMLElement, value: string) => fireEvent.change(field, { target: { value } })

/** 木が出るまで待つ(根は見出しなので、根のすぐ下のノードのラジオボタンで待つ) */
const waitForTree = () => screen.findByRole('radio', { name: 'ジン' })

afterEach(() => {
  vi.restoreAllMocks()
})

describe('画面を開く', () => {
  test('TC-67-01: メニューに「言い換え・スキップ語」が出て、押すとこの画面が開き、タイトルと 2 つの欄が出る', async () => {
    mockScreen()
    render(
      <MemoryRouter initialEntries={['/admin']}>
        <App />
      </MemoryRouter>,
    )

    const menu = await screen.findByRole('navigation', { name: '管理画面のメニュー' })
    fireEvent.click(within(menu).getByRole('link', { name: '言い換え・スキップ語' }))

    expect(await screen.findByRole('heading', { level: 1, name: '言い換え・スキップ語' })).toBeInTheDocument()
    await waitFor(() => expect(document.title).toBe('言い換え・スキップ語 ｜ 宅飲みカクテルデータベース(RC)'))
    expect(screen.getByRole('heading', { level: 2, name: '言い換え辞書' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 2, name: 'スキップ語' })).toBeInTheDocument()
  })

  test('TC-67-02: 言い換えの一覧に、変換前 → 変換後 【ミドル】・note の印・解決した先のパンくず(銘柄なら末尾に銘柄)を出し、解決できない語はそう出す', async () => {
    mockScreen({
      paraphrases: [
        paraphrase({
          id: 1,
          name: 'ジン(ドライ)',
          converted: 'ジン',
          middle: 'ドライジン',
          note: 'ドライと書かれたジン',
          resolved: { path: [LIQUOR, GIN, DRY_GIN], brand: null },
        }),
        paraphrase({
          id: 2,
          name: 'ビーフィーター',
          converted: 'ビーフィーター 40度',
          resolved: {
            path: [LIQUOR, GIN, DRY_GIN],
            brand: { id: 40, name: 'ビーフィーター 40度', name_eng: 'Beefeater 40' },
          },
        }),
        paraphrase({ id: 3, name: '古い語', converted: '消えたノード', resolved: null }),
      ],
    })
    renderScreen()

    const items = await within(await screen.findByRole('region', { name: '言い換えの一覧' })).findAllByRole('listitem')
    expect(items.map((item) => item.textContent)).toEqual([
      'ジン(ドライ) → ジン 【ドライジン】 蒸留酒 / ジン / ドライジン',
      'ビーフィーター → ビーフィーター 40度蒸留酒 / ジン / ドライジン / ビーフィーター 40度',
      '古い語 → 消えたノード解決できません(この名前のノード・銘柄がありません)',
    ])
    expect(within(items[0]).getByRole('img', { name: 'note あり' })).toBeInTheDocument()
    expect(within(items[1]).queryByRole('img')).not.toBeInTheDocument()
  })

  test('TC-67-03: 言い換えの一覧の絞り込みに入れた語を、q で API に渡す', async () => {
    mockScreen({ paraphrases: [paraphrase({ id: 1, name: 'ジン' })] })
    renderScreen()
    await within(await screen.findByRole('region', { name: '言い換えの一覧' })).findByRole('button', { name: 'ジン' })

    typeInto(within(paraphraseSection()).getByRole('searchbox', { name: '絞り込み' }), 'ドライ')

    await waitFor(() =>
      expect(sent('GET', '/admin/paraphrases').map((request) => new URL(request.url).searchParams.get('q'))).toContain(
        'ドライ',
      ),
    )
  })

  test('TC-67-04: スキップ語の一覧を API の順に出し、絞り込みの欄は出さない', async () => {
    mockScreen({
      skipWords: [
        { id: 2, name: '(適量)' },
        { id: 1, name: 'お好みで' },
      ],
    })
    renderScreen()

    const section = await screen.findByRole('region', { name: 'スキップ語' })
    const items = await within(screen.getByRole('region', { name: 'スキップ語の一覧' })).findAllByRole('listitem')
    expect(items.map((item) => item.textContent)).toEqual(['(適量)', 'お好みで'])
    expect(within(section).queryByRole('searchbox')).not.toBeInTheDocument()
  })
})

describe('言い換えを木から選ぶ', () => {
  test('TC-67-05: 根のすぐ下のノードを選ぶと、変換後にその名前が入り、ミドルは空になる', async () => {
    mockScreen()
    mockApi('get', '/categories/{category}', 200, { data: nodeDetail([LIQUOR, VODKA], []) })
    renderScreen()
    await waitForTree()

    fireEvent.click(screen.getByRole('radio', { name: 'ウォッカ' }))

    expect(within(paraphraseForm()).getByRole('textbox', { name: '変換後' })).toHaveValue('ウォッカ')
    expect(within(paraphraseForm()).getByRole('textbox', { name: 'ミドル' })).toHaveValue('')
  })

  test('TC-67-06: それより深いノードを選ぶと、変換後に根のすぐ下の祖先、ミドルにそのノードの名前が入る', async () => {
    mockScreen()
    mockApi('get', '/categories/{category}', 200, { data: nodeDetail([LIQUOR, GIN, DRY_GIN], []) })
    renderScreen()
    await waitForTree()

    fireEvent.click(screen.getByRole('button', { name: 'ジンを開く' }))
    fireEvent.click(screen.getByRole('radio', { name: 'ドライジン' }))

    expect(within(paraphraseForm()).getByRole('textbox', { name: '変換後' })).toHaveValue('ジン')
    expect(within(paraphraseForm()).getByRole('textbox', { name: 'ミドル' })).toHaveValue('ドライジン')
  })

  test('TC-67-07: ノードを選ぶと配下の銘柄が出て、銘柄を選ぶと変換後に銘柄の名前が入り、ミドルは空になる', async () => {
    mockScreen()
    mockApi('get', '/categories/{category}', 200, { data: nodeDetail([LIQUOR, GIN], [BEEFEATER]) })
    renderScreen()
    await waitForTree()

    fireEvent.click(screen.getByRole('button', { name: 'ジンを開く' }))
    fireEvent.click(screen.getByRole('radio', { name: 'ドライジン' }))
    fireEvent.click(screen.getByRole('radio', { name: 'ジン' }))
    const brands = await screen.findByRole('group', { name: '選んだノードの配下の銘柄' })
    fireEvent.click(within(brands).getByRole('radio', { name: 'ビーフィーター 40度' }))

    expect(within(paraphraseForm()).getByRole('textbox', { name: '変換後' })).toHaveValue('ビーフィーター 40度')
    expect(within(paraphraseForm()).getByRole('textbox', { name: 'ミドル' })).toHaveValue('')
    expect(sent('GET', '/categories/2')).toHaveLength(1)
  })

  test('TC-67-08: 根(旧の種別)は選べない(ラジオボタンを出さず、見出しにする)', async () => {
    mockScreen()
    renderScreen()
    await waitForTree()

    expect(screen.queryByRole('radio', { name: '蒸留酒' })).not.toBeInTheDocument()
    expect(screen.queryByRole('radio', { name: 'その他' })).not.toBeInTheDocument()
    expect(screen.getByText('蒸留酒')).toBeInTheDocument()
  })

  test('TC-67-09: 木から選んだ後に変換後を手で書き換えると、木の選択が外れる', async () => {
    mockScreen()
    mockApi('get', '/categories/{category}', 200, { data: nodeDetail([LIQUOR, VODKA], []) })
    renderScreen()
    await waitForTree()
    fireEvent.click(screen.getByRole('radio', { name: 'ウォッカ' }))

    typeInto(within(paraphraseForm()).getByRole('textbox', { name: '変換後' }), 'ウォッカ(手入力)')

    expect(screen.getByRole('radio', { name: 'ウォッカ' })).not.toBeChecked()
    expect(screen.queryByRole('group', { name: '選んだノードの配下の銘柄' })).not.toBeInTheDocument()
  })

  test('TC-67-29: 木の絞り込みの欄で Enter を押しても、フォームを送らない(Enter の既定の動きを止める)', async () => {
    mockScreen()
    renderScreen()
    await waitForTree()

    const search = within(paraphraseForm()).getByRole('searchbox', { name: '名前で絞り込む' })
    typeInto(search, 'ジン')

    expect(fireEvent.keyDown(search, { key: 'Enter' })).toBe(false)
    expect(fireEvent.keyDown(search, { key: 'a' })).toBe(true)
    expect(sent('POST', '/admin/paraphrases')).toHaveLength(0)
  })

  test('TC-67-10: 木の読み込みに失敗したら、その欄にだけ失敗を出し、名前を手で入れて保存できる', async () => {
    mockApi('get', '/admin/me', 200, { id: 1, name: '管理者の太郎' })
    mockApiHandler('get', '/search-form', () => jsonResponse({ message: 'Server Error' }, 500))
    mockApi('get', '/admin/paraphrases', 200, page([]))
    mockApi('get', '/admin/skip-words', 200, { data: [] })
    mockApi('post', '/admin/paraphrases', 200, { data: paraphrase({ id: 9, name: 'ドライ・ジン' }) })
    renderScreen()

    const section = await screen.findByRole('region', { name: '言い換え辞書' })
    expect(await within(section).findByRole('alert')).toHaveTextContent('データを読み込めませんでした(エラー 500)')
    typeInto(within(paraphraseForm()).getByRole('textbox', { name: '変換前' }), 'ドライ・ジン')
    typeInto(within(paraphraseForm()).getByRole('textbox', { name: '変換後' }), 'ジン')
    fireEvent.click(within(paraphraseForm()).getByRole('button', { name: '保存する' }))

    expect(await within(paraphraseForm()).findByText('「ドライ・ジン」を保存しました')).toHaveAttribute(
      'role',
      'status',
    )
  })
})

describe('言い換えを保存・削除する', () => {
  test('TC-67-11: 新しい言い換えを保存すると、空の欄を null にして POST し、フォームを空に戻し、知らせを出して一覧を読み直す', async () => {
    mockScreen()
    mockApi('get', '/categories/{category}', 200, { data: nodeDetail([LIQUOR, GIN, DRY_GIN], []) })
    const saved = paraphrase({
      id: 9,
      name: 'ドライ・ジン',
      converted: 'ジン',
      middle: 'ドライジン',
      command: 'hot',
      note: 'メモ',
      resolved: { path: [LIQUOR, GIN, DRY_GIN], brand: null },
    })
    mockApi('post', '/admin/paraphrases', 200, { data: saved })
    renderScreen()
    await waitForTree()

    const form = paraphraseForm()
    typeInto(within(form).getByRole('textbox', { name: '変換前' }), 'ドライ・ジン')
    fireEvent.change(within(form).getByRole('combobox', { name: 'command' }), { target: { value: 'hot' } })
    typeInto(within(form).getByRole('textbox', { name: 'note' }), 'メモ')
    fireEvent.click(screen.getByRole('button', { name: 'ジンを開く' }))
    fireEvent.click(screen.getByRole('radio', { name: 'ドライジン' }))
    // 保存の後の一覧は、足した語を返す
    mockApi('get', '/admin/paraphrases', 200, page([saved]))
    fireEvent.click(within(form).getByRole('button', { name: '保存する' }))

    expect(await within(form).findByText('「ドライ・ジン」を保存しました')).toHaveAttribute('role', 'status')
    const [post] = sent('POST', '/admin/paraphrases')
    expect(await post.json()).toEqual({
      name: 'ドライ・ジン',
      converted: 'ジン',
      middle: 'ドライジン',
      command: 'hot',
      note: 'メモ',
    })
    expect(within(form).getByRole('textbox', { name: '変換前' })).toHaveValue('')
    expect(await within(paraphraseSection()).findByRole('button', { name: 'ドライ・ジン' })).toBeInTheDocument()
  })

  test('TC-67-12: ミドル・command・note を空にして保存すると、どれも null で送る', async () => {
    mockScreen()
    mockApi('post', '/admin/paraphrases', 200, { data: paraphrase({ id: 9, name: 'ジン' }) })
    renderScreen()
    await waitForTree()

    typeInto(within(paraphraseForm()).getByRole('textbox', { name: '変換前' }), 'じん')
    typeInto(within(paraphraseForm()).getByRole('textbox', { name: '変換後' }), 'ジン')
    typeInto(within(paraphraseForm()).getByRole('textbox', { name: 'ミドル' }), '  ')
    fireEvent.click(within(paraphraseForm()).getByRole('button', { name: '保存する' }))

    await within(paraphraseForm()).findByText('「ジン」を保存しました')
    const [post] = sent('POST', '/admin/paraphrases')
    expect(await post.json()).toEqual({ name: 'じん', converted: 'ジン', middle: null, command: null, note: null })
  })

  test('TC-67-13: 一覧の行を押すとフォームに読み込み(木では解決した先を選び)、保存すると PUT で書き換える', async () => {
    const stored = paraphrase({
      id: 5,
      name: 'ジン(ドライ)',
      converted: 'ジン',
      middle: 'ドライジン',
      command: 'hot',
      note: '旧のメモ',
      resolved: { path: [LIQUOR, GIN, DRY_GIN], brand: null },
    })
    mockScreen({ paraphrases: [stored] })
    mockApi('get', '/categories/{category}', 200, { data: nodeDetail([LIQUOR, GIN, DRY_GIN], []) })
    mockApi('put', '/admin/paraphrases/{paraphrase}', 200, { data: { ...stored, note: '新しいメモ' } })
    renderScreen()
    await waitForTree()

    fireEvent.click(await within(paraphraseSection()).findByRole('button', { name: 'ジン(ドライ)' }))

    const form = paraphraseForm()
    expect(within(form).getByText('言い換え「ジン(ドライ)」を編集中')).toBeInTheDocument()
    expect(within(form).getByRole('textbox', { name: '変換前' })).toHaveValue('ジン(ドライ)')
    expect(within(form).getByRole('combobox', { name: 'command' })).toHaveValue('hot')
    expect(within(form).getByRole('textbox', { name: 'note' })).toHaveValue('旧のメモ')
    expect(within(form).getByRole('textbox', { name: '変換後' })).toHaveValue('ジン')
    expect(within(form).getByRole('textbox', { name: 'ミドル' })).toHaveValue('ドライジン')
    fireEvent.click(screen.getByRole('button', { name: 'ジンを開く' }))
    expect(screen.getByRole('radio', { name: 'ドライジン' })).toBeChecked()

    typeInto(within(form).getByRole('textbox', { name: 'note' }), '新しいメモ')
    fireEvent.click(within(form).getByRole('button', { name: '保存する' }))

    expect(await within(form).findByText('「ジン(ドライ)」を保存しました')).toHaveAttribute('role', 'status')
    const [put] = sent('PUT', '/admin/paraphrases/5')
    expect(await put.json()).toEqual({
      name: 'ジン(ドライ)',
      converted: 'ジン',
      middle: 'ドライジン',
      command: 'hot',
      note: '新しいメモ',
    })
  })

  test('TC-67-14: 保存が 422 なら、誤りを欄ごとに出し(欄の説明に結び付け)、入力を残して編集を続ける', async () => {
    mockScreen()
    mockApiHandler('post', '/admin/paraphrases', () =>
      jsonResponse(
        {
          message: 'The given data was invalid.',
          errors: {
            name: ['変換前はすでに使われています。'],
            middle: ['変換後のノードの配下に、この名前のノードがありません。'],
          },
        },
        422,
      ),
    )
    renderScreen()
    await waitForTree()

    const form = paraphraseForm()
    typeInto(within(form).getByRole('textbox', { name: '変換前' }), 'ジン')
    typeInto(within(form).getByRole('textbox', { name: '変換後' }), 'ジン')
    typeInto(within(form).getByRole('textbox', { name: 'ミドル' }), '無いノード')
    fireEvent.click(within(form).getByRole('button', { name: '保存する' }))

    expect(await within(form).findByRole('alert')).toHaveTextContent('入力に誤りがあります。')
    const name = within(form).getByRole('textbox', { name: '変換前' })
    expect(name).toHaveAttribute('aria-invalid', 'true')
    expect(name).toHaveAccessibleDescription('変換前はすでに使われています。')
    const middle = within(form).getByRole('textbox', { name: 'ミドル' })
    expect(middle).toHaveAttribute('aria-invalid', 'true')
    expect(middle).toHaveAccessibleDescription(
      '変換後のノードの配下のノードの名前(無ければ空) 変換後のノードの配下に、この名前のノードがありません。',
    )
    expect(within(form).getByRole('textbox', { name: '変換後' })).toHaveAttribute('aria-invalid', 'false')
    expect(middle).toHaveValue('無いノード')
  })

  test.each([
    ['500', () => jsonResponse({ message: 'Server Error' }, 500), '保存できませんでした(エラー 500)。'],
    ['通信の失敗', () => Promise.reject(new TypeError('Failed to fetch')), '通信に失敗して保存できませんでした。'],
  ])('TC-67-15: 保存が %s なら、何が起きたかを出し、入力を残す', async (_, respond, text) => {
    mockScreen()
    mockApiHandler('post', '/admin/paraphrases', respond)
    renderScreen()
    await waitForTree()

    typeInto(within(paraphraseForm()).getByRole('textbox', { name: '変換前' }), 'ジン')
    typeInto(within(paraphraseForm()).getByRole('textbox', { name: '変換後' }), 'ジン')
    fireEvent.click(within(paraphraseForm()).getByRole('button', { name: '保存する' }))

    expect(await within(paraphraseForm()).findByRole('alert')).toHaveTextContent(text)
    expect(within(paraphraseForm()).getByRole('textbox', { name: '変換前' })).toHaveValue('ジン')
  })

  test('TC-67-16: 削除は確かめてから消す。取り消せば送らず、確かめたら DELETE して一覧を読み直し、知らせを出す', async () => {
    const stored = paraphrase({ id: 5, name: 'ジン(ドライ)' })
    mockScreen({ paraphrases: [stored] })
    mockApiHandler('delete', '/admin/paraphrases/{paraphrase}', () => new Response(null, { status: 204 }))
    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true)
    renderScreen()
    await waitForTree()
    fireEvent.click(await within(paraphraseSection()).findByRole('button', { name: 'ジン(ドライ)' }))

    fireEvent.click(within(paraphraseForm()).getByRole('button', { name: '削除する' }))
    expect(confirm).toHaveBeenLastCalledWith('言い換え「ジン(ドライ)」を消します。よろしいですか?')
    expect(sent('DELETE', '/admin/paraphrases/5')).toHaveLength(0)

    mockApi('get', '/admin/paraphrases', 200, page([]))
    fireEvent.click(within(paraphraseForm()).getByRole('button', { name: '削除する' }))

    expect(await within(paraphraseForm()).findByText('「ジン(ドライ)」を消しました')).toHaveAttribute('role', 'status')
    expect(sent('DELETE', '/admin/paraphrases/5')).toHaveLength(1)
    expect(await within(paraphraseSection()).findByText('該当するものがありません')).toBeInTheDocument()
    expect(within(paraphraseForm()).queryByRole('button', { name: '削除する' })).not.toBeInTheDocument()
  })

  test('TC-67-17: 削除が失敗したら(404 など)何が起きたかを出し、編集中のまま残る', async () => {
    mockScreen({ paraphrases: [paraphrase({ id: 5, name: 'ジン(ドライ)' })] })
    mockApiHandler('delete', '/admin/paraphrases/{paraphrase}', () => jsonResponse({ message: 'Not Found' }, 404))
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    renderScreen()
    await waitForTree()
    fireEvent.click(await within(paraphraseSection()).findByRole('button', { name: 'ジン(ドライ)' }))

    fireEvent.click(within(paraphraseForm()).getByRole('button', { name: '削除する' }))

    expect(await within(paraphraseForm()).findByRole('alert')).toHaveTextContent('削除する語が見つかりませんでした。')
    expect(within(paraphraseForm()).getByText('言い換え「ジン(ドライ)」を編集中')).toBeInTheDocument()
  })
})

describe('スキップ語', () => {
  test('TC-67-18: スキップ語を足すと POST し、知らせを出して一覧を読み直す', async () => {
    mockScreen()
    mockApi('post', '/admin/skip-words', 200, { data: { id: 3, name: 'お好みで' } })
    renderScreen()
    await within(await screen.findByRole('region', { name: 'スキップ語' })).findByText('該当するものがありません')

    typeInto(within(skipWordForm()).getByRole('textbox', { name: 'スキップ語' }), 'お好みで')
    mockApi('get', '/admin/skip-words', 200, { data: [{ id: 3, name: 'お好みで' }] })
    fireEvent.click(within(skipWordForm()).getByRole('button', { name: '保存する' }))

    expect(await within(skipWordForm()).findByText('「お好みで」を保存しました')).toHaveAttribute('role', 'status')
    const [post] = sent('POST', '/admin/skip-words')
    expect(await post.json()).toEqual({ name: 'お好みで' })
    expect(await within(skipWordSection()).findByRole('button', { name: 'お好みで' })).toBeInTheDocument()
    expect(within(skipWordForm()).getByRole('textbox', { name: 'スキップ語' })).toHaveValue('')
  })

  test('TC-67-19: 一覧の行を押すとフォームに読み込み、保存すると PUT で書き換える', async () => {
    mockScreen({ skipWords: [{ id: 1, name: '(適量)' }] })
    mockApi('put', '/admin/skip-words/{skipWord}', 200, { data: { id: 1, name: '(適宜)' } })
    renderScreen()

    fireEvent.click(
      await within(await screen.findByRole('region', { name: 'スキップ語' })).findByRole('button', { name: '(適量)' }),
    )
    expect(within(skipWordForm()).getByText('スキップ語「(適量)」を編集中')).toBeInTheDocument()
    typeInto(within(skipWordForm()).getByRole('textbox', { name: 'スキップ語' }), '(適宜)')
    fireEvent.click(within(skipWordForm()).getByRole('button', { name: '保存する' }))

    expect(await within(skipWordForm()).findByText('「(適宜)」を保存しました')).toHaveAttribute('role', 'status')
    const [put] = sent('PUT', '/admin/skip-words/1')
    expect(await put.json()).toEqual({ name: '(適宜)' })
  })

  test('TC-67-20: 保存が 422 なら欄に誤りを出し、入力を残す', async () => {
    mockScreen()
    mockApiHandler('post', '/admin/skip-words', () =>
      jsonResponse({ message: 'invalid', errors: { name: ['スキップ語はすでに使われています。'] } }, 422),
    )
    renderScreen()
    await screen.findByRole('region', { name: 'スキップ語' })

    typeInto(within(skipWordForm()).getByRole('textbox', { name: 'スキップ語' }), 'お好みで')
    fireEvent.click(within(skipWordForm()).getByRole('button', { name: '保存する' }))

    expect(await within(skipWordForm()).findByRole('alert')).toHaveTextContent('入力に誤りがあります。')
    const field = within(skipWordForm()).getByRole('textbox', { name: 'スキップ語' })
    expect(field).toHaveAccessibleDescription('スキップ語はすでに使われています。')
    expect(field).toHaveValue('お好みで')
  })

  test('TC-67-21: 削除は確かめてから消す。取り消せば送らず、確かめたら DELETE して一覧から消える', async () => {
    mockScreen({ skipWords: [{ id: 1, name: '(適量)' }] })
    mockApiHandler('delete', '/admin/skip-words/{skipWord}', () => new Response(null, { status: 204 }))
    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true)
    renderScreen()
    fireEvent.click(
      await within(await screen.findByRole('region', { name: 'スキップ語' })).findByRole('button', { name: '(適量)' }),
    )

    fireEvent.click(within(skipWordForm()).getByRole('button', { name: '削除する' }))
    expect(confirm).toHaveBeenLastCalledWith('スキップ語「(適量)」を消します。よろしいですか?')
    expect(sent('DELETE', '/admin/skip-words/1')).toHaveLength(0)

    mockApi('get', '/admin/skip-words', 200, { data: [] })
    fireEvent.click(within(skipWordForm()).getByRole('button', { name: '削除する' }))

    expect(await within(skipWordForm()).findByText('「(適量)」を消しました')).toHaveAttribute('role', 'status')
    expect(sent('DELETE', '/admin/skip-words/1')).toHaveLength(1)
    expect(await within(skipWordSection()).findByText('該当するものがありません')).toBeInTheDocument()
  })
})
