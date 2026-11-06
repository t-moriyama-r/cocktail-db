import { useCallback } from 'react'
import { useParams } from 'react-router'
import type { ApiState } from '../api/useApi'
import { useApi } from '../api/useApi'
import ApiView from '../components/ApiView/ApiView'
import { usePageTitle } from '../components/SiteLayout/usePageTitle'
import DictionaryForm from '../features/admin-dictionary/DictionaryForm'
import { fetchAdminDictionaries, fetchAdminDictionary } from '../features/admin-dictionary/adminDictionaryApi'
import styles from './AdminDictionaryEdit.module.css'

/**
 * 2 つの API の状態を 1 つにする。どちらかが失敗していればその失敗(用語の 404 を先に見る)、
 * どちらかを待っていれば読み込み中、両方そろえば成功。
 */
function combine<A, B>(first: ApiState<A>, second: ApiState<B>): ApiState<[A, B]> {
  for (const state of [first, second]) {
    if (state.kind === 'not-found' || state.kind === 'http-error' || state.kind === 'network-error') {
      return state
    }
  }
  if (first.kind === 'success' && second.kind === 'success') {
    return { kind: 'success', data: [first.data, second.data] }
  }
  return { kind: 'loading' }
}

/**
 * 管理画面の用語の編集の画面(#66)。URL の :id の用語を開き、分類の選択肢は一覧の API の分類から作る。
 * 無い ID は 404 の画面を出す(数字でない ID は、ルートの RequireNumericId が 404 にする)。
 */
export default function AdminDictionaryEdit() {
  usePageTitle('用語の編集')
  const id = Number(useParams().id)
  const loadDictionary = useCallback(() => fetchAdminDictionary(id), [id])
  const dictionary = useApi(loadDictionary)
  const categories = useApi(fetchAdminDictionaries)
  const retry = () => {
    if (dictionary.state.kind !== 'success') {
      dictionary.retry()
    }
    if (categories.state.kind !== 'success') {
      categories.retry()
    }
  }

  return (
    <ApiView state={combine(dictionary.state, categories.state)} onRetry={retry}>
      {([{ data }, { data: categoryList }]) => (
        <main className={styles.page}>
          <h1 className={styles.title}>用語の編集</h1>
          {/* 別の用語へ移ったときに、前の用語の入力を持ち越さないよう、用語ごとに作り直す */}
          <DictionaryForm key={data.id} categories={categoryList} dictionary={data} />
        </main>
      )}
    </ApiView>
  )
}
