import { useRef, useState } from 'react'

/**
 * 重なりを比べる範囲。key が同じ(===)なら同じ範囲とみなす。
 * ノードは親の ID(根どうしなら null。同じ親の下でしか重なりは意味を持たない)、銘柄は全体で比べるので決まった値にする
 */
export type DuplicateScope<K> = { key: K }

/** 比べる範囲が決まらない(scope が null)ことを表す印。根どうし(key が null)と見分けるため */
const NO_SCOPE = Symbol('no-scope')

/**
 * 名前・英名の重なりの知らせ(旧の duplicate_check。銘柄は #633、ノードは #674。共有にしたのは #704)。
 * 欄を離れたときに check へ欄の値を渡すと、find で重なりを問い合わせて warning(「同じ名前が存在しています」か null)を更新する。
 * find は、重なれば知らせの文を、重ならなければ null を返す。問い合わせに失敗したときも null を返す
 * (知らせるだけの重なりで画面に誤りを出す理由が無いので、重ならないときと同じく何も出さない)。
 *
 * 範囲(scope の key)が変わったら、前の範囲で問い合わせた結果と、問い合わせ済みの値を捨てる(#738)。
 * 前の範囲で出した知らせは出さず、前の範囲に戻しても、戻す前の結果は出さない。範囲が変わった後に欄を離れたら、
 * 値が同じでも今の範囲で問い合わせ直す。変わる前に送った問い合わせの応答が後から返っても使わない。
 * 比べる範囲が決まらない(scope が null)うちは問い合わせない。
 * 範囲が変わらないあいだは、旧の @change と同じく、前に問い合わせたとき(最初は開いたとき)から値が変わっていなければ問い合わせない。
 * 空の名前はどれとも重ならないので、問い合わせずに知らせを消す。
 * 続けて問い合わせると先の応答が後から返ることがあるので、今の欄の値と違う結果で上書きしないよう、最後の問い合わせの結果だけを使う。
 */
export function useDuplicateWarning<K>(
  find: (value: string, key: K) => Promise<string | null>,
  scope: DuplicateScope<K> | null,
  initialValue: string,
) {
  const scopeKey: K | typeof NO_SCOPE = scope === null ? NO_SCOPE : scope.key
  // 範囲が変わるたびに 1 つ進める番号。結果と問い合わせ済みの値をこの番号と組にして持ち、番号が違えば捨てたものとみなす
  const [range, setRange] = useState({ key: scopeKey, generation: 0 })
  if (range.key !== scopeKey) setRange({ key: scopeKey, generation: range.generation + 1 })
  const generation = range.key === scopeKey ? range.generation : range.generation + 1

  const [result, setResult] = useState<{ generation: number; message: string } | null>(null)
  const checked = useRef<{ generation: number; value: string } | null>(scope === null ? null : { generation: 0, value: initialValue })
  const latest = useRef(0)

  const check = (value: string) => {
    if (scope === null) return
    const { key } = scope
    if (checked.current !== null && checked.current.generation === generation && checked.current.value === value) return
    checked.current = { generation, value }
    const request = ++latest.current
    if (value === '') {
      setResult(null)
      return
    }
    void find(value, key).then((message) => {
      if (request === latest.current) setResult(message === null ? null : { generation, message })
    })
  }

  const warning = result !== null && scope !== null && result.generation === generation ? result.message : null
  return { warning, check }
}
