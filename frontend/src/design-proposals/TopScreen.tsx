import CocktailTable from './CocktailTable'
import common from './common.module.css'
import { SAMPLE_COCKTAILS, SAMPLE_DETAIL } from './sampleData'
import styles from './TopScreen.module.css'

/**
 * トップの試作。旧の index.vue と同じく、サイト名と副題・新作動画・3 つの一覧(最近の更新・おすすめ・気まぐれ)を並べる。
 * 旧の 7 件ずつの一覧は、見比べやすいよう 3〜4 件に減らしている。
 */
export default function TopScreen() {
  return (
    <>
      <div className={styles.titleBox}>
        <h1 className={common.pageTitle}>宅飲みカクテルデータベース</h1>
        <p className={styles.subtitle}>―宅飲み指向データベース。新たな出会いをあなたに。</p>
      </div>

      <section className={styles.movieBlock} aria-label="新作動画">
        <div className={common.moviePlaceholder}>動画(YouTube の埋め込み)</div>
        <div className={styles.movieDetail}>
          <h2 className={common.heading}>
            新作動画: <a href="#cocktail-1">{SAMPLE_DETAIL.name}({SAMPLE_DETAIL.nameEng})</a>
          </h2>
          <p>
            ベース: <a href="#category">{SAMPLE_DETAIL.base}({SAMPLE_DETAIL.baseEng})</a>
          </p>
          <p>{SAMPLE_DETAIL.description}</p>
        </div>
      </section>

      <div className={styles.lists}>
        <section>
          <h2 className={common.heading}>★最近更新されたカクテル</h2>
          <CocktailTable cocktails={SAMPLE_COCKTAILS.slice(0, 4)} caption="最近更新された順" />
        </section>
        <section>
          <h2 className={common.heading}>
            ★おすすめカクテル <button type="button" className={styles.reget}>再取得</button>
          </h2>
          <CocktailTable cocktails={SAMPLE_COCKTAILS.slice(3, 6)} caption="評価の高いものから" />
        </section>
        <section>
          <h2 className={common.heading}>
            ★気まぐれセレクト <button type="button" className={styles.reget}>再取得</button>
          </h2>
          <CocktailTable cocktails={SAMPLE_COCKTAILS.slice(4, 7)} caption="ランダムに" />
        </section>
      </div>
    </>
  )
}
