import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { describe, expect, test } from 'vitest'
import Top from './Top'
import type { paths } from '../api/schema'
import { buildCocktailRow } from '../components/CocktailTable/buildCocktailRow'
import { apiRequests, jsonResponse, mockApi, mockApiHandler } from '../test/api'

type ListResponse = paths['/cocktails']['get']['responses'][200]['content']['application/json']
type ListItem = ListResponse['data'][number]
type LatestVideoResponse = paths['/cocktails/latest-video']['get']['responses'][200]['content']['application/json']

const RECENT = '★最近更新されたカクテル'
const RECOMMEND = '★おすすめカクテル'
const RANDOM = '★気まぐれセレクト'
const REGET = '再取得'
const RETRY = 'もう一度読み込む'

/** 一覧の API の 1 行。表の行(CocktailSummaryResource)に、技法の英名を足した形 */
const item = (id: number, name: string): ListItem => {
  const row = buildCocktailRow({ id, name })
  return { ...row, method: { ...row.method, name_eng: 'Stir' } }
}

/** 7 件の一覧。名前の頭に一覧の名前を付けて、どの一覧の行かを分ける */
const sevenItems = (prefix: string, firstId: number): ListItem[] =>
  Array.from({ length: 7 }, (_, index) => item(firstId + index, `${prefix}${index + 1}`))

const LATEST_VIDEO: LatestVideoResponse = {
  data: {
    id: 42,
    name: 'モスコミュール',
    name_eng: 'Moscow Mule',
    youtube: 'x9gaP5Q8lc4',
    youtube_created_at: '2024-05-06T07:08:09.000000Z',
    base: { id: 5, name: 'ウォッカ', name_eng: 'Vodka' },
    description: [
      { type: 'text', text: '銅のマグで飲む' },
      { type: 'node', id: 9, text: 'ジンジャーエール', has_youtube: false },
      { type: 'text', text: 'のカクテル' },
    ],
  },
}

/** 3 つの一覧の API を、並べ方ごとに 7 件で返すよう登録する */
const mockLists = () => {
  mockApiHandler('get', '/cocktails', (request) => {
    const sort = new URL(request.url).searchParams.get('sort')
    const body: ListResponse = {
      data: sort === 'recent' ? sevenItems('最近', 100) : sort === 'recommend' ? sevenItems('おすすめ', 200) : sevenItems('気まぐれ', 300),
    }
    return jsonResponse(body, 200)
  })
}

/** 検索フォームの置き場所に仮に置いた材料の欄(#305)が呼ぶ API。この画面のテストでは中身を見ないので空の木にする */
const mockSearchForm = () => mockApi('get', '/search-form', 200, { categories: [], methods: [] })

const renderTop = () =>
  render(
    <MemoryRouter>
      <Top />
    </MemoryRouter>,
  )

/** 送ったリクエストの、パスとクエリ(/api から後ろ) */
const sentUrls = () =>
  apiRequests().map((request) => {
    const url = new URL(request.url)
    return `${url.pathname.replace(/^\/api/, '')}${url.search}`
  })

/** 見出しの名前で一覧の欄を取る */
const listSection = (title: string) => screen.getByRole('region', { name: title })

/** 一覧の表の体の行の、名前の列の文字を上から順に */
const tableNames = (title: string) =>
  within(within(listSection(title)).getByRole('table'))
    .getAllByRole('row')
    .slice(1)
    .map((tr) => within(tr).getAllByRole('cell')[1].textContent)

/** 応答を、テストから返すまで止めておく */
const deferred = () => {
  let resolve: (response: Response) => void = () => {}
  const promise = new Promise<Response>((r) => {
    resolve = r
  })
  return { promise, resolve }
}

