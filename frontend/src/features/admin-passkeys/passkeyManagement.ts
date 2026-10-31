import { toApiResult, type ApiResult } from '../../api/apiResult'
import { api } from '../../api/client'
import type { components } from '../../api/schema'
import { attestationToJson, toCreationOptions } from '../../utils/webauthn'

/** 一覧に出すパスキー 1 つ(GET /api/admin/passkeys の 1 件) */
export type Passkey = components['schemas']['PasskeyResource']

type ApiFailure = Exclude<ApiResult<unknown>, { kind: 'success' }>

/** 名前の上限(BE の `name` の `max:255`) */
export const PASSKEY_NAME_MAX_LENGTH = 255

/**
 * 自分のパスキーの一覧を取る。モジュールの定数にして、useApi に描画のたびに別の関数を渡さないようにする。
 * 一覧の画面(パスキーの管理)が使う。
 */
export const fetchPasskeys = () => api.GET('/admin/passkeys')

/** 画面に出す日付の形(日本の日付で `2026/10/31`) */
const DATE_FORMAT = new Intl.DateTimeFormat('ja-JP', {
  timeZone: 'Asia/Tokyo',
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
})

/** API の日時(ISO 8601)を、日本の日付にする。無いときは null */
export const formatPasskeyDate = (value: string | null): string | null =>
  value === null ? null : DATE_FORMAT.format(new Date(value))

/** パスキーを登録した結果。画面は、成功なら一覧を読み直し、それ以外はメッセージを出す */
export type PasskeyRegistrationResult =
  | { kind: 'success'; passkey: Passkey }
  /** パスキーを作る画面を閉じた・時間切れになった(ブラウザの NotAllowedError・AbortError) */
  | { kind: 'cancelled' }
  /** この端末(認証器)のパスキーは、もう登録してある(登録済みを除外に載せたので、ブラウザが InvalidStateError で断る) */
  | { kind: 'already-registered' }
  /** BE が名前を受け付けなかった(422 の errors.name) */
  | { kind: 'invalid-name' }
  /** BE が応答を確かめられなかった(422 の errors.credential。チャレンジの期限切れ・別のサイトの応答など) */
  | { kind: 'rejected' }
  /** API がほかのエラーを返した・届かなかった */
  | { kind: 'api-error'; error: ApiFailure }
  /** ブラウザがパスキーの操作をほかの理由で断った(SecurityError など。name はその例外の名前) */
  | { kind: 'browser-error'; name: string }

/** ブラウザが投げた例外の名前(DOMException は Error を継がない実装もあるので、name だけを見る) */
const errorName = (error: unknown): string =>
  typeof error === 'object' && error !== null && 'name' in error && typeof error.name === 'string' ? error.name : 'Error'

/** 422 の本文(Laravel の `{ message, errors: { 項目: [...] } }`)に、その項目のエラーがあるか */
const hasFieldError = (body: unknown, field: string): boolean =>
  typeof body === 'object' &&
  body !== null &&
  'errors' in body &&
  typeof body.errors === 'object' &&
  body.errors !== null &&
  field in body.errors

/**
 * パスキーを登録する(#313)。BE の登録の開始(チャレンジと options を受け取る)→ ブラウザでパスキーを作る
 * → BE の登録の完了(名前を付けて保存する)の順に進める。API は docs/decisions/passkey.md の 3。
 * 例外は投げず、どこで止まったかを結果の種類で返す。
 */
export async function registerPasskey(name: string): Promise<PasskeyRegistrationResult> {
  const started = await toApiResult(() => api.POST('/admin/passkeys/registration-options'))
  if (started.kind !== 'success') {
    return { kind: 'api-error', error: started }
  }

  let credential: Credential | null
  try {
    credential = await navigator.credentials.create({ publicKey: toCreationOptions(started.data.options) })
  } catch (error) {
    const name = errorName(error)
    if (name === 'NotAllowedError' || name === 'AbortError') {
      return { kind: 'cancelled' }
    }
    return name === 'InvalidStateError' ? { kind: 'already-registered' } : { kind: 'browser-error', name }
  }
  if (!(credential instanceof PublicKeyCredential)) {
    return { kind: 'cancelled' }
  }

  let finished: Awaited<ReturnType<typeof savePasskey>>
  try {
    finished = await savePasskey(name, credential)
  } catch {
    return { kind: 'api-error', error: { kind: 'network-error' } }
  }
  const { data, error, response } = finished
  if (response.ok && data !== undefined) {
    return { kind: 'success', passkey: data.data }
  }
  if (response.status === 422) {
    return hasFieldError(error, 'name') ? { kind: 'invalid-name' } : { kind: 'rejected' }
  }
  return {
    kind: 'api-error',
    error: response.status === 404 ? { kind: 'not-found' } : { kind: 'http-error', status: response.status },
  }
}

const savePasskey = (name: string, credential: PublicKeyCredential) =>
  api.POST('/admin/passkeys', { body: { name, credential: attestationToJson(credential) } })

/** パスキーを登録できなかったときに、画面に出すメッセージ */
export function passkeyRegistrationErrorMessage(result: Exclude<PasskeyRegistrationResult, { kind: 'success' }>): string {
  switch (result.kind) {
    case 'cancelled':
      return 'パスキーの登録が取り消されたか、時間切れになりました。もう一度お試しください。'
    case 'already-registered':
      return 'この端末のパスキーは、もう登録してあります。'
    case 'invalid-name':
      return `端末の名前は ${PASSKEY_NAME_MAX_LENGTH} 文字までで入れてください。`
    case 'rejected':
      return 'パスキーを確かめられませんでした。もう一度お試しください。'
    case 'browser-error':
      return `パスキーを登録できませんでした(${result.name})。もう一度お試しください。`
    case 'api-error':
      return `パスキーを登録できませんでした${apiFailureDetail(result.error)}`
  }
}

/** パスキーを消した結果。404(もう無い)は、消せたときと同じに扱う(ほかの画面で消した、など) */
export type PasskeyDeletionResult = { kind: 'success' } | { kind: 'api-error'; error: ApiFailure }

/** パスキーを 1 つ消す(#313)。最後の 1 つも消せる(docs/decisions/passkey.md の 4) */
export async function deletePasskey(id: number): Promise<PasskeyDeletionResult> {
  const result = await toApiResult(() => api.DELETE('/admin/passkeys/{passkey}', { params: { path: { passkey: id } } }))
  if (result.kind === 'success' || result.kind === 'not-found') {
    return { kind: 'success' }
  }
  return { kind: 'api-error', error: result }
}

/** パスキーを消せなかったときに、画面に出すメッセージ */
export function passkeyDeletionErrorMessage(result: Exclude<PasskeyDeletionResult, { kind: 'success' }>): string {
  return `パスキーを消せませんでした${apiFailureDetail(result.error)}`
}

const apiFailureDetail = (error: ApiFailure): string => {
  switch (error.kind) {
    case 'network-error':
      return '。通信に失敗しました。接続を確かめて、もう一度お試しください。'
    case 'not-found':
      return '(エラー 404)。時間をおいて、もう一度お試しください。'
    case 'http-error':
      return `(エラー ${error.status})。時間をおいて、もう一度お試しください。`
  }
}
