import { useCallback, useId } from 'react'
import { useParams } from 'react-router'
import { api } from '../api/client'
import type { components } from '../api/schema'
import { useApi } from '../api/useApi'
import Affiliate from '../components/Affiliate/Affiliate'
import ApiView from '../components/ApiView/ApiView'
import BrandTable from '../components/BrandTable/BrandTable'
import type { BrandTableRow } from '../components/BrandTable/BrandTable.types'
import CreditedImage from '../components/CreditedImage/CreditedImage'
import Description from '../components/Description/Description'
import NodeBreadcrumb from '../components/NodeBreadcrumb/NodeBreadcrumb'
import RandomCocktails from '../components/RandomCocktails/RandomCocktails'
import { usePageTitle } from '../components/SiteLayout/usePageTitle'
import SquareDot from '../components/SquareDot/SquareDot'
import YouTubeEmbed from '../components/YouTubeEmbed/YouTubeEmbed'
import styles from './BrandDetail.module.css'

type BrandDetailResource = components['schemas']['BrandDetailResource']
type BrandSummary = components['schemas']['BrandSummaryResource']

/**
 * 銘柄の詳細の画面(旧 Detail/BrandDetail.vue)。URL の ID の銘柄を GET /api/brands/{id} で取って出す。
 * ID が数字であることはルート(RequireNumericId)が確かめている。無い ID は API が 404 を返し、ApiView が 404 の画面を出す。
 */
export default function BrandDetail() {
  const id = Number(useParams().id)
  const request = useCallback(() => api.GET('/brands/{brand}', { params: { path: { brand: id } } }), [id])
  const { state, retry } = useApi(request)

  return (
    <ApiView state={state} onRetry={retry}>
      {({ data }) => <BrandDetailContent brand={data} />}
    </ApiView>
  )
}

/**
 * 上から、タイトル・パンくず・Data(度数)・説明文と YouTube・画像・これを使ったカクテル・銘柄の一覧 2 つ・下のアフィリエイトの順(旧と同じ)。
 *
 * - 画像が無ければ、画像の場所にアフィリエイトを出す。960px 未満では画像の場所には出さず、下に出す(旧と同じく、いつも 1 つだけ見える)
 * - 銘柄の一覧は、0 件なら欄ごと出さない(旧と同じ)。どの一覧をどの見出しで出すかは brandLists を見る
 */
function BrandDetailContent({ brand }: { brand: BrandDetailResource }) {
  const title = `${brand.name}(${brand.name_eng})`
  // 旧と同じく、ブラウザのタイトルも「名前(英名)」にする
  usePageTitle(title)
  // 所属ノードは API のパンくずの末尾(いつもある)
  const node = brand.path[brand.path.length - 1]
  const lists = brandLists(brand)
  const hasImage = !!brand.image

  return (
    <main className={styles.detail}>
      <h1 className={styles.title}>{title}</h1>
      {/* 旧と同じく、祖先から所属ノードまでを出す(根はリンクにしない) */}
      <NodeBreadcrumb nodes={brand.path} />

      <section className={styles.data} aria-label="Data">
        <div className={styles.dataTitle}>
          <SquareDot />
          Data
        </div>
        <div>
          Strength:<b>{brand.strength}</b>%
        </div>
      </section>

      <div className={styles.body}>
        <div className={styles.text}>
          <div className={styles.description}>
            <Description segments={brand.description} />
          </div>
          {brand.youtube && (
            <div className={styles.youtube}>
              <YouTubeEmbed videoId={brand.youtube} startSeconds={brand.youtube_start_seconds} />
            </div>
          )}
        </div>
        <div className={styles.media}>
          {hasImage ? (
            <CreditedImage src={brand.image} alt={brand.name} creditUrl={brand.image_url} creditText={brand.image_txt} />
          ) : (
            <div className={styles.affiliateInImage} data-testid="affiliate-in-image">
              <Affiliate categoryId={node.id} brandId={brand.id} inImage />
            </div>
          )}
        </div>
      </div>

      <div className={`${styles.section} ${styles.usingCocktails}`}>
        <RandomCocktails origin={{ kind: 'brand', id: brand.id }} />
      </div>

      {lists.map((list) => (
        <BrandList key={list.kind} {...list} />
      ))}

      <div
        className={hasImage ? styles.affiliateBottom : `${styles.affiliateBottom} ${styles.affiliateBottomNoImage}`}
        data-testid="affiliate-bottom"
      >
        <Affiliate categoryId={node.id} brandId={brand.id} />
      </div>
    </main>
  )
}

