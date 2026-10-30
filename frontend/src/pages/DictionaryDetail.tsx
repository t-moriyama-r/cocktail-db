import { useCallback } from 'react'
import { Link, useParams } from 'react-router'
import { api } from '../api/client'
import type { components } from '../api/schema'
import { useApi } from '../api/useApi'
import ApiView from '../components/ApiView/ApiView'
import FontAwesomeIcon from '../components/CocktailTable/FontAwesomeIcon'
import Description from '../components/Description/Description'
import RandomCocktails from '../components/RandomCocktails/RandomCocktails'
import type { RandomCocktailsOrigin } from '../components/RandomCocktails/RandomCocktails.types'
import { usePageTitle } from '../components/SiteLayout/usePageTitle'
import { termLabel } from '../features/dictionary/termLabel'
import styles from './DictionaryDetail.module.css'

type DictionaryDetailData = components['schemas']['DictionaryDetailResource']

/** 技法・グラスの用語で出す「これを使ったカクテル」の件数(旧と同じ) */
const RANDOM_COCKTAILS_LIMIT = 7

/**
 * 用語辞典の詳細の画面(旧 Dictionary/detail.vue)。上から、題「〈名前〉(〈英名〉)」・パンくず(分類 → 一覧)・説明文、
 * 技法・グラスの用語なら「これを使ったカクテル(ランダム7種)」と「再取得」。
 *
 * - 無い ID は 404 の画面(旧は何も出さなかった。#98)。数字でない ID はルート(App.tsx)が 404 にする
 * - 旧の API は画像・動画も返していたが、旧の画面は出していなかった。新も出さない
 * - 別の用語へ移ったら、用語もカクテルの欄も取り直す
 */
export default function DictionaryDetail() {
  const id = Number(useParams().id)
  const fetchDictionary = useCallback(
    () => api.GET('/dictionaries/{dictionary}', { params: { path: { dictionary: id } } }),
    [id],
  )
  const { state, retry } = useApi(fetchDictionary)

  return (
    <ApiView state={state} onRetry={retry}>
      {({ data }) => <DictionaryDetailView dictionary={data} />}
    </ApiView>
  )
}

function DictionaryDetailView({ dictionary }: { dictionary: DictionaryDetailData }) {
  const title = termLabel(dictionary)
  usePageTitle(title)
  const origin = randomCocktailsOrigin(dictionary)

  return (
    <main className={styles.page}>
      <h1 className={styles.title}>{title}</h1>
      <nav className={styles.breadcrumb} aria-label="パンくずリスト">
        <FontAwesomeIcon icon="compass" label={null} />
        <Link to="/dictionary">{dictionary.category.name}</Link>
      </nav>
      <div className={styles.description}>
        <Description segments={dictionary.description} />
      </div>
      {origin !== null && (
        <div className={origin.kind === 'method' ? styles.methodCocktails : styles.glassCocktails}>
          <RandomCocktails origin={origin} limit={RANDOM_COCKTAILS_LIMIT} />
        </div>
      )}
    </main>
  )
}

/**
 * 「これを使ったカクテル」の起点。技法の用語は技法(#46 が返す技法の ID)、グラスの用語はグラス(用語の ID)から探す。
 * ほかの用語は出さない。旧は分類の名前(「製法」「グラス」)で判定し、技法の ID と用語の ID が同じことに頼っていた(#46)
 */
function randomCocktailsOrigin(dictionary: DictionaryDetailData): RandomCocktailsOrigin | null {
  if (dictionary.kind === 'method' && dictionary.method_id !== null) {
    return { kind: 'method', id: dictionary.method_id }
  }
  if (dictionary.kind === 'glass') {
    return { kind: 'glass', id: dictionary.id }
  }
  return null
}
