import type { ReactNode } from 'react'
import type { ApiState } from '../../api/useApi'
import NotFound from '../../pages/NotFound'
import ApiError from './ApiError'
import Loading from './Loading'
import styles from './ApiView.module.css'

type Props<T> = {
  /** useApi が返す状態 */
  state: ApiState<T>
  /** useApi が返す retry。エラーの表示の「もう一度読み込む」で呼ぶ */
  onRetry: () => void
  /** 成功したときの画面。`<main>` から書く */
  children: (data: T) => ReactNode
}

/**
 * 1 つの API の応答で中身が決まる画面(詳細など)を、API の状態で出し分ける。
 * 読み込み中・エラーは画面全体をその表示にし、404 は 404 の画面を出す。
 * 旧は API が失敗すると alert を出してページを読み直していたが、新はこの表示を出す(#98)。
 */
export default function ApiView<T>({ state, onRetry, children }: Props<T>) {
  switch (state.kind) {
    case 'success':
      return children(state.data)
    case 'not-found':
      return <NotFound />
    case 'loading':
      return (
        <main className={styles.page}>
          <Loading />
        </main>
      )
    case 'http-error':
    case 'network-error':
      return (
        <main className={styles.page}>
          <ApiError error={state} onRetry={onRetry} />
        </main>
      )
  }
}
