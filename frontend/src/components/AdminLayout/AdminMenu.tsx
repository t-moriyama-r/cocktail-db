import { useState } from 'react'
import { NavLink, useNavigate } from 'react-router'
import { toApiResult, type ApiResult } from '../../api/apiResult'
import { api } from '../../api/client'
import { ADMIN_MENU } from './adminMenu'
import { ADMIN_LOGIN_PATH } from './adminPaths'
import styles from './AdminLayout.module.css'

type LogoutState = { kind: 'idle' } | { kind: 'pending' } | Exclude<ApiResult<void>, { kind: 'success' }>

/**
 * 管理画面のメニュー。並べる画面は adminMenu.ts の ADMIN_MENU で、最後にログアウトを出す
 * (旧のメニューにはログアウトが無かった。#119)。
 */
export default function AdminMenu() {
  const navigate = useNavigate()
  const [logout, setLogout] = useState<LogoutState>({ kind: 'idle' })

  const handleLogout = async () => {
    setLogout({ kind: 'pending' })
    const result = await toApiResult(() => api.POST('/admin/logout'))
    // 401 は、セッションの期限が切れるなどして、もうログインしていないとき。ログアウトできたときと同じに扱う
    if (result.kind === 'success' || (result.kind === 'http-error' && result.status === 401)) {
      // ログアウトの後は、戻る URL を持たせずにログインの画面を出す(もう一度ログインすればメニューへ戻る)
      void navigate(ADMIN_LOGIN_PATH, { replace: true })
      return
    }
    setLogout(result)
  }

  return (
    <nav className={styles.menu} aria-label="管理画面のメニュー">
      <ul className={styles.menuList}>
        {ADMIN_MENU.map((item) => (
          <li key={item.path}>
            <NavLink className={styles.menuLink} to={item.path}>
              {item.label}
            </NavLink>
          </li>
        ))}
        <li>
          <button
            className={styles.logout}
            type="button"
            disabled={logout.kind === 'pending'}
            onClick={() => void handleLogout()}
          >
            ログアウト
          </button>
        </li>
      </ul>
      {logout.kind !== 'idle' && logout.kind !== 'pending' && (
        <p className={styles.logoutError} role="alert">
          {logoutErrorMessage(logout)}
        </p>
      )}
    </nav>
  )
}

function logoutErrorMessage(error: Exclude<LogoutState, { kind: 'idle' | 'pending' }>): string {
  switch (error.kind) {
    case 'network-error':
      return 'ログアウトできませんでした。通信に失敗しました。接続を確かめて、もう一度お試しください。'
    case 'not-found':
      return 'ログアウトできませんでした(エラー 404)。時間をおいて、もう一度お試しください。'
    case 'http-error':
      return `ログアウトできませんでした(エラー ${error.status})。時間をおいて、もう一度お試しください。`
  }
}
