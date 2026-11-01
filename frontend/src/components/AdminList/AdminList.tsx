import { useCallback, useState, type Key, type ReactNode } from 'react'
import type { FetchResult } from '../../api/apiResult'
import { useApi } from '../../api/useApi'
import InlineApiView from '../ApiView/InlineApiView'
import { toAdminListQuery } from './adminListQuery'
import styles from './AdminList.module.css'

/** 一覧の API に渡す条件。q は前後の空白を除いた絞り込みの語で、空なら undefined(絞り込まない) */
export type AdminListQuery = { q: string | undefined; page: number }

/** 一覧の API の応答のうち、ここで使う部分(Laravel のページに分けた応答。`/admin/cocktails` など) */
export type AdminListPage<T> = { data: T[]; meta: { current_page: number; last_page: number } }

type Props<T> = {
  /**
   * 条件を受け取って一覧の API を呼ぶ。描画のたびに作り直さないもの(モジュールの定数か useCallback)を渡す。
   * 絞り込み(部分一致・かなと全角のそろえ方)・並び・ページに切るのは API がする(#284 の 6)
   */
  load: (query: AdminListQuery) => Promise<FetchResult<AdminListPage<T>>>
  /** 行の key */
  itemKey: (item: T) => Key
  /** 1 行の中身。「名前(英名)」のリンクは AdminListLink */
  renderItem: (item: T) => ReactNode
  /** 絞り込みの欄を出すか。出さないときは、いつも絞り込まずに呼ぶ(旧はノードの編集画面の下の子の一覧で出さなかった) */
  searchable?: boolean
}

/**
 * 管理画面の一覧の共通部品(旧 `Admin/Super/ListComponent.vue`)。絞り込みの欄・行の並び・ページ送りを出す。
 *
 * 旧と同じく、入れたそばから絞り込む(送信のボタンは無い)。旧は全件を受け取って画面で絞ったが、新は語を API に渡し、
 * API が絞って並べた 1 ページ分を出す(#284 の 6: 部分一致・50 件ずつ)。語を変えたら 1 ページ目に戻る。
 * 旧は 0 件のとき何も出さなかったが、新は「該当するものがありません」と出す(読み込み中・失敗と見分けるため。#120)
 */
export default function AdminList<T>({ load, itemKey, renderItem, searchable = true }: Props<T>) {
  const [input, setInput] = useState('')
  const [page, setPage] = useState(1)
  const q = searchable ? toAdminListQuery(input) : undefined

  const request = useCallback(() => load({ q, page }), [load, q, page])
  const { state, retry } = useApi(request)

  const changeInput = (value: string) => {
    // 前後の空白だけを変えたときは絞り込みが変わらないので、ページも戻さない
    if (toAdminListQuery(value) !== q) {
      setPage(1)
    }
    setInput(value)
  }

  return (
    <div className={styles.list}>
      {searchable && (
        <label className={styles.search}>
          <span className={styles.searchLabel}>絞り込み</span>
          <input
            className={styles.searchInput}
            type="search"
            value={input}
            onChange={(event) => changeInput(event.target.value)}
          />
        </label>
      )}
      <InlineApiView state={state} onRetry={retry}>
        {({ data, meta }) => (
          <>
            {data.length === 0 ? (
              <p className={styles.empty}>該当するものがありません</p>
            ) : (
              <ul className={styles.items}>
                {data.map((item) => (
                  <li key={itemKey(item)} className={styles.item}>
                    {renderItem(item)}
                  </li>
                ))}
              </ul>
            )}
            {meta.last_page > 1 && (
              <nav className={styles.pager} aria-label="ページ送り">
                <button
                  className={styles.pageButton}
                  type="button"
                  disabled={meta.current_page <= 1}
                  onClick={() => setPage(meta.current_page - 1)}
                >
                  前のページ
                </button>
                <span>
                  {meta.current_page} / {meta.last_page} ページ
                </span>
                <button
                  className={styles.pageButton}
                  type="button"
                  disabled={meta.current_page >= meta.last_page}
                  onClick={() => setPage(meta.current_page + 1)}
                >
                  次のページ
                </button>
              </nav>
            )}
          </>
        )}
      </InlineApiView>
    </div>
  )
}
