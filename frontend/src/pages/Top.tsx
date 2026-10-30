import { useState } from 'react'
import { useNavigate } from 'react-router'
import FontAwesomeIcon from '../components/CocktailTable/FontAwesomeIcon'
import { usePageTitle } from '../components/SiteLayout/usePageTitle'
import CocktailSearch from '../features/search/CocktailSearch'
import { defaultSearchFormValues, type SearchFormValues } from '../features/search/searchForm'
import InformationMenu, { SEARCH_FORM_ANCHOR } from '../features/top/InformationMenu'
import LatestVideo from '../features/top/LatestVideo'
import TopCocktailList from '../features/top/TopCocktailList'
import styles from '../features/top/Top.module.css'

/**
 * トップページ(旧 components/index.vue)。上から、サイト名と副題・新着動画・メニュー・3 つの一覧・条件検索のフォーム。
 * 欄ごとに API を呼び、欄ごとに読み込み中・失敗を出す(どれかが失敗しても、ほかの欄は出す)。
 * カクテルの件数はヘッダーの「現在の登録数」(#99)が出すので、ここでは出さない(旧もトップの本文には出していない)。
 * 条件検索のフォームの値は、このページで持つ(旧もトップに置いたフォームは、ヘッダーのモーダルとは別に値を持っていた)。
 */
export default function Top() {
  usePageTitle('トップページ')
  const [searchValues, setSearchValues] = useState<SearchFormValues>(defaultSearchFormValues)
  const navigate = useNavigate()

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
      <section id={SEARCH_FORM_ANCHOR} className={styles.searchForm} aria-label="カクテル検索">
        <CocktailSearch
          values={searchValues}
          onValuesChange={setSearchValues}
          // 条件を URL に入れて結果の画面で検索するのは #102。それまでは結果の画面へ移るだけ
          onSubmit={() => navigate('/result/Cocktails')}
        />
      </section>
    </main>
  )
}
