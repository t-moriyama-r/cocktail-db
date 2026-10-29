import { useCallback, useId, useState } from 'react'
import { useApi } from '../../api/useApi'
import ApiError from '../ApiView/ApiError'
import CocktailTable from '../CocktailTable/CocktailTable'
import type { CocktailTableRow } from '../CocktailTable/CocktailTable.types'
import FontAwesomeIcon from '../CocktailTable/FontAwesomeIcon'
import styles from './RandomCocktails.module.css'
import type { RandomCocktailsOrigin } from './RandomCocktails.types'
import { fetchRandomCocktails } from './fetchRandomCocktails'

/**
 * 起点から選んだランダムなカクテルの一覧と「再取得」(旧 Detail/component/RandomCocktail.vue)。
 * カテゴリ・銘柄・カクテル・用語の詳細で使う。
 *
 * - 見出しは旧と同じ。0 件でも、読み込み中でも出す(読み込み中は見出しだけ)
 * - 「再取得」を押すと、同じ起点で取り直す。旧と同じく、新しい一覧が届くまでは今の一覧を出したままにする
 * - 起点が変わったら、今の一覧を消して取り直す(旧の用語詳細は技法が変わっても取り直さなかった。写さない)
 * - API が失敗したら、旧の alert と読み直しの代わりに、エラーの表示と「もう一度読み込む」を出す(#98)
 */
type Props = {
  origin: RandomCocktailsOrigin
  /** 出す件数(既定 5。旧の用語の技法・グラスは 7) */
  limit?: number
}

const DEFAULT_LIMIT = 5

/** 0 件の一覧。描画のたびに作り直すと、覚えた一覧と別物になって覚え直し続けるので、1 つを使い回す */
const NO_ROWS: readonly CocktailTableRow[] = []

export default function RandomCocktails({ origin, limit = DEFAULT_LIMIT }: Props) {
  const { kind, id } = origin
  const request = useCallback(() => fetchRandomCocktails({ kind, id }, limit), [kind, id, limit])
  const { state, retry } = useApi(request)

  // 「再取得」の読み込み中も前の一覧を出せるよう、同じ起点で最後に届いた一覧を覚えておく(描画の中で前の描画の値を覚える形)
  const [shown, setShown] = useState<{ request: typeof request; rows: readonly CocktailTableRow[] } | null>(null)
  // 起点のカクテルが下書き・無い ID(404)は、当たるものが無いのと同じく 0 件の表にする
  // 起点がカクテル以外の 404 は、0 件ではなく API のエラーとして出す
  const emptyOrigin = state.kind === 'not-found' && kind === 'cocktail'
  const latest = state.kind === 'success' ? state.data.data : emptyOrigin ? NO_ROWS : null
  const apiError =
    state.kind === 'not-found' && kind !== 'cocktail'
      ? ({ kind: 'http-error', status: 404 } as const)
      : state.kind === 'http-error' || state.kind === 'network-error'
        ? state
        : null
  if (latest !== null && (shown?.request !== request || shown.rows !== latest)) {
    setShown({ request, rows: latest })
  } else if (latest === null && state.kind !== 'loading' && shown !== null) {
    setShown(null)
  }

  const headingId = useId()
  const heading = randomCocktailsHeading(kind, limit)
  const rows = latest ?? (state.kind === 'loading' && shown?.request === request ? shown.rows : null)

  return (
    <section className={styles.section} aria-labelledby={headingId} aria-busy={state.kind === 'loading'}>
      <div className={styles.header}>
        <h2 className={styles.heading} id={headingId}>
          <span className={styles.squareDot} aria-hidden="true" />
          {heading}
        </h2>
        <button className={styles.reget} type="button" onClick={retry}>
          <FontAwesomeIcon icon="redo" label={null} />
          再取得
        </button>
      </div>
      {apiError !== null && <ApiError error={apiError} onRetry={retry} />}
      {rows !== null && <CocktailTable cocktails={rows} sort={{ mode: 'local' }} label={heading} />}
    </section>
  )
}

/** 見出し(旧と同じ)。カクテル起点は「材料が似たカクテル」、ベース起点は「同じベースのカクテル」、ほかは件数つきの「これを使ったカクテル」 */
function randomCocktailsHeading(kind: RandomCocktailsOrigin['kind'], limit: number): string {
  switch (kind) {
    case 'cocktail':
      return '材料が似たカクテル'
    case 'base':
      return '同じベースのカクテル'
    default:
      return `これを使ったカクテル(ランダム${limit}種)`
  }
}
