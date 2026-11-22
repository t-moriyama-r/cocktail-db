import { useCallback } from 'react'
import { useParams } from 'react-router'
import { api } from '../api/client'
import { useApi } from '../api/useApi'
import ApiView from '../components/ApiView/ApiView'
import CocktailDetailView from '../features/cocktail-detail/CocktailDetailView'

/**
 * カクテルの詳細の画面(旧 Detail/CocktailDetail.vue)。URL の ID のカクテルを GET /api/cocktails/{id} で取って出す。
 * ID が数字であることはルート(RequireNumericId)が確かめている。無い ID・下書きは API が 404 を返し、404 の画面を出す。
 */
export default function CocktailDetail() {
  const { id } = useParams()
  const cocktailId = Number(id)
  const fetchCocktail = useCallback(
    () => api.GET('/cocktails/{cocktail}', { params: { path: { cocktail: cocktailId } } }),
    [cocktailId],
  )
  const { state, retry } = useApi(fetchCocktail)

  return (
    <ApiView state={state} onRetry={retry}>
      {(response) => <CocktailDetailView cocktail={response.data} />}
    </ApiView>
  )
}
