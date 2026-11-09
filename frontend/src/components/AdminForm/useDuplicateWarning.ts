import { useRef, useState } from 'react'

/**
 * 重なりを比べる範囲。key が同じ(===)なら同じ範囲とみなす。
 * ノードは親の ID(根どうしなら null。同じ親の下でしか重なりは意味を持たない)、銘柄は全体で比べるので決まった値にする
 */
export type DuplicateScope<K> = { key: K }

/**
 * 名前・英名の重なりの知らせ(旧の duplicate_check。銘柄は #633、ノードは #674。共有にしたのは #704)。
 * 欄を離れたときに check へ欄の値を渡すと、find で重なりを問い合わせて warning(「同じ名前が存在しています」か null)を更新する。
 * find は、重なれば知らせの文を、重ならなければ null を返す。問い合わせに失敗したときも null を返す
 * (知らせるだけの重なりで画面に誤りを出す理由が無いので、重ならないときと同じく何も出さない)。
 *
 * 結果は比べた範囲と組にして持つ。scope の範囲が変わったら、前の範囲で出した知らせは出さず、同じ値のまま check しても
 * 新しい範囲で問い合わせ直す。比べる範囲が決まらない(scope が null)うちは問い合わせない。
 * 旧の @change と同じく、前に問い合わせたとき(最初は開いたとき)から値も範囲も変わっていなければ問い合わせない。
 * 空の名前はどれとも重ならないので、問い合わせずに知らせを消す。
 * 続けて問い合わせると先の応答が後から返ることがあるので、今の欄の値と違う結果で上書きしないよう、最後の問い合わせの結果だけを使う。
 */
export function useDuplicateWarning<K>(
  find: (value: string, key: K) => Promise<string | null>,
  scope: DuplicateScope<K> | null,
  initialValue: string,
) {
  const [result, setResult] = useState<{ key: K; message: string } | null>(null)
  const checked = useRef<{ key: K; value: string } | null>(scope === null ? null : { key: scope.key, value: initialValue })
  const latest = useRef(0)

  const check = (value: string) => {
    if (scope === null) return
    const { key } = scope
    if (checked.current !== null && checked.current.key === key && checked.current.value === value) return
    checked.current = { key, value }
    const request = ++latest.current
    if (value === '') {
      setResult(null)
      return
    }
    void find(value, key).then((message) => {
      if (request === latest.current) setResult(message === null ? null : { key, message })
    })
  }

  const warning = result !== null && scope !== null && result.key === scope.key ? result.message : null
  return { warning, check }
}
