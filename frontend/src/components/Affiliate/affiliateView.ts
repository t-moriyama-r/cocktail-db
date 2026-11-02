import type { fetchAffiliate } from './fetchAffiliate'

/**
 * GET /api/affiliates が返す、商品の欄の 1 件(#47)。openapi-fetch が返す型から取る
 * (スキーマの AffiliateResource とは、値が null だけの項目(手で入れた値の fetched_at)の扱いが違う)
 */
export type AffiliateData = NonNullable<Awaited<ReturnType<typeof fetchAffiliate>>['data']>['data']

/** 価格のある提供元(取った時刻と免責の文言を添える相手)。手で入れた価格は API から取った値ではないので、添えない */
export type PriceSource = 'amazon' | 'rakuten'

/** 価格があるか。旧の画面は価格が「真」かで見ていた(0 は無いのと同じ)。期限切れの価格は API が null で返す(#247) */
export const hasPrice = (price: number | null): price is number => price !== null && price > 0

/**
 * 欄を出すか。旧(AffiliateContainer.vue の is_exist_affiliate)は、商品のページの URL(Amazon・楽天・手で入れた Amazon)の
 * どれかがあるときだけ出した。新は期限(#247)を過ぎると商品のページの URL を返さず、検索結果のページの URL だけを返すので、
 * 検索結果のページの URL(Amazon・楽天)があるときも出す(期限切れは「検索のリンクだけ」にする。docs/decisions/affiliate-freshness.md の 4)
 */
export function hasAffiliate(data: AffiliateData): boolean {
  return [
    data.amazon.detail_url,
    data.rakuten.detail_url,
    data.human.amazon_detail_url,
    data.amazon.search_url,
    data.rakuten.search_url,
  ].some((url) => !!url)
}

/**
 * 本体の画像のリンク先。旧は、手で入れた値なら手で入れた Amazon の商品のページ、無ければ Amazon の商品のページにしていた。
 * 楽天のときは旧は画像の URL にしていた(不具合)ので、楽天の商品のページにする(#303 のやること 6)
 */
export function imageHref(data: AffiliateData): string | null {
  const display = data.display
  if (display === null) return null
  if (display.source === 'human') return display.detail_url || data.amazon.detail_url || null
  return display.detail_url || null
}

/**
 * Amazon と楽天のどちらの価格を目立たせるか。両方の価格が期限内(API が両方の価格を返した)ときだけ、安い方を目立たせる
 * (docs/decisions/affiliate-freshness.md の 4)。同じ価格なら、旧と同じく楽天を目立たせる
 */
export function lowerPriceSource(data: AffiliateData): PriceSource | null {
  const amazon = data.amazon.price
  const rakuten = data.rakuten.price
  if (!hasPrice(amazon) || !hasPrice(rakuten)) return null
  return amazon < rakuten ? 'amazon' : 'rakuten'
}

const FETCHED_AT_FORMAT = new Intl.DateTimeFormat('ja-JP', {
  timeZone: 'Asia/Tokyo',
  year: 'numeric',
  month: 'numeric',
  day: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
  hourCycle: 'h23',
})

/**
 * 取った時刻を、日本時間の日付と時刻で `2026年10月27日 14:11` の形にする(docs/decisions/affiliate-freshness.md の 3。
 * Amazon は同じ日なら日付を省いてよいが、楽天に合わせていつも日付を出す)。読めない値なら null
 */
export function formatFetchedAt(fetchedAt: string): string | null {
  const date = new Date(fetchedAt)
  if (Number.isNaN(date.getTime())) return null
  const parts = Object.fromEntries(FETCHED_AT_FORMAT.formatToParts(date).map(({ type, value }) => [type, value]))
  return `${parts.year}年${parts.month}月${parts.day}日 ${parts.hour}:${parts.minute}`
}

/**
 * 価格の横に添える免責の文言(docs/decisions/affiliate-freshness.md の 3。規約の原文のまま変えずに使う)
 */
export const PRICE_DISCLAIMER: Record<PriceSource, string> = {
  amazon:
    '価格および発送可能時期は表示された日付/時刻の時点のものであり、変更される場合があります。本商品の購入においては、購入の時点でAmazon.co.jpに表示されている価格および発送可能時期の情報が適用されます。',
  rakuten:
    'このサイトで掲載されている情報は、宅飲みカクテルデータベースの作成者により運営されています。価格、販売可能情報は、変更される場合があります。購入時に楽天市場店舗（www.rakuten.co.jp）に表示されている価格が、その商品の販売に適用されます。',
}

/**
 * ボタンに添える価格。期限内の価格があり、取った時刻が読めるときだけ返す
 * (価格は取った時刻と一緒にしか出さない。docs/decisions/affiliate-freshness.md の 3)
 */
export function shownButtonPrice(price: number | null, fetchedAt: string | null): number | null {
  return hasPrice(price) && fetchedAt !== null && formatFetchedAt(fetchedAt) !== null ? price : null
}

/**
 * その提供元(Amazon・楽天)の API から取った商品の情報を、欄に出すか。本体(画像・題名・価格)がその提供元のときか、
 * その提供元のボタンに価格を添えるとき。期限切れで検索のリンクだけのときと、手で入れた値は、API から取った情報ではないので入れない
 */
export function showsSourceContent(data: AffiliateData, source: PriceSource): boolean {
  if (data.display?.source === source) return true
  return shownButtonPrice(data[source].price, data[source].fetched_at) !== null
}

/**
 * Amazon の商品の情報を出すときに添える、提供の文言(docs/amazon-creators-api.md の 9.5。利用要件 (q) の原文で、
 * 「本アプリケーション内」「本サイト上」のうち「本サイト上」を選んだもの。docs/decisions/affiliate-freshness.md の 3)
 */
export const AMAZON_ATTRIBUTION =
  '本サイト上で表示されるコンテンツの一部は、アマゾンジャパン合同会社またはその関連会社により提供されたものです。これらのコンテンツは「現状有姿」で提供されており、随時変更または削除される場合があります。'

/**
 * 楽天の商品の情報を出すときに添える、楽天ウェブサービスのクレジット(テキストの形)。公式の HTML のソースを変えずに使う
 * (docs/rakuten-api.md の 4.2。docs/decisions/affiliate-freshness.md の 3)。中身を変えないので、属性(rel など)も足さない
 */
export const RAKUTEN_CREDIT_HTML =
  '<!-- Rakuten Web Services Attribution Snippet FROM HERE --><a href="https://webservice.rakuten.co.jp/" target="_blank">Supported by Rakuten Developers</a><!-- Rakuten Web Services Attribution Snippet TO HERE -->'
