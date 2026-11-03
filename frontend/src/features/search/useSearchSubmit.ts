import { useCallback } from 'react'
import { useNavigate } from 'react-router'
import type { SearchConditions } from './searchForm'
import { searchQueryFromConditions, searchResultUrl } from './searchQuery'
import type { components } from '../../api/schema'
import { buildSelectionTree } from './selection'
import { sortSearchFormTree } from './sortSearchFormTree'

type SearchFormNode = components['schemas']['SearchFormNodeResource']

/**
 * 検索ボタンで呼ぶ関数を返す。条件を URL のクエリにして、結果の画面へ移る(#102)。
 * 結果の画面にいるときも URL を変えて移る(履歴に積むので、戻るボタンで前の条件と結果に戻る)。
 * 木は画面と同じ並び(sortSearchFormTree)で作り、URL の ID の並びを画面の木の順にそろえる
 */
export function useSearchSubmit(categories: readonly SearchFormNode[], methods: readonly { readonly id: number }[]) {
  const navigate = useNavigate()
  return useCallback(
    (conditions: SearchConditions) => {
      const tree = buildSelectionTree(sortSearchFormTree(categories))
      void navigate(searchResultUrl(searchQueryFromConditions(tree, methods, conditions)))
    },
    [categories, methods, navigate],
  )
}
