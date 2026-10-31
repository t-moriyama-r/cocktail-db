import { useState, type FormEvent } from 'react'
import { useApi } from '../api/useApi'
import InlineApiView from '../components/ApiView/InlineApiView'
import { usePageTitle } from '../components/SiteLayout/usePageTitle'
import {
  deletePasskey,
  fetchPasskeys,
  formatPasskeyDate,
  PASSKEY_NAME_MAX_LENGTH,
  passkeyDeletionErrorMessage,
  passkeyRegistrationErrorMessage,
  registerPasskey,
  type Passkey,
  type PasskeyDeletionResult,
  type PasskeyRegistrationResult,
} from '../features/admin-passkeys/passkeyManagement'
import { isPasskeyRegistrationSupported } from '../utils/webauthn'
import styles from './AdminPasskeys.module.css'

/** 登録・削除が終わったときに、一覧の上に出す知らせ */
type Notice = { kind: 'registered'; name: string } | { kind: 'deleted'; name: string } | null

/**
 * 管理画面のパスキーの管理(/admin/passkeys。#313)。ログインしている管理者の、自分のパスキーを登録・一覧・削除する。
 * 旧は X でのログインだけで、パスキーの画面は無かった(docs/decisions/passkey.md)。
 *
 * 登録は端末の名前を付けて行い、一覧には名前・登録日・最後に使った日を、登録した順に出す。
 * 削除は「削除」を押した後に確かめてから消す。最後の 1 つも消せる(docs/decisions/passkey.md の 4)が、
 * 消すと非常用のログイン URL でしか入れなくなるので、そのときはそう書いて確かめる。
 */
export default function AdminPasskeys() {
  usePageTitle('パスキー')
  const { state, retry } = useApi(fetchPasskeys)
  const [notice, setNotice] = useState<Notice>(null)

  return (
    <main>
      <h1>パスキー</h1>
      <p>管理画面へのログインに使うパスキーです。端末ごとに登録します。</p>

      <section className={styles.section} aria-labelledby="passkey-register-heading">
        <h2 id="passkey-register-heading">パスキーを登録する</h2>
        {isPasskeyRegistrationSupported() ? (
          <RegisterForm
            onRegistered={(passkey) => {
              setNotice({ kind: 'registered', name: passkey.name })
              retry()
            }}
          />
        ) : (
          <p>このブラウザはパスキーの登録に対応していません。パスキーに対応したブラウザで開いてください。</p>
        )}
      </section>

      <section className={styles.section} aria-labelledby="passkey-list-heading">
        <h2 id="passkey-list-heading">登録したパスキー</h2>
        {notice !== null && (
          <p className={styles.notice} role="status">
            {notice.kind === 'registered' ? `「${notice.name}」を登録しました。` : `「${notice.name}」を消しました。`}
          </p>
        )}
        <InlineApiView state={state} onRetry={retry}>
          {({ data }) => (
            <PasskeyList
              passkeys={data}
              onDeleted={(passkey) => {
                setNotice({ kind: 'deleted', name: passkey.name })
                retry()
              }}
            />
          )}
        </InlineApiView>
      </section>
    </main>
  )
}

type RegisterState =
  | { kind: 'idle' }
  | { kind: 'pending' }
  | { kind: 'empty-name' }
  | Exclude<PasskeyRegistrationResult, { kind: 'success' }>

/** 端末の名前を入れて、パスキーを登録するフォーム */
function RegisterForm({ onRegistered }: { onRegistered: (passkey: Passkey) => void }) {
  const [name, setName] = useState('')
  const [state, setState] = useState<RegisterState>({ kind: 'idle' })

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    // BE は前後の空白を落として受け取る(空白だけの名前は空と同じ)ので、画面でも同じに確かめる
    const trimmed = name.trim()
    if (trimmed === '') {
      setState({ kind: 'empty-name' })
      return
    }
    setState({ kind: 'pending' })
    const result = await registerPasskey(trimmed)
    if (result.kind === 'success') {
      setName('')
      setState({ kind: 'idle' })
      onRegistered(result.passkey)
      return
    }
    setState(result)
  }

  return (
    <form className={styles.form} noValidate onSubmit={(event) => void handleSubmit(event)}>
      <label className={styles.label} htmlFor="passkey-name">
        端末の名前
      </label>
      <div className={styles.formRow}>
        <input
          id="passkey-name"
          className={styles.input}
          type="text"
          value={name}
          maxLength={PASSKEY_NAME_MAX_LENGTH}
          placeholder="例: 仕事の MacBook"
          aria-describedby="passkey-name-hint"
          onChange={(event) => setName(event.target.value)}
        />
        <button className={styles.primaryButton} type="submit" disabled={state.kind === 'pending'}>
          パスキーを登録
        </button>
      </div>
      <p id="passkey-name-hint" className={styles.hint}>
        どの端末のパスキーかが一覧で分かる名前を、{PASSKEY_NAME_MAX_LENGTH} 文字までで付けてください。
      </p>
      {state.kind === 'empty-name' && (
        <p className={styles.error} role="alert">
          端末の名前を入れてください。
        </p>
      )}
      {state.kind !== 'idle' && state.kind !== 'pending' && state.kind !== 'empty-name' && (
        <p className={styles.error} role="alert">
          {passkeyRegistrationErrorMessage(state)}
        </p>
      )}
    </form>
  )
}

