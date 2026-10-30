import { api } from '../../api/client'
import { useApi } from '../../api/useApi'
import ApiError from '../../components/ApiView/ApiError'
import Loading from '../../components/ApiView/Loading'
import CocktailSearchForm, { type CocktailSearchFormProps } from './CocktailSearchForm'

const fetchSearchForm = () => api.GET('/search-form')

type Props = Omit<CocktailSearchFormProps, 'categories' | 'methods'>

/**
 * 条件検索のフォーム(#307)に、フォームの部品(材料の木と技法。GET /api/search-form。#95)を読んで渡す。
 * ヘッダーのモーダル(#99)とトップ(#50)はこれを置く。
 * 読み込み中・失敗の表示は詳細の画面と同じ部品を使う。ただし置く先がモーダルやページの一部なので、
 * 画面全体を出し分ける ApiView(`<main>` を出す)は使わない
 */
export default function CocktailSearch(props: Props) {
  const { state, retry } = useApi(fetchSearchForm)

  switch (state.kind) {
    case 'success':
      return <CocktailSearchForm {...props} categories={state.data.categories} methods={state.data.methods} />
    case 'loading':
      return <Loading />
    case 'not-found':
      // 部品の API は 404 を返さない。返ったときもほかのエラーと同じく、読み直せるようにする
      return <ApiError error={{ kind: 'http-error', status: 404 }} onRetry={retry} />
    case 'http-error':
    case 'network-error':
      return <ApiError error={state} onRetry={retry} />
  }
}
