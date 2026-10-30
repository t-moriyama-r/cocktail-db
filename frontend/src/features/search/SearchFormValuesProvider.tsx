import { useMemo, useState, type ReactNode } from 'react'
import { defaultSearchFormValues, type SearchFormValues } from './searchForm'
import { SearchFormValuesContext } from './searchFormValuesContext'

/** ヘッダーのモーダルと結果の画面が共有する、条件検索のフォームの値を持つ(searchFormValuesContext.ts) */
export default function SearchFormValuesProvider({ children }: { children: ReactNode }) {
  const [values, setValues] = useState<SearchFormValues>(defaultSearchFormValues)
  const state = useMemo(() => ({ values, setValues }), [values])
  return <SearchFormValuesContext.Provider value={state}>{children}</SearchFormValuesContext.Provider>
}
