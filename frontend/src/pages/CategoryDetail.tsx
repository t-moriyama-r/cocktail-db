import { useCallback, useId, type ReactNode } from 'react'
import { Link, useParams } from 'react-router'
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
import { brandPath, categoryPath } from '../utils/detailPaths'
import styles from './CategoryDetail.module.css'

type CategoryDetailResource = components['schemas']['CategoryDetailResource']
type BrandSummaryResource = components['schemas']['BrandSummaryResource']

/**
 * カテゴリ(木のノード)の詳細の画面。旧のカテゴリの詳細(Detail/CategoryDetail.vue)とミドルの詳細(MiddleDetail.vue)を、
 * 任意の深さのノードで 1 つにしたもの(docs/decisions/category-detail.md)。
 *
 * 上から、タイトル・パンくず・説明文と画像・有名な銘柄と子のノード(#105)・これを使ったカクテル・
 * 登録されている銘柄の一覧・商品の欄(アフィリエイト)の順に出す(#106)。
 * - 銘柄の一覧は、このノードと配下すべての銘柄(API の brands)。0 件なら欄ごと出さない(旧と同じ)
 * - これを使ったカクテルは、旧と同じくランダムの 5 件と「再取得」。配下のノードを使うカクテルも当たる
 *   (docs/decisions/public-detail.md の決まったこと 1)
 * - カクテル → 銘柄の一覧の並びは、旧のカテゴリの詳細と同じ(旧はカテゴリとミドルで逆だった。#631 でオーナーが決めた。
 *   docs/decisions/public-detail.md の「旧との差分」)
 * - 商品の欄は、画像が無ければ画像の場所に出す(960px 以上)。960px 未満と、画像があるときは、いちばん下に出す
 *   (旧と同じ。どの幅でも 1 つだけ見える)
 */
export default function CategoryDetail() {
  // RequireNumericId が、数字の ID のときだけこの画面を出す
  const id = Number(useParams().id)
  const request = useCallback(() => api.GET('/categories/{category}', { params: { path: { category: id } } }), [id])
  const { state, retry } = useApi(request)

  return (
    <ApiView state={state} onRetry={retry}>
      {({ data }) => <CategoryDetailContent node={data} />}
    </ApiView>
  )
}

function CategoryDetailContent({ node }: { node: CategoryDetailResource }) {
  const title = nameWithEng(node)
  // 旧と同じく、ブラウザのタイトルも「名前(英名)」にする。読み込み中・404 の画面のタイトルは ApiView の側が決める
  usePageTitle(title)

  return (
    <main className={styles.detail}>
      <h1 className={styles.title}>{title}</h1>
      {/* パンくずは祖先だけ。API のパンくずの末尾は開いているノード自身なので除く */}
      <NodeBreadcrumb nodes={node.path.slice(0, -1)} />

      <div className={styles.body}>
        <div className={styles.text}>
          <Description segments={node.description} />
          <div className={styles.youtube}>
            <YouTubeEmbed videoId={node.youtube} startSeconds={node.youtube_start_seconds} />
          </div>
        </div>
        <div className={styles.media}>
          {node.image ? (
            <CreditedImage src={node.image} alt={node.name} creditUrl={node.image_url} creditText={node.image_txt} />
          ) : (
            // 画像が無いときは、旧と同じくここにアフィリエイトを出す(960px 未満では出さず、いちばん下に出す)
            <div className={styles.affiliateInImage} data-testid="affiliate-in-image">
              <Affiliate categoryId={node.id} inImage />
            </div>
          )}
        </div>
      </div>

      <div className={styles.intro}>
        {node.famous_brand && (
          <IntroSection heading="有名な銘柄" kind="famous">
            <Link to={brandPath(node.famous_brand.id)}>{nameWithEng(node.famous_brand)}</Link>
          </IntroSection>
        )}
        <IntroSection heading="子のノード" kind="children">
          {node.children.length > 0 ? (
            <ul className={styles.childList}>
              {node.children.map((child) => (
                <li key={child.id}>
                  <Link to={categoryPath(child.id)}>{nameWithEng(child)}</Link>
                </li>
              ))}
            </ul>
          ) : (
            // 旧のカテゴリの詳細の「ミドルカテゴリの登録なし」にあたる文(docs/decisions/public-detail.md の既定)
            <p className={styles.empty}>子のノードの登録なし</p>
          )}
        </IntroSection>
      </div>

      <div className={styles.cocktails}>
        <RandomCocktails origin={{ kind: 'category', id: node.id }} />
      </div>

      {node.brands.length > 0 && <BrandList brands={node.brands} />}

      {/* 画像が無いときは、960px 以上では画像の場所に出しているので、ここには出さない(旧の is_already_display_inImage) */}
      <div
        className={node.image ? styles.affiliateBottom : `${styles.affiliateBottom} ${styles.affiliateBottomNoImage}`}
        data-testid="affiliate-bottom"
      >
        <Affiliate categoryId={node.id} />
      </div>
    </main>
  )
}

/** 登録されている銘柄の一覧(旧の belong_brand_container)。このノードと配下すべてのノードの銘柄を、銘柄の表で出す */
function BrandList({ brands }: { brands: readonly BrandSummaryResource[] }) {
  const headingId = useId()
  return (
    <section className={styles.brands} aria-labelledby={headingId}>
      <h2 className={styles.blockHeading} id={headingId}>
        <SquareDot />
        {BRAND_LIST_HEADING}
      </h2>
      <BrandTable brands={brands.map(toBrandRow)} label={BRAND_LIST_HEADING} />
    </section>
  )
}

const BRAND_LIST_HEADING = '登録されている銘柄の一覧'

/**
 * API の銘柄を、銘柄の表の行にする。ノードの詳細の銘柄は更新日時を返さないので、動画の列で並べ替えると、
 * 動画のある行どうしは渡した順(ID の順)のままになる(名前検索の結果と同じ)
 */
function toBrandRow(brand: BrandSummaryResource): BrandTableRow {
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

/**
 * 有名な銘柄と子のノードの欄(旧の intro_container)。見出しと中身を横に並べ、欄ごとに線の色を変える。
 * 960px 以上では、2 つの欄を右に寄せて横に並べる(旧と同じ)。
 */
function IntroSection({ heading, kind, children }: { heading: string; kind: keyof typeof INTRO_LINE; children: ReactNode }) {
  const headingId = useId()
  return (
    <section className={`${styles.introSection} ${INTRO_LINE[kind]}`} aria-labelledby={headingId}>
      <h2 className={styles.introHeading} id={headingId}>
        <SquareDot />
        {heading}
      </h2>
      <div>{children}</div>
    </section>
  )
}

/** 欄ごとの線の色(旧の famous_brand は青、middle_list は橙) */
const INTRO_LINE = {
  famous: styles.famousSection,
  children: styles.childrenSection,
} as const

/** 旧の詳細と同じ「名前(英名)」 */
function nameWithEng({ name, name_eng }: { name: string; name_eng: string }): string {
  return `${name}(${name_eng})`
}