describe('トップページの見出し', () => {
  test('TC-50-01: カクテルのアイコンと「宅飲みカクテルデータベース」の見出し、副題を出し、タイトルは「トップページ」', async () => {
    mockApi('get', '/cocktails/latest-video', 200, LATEST_VIDEO)
    mockLists()
    mockSearchForm()

    renderTop()

    const heading = screen.getByRole('heading', { level: 1, name: '宅飲みカクテルデータベース' })
    expect(heading.querySelector('svg[data-icon="cocktail"]')).not.toBeNull()
    expect(screen.getByText('―宅飲み指向データベース。新たな出会いをあなたに。')).toBeInTheDocument()
    expect(document.title).toBe('トップページ ｜ 宅飲みカクテルデータベース(RC)')
    // 欄の中身が出るまで待ってから終える(後から届く応答で、終わった画面を書き換えない)
    await screen.findByRole('region', { name: '新作動画' })
  })
})

describe('トップページの新着動画', () => {
  test('TC-50-02: 新着動画の埋め込み(開始位置なし)・カクテルとベースへのリンク・説明文を出す', async () => {
    mockApi('get', '/cocktails/latest-video', 200, LATEST_VIDEO)
    mockLists()
    mockSearchForm()

    renderTop()

    const video = await screen.findByRole('region', { name: '新作動画' })
    expect(within(video).getByTitle('YouTube の動画')).toHaveAttribute('src', 'https://www.youtube.com/embed/x9gaP5Q8lc4')
    expect(within(video).getByRole('heading', { level: 2 })).toHaveTextContent('新作動画：モスコミュール(Moscow Mule)')
    expect(within(video).getByRole('link', { name: 'モスコミュール(Moscow Mule)' })).toHaveAttribute(
      'href',
      '/detail/cocktail/42',
    )
    expect(within(video).getByText('ベース：', { exact: false })).toHaveTextContent('ベース：ウォッカ(Vodka)')
    expect(within(video).getByRole('link', { name: 'ウォッカ(Vodka)' })).toHaveAttribute('href', '/category/5')
    expect(within(video).getByRole('link', { name: 'ジンジャーエール' })).toHaveAttribute('href', '/category/9')
    expect(video).toHaveTextContent('銅のマグで飲むジンジャーエールのカクテル')
  })

  test('TC-50-03: 英名が無いカクテルは、旧と同じく「名前()」と出す', async () => {
    mockApi('get', '/cocktails/latest-video', 200, { data: { ...LATEST_VIDEO.data, name_eng: null } })
    mockLists()
    mockSearchForm()

    renderTop()

    const video = await screen.findByRole('region', { name: '新作動画' })
    expect(within(video).getByRole('link', { name: 'モスコミュール()' })).toBeInTheDocument()
  })

  test('TC-50-04: 動画のあるカクテルが無い(404)ときは、新着動画の欄を出さず、メニューと一覧は出す', async () => {
    mockApiHandler('get', '/cocktails/latest-video', () => jsonResponse({ message: 'Not Found' }, 404))
    mockLists()
    mockSearchForm()

    renderTop()

    expect(await screen.findByRole('link', { name: '最近1' })).toBeInTheDocument()
    expect(screen.queryByRole('region', { name: '新作動画' })).not.toBeInTheDocument()
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 2, name: 'Menu' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 2, name: 'What is this!?' })).toBeInTheDocument()
  })

  test('TC-50-05: 新着動画の応答を待つあいだは読み込み中を出し、API がエラーを返したらエラーの表示を出し、もう一度読み込むと出す', async () => {
    const first = deferred()
    let count = 0
    mockApiHandler('get', '/cocktails/latest-video', () =>
      count++ === 0 ? first.promise : jsonResponse(LATEST_VIDEO, 200),
    )
    mockLists()
    mockSearchForm()

    renderTop()

    expect(screen.getByRole('status')).toHaveTextContent('読み込み中…')
    // 500 の本文はスキーマに無いので型を当てない
    await act(async () => first.resolve(jsonResponse({ message: 'Server Error' }, 500)))
    expect(screen.getByRole('alert')).toHaveTextContent('データを読み込めませんでした(エラー 500)')

    fireEvent.click(screen.getByRole('button', { name: RETRY }))

    expect(await screen.findByRole('region', { name: '新作動画' })).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })
})

