import { useCallback, useEffect, useMemo } from 'react'
import { useLocation, useNavigate } from 'react-router'
import { api } from '../api/client'
import type { paths } from '../api/schema'
import { useApi } from '../api/useApi'
import InlineApiView from '../components/ApiView/InlineApiView'
import CocktailHitCount from '../components/CocktailTable/CocktailHitCount'
import CocktailTable from '../components/CocktailTable/CocktailTable'
import type { CocktailSort } from '../components/CocktailTable/CocktailTable.types'
import Pager from '../components/Pager/Pager'
import { usePageTitle } from '../components/SiteLayout/usePageTitle'
import { SubmittingForm } from '../features/search/CocktailSearch'
import { toSearchConditions, type SearchFormValues } from '../features/search/searchForm'
import { useSearchFormValues } from '../features/search/searchFormValuesContext'
import {
  formValuesFromQuery,
  parseSearchQuery,
  searchQueryFromConditions,
  searchRequestBody,
  searchResultUrl,
} from '../features/search/searchQuery'
import { buildSelectionTree, type SelectionTree } from '../features/search/selection'
import { sortSearchFormTree } from '../features/search/sortSearchFormTree'
import styles from './CocktailSearchResult.module.css'

type SearchForm = paths['/search-form']['get']['responses'][200]['content']['application/json']

const fetchSearchForm = () => api.GET('/search-form')

/**
 * 条件検索の結果の画面(旧 Results/ResultListCocktail.vue。#102)。URL のクエリの条件(searchQuery.ts)で検索する。
 *
 * - 条件は URL から読む。読み直す・URL を直接開く・戻るボタンで、その URL の条件と結果に戻る
 *   (旧は条件を画面の中の状態にだけ持ち、URL に残さなかった。旧との差分。#102)
 * - URL の条件は、ヘッダーのモーダルの条件検索のフォームにも戻す(開くと、いまの結果の条件が入っている)
 * - 材料が 1 つも無い(クエリ無しの /result/Cocktails・不正な ID だけ)ときは、検索せず、条件の無い検索の画面(フォーム)を出す
 * - 不正な値(数字でない ID・知らない mode・負のページなど)は無視して既定値にする
 *
 * 材料の木と技法の一覧(GET /api/search-form)を読んでから、URL の ID を木と突き合わせて検索する。
 * 一覧の表の並べ替えは URL の sort・order に、ページは page に入れる(#103。1 ページ 50 件・既定の並びは ID の昇順。
 * docs/decisions/search-screen.md の決まったこと 5)。件数は全ページの合計を出し、0 件でも見出しの行だけの表を出す(旧と同じ)
 */
export default function CocktailSearchResult() {
  usePageTitle('カクテル検索結果')
  const { search } = useLocation()
  const { state, retry } = useApi(fetchSearchForm)

  return (
    <main className={styles.page}>
      <h1 className={styles.title}>結果</h1>
      {/* 見出しは読み込みを待たずに出す。読み込み中・失敗の表示は見出しの下に出す */}
      <InlineApiView state={state} onRetry={retry}>
        {(form) => <SearchFromUrl form={form} search={search} />}
      </InlineApiView>
    </main>
  )
}

/** URL の条件をフォームの値に戻し、材料があれば検索する */
function SearchFromUrl({ form, search }: { form: SearchForm; search: string }) {
  const { values, setValues } = useSearchFormValues()
  const tree = useMemo(() => buildSelectionTree(sortSearchFormTree(form.categories)), [form.categories])
  const query = useMemo(() => parseSearchQuery(search), [search])
  const restored = useMemo(() => formValuesFromQuery(tree, form.methods, query), [tree, form.methods, query])

  // URL が変わるたびに、ヘッダーのモーダルのフォームの値を URL の条件にする
  useEffect(() => {
    setValues(restored)
  }, [restored, setValues])

  if (restored.selection.size === 0) {
    return (
      <SubmittingForm categories={form.categories} methods={form.methods} values={values} onValuesChange={setValues} />
    )
  }
  return <SearchResults form={form} tree={tree} restored={restored} sort={query.sort} page={query.page} />
}

type SearchResultsProps = {
  form: SearchForm
  tree: SelectionTree
  /** URL から戻したフォームの値 */
  restored: SearchFormValues
  sort: CocktailSort | null
  page: number
}

/** URL の条件で検索し、件数と一覧の表を出す */
function SearchResults({ form, tree, restored, sort, page }: SearchResultsProps) {
  const navigate = useNavigate()
  const conditions = useMemo(() => toSearchConditions(form.methods, restored), [form.methods, restored])
  const request = useMemo(() => {
    const body = searchRequestBody(tree, conditions, sort, page)
    return () => api.POST('/cocktails/search', { body })
  }, [tree, conditions, sort, page])
  const { state, retry } = useApi(request)

  // 並べ替えを変えたら、同じ条件で 1 ページ目から
  const onSortChange = useCallback(
    (next: CocktailSort) => {
      void navigate(searchResultUrl({ ...searchQueryFromConditions(tree, form.methods, conditions), sort: next }))
    },
    [navigate, tree, form.methods, conditions],
  )
  // ページを移っても、条件と並びはそのまま
  const pageUrl = useCallback(
    (next: number) => searchResultUrl({ ...searchQueryFromConditions(tree, form.methods, conditions), sort, page: next }),
    [tree, form.methods, conditions, sort],
  )

  return (
    <InlineApiView state={state} onRetry={retry}>
      {(result) => (
        <section aria-label="カクテル検索結果">
          <CocktailHitCount heading="カクテル検索結果" count={result.meta.total} />
          <CocktailTable cocktails={result.data} sort={{ mode: 'controlled', sort, onSortChange }} label="カクテル検索結果" />
          <Pager current={page} last={result.meta.last_page} hrefOf={pageUrl} label="カクテル検索結果のページ" />
        </section>
      )}
    </InlineApiView>
  )
}
