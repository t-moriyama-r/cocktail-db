import { usePageTitle } from '../components/SiteLayout/usePageTitle'

/** 条件検索の結果の画面。中身はまだ無い(#102・#103 で作る) */
export default function CocktailSearchResult() {
  usePageTitle('カクテル検索結果')
  return (
    <main>
      <h1>条件検索の結果</h1>
    </main>
  )
}
