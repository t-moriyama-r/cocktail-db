import { useApi } from '../api/useApi'
import InlineApiView from '../components/ApiView/InlineApiView'
import { usePageTitle } from '../components/SiteLayout/usePageTitle'
import DictionaryForm from '../features/admin-dictionary/DictionaryForm'
import { fetchAdminDictionaries } from '../features/admin-dictionary/adminDictionaryApi'
import styles from './AdminDictionaryEdit.module.css'

/** 管理画面の用語の新規の画面(#66)。分類の選択肢は、一覧の API の分類(用語の無い分類も含む)から作る */
export default function AdminDictionaryNew() {
  usePageTitle('用語の新規')
  const { state, retry } = useApi(fetchAdminDictionaries)

  return (
    <main className={styles.page}>
      <h1 className={styles.title}>用語の新規</h1>
      <InlineApiView state={state} onRetry={retry}>
        {({ data }) => <DictionaryForm categories={data} />}
      </InlineApiView>
    </main>
  )
}
