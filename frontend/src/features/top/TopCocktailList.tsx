import { useCallback, useId, useState } from 'react'
import { api } from '../../api/client'
import type { paths } from '../../api/schema'
import { useApi } from '../../api/useApi'
import ApiError from '../../components/ApiView/ApiError'
import CocktailTable from '../../components/CocktailTable/CocktailTable'
import type { CocktailTableRow } from '../../components/CocktailTable/CocktailTable.types'
import FontAwesomeIcon from '../../components/CocktailTable/FontAwesomeIcon'
import styles from './Top.module.css'

/** GET /api/cocktails の並べ方(recent: 更新の新しい順・recommend: 評価が 3 以上からランダム・random: ランダム) */
export type TopCocktailSort = NonNullable<paths['/cocktails']['get']['parameters']['query']>['sort']

/** 旧と同じく、どの一覧も 7 件 */
export const TOP_LIST_LIMIT = 7

type Props = {
  /** 見出し(旧と同じ文言) */
  title: string
  sort: TopCocktailSort
  /** 見出しの横に「再取得」を出す(旧はおすすめ・気まぐれだけに出していた) */
  canReget?: boolean
}

/**
 * トップの 3 つの一覧(最近更新された・おすすめ・気まぐれ)の 1 つ(旧 components/index.vue の new_updates_div)。
 *
 * - 見出しは読み込み中も出し、表は届いてから出す(旧も届くまで表を出さなかった)
 * - 「再取得」を押すと取り直す。新しい一覧が届くまでは、今の一覧を出したままにする(旧と同じ)
 * - API が失敗したら、エラーの表示と「もう一度読み込む」を出す(#98)
 */
export default function TopCocktailList({ title, sort, canReget = false }: Props) {
  const request = useCallback(
    () => api.GET('/cocktails', { params: { query: { sort, limit: TOP_LIST_LIMIT } } }),
    [sort],
  )
  const { state, retry } = useApi(request)

  // 「再取得」の読み込み中も前の一覧を出せるよう、最後に届いた一覧を覚えておく(描画の中で前の描画の値を覚える形)
  const [shown, setShown] = useState<readonly CocktailTableRow[] | null>(null)
  const latest = state.kind === 'success' ? state.data.data : null
  if (latest !== null && shown !== latest) {
    setShown(latest)
  } else if ((state.kind === 'http-error' || state.kind === 'network-error') && shown !== null) {
    setShown(null)
  }
  const rows = latest ?? (state.kind === 'loading' ? shown : null)

  const headingId = useId()
  return (
    <section className={styles.list} aria-labelledby={headingId} aria-busy={state.kind === 'loading'}>
      <div className={styles.listHeader}>
        <h2 className={styles.listHeading} id={headingId}>
          {title}
        </h2>
        {canReget && (
          <button className={styles.reget} type="button" onClick={retry}>
            <FontAwesomeIcon icon="redo" label={null} />
            再取得
          </button>
        )}
      </div>
      {(state.kind === 'http-error' || state.kind === 'network-error') && <ApiError error={state} onRetry={retry} />}
      {/* 404 は返らない API だが、返ったら当たるものが無いのと同じく表を出さない */}
      {rows !== null && <CocktailTable cocktails={rows} sort={{ mode: 'local' }} label={title} />}
    </section>
  )
}