describe('トップページのメニュー', () => {
  test('TC-50-06: 「Menu」に、カクテル検索(ページ内)・カクテル動画リスト(新しいタブ)・用語辞典へのリンクと説明を、旧と同じ文言で出す', async () => {
    mockApi('get', '/cocktails/latest-video', 200, LATEST_VIDEO)
    mockLists()
    mockSearchForm()

    const { container } = renderTop()

    const menu = screen.getByRole('region', { name: 'Menu' })
    const search = within(menu).getByRole('link', { name: 'カクテル検索' })
    expect(search).toHaveAttribute('href', '#cocktailform_anchor')
    // ページ内リンクの飛び先が、同じ画面にある
    expect(container.querySelector('#cocktailform_anchor')).not.toBeNull()
    const videos = within(menu).getByRole('link', { name: 'カクテル動画リスト' })
    expect(videos).toHaveAttribute('href', 'https://www.youtube.com/channel/UCJBSxULGc7KiQfjo85qoG7g')
    expect(videos).toHaveAttribute('target', '_blank')
    expect(within(menu).getByRole('link', { name: '用語辞典' })).toHaveAttribute('href', '/dictionary')
    expect(menu).toHaveTextContent(
      [
        '・カクテル検索',
        '具体的な条件を指定してカクテルが検索できます。',
        '名前から検索する時は上部のフォームよりどうぞ。',
        '・カクテル動画リスト',
        'カクテルのメイキング＆感想動画のリスト(youtubeに飛びます)',
        '・用語辞典',
        '素人解説の辞典です。分からない単語があれば。',
      ].join(''),
    )
    await screen.findByRole('region', { name: '新作動画' })
  })

  test('TC-50-07: 「What is this!?」に、旧と同じサイトの紹介文と作者のサイトへのリンク(新しいタブ)を出す', async () => {
    mockApi('get', '/cocktails/latest-video', 200, LATEST_VIDEO)
    mockLists()
    mockSearchForm()

    renderTop()

    const about = screen.getByRole('region', { name: 'What is this!?' })
    const author = within(about).getByRole('link', { name: '私(ろんぐ)' })
    expect(author).toHaveAttribute('href', 'http://trc-vlackhistory.net')
    expect(author).toHaveAttribute('target', '_blank')
    expect(about).toHaveTextContent(
      [
        '私(ろんぐ)が、個人的に作ったカクテルのデータベースです。',
        '「今手元にあるお酒で何が作れるか」「聞いたことのないカクテルとの出会い」に重点を置いています。',
        '私自身が作って飲んだカクテルに関しては、私が忘れるので評価とコメントを残しています。',
        '私がおすすめするカクテルを試してみるも良し！地雷臭のするカクテルに挑戦しても良し！',
        '宅飲みの際の話題のネタにも。カクテルは意外と簡単に作れるものです！ビールだけじゃ勿体無い！',
        'さあ、あなたも是非おうちでカクテル、作りませんか！？',
        'まずは下のフォーム、もしくは上の検索ボタンから、知ってるカクテルでも探してみましょう！',
      ].join(''),
    )
    await screen.findByRole('region', { name: '新作動画' })
  })
})

