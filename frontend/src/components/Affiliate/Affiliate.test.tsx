import { render, screen, waitFor, within } from '@testing-library/react'
import type { ReactElement } from 'react'
import { MemoryRouter } from 'react-router'
import { describe, expect, test } from 'vitest'
import type { components } from '../../api/schema'
import { apiRequests, jsonResponse, mockApi, mockApiHandler } from '../../test/api'
import Affiliate from './Affiliate'

type AffiliateResource = components['schemas']['AffiliateResource']

const SEARCH_LINK = '⇒このアイテムが使われているカクテルを探す'
const AMAZON_DISCLAIMER =
  '価格および発送可能時期は表示された日付/時刻の時点のものであり、変更される場合があります。本商品の購入においては、購入の時点でAmazon.co.jpに表示されている価格および発送可能時期の情報が適用されます。'
const RAKUTEN_DISCLAIMER =
  'このサイトで掲載されている情報は、宅飲みカクテルデータベースの作成者により運営されています。価格、販売可能情報は、変更される場合があります。購入時に楽天市場店舗（www.rakuten.co.jp）に表示されている価格が、その商品の販売に適用されます。'

/** 取った時刻(UTC)。日本時間では 2026年10月27日 14:11 */
const AMAZON_FETCHED_AT = '2026-10-27T05:11:00+00:00'
/** 日本時間では 2026年10月27日 09:05 */
const RAKUTEN_FETCHED_AT = '2026-10-27T00:05:00+00:00'

const AMAZON_DETAIL = 'https://www.amazon.co.jp/dp/B000000001?tag=example-22'
const AMAZON_SEARCH = 'https://www.amazon.co.jp/s?k=%E3%83%9C%E3%83%B3%E3%83%99%E3%82%A4&tag=example-22'
const RAKUTEN_DETAIL = 'https://hb.afl.rakuten.co.jp/hgc/example/?pc=https%3A%2F%2Fitem.rakuten.co.jp%2Fshop%2Fitem%2F'
const RAKUTEN_SEARCH = 'https://hb.afl.rakuten.co.jp/hgc/example/?pc=https%3A%2F%2Fsearch.rakuten.co.jp%2Fsearch%2Fmall%2F'
const HUMAN_DETAIL = 'https://www.amazon.co.jp/dp/B000000099?tag=example-22'

/** Amazon・楽天の両方が期限内で、手で入れた値が無い行(API の応答の data) */
const freshBoth = (): AffiliateResource => ({
  category_id: 12,
  brand_id: 345,
  name: 'ボンベイ・サファイア',
  display: {
    source: 'amazon',
    title: 'ボンベイ・サファイア 700ml(Amazon)',
    price: 3200,
    image_url: 'https://m.media-amazon.com/images/I/amazon.jpg',
    detail_url: AMAZON_DETAIL,
    fetched_at: AMAZON_FETCHED_AT,
  },
  amazon: {
    title: 'ボンベイ・サファイア 700ml(Amazon)',
    price: 3200,
    image_url: 'https://m.media-amazon.com/images/I/amazon.jpg',
    detail_url: AMAZON_DETAIL,
    search_url: AMAZON_SEARCH,
    fetched_at: AMAZON_FETCHED_AT,
  },
  rakuten: {
    title: 'ボンベイ・サファイア 700ml(楽天)',
    price: 3000,
    image_url: 'https://thumbnail.image.rakuten.co.jp/rakuten.jpg',
    detail_url: RAKUTEN_DETAIL,
    search_url: RAKUTEN_SEARCH,
    fetched_at: RAKUTEN_FETCHED_AT,
  },
  human: { title: null, price: null, image_url: null, amazon_detail_url: null, amazon_search_url: null },
})

