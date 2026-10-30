import { createContext, useContext } from 'react'
import { defaultSearchFormValues, type SearchFormValues } from './searchForm'

type SearchFormValuesState = {
  readonly values: SearchFormValues
  readonly setValues: (next: SearchFormValues) => void
}

/**
 * ヘッダーのモーダルの条件検索のフォームの値(#102)。SearchFormValuesProvider が持つ。
 * 結果の画面が URL から戻した条件をここに入れ、モーダルを開くと、いま出している結果の条件が入っている
 * (旧も、結果の画面でモーダルを開くと検索した条件が残っていた)。
 * Provider の外では既定値を返し、書き換えは捨てる(部品を単体で描くテストのため)
 */
export const SearchFormValuesContext = createContext<SearchFormValuesState>({
  values: defaultSearchFormValues,
  setValues: () => {},
})

export function useSearchFormValues(): SearchFormValuesState {
  return useContext(SearchFormValuesContext)
}
