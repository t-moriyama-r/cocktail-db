import { useCallback, type ReactNode } from 'react'
import { Link } from 'react-router'
import { useApi } from '../../api/useApi'
import { defaultSearchQuery, searchResultUrl } from '../../features/search/searchQuery'
import styles from './Affiliate.module.css'
import {
  formatFetchedAt,
  hasAffiliate,
  hasPrice,
  imageHref,
  lowerPriceSource,
  PRICE_DISCLAIMER,
  type AffiliateData,
  type PriceSource,
} from './affiliateView'
import { fetchAffiliate } from './fetchAffiliate'

/**
 * 詳細の画面(カテゴリ・銘柄・カクテルの材料)に出す、商品の欄(旧 Detail/component/AffiliateContainer.vue)。
 * ノード・銘柄を受け取り、GET /api/affiliates(#47)で 1 件を取って出す。
 *
 * - 出すのは、商品か検索結果のページの URL があるときだけ(affiliateView.ts の hasAffiliate)。行が無い(404)・
 *   読み込み中・API の失敗では、旧と同じく何も出さない(旧はエラーを握りつぶして欄を出さなかった。docs/decisions/affiliate-api.md)
 * - 並びは旧と同じ: 画像 → 題名 → 価格と取った時刻 → 「Amazonで探す」「楽天で探す」 → 「⇒このアイテムが使われているカクテルを探す」
 * - 画像・題名・価格は、API の display(手で入れた価格 → Amazon → 楽天の順に選んだもの)を出す。display が無ければボタンだけ
 * - 価格には、取った時刻(日本時間の日付と時刻)と、規約の免責の文言を添える。期限切れの価格は API が返さないので出ない
 *   (docs/decisions/affiliate-freshness.md の 3・4)
 */
type Props = {
  /** 詳細のノード(カテゴリ)の ID。「このアイテムが使われているカクテルを探す」の条件にもする */
  categoryId: number
  /** 銘柄の ID。銘柄の詳細・銘柄を決めた材料のとき */
  brandId?: number | null
  /** 画像の代わりに画像の欄に出すとき(旧の is_photo_space)。幅に関わらず縦に並べる */
  inImage?: boolean
}

/** 外のサイト(Amazon・楽天)へのリンクに付ける rel。広告のリンクなので sponsored を付ける */
const EXTERNAL_REL = 'nofollow sponsored'

export default function Affiliate({ categoryId, brandId = null, inImage = false }: Props) {
  const request = useCallback(() => fetchAffiliate(categoryId, brandId), [categoryId, brandId])
  const { state } = useApi(request)

  if (state.kind !== 'success' || !hasAffiliate(state.data.data)) {
    return null
  }
  return <AffiliateBox data={state.data.data} categoryId={categoryId} inImage={inImage} />
}

function AffiliateBox({ data, categoryId, inImage }: { data: AffiliateData; categoryId: number; inImage: boolean }) {
  const { display, amazon, rakuten } = data
  const lower = lowerPriceSource(data)
  const imageUrl = display?.image_url
  const title = display?.title

  return (
    <aside className={inImage ? `${styles.box} ${styles.inImage}` : styles.box} aria-label="商品">
      {imageUrl && (
        <figure className={styles.thumb}>
          <ExternalLink href={imageHref(data)}>
            <img className={styles.thumbImage} src={imageUrl} alt={title || data.name} />
          </ExternalLink>
        </figure>
      )}
      <div className={styles.content}>
        {title && (
          <div className={styles.title}>
            <ExternalLink href={display.detail_url}>{title}</ExternalLink>
          </div>
        )}
        {display !== null && hasPrice(display.price) && (
          <div className={styles.snippet}>
            {display.source === 'human' ? (
              <span className={styles.price}>{yen(display.price)}</span>
            ) : (
              <PriceWithNote source={display.source} price={display.price} fetchedAt={display.fetched_at} className={styles.price} />
            )}
          </div>
        )}
        <div className={styles.buttons}>
          {amazon.search_url && (
            <ShopButton
              source="amazon"
              label="Amazonで探す"
              href={amazon.search_url}
              price={amazon.price}
              fetchedAt={amazon.fetched_at}
              isLower={lower === 'amazon'}
            />
          )}
          {rakuten.search_url && (
            <ShopButton
              source="rakuten"
              label="楽天で探す"
              href={rakuten.search_url}
              price={rakuten.price}
              fetchedAt={rakuten.fetched_at}
              isLower={lower === 'rakuten'}
            />
          )}
        </div>
        <div className={styles.searchByItem}>
          <Link to={searchResultUrl({ ...defaultSearchQuery, nodeIds: [categoryId] })}>⇒このアイテムが使われているカクテルを探す</Link>
        </div>
      </div>
    </aside>
  )
}

