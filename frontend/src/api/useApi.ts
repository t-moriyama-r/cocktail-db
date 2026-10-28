import { useCallback, useEffect, useState } from 'react'
import { toApiResult, type ApiResult, type FetchResult } from './apiResult'

/** 画面が出し分ける API の状態。応答を待っているあいだは loading */
export type ApiState<T> = { kind: 'loading' } | ApiResult<T>

type Request<T> = () => Promise<FetchResult<T>>

type Settled<T> = { request: Request<T>; attempt: number; result: ApiResult<T> }

const LOADING = { kind: 'loading' } as const

/**
 * 画面を開いたときに API を呼び、状態(読み込み中・成功・404・エラー)と、もう一度呼ぶための retry を返す。
 *
 * request は、描画のたびに作り直さないもの(モジュールの定数か useCallback)を渡す。
 * request が変わる(URL の ID が変わった、など)か retry を呼ぶと、読み込み中に戻して呼び直す。
 * 前の呼び出しの応答が後から返っても、今の状態には使わない。
 */
export function useApi<T>(request: Request<T>): { state: ApiState<T>; retry: () => void } {
  const [attempt, setAttempt] = useState(0)
  const [settled, setSettled] = useState<Settled<T> | null>(null)

  useEffect(() => {
    let current = true
    void toApiResult(request).then((result) => {
      if (current) {
        setSettled({ request, attempt, result })
      }
    })
    return () => {
      current = false
    }
  }, [request, attempt])

  const retry = useCallback(() => setAttempt((count) => count + 1), [])

  // 今の request と回の応答が来るまでは読み込み中(前の request・前の回の結果は出さない)
  const state = settled !== null && settled.request === request && settled.attempt === attempt ? settled.result : LOADING
  return { state, retry }
}