describe('トップページの 3 つの一覧', () => {
  test('TC-50-08: 最近更新された・おすすめ・気まぐれの一覧を、それぞれの並べ方で 7 件取り、API の順で表に出す', async () => {
    mockApi('get', '/cocktails/latest-video', 200, LATEST_VIDEO)
    mockLists()
    mockSearchForm()

    renderTop()

    expect(await screen.findByRole('link', { name: '気まぐれ7' })).toBeInTheDocument()
    expect(await screen.findByRole('link', { name: 'おすすめ7' })).toBeInTheDocument()
    expect(await screen.findByRole('link', { name: '最近7' })).toBeInTheDocument()
    expect(tableNames(RECENT)).toEqual(['最近1', '最近2', '最近3', '最近4', '最近5', '最近6', '最近7'])
    expect(tableNames(RECOMMEND)).toHaveLength(7)
    expect(tableNames(RECOMMEND)[0]).toBe('おすすめ1')
    expect(tableNames(RANDOM)).toHaveLength(7)
    expect(tableNames(RANDOM)[0]).toBe('気まぐれ1')
    expect(sentUrls()).toEqual(
      expect.arrayContaining([
        '/cocktails?sort=recent&limit=7',
        '/cocktails?sort=recommend&limit=7',
        '/cocktails?sort=random&limit=7',
      ]),
    )
  })

  test('TC-50-09: 「再取得」は、おすすめと気まぐれにだけ出し、最近更新されたには出さない', async () => {
    mockApi('get', '/cocktails/latest-video', 200, LATEST_VIDEO)
    mockLists()
    mockSearchForm()

    renderTop()
    await screen.findByRole('link', { name: '最近1' })

    expect(within(listSection(RECENT)).queryByRole('button', { name: REGET })).not.toBeInTheDocument()
    expect(within(listSection(RECOMMEND)).getByRole('button', { name: REGET })).toBeInTheDocument()
    expect(within(listSection(RANDOM)).getByRole('button', { name: REGET })).toBeInTheDocument()
  })

  test.each([
    ['TC-50-10', RECOMMEND, 'recommend', 'おすすめ'],
    ['TC-50-11', RANDOM, 'random', '気まぐれ'],
  ] as const)(
    '%s: 「%s」の「再取得」を押すと、その一覧だけを取り直し、届くまでは前の一覧を出したままにする',
    async (_id, title, sort, prefix) => {
      const retried = deferred()
      let count = 0
      mockApi('get', '/cocktails/latest-video', 200, LATEST_VIDEO)
      mockApiHandler('get', '/cocktails', (request) => {
        const requested = new URL(request.url).searchParams.get('sort')
        if (requested !== sort) {
          return jsonResponse({ data: sevenItems('ほか', 900) } satisfies ListResponse, 200)
        }
        return count++ === 0
          ? jsonResponse({ data: sevenItems(prefix, 100) } satisfies ListResponse, 200)
          : retried.promise
      })
      mockSearchForm()
      renderTop()
      expect(await screen.findByRole('link', { name: `${prefix}1` })).toBeInTheDocument()
      const before = apiRequests().length

      fireEvent.click(within(listSection(title)).getByRole('button', { name: REGET }))

      expect(tableNames(title)[0]).toBe(`${prefix}1`)
      await act(async () =>
        retried.resolve(jsonResponse({ data: sevenItems('新しい', 500) } satisfies ListResponse, 200)),
      )
      expect(tableNames(title)).toEqual(['新しい1', '新しい2', '新しい3', '新しい4', '新しい5', '新しい6', '新しい7'])
      // 押した一覧の API だけを呼び直す
      expect(sentUrls().slice(before)).toEqual([`/cocktails?sort=${sort}&limit=7`])
    },
  )

  test('TC-50-12: 一覧の API がエラーを返したら、その一覧にエラーの表示を出し、ほかの欄は出す', async () => {
    mockApi('get', '/cocktails/latest-video', 200, LATEST_VIDEO)
    mockApiHandler('get', '/cocktails', (request) =>
      new URL(request.url).searchParams.get('sort') === 'random'
        ? jsonResponse({ message: 'Server Error' }, 500)
        : jsonResponse({ data: sevenItems('ほか', 100) } satisfies ListResponse, 200),
    )
    mockSearchForm()

    renderTop()

    expect(await within(listSection(RANDOM)).findByRole('alert')).toHaveTextContent('データを読み込めませんでした(エラー 500)')
    expect(within(listSection(RANDOM)).queryByRole('table')).not.toBeInTheDocument()
    expect(await within(listSection(RECENT)).findByRole('table')).toBeInTheDocument()
    expect(await screen.findByRole('region', { name: '新作動画' })).toBeInTheDocument()
  })
})