type BrandListProps = { kind: keyof typeof LIST_LINE; heading: string; brands: readonly BrandSummary[] }

/**
 * 出す銘柄の一覧と見出し。旧の「〈ミドル〉の銘柄の一覧」(同じミドルのほかの銘柄)と「〈カテゴリ〉銘柄の一覧」(同じカテゴリで
 * 別のミドルの銘柄)を、任意の深さの木に広げたもの(docs/decisions/brand-detail.md の「画面(#54)」)。
 *
 * - 親が根でない(深さ 3 以上): 同じノードのほかの銘柄を「〈所属ノード〉の銘柄の一覧」、親ノードの配下の銘柄を
 *   「〈親ノード〉銘柄の一覧」で、この順に出す(旧のミドルのある銘柄と同じ)
 * - 親が根(いちばん上の段。旧のミドルの無い銘柄): 旧は、同じカテゴリのほかの銘柄を「〈カテゴリ〉銘柄の一覧」の 1 つにまとめて
 *   出していた。API は所属ノードの直下とその配下に分けて返すので、ID の順に 1 つにまとめ、「〈所属ノード〉銘柄の一覧」で出す
 * - 所属ノードが根(旧に無い): 親が無いので、同じノードのほかの銘柄だけを「〈所属ノード〉の銘柄の一覧」で出す
 */
function brandLists(brand: BrandDetailResource): BrandListProps[] {
  const { path } = brand
  const node = path[path.length - 1]
  if (path.length === 2) {
    const brands = [...brand.same_node_brands, ...brand.parent_subtree_brands].toSorted((a, b) => a.id - b.id)
    return [{ kind: 'parentSubtree', heading: `${node.name}銘柄の一覧`, brands }]
  }
  const lists: BrandListProps[] = [{ kind: 'sameNode', heading: `${node.name}の銘柄の一覧`, brands: brand.same_node_brands }]
  if (path.length >= 3) {
    lists.push({ kind: 'parentSubtree', heading: `${path[path.length - 2].name}銘柄の一覧`, brands: brand.parent_subtree_brands })
  }
  return lists
}

/** 銘柄の一覧の欄。旧と同じく、0 件なら欄ごと出さない。欄ごとに左の線の色を変える */
function BrandList({ heading, brands, kind }: BrandListProps) {
  const headingId = useId()
  if (brands.length === 0) {
    return null
  }
  return (
    <section className={`${styles.section} ${LIST_LINE[kind]}`} aria-labelledby={headingId}>
      <h2 className={styles.listHeading} id={headingId}>
        <SquareDot />
        {heading}
      </h2>
      <BrandTable brands={brands.map(toBrandRow)} label={heading} />
    </section>
  )
}

/** 欄ごとの線の色(旧の brands_belong_middle は青緑、brands_belong_category_except_middle は紫) */
const LIST_LINE = {
  sameNode: styles.sameNodeList,
  parentSubtree: styles.parentSubtreeList,
} as const

/** API の銘柄を、銘柄の一覧の表の行にする。銘柄の詳細の一覧は更新日時を持たないので、動画の列の並びは渡した順のまま */
function toBrandRow(brand: BrandSummary): BrandTableRow {
  return {
    id: brand.id,
    name: brand.name,
    name_eng: brand.name_eng,
    path: brand.path,
    strength: brand.strength,
    youtube: brand.youtube,
    updated_at: null,
  }
}
