import { useCallback } from 'react'
import { api } from '../../api/client'
import { useApi } from '../../api/useApi'
import ApiError from '../../components/ApiView/ApiError'
import CocktailNotice from './CocktailNotice'

type Props = {
  cocktailId: number
}

/**
 * 英名が同じ、ほかのカクテル(旧 Detail/CocktailDetail.vue の same_name_cocktails)。GET /api/cocktails/{id}/same-name で取る。
 *
 * - 1 件以上のときだけ「同じ名前のカクテルがあります！」と表を出す。0 件・読み込み中は何も出さない(旧と同じ)
 * - 404(下書き・無い ID)は、同じ名前が無いのと同じく出さない。詳細の画面が 404 の画面を出す
 * - 失敗したら、旧の alert と読み直しの代わりに、エラーの表示と「もう一度読み込む」を出す(#98)
 */
export default function SameNameCocktails({ cocktailId }: Props) {
  const request = useCallback(
    () => api.GET('/cocktails/{cocktail}/same-name', { params: { path: { cocktail: cocktailId } } }),
    [cocktailId],
  )
  const { state, retry } = useApi(request)

  switch (state.kind) {
    case 'success':
      return state.data.data.length > 0 ? (
        <CocktailNotice message="同じ名前のカクテルがあります！" cocktails={state.data.data} />
      ) : null
    case 'http-error':
    case 'network-error':
      return <ApiError error={state} onRetry={retry} />
    case 'loading':
    case 'not-found':
      return null
  }
}
