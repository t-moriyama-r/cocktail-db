import { Link } from 'react-router'
import { api } from '../api/client'
import { useApi } from '../api/useApi'
import ApiView from '../components/ApiView/ApiView'
import { usePageTitle } from '../components/SiteLayout/usePageTitle'
import { termLabel } from '../features/dictionary/termLabel'
import { dictionaryPath } from '../utils/detailPaths'
import styles from './DictionaryIndex.module.css'

const fetchDictionaries = () => api.GET('/dictionaries')

/**
 * 用語辞典の一覧の画面(旧 Dictionary/index.vue)。
 *
 * - 分類ごとに、見出し「〈分類の名前〉(〈英名〉)」と、その用語「〈名前〉(〈英名〉)」(→ 用語の詳細)を並べる
 * - 分類は API の順(ID の順)、用語も API の順(かな・全角をそろえた名前の順。docs/decisions/dictionary.md)で出し、画面では並べ替えない
 * - 用語が 0 件の分類は出さない(旧と同じ。API は空の並びで返す)
 * - 旧の画面には題(h1)が無かった。見た目は旧のまま、読み上げのための題だけを置く
 */
export default function DictionaryIndex() {
  usePageTitle('用語辞典')
  const { state, retry } = useApi(fetchDictionaries)

  return (
    <ApiView state={state} onRetry={retry}>
      {({ data }) => (
        <main className={styles.page}>
          <h1 className={styles.visuallyHidden}>用語辞典</h1>
          {data
            .filter((category) => category.dictionaries.length > 0)
            .map((category) => (
              <section key={category.id} className={styles.category} aria-labelledby={`dictionary-category-${category.id}`}>
                <h2 className={styles.categoryTitle} id={`dictionary-category-${category.id}`}>
                  {termLabel(category)}
                </h2>
                <ul className={styles.terms}>
                  {category.dictionaries.map((term) => (
                    <li key={term.id}>
                      <Link to={dictionaryPath(term.id)}>{termLabel(term)}</Link>
                    </li>
                  ))}
                </ul>
              </section>
            ))}
        </main>
      )}
    </ApiView>
  )
}