const EXPIRED_AMAZON: AffiliateResource['amazon'] = {
  title: null,
  price: null,
  image_url: null,
  detail_url: null,
  search_url: AMAZON_SEARCH,
  fetched_at: null,
}
const EXPIRED_RAKUTEN: AffiliateResource['rakuten'] = {
  title: null,
  price: null,
  image_url: null,
  detail_url: null,
  search_url: RAKUTEN_SEARCH,
  fetched_at: null,
}
const NOT_FOUND_AMAZON: AffiliateResource['amazon'] = { ...EXPIRED_AMAZON, search_url: null }
const NOT_FOUND_RAKUTEN: AffiliateResource['rakuten'] = { ...EXPIRED_RAKUTEN, search_url: null }

/** 楽天だけが期限内(Amazon は期限切れ)。API の display は楽天になる */
const freshRakutenOnly = (): AffiliateResource => {
  const base = freshBoth()
  return {
    ...base,
    display: {
      source: 'rakuten',
      title: base.rakuten.title,
      price: base.rakuten.price,
      image_url: base.rakuten.image_url,
      detail_url: base.rakuten.detail_url,
      fetched_at: base.rakuten.fetched_at,
    },
    amazon: EXPIRED_AMAZON,
  }
}

/** 手で入れた値がある行。API の display は手で入れた値になる */
const withHuman = (amazonDetail: string | null): AffiliateResource => {
  const base = freshBoth()
  return {
    ...base,
    display: {
      source: 'human',
      title: '手で入れた題名',
      price: 2500,
      image_url: 'https://example.com/human.jpg',
      detail_url: amazonDetail,
      fetched_at: null,
    },
    human: { title: '手で入れた題名', price: 2500, image_url: 'https://example.com/human.jpg', amazon_detail_url: amazonDetail, amazon_search_url: null },
  }
}

const mockAffiliate = (data: AffiliateResource) => mockApi('get', '/affiliates', 200, { data })

// 「このアイテムが使われているカクテルを探す」が画面の中のリンクなので、ルーターの中で描く
const renderAffiliate = (element: ReactElement) => render(<MemoryRouter>{element}</MemoryRouter>)

/** 応答が届いて欄が出るまで待つ */
const findBox = () => screen.findByRole('complementary', { name: '商品' })

/** 題名のリンク(画像のリンクも同じ名前になるので、題名の文字から辿る) */
const titleLink = (box: HTMLElement, title: string) => within(box).getByText(title).closest('a')

/** 送ったリクエストの、パスとクエリ(/api から後ろ) */
const sentUrls = () =>
  apiRequests().map((request) => {
    const url = new URL(request.url)
    return `${url.pathname.replace(/^\/api/, '')}${url.search}`
  })

/** 欄が出ないことを、応答が届いた後で確かめる(届く前は、応答を待つあいだも何も出さないので区別がつかない) */
const expectNothingAfterResponse = async (container: HTMLElement) => {
  await waitFor(() => expect(apiRequests()).toHaveLength(1))
  // 応答を処理し終えるまで待つ
  await new Promise((resolve) => setTimeout(resolve, 0))
  expect(container).toBeEmptyDOMElement()
}

describe('Affiliate の呼ぶ API', () => {
  test('TC-303-01: ノードと銘柄を渡すと、GET /api/affiliates に category_id と brand_id を送る', async () => {
    mockAffiliate(freshBoth())

    renderAffiliate(<Affiliate categoryId={12} brandId={345} />)

    await findBox()
    expect(sentUrls()).toEqual(['/affiliates?category_id=12&brand_id=345'])
  })

  test('TC-303-02: 銘柄を渡さなければ、category_id だけを送る', async () => {
    mockAffiliate(freshBoth())

    renderAffiliate(<Affiliate categoryId={12} />)

    await findBox()
    expect(sentUrls()).toEqual(['/affiliates?category_id=12'])
  })
})

