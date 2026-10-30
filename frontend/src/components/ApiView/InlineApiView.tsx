import type { ReactNode } from 'react'
import type { ApiState } from '../../api/useApi'
import ApiError from './ApiError'
import Loading from './Loading'

type Props<T> = {
  /** useApi が返す状態 */
  state: ApiState<T>
  /** useApi が返す retry。エラーの表示の「もう一度読み込む」で呼ぶ */
  onRetry: () => void
  /** 成功したときの中身 */
  children: (data: T) => ReactNode
}

/**
 * 画面の一部(モーダルの中・ページの中の欄)を、API の状態で出し分ける。読み込み中・エラーの表示は ApiView と同じ部品。
 * 画面全体を出し分ける ApiView と違い `<main>` を出さず、404 も 404 の画面にせず、ほかのエラーと同じく読み直せるようにする
 * (欄の API は 404 を返さないので、返ったときは不具合として扱う)
 */
export default function InlineApiView<T>({ state, onRetry, children }: Props<T>) {
  switch (state.kind) {
    case 'success':
      return children(state.data)
    case 'loading':
      return <Loading />
    case 'not-found':
      return <ApiError error={{ kind: 'http-error', status: 404 }} onRetry={onRetry} />
    case 'http-error':
    case 'network-error':
      return <ApiError error={state} onRetry={onRetry} />
  }
}