/** 旧と同じく、桁の区切りを入れずに `¥3200` の形で出す */
const yen = (price: number) => `¥${price}`

/** 外のサイトへのリンク。URL が無ければ中身だけを出す(旧は URL が無くてもリンクの形で置いていた) */
function ExternalLink({ href, children }: { href: string | null | undefined; children: ReactNode }) {
  if (!href) return children
  return (
    <a href={href} target="_blank" rel={EXTERNAL_REL}>
      {children}
    </a>
  )
}

/** 「Amazonで探す」「楽天で探す」。期限内の価格があればボタンに添え、ボタンの下に取った時刻と免責の文言を出す */
function ShopButton(props: {
  source: PriceSource
  label: string
  href: string
  price: number | null
  fetchedAt: string | null
  isLower: boolean
}) {
  const { source, label, href, price, fetchedAt, isLower } = props
  const fetched = fetchedAt === null ? null : formatFetchedAt(fetchedAt)
  // 価格は取った時刻と一緒にしか出さない(規約。docs/decisions/affiliate-freshness.md の 3)
  const shownPrice = hasPrice(price) && fetched !== null ? price : null
  return (
    <div className={`${styles.shopLink} ${styles[source]}`}>
      <a href={href} target="_blank" rel={EXTERNAL_REL}>
        {label}
        {shownPrice !== null && (
          <>
            <br />
            <span className={styles.buttonPrice}>
              {isLower ? <strong className={styles.lower}>({yen(shownPrice)})</strong> : `(${yen(shownPrice)})`}
            </span>
          </>
        )}
      </a>
      {shownPrice !== null && fetchedAt !== null && <FetchedNote source={source} fetchedAt={fetchedAt} />}
    </div>
  )
}

/** 本体の価格と、その横の取った時刻・免責の文言。取った時刻が無ければ価格も出さない */
function PriceWithNote({
  source,
  price,
  fetchedAt,
  className,
}: {
  source: PriceSource
  price: number
  fetchedAt: string | null
  className: string
}) {
  if (fetchedAt === null || formatFetchedAt(fetchedAt) === null) return null
  return (
    <>
      <span className={className}>{yen(price)}</span> <FetchedNote source={source} fetchedAt={fetchedAt} />
    </>
  )
}

/**
 * 取った時刻と免責の文言。`(2026年10月27日 14:11 時点 −詳細はこちら−)` の形で、「−詳細はこちら−」を開くと文言が出る
 * (docs/decisions/affiliate-freshness.md の 3。Amazon・楽天とも、リンク・ポップアップで見られる形でよい)
 */
function FetchedNote({ source, fetchedAt }: { source: PriceSource; fetchedAt: string }) {
  const fetched = formatFetchedAt(fetchedAt)
  if (fetched === null) return null
  return (
    <div className={styles.note}>
      (<time dateTime={fetchedAt}>{fetched}</time> 時点{' '}
      <details className={styles.disclaimer}>
        <summary>−詳細はこちら−</summary>
        <span className={styles.disclaimerText}>{PRICE_DISCLAIMER[source]}</span>
      </details>
      )
    </div>
  )
}
