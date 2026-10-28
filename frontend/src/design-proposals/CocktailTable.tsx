import common from './common.module.css'
import type { SampleCocktail } from './sampleData'
import styles from './CocktailTable.module.css'

/**
 * カクテルの一覧の表。旧の Results/Tables/Cocktails.vue と同じ列を持つ。
 * 旧は 600px 未満で別の DOM(名前の行と数値の行の 2 段)に描き分けていたが、試作では同じ表を CSS だけで 2 段に組み替える。
 * 並べ替えの見た目を比べるため、見出しの 1 つに並べ替え中の印を付けている(並べ替えそのものは動かない)。
 */
export default function CocktailTable({ cocktails, caption }: { cocktails: SampleCocktail[]; caption: string }) {
  return (
    <table className={styles.table}>
      <caption className={styles.caption}>{caption}</caption>
      <thead>
        <tr>
          <th className={styles.method}>製法</th>
          <th className={styles.name}>
            名前 <span aria-hidden="true">▲</span>
          </th>
          <th className={styles.nameEng}>Name</th>
          <th className={styles.base}>ベース</th>
          <th className={styles.num}>必須</th>
          <th className={styles.num}>甘さ</th>
          <th className={styles.rateCol}>評価</th>
          <th className={styles.num}>度数</th>
          <th className={styles.num}>動画</th>
        </tr>
      </thead>
      <tbody>
        {cocktails.map((c) => (
          <tr key={c.id} className={c.hasMovie ? styles.hasMovie : undefined}>
            <td className={styles.method} data-label="製法">
              {c.method}
            </td>
            <td className={styles.name}>
              <a href={`#cocktail-${c.id}`}>{c.name}</a>
            </td>
            <td className={styles.nameEng}>
              <a href={`#cocktail-${c.id}`}>{c.nameEng}</a>
            </td>
            <td className={styles.base}>
              <a href="#category">
                {c.base}
                <span className={styles.baseEng}>({c.baseEng})</span>
              </a>
            </td>
            <td className={styles.num} data-label="必須">
              {c.recipeCount}
            </td>
            <td className={styles.num} data-label="甘さ">
              {c.sweet}
            </td>
            <td className={`${styles.rateCol} ${common.rate}`} data-label="評価">
              {c.rate > 0 ? '★'.repeat(c.rate) : '-'}
            </td>
            <td className={styles.num} data-label="度数">
              {c.strength.toFixed(1)}
            </td>
            <td className={styles.num} data-label="動画">
              {c.hasMovie ? '▶' : '-'}
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}