describe('Affiliate の出す条件', () => {
  test('TC-303-03: 商品のページの URL も検索結果のページの URL も 1 つも無ければ、何も出さない', async () => {
    mockAffiliate({ ...freshBoth(), display: null, amazon: NOT_FOUND_AMAZON, rakuten: NOT_FOUND_RAKUTEN })

    const { container } = renderAffiliate(<Affiliate categoryId={12} />)

    await expectNothingAfterResponse(container)
  })

  test('TC-303-04: 商品の行が無い(404)なら、何も出さない', async () => {
    mockApiHandler('get', '/affiliates', () => jsonResponse({ message: 'No query results for model.' }, 404))

    const { container } = renderAffiliate(<Affiliate categoryId={12} />)

    await expectNothingAfterResponse(container)
  })

  test('TC-303-05: API が 500 を返したら、旧と同じく何も出さない', async () => {
    mockApiHandler('get', '/affiliates', () => jsonResponse({ message: 'Server Error' }, 500))

    const { container } = renderAffiliate(<Affiliate categoryId={12} />)

    await expectNothingAfterResponse(container)
  })

  test('TC-303-06: 手で入れた Amazon の商品のページの URL だけがあるときも出す', async () => {
    mockAffiliate({ ...withHuman(HUMAN_DETAIL), amazon: NOT_FOUND_AMAZON, rakuten: NOT_FOUND_RAKUTEN })

    renderAffiliate(<Affiliate categoryId={12} />)

    expect(titleLink(await findBox(), '手で入れた題名')).toHaveAttribute('href', HUMAN_DETAIL)
  })
})

describe('Affiliate の並びと、出す値の優先順位', () => {
  test('TC-303-07: 画像 → 題名 → 価格と取った時刻 → Amazon・楽天のボタン → カクテルを探す の順に並ぶ', async () => {
    mockAffiliate(freshBoth())

    renderAffiliate(<Affiliate categoryId={12} />)

    const box = await findBox()
    const text = box.textContent ?? ''
    const order = ['ボンベイ・サファイア 700ml(Amazon)', '¥3200', '時点', 'Amazonで探す', '楽天で探す', SEARCH_LINK].map((part) =>
      text.indexOf(part),
    )
    expect(within(box).getAllByRole('img')[0]).toHaveAttribute('src', 'https://m.media-amazon.com/images/I/amazon.jpg')
    expect(order.every((index) => index >= 0)).toBe(true)
    expect([...order].sort((a, b) => a - b)).toEqual(order)
  })

  test('TC-303-08: 手で入れた価格があれば、Amazon・楽天より先に手で入れた画像・題名・価格を出し、取った時刻は添えない', async () => {
    mockAffiliate(withHuman(HUMAN_DETAIL))

    renderAffiliate(<Affiliate categoryId={12} />)

    const box = await findBox()
    expect(within(box).getByRole('img')).toHaveAttribute('src', 'https://example.com/human.jpg')
    expect(titleLink(box, '手で入れた題名')).toHaveAttribute('href', HUMAN_DETAIL)
    expect(within(box).getByText('¥2500')).toBeInTheDocument()
    expect(within(box).queryByText('ボンベイ・サファイア 700ml(Amazon)')).not.toBeInTheDocument()
  })

  test('TC-303-09: 手で入れた値に商品のページの URL が無ければ、画像のリンク先は Amazon の商品のページにする(旧と同じ)', async () => {
    mockAffiliate(withHuman(null))

    renderAffiliate(<Affiliate categoryId={12} />)

    const box = await findBox()
    expect(within(box).getByRole('img').closest('a')).toHaveAttribute('href', AMAZON_DETAIL)
  })

  test('TC-303-10: 手で入れた価格が無ければ Amazon の画像・題名・価格を出す', async () => {
    mockAffiliate(freshBoth())

    renderAffiliate(<Affiliate categoryId={12} />)

    const box = await findBox()
    expect(within(box).getByRole('img')).toHaveAttribute('src', 'https://m.media-amazon.com/images/I/amazon.jpg')
    expect(titleLink(box, 'ボンベイ・サファイア 700ml(Amazon)')).toHaveAttribute('href', AMAZON_DETAIL)
    expect(within(box).getByText('¥3200')).toBeInTheDocument()
  })

  test('TC-303-11: Amazon の価格が無ければ楽天の画像・題名・価格を出し、画像のリンク先は楽天の商品のページにする', async () => {
    mockAffiliate(freshRakutenOnly())

    renderAffiliate(<Affiliate categoryId={12} />)

    const box = await findBox()
    const image = within(box).getByRole('img')
    expect(image).toHaveAttribute('src', 'https://thumbnail.image.rakuten.co.jp/rakuten.jpg')
    expect(image.closest('a')).toHaveAttribute('href', RAKUTEN_DETAIL)
    expect(titleLink(box, 'ボンベイ・サファイア 700ml(楽天)')).toHaveAttribute('href', RAKUTEN_DETAIL)
    expect(within(box).getByText('¥3000')).toBeInTheDocument()
  })

  test('TC-303-12: どの価格も無ければ、画像・題名・価格を出さず、ボタンとカクテルを探すだけを出す', async () => {
    mockAffiliate({ ...freshBoth(), display: null, amazon: EXPIRED_AMAZON, rakuten: EXPIRED_RAKUTEN })

    renderAffiliate(<Affiliate categoryId={12} />)

    const box = await findBox()
    expect(within(box).queryByRole('img')).not.toBeInTheDocument()
    expect(within(box).queryByText(/¥/)).not.toBeInTheDocument()
    expect(within(box).getAllByRole('link').map((link) => link.textContent)).toEqual(['Amazonで探す', '楽天で探す', SEARCH_LINK])
  })

  test('TC-303-13: 商品のページへのリンクは別タブで開き、rel に nofollow sponsored を付ける', async () => {
    mockAffiliate(freshBoth())

    renderAffiliate(<Affiliate categoryId={12} />)

    const box = await findBox()
    // 画像・題名・Amazon と楽天のボタン
    const external = within(box)
      .getAllByRole('link')
      .filter((link) => link.textContent !== SEARCH_LINK)
    expect(external).toHaveLength(4)
    for (const link of external) {
      expect(link).toHaveAttribute('target', '_blank')
      expect(link).toHaveAttribute('rel', 'nofollow sponsored')
    }
  })
})

