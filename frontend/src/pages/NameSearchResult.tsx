import { useCallback } from 'react'
import { useParams } from 'react-router'
import { api } from '../api/client'
import type { components } from '../api/schema'
import { useApi } from '../api/useApi'
import ApiView from '../components/ApiView/ApiView'
import BrandTable from '../components/BrandTable/BrandTable'
import type { BrandTableRow } from '../components/BrandTable/BrandTable.types'
import CocktailHitCount from '../components/CocktailTable/CocktailHitCount'
import CocktailTable from '../components/CocktailTable/CocktailTable'
import NodeTable from '../features/name-search/NodeTable'
import styles from './NameSearchResult.module.css'

type NameSearchBrand = components['schemas']['NameSearchBrandResource']

const LOCAL_SORT = { mode: 'local' } as const

/**
 * 名前検索の結果の画面(旧 Results/fromNameResult.vue・Results/children/*)。URL の :word で GET /api/search を呼び、
 * 材料名で当たったカクテル(旧のインテリジェンスサーチ。#173)・カクテル・ノード・銘柄を、種類ごとの欄に全件出す。
 * 0 件の欄は欄ごと出さない(旧と同じ)。
 *
 * 旧はヘッダーの検索結果を写すだけで API を呼ばなかったので、URL を直に開く・読み直すと空になった。
 * 新はいつも URL の語で検索する(旧との差分。#52)。/result/FromNameResultEx/:word も同じ画面(#97)
 */
export default function NameSearchResult() {
  const { word = '' } = useParams()
  const request = useCallback(() => api.GET('/search', { params: { query: { q: word } } }), [word])
  const { state, retry } = useApi(request)

  return (
    <ApiView state={state} onRetry={retry}>
      {(data) => {
        const matchedAliases = new Map(
          data.cocktails.flatMap((c) => (c.matched_alias ? [[c.id, c.matched_alias] as const] : [])),
        )
        const searchWord = `(検索語句：${word})`
        return (
          <main className={styles.page}>
            <h1 className={styles.title}>結果</h1>

            {data.ingredient_matches.length > 0 && (
              <section className={styles.section} aria-label="インテリジェンスサーチ結果">
                <CocktailHitCount heading="インテリジェンスサーチ結果" count={data.ingredient_matches.length}>
                  {searchWord}
                </CocktailHitCount>
                <CocktailTable cocktails={data.ingredient_matches} sort={LOCAL_SORT} label="インテリジェンスサーチ結果" />
              </section>
            )}

            {data.cocktails.length > 0 && (
              <section className={styles.section} aria-label="カクテル検索結果">
                <CocktailHitCount heading="カクテル検索結果" count={data.cocktails.length}>
                  {searchWord}
                </CocktailHitCount>
                <CocktailTable
                  cocktails={data.cocktails}
                  sort={LOCAL_SORT}
                  label="カクテル検索結果"
                  matchedAliases={matchedAliases}
                />
              </section>
            )}

            {data.nodes.length > 0 && (
              <section className={styles.section} aria-label="カテゴリー検索結果">
                <p className={styles.hitCount}>
                  カテゴリー検索結果 {data.nodes.length}件ヒットしました。{searchWord}
                </p>
                <NodeTable nodes={data.nodes} label="カテゴリー検索結果" />
              </section>
            )}

            {data.brands.length > 0 && (
              <section className={styles.section} aria-label="銘柄検索結果">
                <p className={styles.hitCount}>
                  銘柄検索結果 {data.brands.length}件ヒットしました。{searchWord}
                </p>
                <BrandTable brands={data.brands.map(toBrandRow)} label="銘柄検索結果" />
              </section>
            )}
          </main>
        )
      }}
    </ApiView>
  )
}

/** API の銘柄を、銘柄の一覧の表の行にする。名前検索の応答は更新日時を持たないので、動画の列の並びは渡した順のまま */
function toBrandRow(brand: NameSearchBrand): BrandTableRow {
  return {
    id: brand.id,
    name: brand.name,
    name_eng: brand.name_eng,
    path: brand.path,
    strength: brand.volume,
    youtube: brand.youtube,
    updated_at: null,
  }
}
