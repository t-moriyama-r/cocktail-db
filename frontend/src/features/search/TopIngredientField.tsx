import { useState } from 'react'
import { api } from '../../api/client'
import { useApi } from '../../api/useApi'
import IngredientTreeField from './IngredientTreeField'
import { emptySelection, type TreeSelection } from './selection'

const fetchSearchForm = () => api.GET('/search-form')

/**
 * トップの下に置く、材料の欄(#305)。受け入れ条件の E2E で画面から確かめられるようにするための仮の置き場所で、
 * 検索のフォーム(#307)ができたらフォームの中へ移す。検索ボタンはまだ無いので、選んだ状態は持つだけで送らない。
 * 読み込み中・失敗のときは何も出さない(この欄は、フォームができるまでの仮の置きものなので)。
 */
export default function TopIngredientField() {
  const { state } = useApi(fetchSearchForm)
  const [selection, setSelection] = useState<TreeSelection>(emptySelection)

  if (state.kind !== 'success') {
    return null
  }
  return (
    <section>
      <IngredientTreeField categories={state.data.categories} selection={selection} onSelectionChange={setSelection} />
    </section>
  )
}
