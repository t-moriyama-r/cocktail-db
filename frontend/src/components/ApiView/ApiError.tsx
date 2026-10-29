import type { ApiResult } from '../../api/apiResult'
import styles from './ApiView.module.css'

type Props = {
  error: Extract<ApiResult<unknown>, { kind: 'http-error' | 'network-error' }>
  /** 「もう一度読み込む」で呼ぶ。ページは読み直さず、API だけを呼び直す */
  onRetry: () => void
}

/** API が失敗したときの表示。何が起きたかと、もう一度読み込むボタンを出す */
export default function ApiError({ error, onRetry }: Props) {
  return (
    <div className={styles.error} role="alert">
      <p>{message(error)}</p>
      <button className={styles.retry} type="button" onClick={onRetry}>
        もう一度読み込む
      </button>
    </div>
  )
}

function message(error: Props['error']): string {
  switch (error.kind) {
    case 'network-error':
      return '通信に失敗しました。接続を確かめて、もう一度お試しください。'
    case 'http-error':
      return `データを読み込めませんでした(エラー ${error.status})。時間をおいて、もう一度お試しください。`
  }
}
