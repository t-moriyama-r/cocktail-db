import common from './common.module.css'
import { SAMPLE_DETAIL } from './sampleData'
import styles from './DetailScreen.module.css'

/**
 * カクテル詳細の試作。旧の Detail/CocktailDetail.vue と同じ要素(パンくず・名前・ベースほかの属性・グラス・
 * 説明・動画・レシピ・Note)を並べる。
 */
export default function DetailScreen() {
  const c = SAMPLE_DETAIL
  return (
    <article className={styles.detail}>
      <nav className={common.muted} aria-label="パンくず">
        <a href="#top">トップ</a> › <a href="#category">{c.base}</a> › {c.name}
      </nav>
      <h1 className={common.pageTitle}>
        {c.name}
        <span className={styles.nameEng}>({c.nameEng})</span>
      </h1>

      <dl className={styles.attributes}>
        <div className={styles.baseAttr}>
          <dt>ベース</dt>
          <dd>
            <a href="#category">
              {c.base}({c.baseEng})
            </a>
          </dd>
        </div>
        <div>
          <dt>製法</dt>
          <dd>
            <a href="#dictionary">{c.method}</a>
          </dd>
        </div>
        <div>
          <dt>甘さ</dt>
          <dd>{c.sweet}</dd>
        </div>
        <div>
          <dt>おすすめ度</dt>
          <dd className={common.rate}>{'★'.repeat(c.rate)}</dd>
        </div>
        <div>
          <dt>度数</dt>
          <dd>{c.strength}%</dd>
        </div>
        <div className={styles.baseAttr}>
          <dt>グラス</dt>
          <dd>
            <a href="#dictionary">{c.glass}</a>
          </dd>
        </div>
      </dl>

      <div className={styles.body}>
        <div>
          <h2 className={common.heading}>説明</h2>
          <p>{c.description}</p>
          <div className={common.moviePlaceholder}>動画(YouTube の埋め込み)</div>
        </div>
        <div>
          <h2 className={common.heading}>レシピ</h2>
          <table className={styles.recipe}>
            <thead>
              <tr>
                <th>材料</th>
                <th className={styles.materialEng}>Material</th>
                <th className={styles.amount}>分量</th>
              </tr>
            </thead>
            <tbody>
              {c.recipes.map((r) => (
                <tr key={r.material}>
                  <td>
                    <a href="#category">{r.material}</a>
                    {r.optional && <span className={common.muted}>(任意)</span>}
                  </td>
                  <td className={styles.materialEng}>
                    <a href="#category">{r.materialEng}</a>
                  </td>
                  <td className={styles.amount}>{r.amount}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <aside className={`${common.card} ${styles.note}`}>
            <strong>Note</strong>
            <p>{c.note}</p>
          </aside>
        </div>
      </div>
    </article>
  )
}
