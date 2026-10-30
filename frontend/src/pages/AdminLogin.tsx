import { Navigate, useLocation } from 'react-router'
import { useApi } from '../api/useApi'
import ApiError from '../components/ApiView/ApiError'
import Loading from '../components/ApiView/Loading'
import { afterAdminLoginPath } from '../components/AdminLayout/adminPaths'
import { fetchCurrentAdmin } from '../components/AdminLayout/currentAdmin'
import { usePageTitle } from '../components/SiteLayout/usePageTitle'
import styles from './AdminLogin.module.css'

/**
 * 管理画面へのログインの画面(/admin/login)。未ログインで管理画面を開くと、ここへ移る(#119)。
 *
 * もうログインしていれば、開こうとしていた URL(`?redirect=…`)へ戻す。
 * パスキーでログインするボタンは #312 で足し、ログインできたら同じく afterAdminLoginPath の URL へ移す。
 */
export default function AdminLogin() {
  usePageTitle('ログイン')
  const { search } = useLocation()
  const { state, retry } = useApi(fetchCurrentAdmin)

  if (state.kind === 'success') {
    return <Navigate to={afterAdminLoginPath(search)} replace />
  }

  return (
    <main className={styles.page}>
      <h1>管理画面へのログイン</h1>
      {state.kind === 'loading' && <Loading />}
      {state.kind === 'http-error' && state.status === 401 && (
        <>
          <p>管理画面を使うには、ログインが必要です。</p>
          <p>パスキーでログインできないときは、サーバーで発行した非常用のログイン URL を開いてください。</p>
        </>
      )}
      {((state.kind === 'http-error' && state.status !== 401) || state.kind === 'network-error') && (
        <ApiError error={state} onRetry={retry} />
      )}
      {state.kind === 'not-found' && <ApiError error={{ kind: 'http-error', status: 404 }} onRetry={retry} />}
    </main>
  )
}
