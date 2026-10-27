import CocktailTable from './CocktailTable'
import common from './common.module.css'
import { SAMPLE_COCKTAILS, SAMPLE_SEARCH_CONDITIONS } from './sampleData'
import styles from './ResultScreen.module.css'

/** 条件検索の結果の試作。検索した条件と件数を上に出し、旧と同じ列の表を並べる */
export default function ResultScreen() {
  return (
    <>
      <h1 className={common.pageTitle}>条件検索の結果</h1>
      <div className={`${common.card} ${styles.conditions}`}>
        <ul>
          {SAMPLE_SEARCH_CONDITIONS.map((condition) => (
            <li key={condition}>{condition}</li>
          ))}
        </ul>
        <button type="button" className={styles.edit}>
          条件を変える
        </button>
      </div>
      <p className={styles.count}>
        <strong>{SAMPLE_COCKTAILS.length}</strong> 件
      </p>
      <CocktailTable cocktails={SAMPLE_COCKTAILS} caption="見出しを押すと並べ替える" />
    </>
  )
}