/** 登録したパスキーの一覧。どれも無いときはそう出す */
function PasskeyList({ passkeys, onDeleted }: { passkeys: Passkey[]; onDeleted: (passkey: Passkey) => void }) {
  if (passkeys.length === 0) {
    return <p>登録したパスキーはありません。いまは非常用のログイン URL でしかログインできません。</p>
  }
  return (
    <ul className={styles.list} aria-label="登録したパスキー">
      {passkeys.map((passkey) => (
        <PasskeyItem key={passkey.id} passkey={passkey} isLast={passkeys.length === 1} onDeleted={onDeleted} />
      ))}
    </ul>
  )
}

type DeleteState =
  | { kind: 'idle' }
  | { kind: 'confirming' }
  | { kind: 'pending' }
  | Exclude<PasskeyDeletionResult, { kind: 'success' }>

/** 一覧の 1 行。名前・登録日・最後に使った日と、削除のボタン(押すと確かめてから消す) */
function PasskeyItem({
  passkey,
  isLast,
  onDeleted,
}: {
  passkey: Passkey
  /** 残っているパスキーがこれだけか(消すと、非常用のログイン URL でしか入れなくなる) */
  isLast: boolean
  onDeleted: (passkey: Passkey) => void
}) {
  const [state, setState] = useState<DeleteState>({ kind: 'idle' })
  const createdAt = formatPasskeyDate(passkey.created_at)
  const lastUsedAt = formatPasskeyDate(passkey.last_used_at)

  const handleDelete = async () => {
    setState({ kind: 'pending' })
    const result = await deletePasskey(passkey.id)
    if (result.kind === 'success') {
      onDeleted(passkey)
      return
    }
    setState(result)
  }

  return (
    <li className={styles.item} aria-label={passkey.name}>
      <div className={styles.itemBody}>
        <p className={styles.name}>{passkey.name}</p>
        <dl className={styles.dates}>
          <div>
            <dt>登録日</dt>
            <dd>{createdAt ?? '-'}</dd>
          </div>
          <div>
            <dt>最後に使った日</dt>
            <dd>{lastUsedAt ?? 'まだ使っていません'}</dd>
          </div>
        </dl>
      </div>
      {state.kind === 'confirming' || state.kind === 'pending' ? (
        <div className={styles.confirm} role="group" aria-label={`「${passkey.name}」の削除の確認`}>
          <p className={styles.confirmText}>
            「{passkey.name}」を消しますか? 消すと、このパスキーではログインできなくなります。
            {isLast && ' これが最後のパスキーです。消すと、次からは非常用のログイン URL でしかログインできません。'}
          </p>
          <div className={styles.actions}>
            <button
              className={styles.dangerButton}
              type="button"
              disabled={state.kind === 'pending'}
              onClick={() => void handleDelete()}
            >
              消す
            </button>
            <button
              className={styles.secondaryButton}
              type="button"
              disabled={state.kind === 'pending'}
              onClick={() => setState({ kind: 'idle' })}
            >
              やめる
            </button>
          </div>
        </div>
      ) : (
        <div className={styles.actions}>
          <button
            className={styles.secondaryButton}
            type="button"
            aria-label={`「${passkey.name}」を削除`}
            onClick={() => setState({ kind: 'confirming' })}
          >
            削除
          </button>
        </div>
      )}
      {state.kind === 'api-error' && (
        <p className={styles.error} role="alert">
          {passkeyDeletionErrorMessage(state)}
        </p>
      )}
    </li>
  )
}
