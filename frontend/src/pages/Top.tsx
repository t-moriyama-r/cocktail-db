import FontAwesomeIcon from '../components/CocktailTable/FontAwesomeIcon'
import { usePageTitle } from '../components/SiteLayout/usePageTitle'
import TopIngredientField from '../features/search/TopIngredientField'
import InformationMenu, { SEARCH_FORM_ANCHOR } from '../features/top/InformationMenu'
import LatestVideo from '../features/top/LatestVideo'
import TopCocktailList from '../features/top/TopCocktailList'
import styles from '../features/top/Top.module.css'

/**
 * トップページ(旧 components/index.vue)。上から、サイト名と副題・新着動画・メニュー・3 つの一覧・条件検索のフォームの置き場所。
 * 欄ごとに API を呼び、欄ごとに読み込み中・失敗を出す(どれかが失敗しても、ほかの欄は出す)。
 * カクテルの件数はヘッダーの「現在の登録数」(#99)が出すので、ここでは出さない(旧もトップの本文には出していない)。
 */
export default function Top() {
  usePageTitle('トップページ')

  return (
    <main className={styles.top}>
      <div className={styles.titleBox}>
        <h1 className={styles.title}>
          <FontAwesomeIcon icon="cocktail" label={null} />
          宅飲みカクテルデータベース
        </h1>
        <p className={styles.subtitle}>―宅飲み指向データベース。新たな出会いをあなたに。</p>
      </div>
      <LatestVideo />
      <InformationMenu />
      <div className={styles.lists}>
        <TopCocktailList title="★最近更新されたカクテル" sort="recent" />
        <TopCocktailList title="★おすすめカクテル" sort="recommend" canReget />
        <TopCocktailList title="★気まぐれセレクト" sort="random" canReget />
      </div>
      {/* 条件検索のフォーム(#307)の置き場所。フォームができるまでは、材料の欄(#305)だけを仮に置く */}
      <section id={SEARCH_FORM_ANCHOR} className={styles.searchForm} aria-label="カクテル検索">
        <TopIngredientField />
      </section>
    </main>
  )
}
