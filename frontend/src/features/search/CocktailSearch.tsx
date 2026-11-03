import { api } from '../../api/client'
import { useApi } from '../../api/useApi'
import InlineApiView from '../../components/ApiView/InlineApiView'
import CocktailSearchForm, { type CocktailSearchFormProps } from './CocktailSearchForm'
import { useSearchSubmit } from './useSearchSubmit'

const fetchSearchForm = () => api.GET('/search-form')

type Props = Omit<CocktailSearchFormProps, 'categories' | 'methods' | 'onSubmit'> & {
  /** 検索ボタンで結果の画面へ移った後に呼ぶ(モーダルを閉じるのに使う) */
  onSearched?: () => void
}

type FormProps = Omit<CocktailSearchFormProps, 'onSubmit'> & Pick<Props, 'onSearched'>

/**
 * 条件検索のフォーム(#307)に、フォームの部品(材料の木と技法。GET /api/search-form。#95)を読んで渡す。
 * ヘッダーのモーダル(#99)・トップ(#50)・条件の無い結果の画面(#102)はこれを置く。
 * 検索ボタンを押すと、条件を URL に入れて結果の画面へ移る(#102)。
 * 読み込み中・失敗の表示は詳細の画面と同じ部品を使う。ただし置く先がモーダルやページの一部なので、
 * 画面全体を出し分ける ApiView(`<main>` を出す)ではなく InlineApiView を使う
 */
export default function CocktailSearch(props: Props) {
  const { state, retry } = useApi(fetchSearchForm)
  return (
    <InlineApiView state={state} onRetry={retry}>
      {(data) => <SubmittingForm {...props} categories={data.categories} methods={data.methods} />}
    </InlineApiView>
  )
}

/** 部品を読み終えたフォーム。検索ボタンで結果の画面へ移る */
export function SubmittingForm({ onSearched, ...props }: FormProps) {
  const submit = useSearchSubmit(props.categories, props.methods)
  return (
    <CocktailSearchForm
      {...props}
      onSubmit={(conditions) => {
        submit(conditions)
        onSearched?.()
      }}
    />
  )
}
