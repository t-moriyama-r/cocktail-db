import { useRef, useState } from 'react'
import { findCategoryDuplicate, type DuplicateField } from './categoryApi'

/** 重なりを比べる相手。parentId の子どうし(null なら根どうし)で比べる */
type Scope = { parentId: number | null }

/**
 * ノードの名前・英名の重なりの知らせ(旧の duplicate_check。#674)。欄を離れたときに check へ欄の値を渡すと、
 * 重なりを問い合わせて warning(「同じ名前が存在しています」か null)を更新する。
 *
 * 重なりは同じ親の下でしか意味を持たないので、結果は比べた親と組にして持つ。scope の親が変わったら、前の親で出した知らせは出さず、
 * 同じ値のまま check しても新しい親で問い合わせ直す。比べる親が決まらない(scope が null)うちは問い合わせない。
 * 旧の @change と同じく、前に問い合わせたとき(最初は開いたとき)から値も親も変わっていなければ問い合わせない。
 * 空の名前はどれとも重ならないので、問い合わせずに知らせを消す。
 * 問い合わせに失敗したときは、知らせるだけの重なりで画面に誤りを出す理由が無いので、重ならないときと同じく何も出さない。
 * 続けて問い合わせると先の応答が後から返ることがあるので、今の欄の値と違う結果で上書きしないよう、最後の問い合わせの結果だけを使う。
 */
export function useCategoryDuplicateWarning(field: DuplicateField, nodeId: number | null, scope: Scope | null, initialValue: string) {
  const [result, setResult] = useState<{ parentId: number | null; message: string } | null>(null)
  const checked = useRef<{ parentId: number | null; value: string } | null>(
    scope === null ? null : { parentId: scope.parentId, value: initialValue },
  )
  const latest = useRef(0)

  const check = (value: string) => {
    if (scope === null) return
    const { parentId } = scope
    if (checked.current !== null && checked.current.parentId === parentId && checked.current.value === value) return
    checked.current = { parentId, value }
    const request = ++latest.current
    if (value === '') {
      setResult(null)
      return
    }
    void findCategoryDuplicate(field, value, parentId, nodeId).then((message) => {
      if (request === latest.current) setResult(message === null ? null : { parentId, message })
    })
  }

  const warning = result !== null && scope !== null && result.parentId === scope.parentId ? result.message : null
  return { warning, check }
}
