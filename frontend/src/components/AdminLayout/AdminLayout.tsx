import { useLayoutEffect } from 'react'
import { Link, Navigate, Outlet, useLocation } from 'react-router'
import { useApi } from '../../api/useApi'
import ApiError from '../ApiView/ApiError'
import Loading from '../ApiView/Loading'
import { SITE_TITLE } from '../SiteLayout/usePageTitle'
import AdminMenu from './AdminMenu'
import { fetchCurrentAdmin } from './currentAdmin'
import { ADMIN_HOME_PATH, adminLoginPath } from './adminPaths'
import styles from './AdminLayout.module.css'

/**
 * 管理画面(/admin 以下。ログインの画面を除く)を包む枠。ログインしているかを確かめてから、上に管理画面の題とメニュー、
 * 下に各画面を出す。旧の管理画面には枠もメニューも無く、各画面から戻るリンクも無かった。新はどの画面にもメニューを出す(#119)。
 *
 * ログインしていなければ(/api/admin/me が 401)、開こうとしていた URL を持たせてログインの画面へ移す。
 */
export default function AdminLayout() {
  const location = useLocation()
  const { state, retry } = useApi(fetchCurrentAdmin)
  // 画面がページ名を渡さないときもサイト名だけを出す(公開ページの SiteLayout と同じ)
  useLayoutEffect(() => {
    document.title = SITE_TITLE
  }, [])

  switch (state.kind) {
    case 'loading':
      return (
        <main className={styles.status}>
          <Loading />
        </main>
      )
    case 'http-error':
      if (state.status === 401) {
        return <Navigate to={adminLoginPath(location)} replace />
      }
      return (
        <main className={styles.status}>
          <ApiError error={state} onRetry={retry} />
        </main>
      )
    case 'network-error':
      return (
        <main className={styles.status}>
          <ApiError error={state} onRetry={retry} />
        </main>
      )
    case 'not-found':
      // /api/admin/me は 404 を返さない。返ったときは、ほかのエラーと同じく読み込めなかったと出す
      return (
        <main className={styles.status}>
          <ApiError error={{ kind: 'http-error', status: 404 }} onRetry={retry} />
        </main>
      )
    case 'success':
      return (
        <div className={styles.root}>
          <header className={styles.header}>
            <Link className={styles.title} to={ADMIN_HOME_PATH}>
              管理画面
            </Link>
            <span className={styles.admin}>{state.data.name} でログイン中</span>
          </header>
          <AdminMenu />
          <div className={styles.content}>
            <Outlet />
          </div>
        </div>
      )
  }
}
