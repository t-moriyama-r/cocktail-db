import { toApiResult, type ApiResult } from '../../api/apiResult'
import { api } from '../../api/client'
import { assertionToJson, toRequestOptions } from '../../utils/webauthn'

type ApiFailure = Exclude<ApiResult<unknown>, { kind: 'success' }>

/** パスキーでログインした結果。ログインの画面は、成功なら移り、それ以外はメッセージを出す */
export type PasskeyLoginResult =
  | { kind: 'success' }
  /** パスキーを選ぶ画面を閉じた・時間切れになった(ブラウザの NotAllowedError・AbortError) */
  | { kind: 'cancelled' }
  /** BE が応答を確かめられなかった(422)。登録していない・消したパスキー、別のサイトのパスキーなど */
  | { kind: 'rejected' }
  /** 試す回数が多すぎる(429。同じ IP から 1 分に 10 回まで。docs/decisions/passkey.md の 7) */
  | { kind: 'too-many' }
  /** API がほかのエラーを返した・届かなかった */
  | { kind: 'api-error'; error: ApiFailure }
  /** ブラウザがパスキーの操作をほかの理由で断った(SecurityError など。name はその例外の名前) */
  | { kind: 'browser-error'; name: string }

const fromApiFailure = (error: ApiFailure): PasskeyLoginResult => {
  if (error.kind === 'http-error' && error.status === 422) {
    return { kind: 'rejected' }
  }
  if (error.kind === 'http-error' && error.status === 429) {
    return { kind: 'too-many' }
  }
  return { kind: 'api-error', error }
}

/**
 * パスキーで管理画面にログインする(#312)。BE のログインの開始(チャレンジを受け取る)→ ブラウザでパスキーを選んで署名する
 * → BE のログインの完了(署名を確かめてログインする)の順に進める。API は docs/decisions/passkey.md の 3。
 * 例外は投げず、どこで止まったかを結果の種類で返す。
 */
export async function loginWithPasskey(): Promise<PasskeyLoginResult> {
  const started = await toApiResult(() => api.POST('/auth/passkey/login-options'))
  if (started.kind !== 'success') {
    return fromApiFailure(started)
  }

  let credential: Credential | null
  try {
    credential = await navigator.credentials.get({ publicKey: toRequestOptions(started.data.options) })
  } catch (error) {
    // ブラウザは DOMException を投げる(Error を継がない実装もあるので、name だけを見る)
    const name = typeof error === 'object' && error !== null && 'name' in error && typeof error.name === 'string' ? error.name : 'Error'
    return name === 'NotAllowedError' || name === 'AbortError' ? { kind: 'cancelled' } : { kind: 'browser-error', name }
  }
  if (!(credential instanceof PublicKeyCredential)) {
    return { kind: 'cancelled' }
  }

  const finished = await toApiResult(() =>
    api.POST('/auth/passkey/login', { body: { credential: assertionToJson(credential) } }),
  )
  return finished.kind === 'success' ? { kind: 'success' } : fromApiFailure(finished)
}

/** ログインできなかったときに、ログインの画面に出すメッセージ */
export function passkeyLoginErrorMessage(result: Exclude<PasskeyLoginResult, { kind: 'success' }>): string {
  switch (result.kind) {
    case 'cancelled':
      return 'パスキーでのログインが取り消されたか、時間切れになりました。もう一度お試しください。'
    case 'rejected':
      return 'パスキーを確かめられませんでした。このサイトに登録したパスキーを選んで、もう一度お試しください。'
    case 'too-many':
      return 'ログインを試した回数が多すぎます。1 分ほど待ってから、もう一度お試しください。'
    case 'browser-error':
      return `パスキーでログインできませんでした(${result.name})。もう一度お試しください。`
    case 'api-error':
      switch (result.error.kind) {
        case 'network-error':
          return 'ログインできませんでした。通信に失敗しました。接続を確かめて、もう一度お試しください。'
        case 'not-found':
          return 'ログインできませんでした(エラー 404)。時間をおいて、もう一度お試しください。'
        case 'http-error':
          return `ログインできませんでした(エラー ${result.error.status})。時間をおいて、もう一度お試しください。`
      }
  }
}
