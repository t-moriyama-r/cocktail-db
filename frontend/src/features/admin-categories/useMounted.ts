import { useEffect, useRef, type RefObject } from 'react'

/**
 * 画面に出ている間だけ current が true の参照。保存・付け替え・削除の応答を待つ間に別の画面へ移っていたら、
 * 応答の後にカテゴリ木の画面へ戻さないために使う
 */
export function useMounted(): RefObject<boolean> {
  const mounted = useRef(false)
  useEffect(() => {
    mounted.current = true
    return () => {
      mounted.current = false
    }
  }, [])
  return mounted
}
