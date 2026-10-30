import { useCallback, useId, type ReactNode } from 'react'
import { Link, useParams } from 'react-router'
import { api } from '../api/client'
import type { components } from '../api/schema'
import { useApi } from '../api/useApi'
import ApiView from '../components/ApiView/ApiView'
import CreditedImage from '../components/CreditedImage/CreditedImage'
import Description from '../components/Description/Description'
import NodeBreadcrumb from '../components/NodeBreadcrumb/NodeBreadcrumb'
import { usePageTitle } from '../components/SiteLayout/usePageTitle'
import SquareDot from '../components/SquareDot/SquareDot'
import YouTubeEmbed from '../components/YouTubeEmbed/YouTubeEmbed'
import styles from './CategoryDetail.module.css'

type CategoryDetailResource = components['schemas']['CategoryDetailResource']

/**
 * カテゴリ(木のノード)の詳細の画面。旧のカテゴリの詳細(Detail/CategoryDetail.vue)とミドルの詳細(MiddleDetail.vue)を、
 * 任意の深さのノードで 1 つにしたもの(docs/decisions/category-detail.md)。
 * 銘柄の一覧・これを使ったカクテル・アフィリエイトの中身は #106 で足す。
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
            // 画像が無いときは、旧と同じくここにアフィリエイトを出す(中身は #106。960px 未満では出さない)
            <div className={styles.affiliateInImage} data-testid="affiliate-in-image" />
          )}
        </div>
      </div>

      <div className={styles.intro}>
        {node.famous_brand && (
          <IntroSection heading="有名な銘柄" kind="famous">
            <Link to={`/detail/brand/${node.famous_brand.id}`}>{nameWithEng(node.famous_brand)}</Link>
          </IntroSection>
        )}
        <IntroSection heading="子のノード" kind="children">
          {node.children.length > 0 ? (
            <ul className={styles.childList}>
              {node.children.map((child) => (
                <li key={child.id}>
                  <Link to={`/category/${child.id}`}>{nameWithEng(child)}</Link>
                </li>
              ))}
            </ul>
          ) : (
            // 旧のカテゴリの詳細の「ミドルカテゴリの登録なし」にあたる文(docs/decisions/public-detail.md の既定)
            <p className={styles.empty}>子のノードの登録なし</p>
          )}
        </IntroSection>
      </div>
    </main>
  )
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