describe('Affiliate の価格・取った時刻・免責の文言', () => {
  test('TC-303-14: 本体の価格の横に、取った時刻を日本時間の日付と時刻で「2026年10月27日 14:11 時点」の形で出す', async () => {
    mockAffiliate(freshBoth())

    renderAffiliate(<Affiliate categoryId={12} />)

    const box = await findBox()
    const snippet = within(box).getByText('¥3200').parentElement!
    expect(snippet).toHaveTextContent('¥3200 (2026年10月27日 14:11 時点 −詳細はこちら−')
  })

  test('TC-303-15: 本体の価格が Amazon なら、「−詳細はこちら−」に Amazon の免責の文言を出す', async () => {
    mockAffiliate(freshBoth())

    renderAffiliate(<Affiliate categoryId={12} />)

    const box = await findBox()
    const snippet = within(box).getByText('¥3200').parentElement!
    expect(within(snippet).getByText('−詳細はこちら−')).toBeInTheDocument()
    expect(within(snippet).getByText(AMAZON_DISCLAIMER)).toBeInTheDocument()
  })

  test('TC-303-16: 本体の価格が楽天なら、楽天の取った時刻と楽天の免責の文言を出す', async () => {
    mockAffiliate(freshRakutenOnly())

    renderAffiliate(<Affiliate categoryId={12} />)

    const box = await findBox()
    const snippet = within(box).getByText('¥3000').parentElement!
    expect(snippet).toHaveTextContent('(2026年10月27日 09:05 時点')
    expect(within(snippet).getByText(RAKUTEN_DISCLAIMER)).toBeInTheDocument()
  })

  test('TC-303-17: ボタンには、期限内の価格と、その提供元の取った時刻・免責の文言を添える', async () => {
    mockAffiliate(freshBoth())

    renderAffiliate(<Affiliate categoryId={12} />)

    const box = await findBox()
    const amazon = within(box).getByRole('link', { name: /Amazonで探す/ })
    const rakuten = within(box).getByRole('link', { name: /楽天で探す/ })
    expect(amazon).toHaveTextContent('Amazonで探す(¥3200)')
    expect(amazon).toHaveAttribute('href', AMAZON_SEARCH)
    expect(rakuten).toHaveTextContent('楽天で探す(¥3000)')
    expect(rakuten).toHaveAttribute('href', RAKUTEN_SEARCH)
    expect(amazon.parentElement).toHaveTextContent('(2026年10月27日 14:11 時点')
    expect(within(amazon.parentElement!).getByText(AMAZON_DISCLAIMER)).toBeInTheDocument()
    expect(rakuten.parentElement).toHaveTextContent('(2026年10月27日 09:05 時点')
    expect(within(rakuten.parentElement!).getByText(RAKUTEN_DISCLAIMER)).toBeInTheDocument()
  })

  test('TC-303-18: 期限切れ(API が価格を返さない)の提供元のボタンは、価格も取った時刻も出さない(旧の「(¥)」を写さない)', async () => {
    mockAffiliate(freshRakutenOnly())

    renderAffiliate(<Affiliate categoryId={12} />)

    const box = await findBox()
    const amazon = within(box).getByRole('link', { name: /Amazonで探す/ })
    expect(amazon).toHaveTextContent(/^Amazonで探す$/)
    expect(amazon.parentElement).not.toHaveTextContent('時点')
  })

  test('TC-303-19: 検索結果のページの URL が無い提供元のボタンは出さない', async () => {
    mockAffiliate({ ...freshBoth(), rakuten: NOT_FOUND_RAKUTEN })

    renderAffiliate(<Affiliate categoryId={12} />)

    const box = await findBox()
    expect(within(box).getByRole('link', { name: /Amazonで探す/ })).toBeInTheDocument()
    expect(within(box).queryByRole('link', { name: /楽天で探す/ })).not.toBeInTheDocument()
  })
})

