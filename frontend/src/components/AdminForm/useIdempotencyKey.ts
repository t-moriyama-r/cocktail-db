import { useCallback, useRef } from 'react'

/**
 * 新規の保存の送信に付ける冪等キー(`Idempotency-Key`。docs/decisions/admin-idempotency.md。FE は #758)。
 *
 * キーは「1 つの保存」ごとに決める。current は、reset するまで同じキーを返す。
 * - 応答が返らなかった・422 などで画面に残った後の送り直しは、入力を直していても同じキーで送る。
 *   応答が失われた 1 回目が保存できていれば、BE は同じ中身の再送に 1 回目の応答を返し、中身の違う再送を 422
 *   (`errors.idempotency_key`)で断るので、同じ保存で銘柄が 2 件できない。成功しなかった送信は BE が記録しないので、
 *   422 の後に入力を直して同じキーで送っても保存できる
 * - 保存できた後と、BE がキーを断った後は reset する。次の送信には新しいキーを付ける
 *
 * 画面を開き直すと(フックが作り直されると)新しいキーになる。
 */
export function useIdempotencyKey() {
  const key = useRef<string | null>(null)

  const current = useCallback((): string => {
    key.current ??= crypto.randomUUID()
    return key.current
  }, [])

  const reset = useCallback(() => {
    key.current = null
  }, [])

  return { current, reset }
}
