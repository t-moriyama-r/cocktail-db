import { Link, useSearchParams } from 'react-router'
import { api } from '../api/client'
import type { paths } from '../api/schema'
import AdminList, { type AdminListQuery } from '../components/AdminList/AdminList'
import AdminListLink from '../components/AdminList/AdminListLink'
import {
  ADMIN_COCKTAILS_PATH,
  ADMIN_DRAFT_COCKTAILS_PATH,
  ADMIN_NEW_COCKTAIL_PATH,
  adminCocktailEditPath,
} from '../components/AdminLayout/adminPaths'
import { usePageTitle } from '../components/SiteLayout/usePageTitle'
import styles from './AdminCocktails.module.css'

/** 一覧に出すカクテルの種類。管理 API(#289)の status と同じ値 */
type CocktailStatus = 'published' | 'draft'

/** 種類を持たせる URL のクエリの名前(`/admin/cocktails?status=draft`) */
const STATUS_PARAM = 'status'

/** 切り替えに並べる種類。公開済みを先(クエリの無い URL)にする */
const STATUSES: readonly { status: CocktailStatus; label: string }[] = [
  { status: 'published', label: '公開済み' },
  { status: 'draft', label: '下書き' },
]

/** URL のクエリの値を種類にする。無い・知らない値なら公開済み */
const toStatus = (value: string | null): CocktailStatus => (value === 'draft' ? 'draft' : 'published')

/** その種類の一覧を開く URL。公開済みはクエリを付けない(メニューから開いたときと同じ URL にする) */
const statusPath = (status: CocktailStatus): string => (status === 'published' ? ADMIN_COCKTAILS_PATH : ADMIN_DRAFT_COCKTAILS_PATH)

/** 種類ごとに一覧の API を呼ぶ関数。AdminList に渡すので、描画のたびに作り直さないようモジュールで作る */
const loadCocktails = (status: CocktailStatus) => ({ q, page }: AdminListQuery) =>
  api.GET('/admin/cocktails', { params: { query: { status, q, page } } })

const LOADERS = { published: loadCocktails('published'), draft: loadCocktails('draft') } as const

/** 一覧の 1 行(管理 API の応答の型) */
type CocktailItem = paths['/admin/cocktails']['get']['responses'][200]['content']['application/json']['data'][number]

const itemKey = (cocktail: CocktailItem) => cocktail.id

const renderItem = (cocktail: CocktailItem) => (
  <AdminListLink to={adminCocktailEditPath(cocktail.id)} name={cocktail.name} nameEng={cocktail.name_eng} />
)

/**
 * 管理画面のカクテルの一覧(/admin/cocktails。#126)。公開済み・下書きを切り替えて出す。
 *
 * 旧は公開済み(`/admin/list/Cocktail`)と下書き(`/admin/list/CocktailDraft`)が別の画面で、メニューの
 * 「カクテル編集」「ドラフト編集」から開いた(旧 `Admin/Super/CocktailList.vue`・`CocktailDraftList.vue`)。
 * 新は 1 つの画面にまとめ、どちらを出しているかを URL のクエリ(`?status=draft`)に持たせる(ブックマーク・戻るで同じ一覧に戻れる)。
 * 下書きかどうかは API が決める(保存のボタンで決まる。docs/decisions/admin-screens.md の 1)。
 *
 * 一覧そのもの(絞り込み・並び・ページ送り)は共通の部品(AdminList。#120)。1 行は旧と同じ「名前(英名)」で、編集画面(#127)へのリンク。
 * 種類を切り替えたら、絞り込みの語とページは消して 1 ページ目から出す(旧も別の画面へ移るので、語は残らなかった)。
 * 新規は、銘柄の一覧(#64)と同じく一覧の上の「新規」のリンクから開く(旧はメニューの「カクテル新規」。docs/decisions/admin-cocktails.md)。
 */
export default function AdminCocktails() {
  usePageTitle('カクテル')
  const [searchParams] = useSearchParams()
  const current = toStatus(searchParams.get(STATUS_PARAM))

  return (
    <main>
      <h1>カクテル</h1>
      <p className={styles.actions}>
        <Link to={ADMIN_NEW_COCKTAIL_PATH}>新規</Link>
      </p>
      <nav className={styles.switcher} aria-label="カクテルの一覧の切り替え">
        <ul className={styles.switcherList}>
          {STATUSES.map(({ status, label }) => (
            <li key={status}>
              <Link
                className={styles.switcherLink}
                to={statusPath(status)}
                aria-current={status === current ? 'page' : undefined}
              >
                {label}
              </Link>
            </li>
          ))}
        </ul>
      </nav>
      {/* 種類ごとに部品を作り直し、絞り込みの語とページを消す */}
      <AdminList key={current} load={LOADERS[current]} itemKey={itemKey} renderItem={renderItem} />
    </main>
  )
}
