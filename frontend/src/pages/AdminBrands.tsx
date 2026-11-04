import { Link } from 'react-router'
import { api } from '../api/client'
import type { paths } from '../api/schema'
import AdminList, { type AdminListQuery } from '../components/AdminList/AdminList'
import AdminListLink from '../components/AdminList/AdminListLink'
import { ADMIN_NEW_BRAND_PATH, adminBrandEditPath } from '../components/AdminLayout/adminPaths'
import { usePageTitle } from '../components/SiteLayout/usePageTitle'
import { brandPlacementLabel } from '../features/admin-brands/brandFormValues'
import styles from './AdminBrands.module.css'

/** 一覧の 1 行(管理 API の応答の型) */
type BrandItem = paths['/admin/brands']['get']['responses'][200]['content']['application/json']['data'][number]

/** 一覧の API を呼ぶ。AdminList に渡すので、描画のたびに作り直さないようモジュールに置く */
const loadBrands = ({ q, page }: AdminListQuery) => api.GET('/admin/brands', { params: { query: { q, page } } })

const itemKey = (brand: BrandItem) => brand.id

const renderItem = (brand: BrandItem) => {
  const placement = brandPlacementLabel(brand.category_path)
  return (
    <>
      <AdminListLink to={adminBrandEditPath(brand.id)} name={brand.name} nameEng={brand.name_eng} />
      {placement !== '' && <span className={styles.placement}>({placement})</span>}
    </>
  )
}

/**
 * 管理画面の銘柄の一覧(/admin/brands。旧 `Admin/Super/BrandList.vue`。#64)。
 *
 * 一覧そのもの(絞り込み・並び・ページ送り)は共通の部品(AdminList。#120)。1 行は旧と同じく「名前(英名)」の編集画面へのリンクと、
 * その後ろに括弧で所属(根を除いたパンくずの、いちばん上の段と所属のノード。有名な銘柄になっている段は名前の前に ★)。
 * 旧はメニューの「銘柄新規」「銘柄編集」が別の項目だったが、新はメニューを「銘柄」1 つにし、新規はこの一覧の上のリンクから開く。
 */
export default function AdminBrands() {
  usePageTitle('銘柄')

  return (
    <main>
      <h1>銘柄</h1>
      <p className={styles.actions}>
        <Link to={ADMIN_NEW_BRAND_PATH}>新規</Link>
      </p>
      <AdminList load={loadBrands} itemKey={itemKey} renderItem={renderItem} />
    </main>
  )
}