describe('Affiliate の安い方を目立たせる', () => {
  /** 目立たせた価格(strong)の文字。無ければ空 */
  const emphasized = (box: HTMLElement) => within(box).queryAllByRole('strong').map((element) => element.textContent)

  test.each([
    ['楽天の方が安い', 3200, 3000, ['(¥3000)']],
    ['Amazon の方が安い', 2900, 3000, ['(¥2900)']],
    ['同じ価格(旧と同じく楽天)', 3000, 3000, ['(¥3000)']],
  ])('TC-303-20: 両方の価格が期限内で%sなら、安い方のボタンの価格だけを目立たせる', async (_, amazonPrice, rakutenPrice, expected) => {
    const base = freshBoth()
    mockAffiliate({ ...base, amazon: { ...base.amazon, price: amazonPrice }, rakuten: { ...base.rakuten, price: rakutenPrice } })

    renderAffiliate(<Affiliate categoryId={12} />)

    const box = await findBox()
    expect(emphasized(box)).toEqual(expected)
    const lowerButton = within(box).getByRole('link', { name: rakutenPrice <= amazonPrice ? /楽天で探す/ : /Amazonで探す/ })
    expect(within(lowerButton).getByRole('strong')).toBeInTheDocument()
  })

  test('TC-303-21: 片方が期限切れなら、残った方の価格も目立たせない', async () => {
    mockAffiliate(freshRakutenOnly())

    renderAffiliate(<Affiliate categoryId={12} />)

    const box = await findBox()
    expect(within(box).getByRole('link', { name: /楽天で探す/ })).toHaveTextContent('楽天で探す(¥3000)')
    expect(emphasized(box)).toEqual([])
  })
})

describe('Affiliate のカクテルを探すリンク', () => {
  test('TC-303-22: 「⇒このアイテムが使われているカクテルを探す」は、渡したノードを条件にした条件検索の結果の URL へのリンクにする', async () => {
    mockAffiliate(freshBoth())

    renderAffiliate(<Affiliate categoryId={12} brandId={345} />)

    expect(await screen.findByRole('link', { name: SEARCH_LINK })).toHaveAttribute('href', '/result/Cocktails?n=12')
  })
})
