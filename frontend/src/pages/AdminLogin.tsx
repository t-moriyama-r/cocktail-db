import { useState } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router'
import { useApi } from '../api/useApi'
import ApiError from '../components/ApiView/ApiError'
import Loading from '../components/ApiView/Loading'
import { afterAdminLoginPath } from '../components/AdminLayout/adminPaths'
import { fetchCurrentAdmin } from '../components/AdminLayout/currentAdmin'
import {
  loginWithPasskey,
  passkeyLoginErrorMessage,
  type PasskeyLoginResult,
} from '../components/AdminLayout/passkeyLogin'
import { usePageTitle } from '../components/SiteLayout/usePageTitle'
import { isPasskeySupported } from '../utils/webauthn'
import styles from './AdminLogin.module.css'

/**
 * 管理画面へのログインの画面(/admin/login)。未ログインで管理画面を開くと、ここへ移る(#119)。
 *
 * もうログインしていれば、開こうとしていた URL(`?redirect=…`)へ戻す。
 * 未ログインなら「パスキーでログイン」のボタンを出し、ログインできたら同じく afterAdminLoginPath の URL へ移す(#312)。
 * ブラウザがパスキーに対応していなければ、ボタンの代わりにそう出し、非常用のログイン URL(#115)を案内する。
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
          {isPasskeySupported() ? <PasskeyLoginButton search={search} /> : <p>このブラウザはパスキーに対応していません。</p>}
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

type ButtonState = { kind: 'idle' } | { kind: 'pending' } | Exclude<PasskeyLoginResult, { kind: 'success' }>

/** 「パスキーでログイン」のボタン。ログインできたら search の redirect の URL(無ければメニュー)へ移し、できなければメッセージを出す */
function PasskeyLoginButton({ search }: { search: string }) {
  const navigate = useNavigate()
  const [state, setState] = useState<ButtonState>({ kind: 'idle' })

  const handleClick = async () => {
    setState({ kind: 'pending' })
    const result = await loginWithPasskey()
    if (result.kind === 'success') {
      void navigate(afterAdminLoginPath(search), { replace: true })
      return
    }
    setState(result)
  }

  return (
    <div className={styles.passkey}>
      <button
        className={styles.passkeyButton}
        type="button"
        disabled={state.kind === 'pending'}
        onClick={() => void handleClick()}
      >
        パスキーでログイン
      </button>
      {state.kind !== 'idle' && state.kind !== 'pending' && (
        <p className={styles.error} role="alert">
          {passkeyLoginErrorMessage(state)}
        </p>
      )}
    </div>
  )
}
