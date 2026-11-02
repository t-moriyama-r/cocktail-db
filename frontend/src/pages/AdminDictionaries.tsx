import { Link, useLocation } from 'react-router'
import { useApi } from '../api/useApi'
import { ADMIN_DICTIONARY_NEW_PATH, adminDictionaryEditPath } from '../components/AdminLayout/adminPaths'
import InlineApiView from '../components/ApiView/InlineApiView'
import { usePageTitle } from '../components/SiteLayout/usePageTitle'
import { fetchAdminDictionaries } from '../features/admin-dictionary/adminDictionaryApi'
import { readAdminDictionariesNotice } from '../features/admin-dictionary/adminDictionariesNotice'
import { termLabel } from '../features/dictionary/termLabel'
import styles from './AdminDictionaries.module.css'

/**
 * 管理画面の用語辞典の一覧(#66。旧 Admin/Super/DictionaryList.vue。決まりは docs/decisions/admin-dictionary.md)。
 *
 * - 分類ごとに、見出し「〈分類の名前〉(〈英名〉)」と、その用語「〈名前〉(〈英名〉)」を並べる(旧と同じ)
 * - 分類は API の順(ID の順)、用語も API の順(かな・全角をそろえた名前の順。#315)で出し、画面では並べ替えない
 * - 用語が 0 件の分類は出さない(旧と同じ。API は新規の画面の選択肢のために空の並びで返す)
 * - 用語のリンクは、用語の編集の画面へ(旧はカクテルの編集画面へのリンクになっていた。旧の不具合)
 * - 旧に無い「新規」のリンクを上に置く(旧は保存を止めていた)
 */
export default function AdminDictionaries() {
  usePageTitle('用語辞典')
  const { state, retry } = useApi(fetchAdminDictionaries)
  const location = useLocation()
  const notice = readAdminDictionariesNotice(location.state)

  return (
    <main className={styles.page}>
      <h1 className={styles.title}>用語辞典</h1>
      {notice !== null && (
        <p className={styles.notice} role="status">
          {notice}
        </p>
      )}
      <p>
        <Link to={ADMIN_DICTIONARY_NEW_PATH}>新規</Link>
      </p>
      <InlineApiView state={state} onRetry={retry}>
        {({ data }) => {
          const categories = data.filter((category) => category.dictionaries.length > 0)
          if (categories.length === 0) {
            return <p className={styles.empty}>用語がありません</p>
          }
          return categories.map((category) => (
            <section
              key={category.id}
              className={styles.category}
              aria-labelledby={`admin-dictionary-category-${category.id}`}
            >
              <h2 className={styles.categoryTitle} id={`admin-dictionary-category-${category.id}`}>
                {termLabel(category)}
              </h2>
              <ul className={styles.terms}>
                {category.dictionaries.map((term) => (
                  <li key={term.id}>
                    <Link to={adminDictionaryEditPath(term.id)}>{termLabel(term)}</Link>
                  </li>
                ))}
              </ul>
            </section>
          ))
        }}
      </InlineApiView>
    </main>
  )
}
