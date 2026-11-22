/**
 * パスキー(WebAuthn)の値を、BE の JSON とブラウザの API(navigator.credentials)のあいだで変換する(#312)。
 *
 * BE(laravel/passkeys)は、チャレンジや資格情報の ID などのバイト列を base64url(詰め物なし)の文字列で受け渡しする。
 * ブラウザの navigator.credentials.get() はバイト列(ArrayBuffer)で受け取り・返すので、ここで変換する。
 * ブラウザにも同じ変換(PublicKeyCredential.parseRequestOptionsFromJSON・toJSON)があるが、まだ無いブラウザがあるので使わない。
 */

/** base64url の文字列(詰め物の `=` はあっても無くてもよい)を、バイト列にする */
export function base64UrlToBytes(value: string): Uint8Array<ArrayBuffer> {
  const base64 = value.replace(/-/g, '+').replace(/_/g, '/').replace(/=+$/, '')
  const binary = atob(base64.padEnd(base64.length + ((4 - (base64.length % 4)) % 4), '='))
  const bytes = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i++) {
    bytes[i] = binary.charCodeAt(i)
  }
  return bytes
}

/** バイト列を、BE が受け取る base64url(詰め物なし)の文字列にする */
export function bytesToBase64Url(buffer: ArrayBuffer | ArrayBufferView): string {
  const bytes = buffer instanceof ArrayBuffer ? new Uint8Array(buffer) : new Uint8Array(buffer.buffer, buffer.byteOffset, buffer.byteLength)
  let binary = ''
  for (const byte of bytes) {
    binary += String.fromCharCode(byte)
  }
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

/** このブラウザでパスキーを使えるか(WebAuthn の API があるか) */
export function isPasskeySupported(): boolean {
  return typeof window.PublicKeyCredential === 'function' && typeof navigator.credentials?.get === 'function'
}

const USER_VERIFICATIONS: readonly UserVerificationRequirement[] = ['required', 'preferred', 'discouraged']

const isRecord =(value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null

/**
 * BE が返したログインの options(`POST /api/auth/passkey/login-options` の `options`)を、
 * navigator.credentials.get() の `publicKey` に渡す形にする。チャレンジが無いなど、使えない options なら TypeError。
 */
export function toRequestOptions(options: Record<string, unknown>): PublicKeyCredentialRequestOptions {
  const { challenge, timeout, rpId, userVerification, allowCredentials } = options
  if (typeof challenge !== 'string') {
    throw new TypeError('パスキーのログインの options にチャレンジが無い')
  }
  const publicKey: PublicKeyCredentialRequestOptions = { challenge: base64UrlToBytes(challenge) }
  if (typeof timeout === 'number') {
    publicKey.timeout = timeout
  }
  if (typeof rpId === 'string') {
    publicKey.rpId = rpId
  }
  const verification = USER_VERIFICATIONS.find((value) => value === userVerification)
  if (verification !== undefined) {
    publicKey.userVerification = verification
  }
  if (Array.isArray(allowCredentials)) {
    publicKey.allowCredentials = toDescriptors(allowCredentials)
  }
  return publicKey
}

/** BE の資格情報の一覧(allowCredentials・excludeCredentials)の ID をバイト列にする。ID の無いものは外す */
function toDescriptors(descriptors: unknown[]): PublicKeyCredentialDescriptor[] {
  return descriptors.filter(isRecord).flatMap((descriptor) =>
    typeof descriptor.id === 'string'
      ? [
          {
            type: 'public-key' as const,
            id: base64UrlToBytes(descriptor.id),
            ...(Array.isArray(descriptor.transports)
              ? { transports: descriptor.transports.filter((t): t is AuthenticatorTransport => typeof t === 'string') }
              : {}),
          },
        ]
      : [],
  )
}

const ATTACHMENTS: readonly AuthenticatorAttachment[] = ['platform', 'cross-platform']
const RESIDENT_KEYS: readonly ResidentKeyRequirement[] = ['required', 'preferred', 'discouraged']

/** BE の認証器の条件(端末が覚えるパスキーにする・本人確認を求める、など)のうち、ブラウザが知っている値だけを渡す */
function toAuthenticatorSelection(selection: Record<string, unknown>): AuthenticatorSelectionCriteria {
  const { authenticatorAttachment, residentKey, requireResidentKey, userVerification } = selection
  const criteria: AuthenticatorSelectionCriteria = {}
  const attachment = ATTACHMENTS.find((value) => value === authenticatorAttachment)
  if (attachment !== undefined) {
    criteria.authenticatorAttachment = attachment
  }
  const resident = RESIDENT_KEYS.find((value) => value === residentKey)
  if (resident !== undefined) {
    criteria.residentKey = resident
  }
  if (typeof requireResidentKey === 'boolean') {
    criteria.requireResidentKey = requireResidentKey
  }
  const verification = USER_VERIFICATIONS.find((value) => value === userVerification)
  if (verification !== undefined) {
    criteria.userVerification = verification
  }
  return criteria
}

/** このブラウザでパスキーを登録できるか(WebAuthn の登録の API があるか。#313) */
export function isPasskeyRegistrationSupported(): boolean {
  return typeof window.PublicKeyCredential === 'function' && typeof navigator.credentials?.create === 'function'
}

/**
 * BE が返した登録の options(`POST /api/admin/passkeys/registration-options` の `options`)を、
 * navigator.credentials.create() の `publicKey` に渡す形にする(#313)。チャレンジ・RP・ユーザーの ID・アルゴリズムの
 * どれかが無いなど、使えない options なら TypeError。登録済みのパスキー(excludeCredentials)も、ID をバイト列にして渡す。
 */
export function toCreationOptions(options: Record<string, unknown>): PublicKeyCredentialCreationOptions {
  const { challenge, rp, user, pubKeyCredParams, timeout, excludeCredentials, authenticatorSelection, attestation } = options
  if (typeof challenge !== 'string') {
    throw new TypeError('パスキーの登録の options にチャレンジが無い')
  }
  if (!isRecord(rp) || typeof rp.name !== 'string') {
    throw new TypeError('パスキーの登録の options に RP が無い')
  }
  if (!isRecord(user) || typeof user.id !== 'string' || typeof user.name !== 'string') {
    throw new TypeError('パスキーの登録の options にユーザーが無い')
  }
  if (!Array.isArray(pubKeyCredParams)) {
    throw new TypeError('パスキーの登録の options にアルゴリズムが無い')
  }
  const publicKey: PublicKeyCredentialCreationOptions = {
    challenge: base64UrlToBytes(challenge),
    rp: { name: rp.name, ...(typeof rp.id === 'string' ? { id: rp.id } : {}) },
    user: {
      id: base64UrlToBytes(user.id),
      name: user.name,
      displayName: typeof user.displayName === 'string' ? user.displayName : user.name,
    },
    pubKeyCredParams: pubKeyCredParams
      .filter(isRecord)
      .flatMap((param) => (typeof param.alg === 'number' ? [{ type: 'public-key' as const, alg: param.alg }] : [])),
  }
  if (typeof timeout === 'number') {
    publicKey.timeout = timeout
  }
  if (Array.isArray(excludeCredentials)) {
    publicKey.excludeCredentials = toDescriptors(excludeCredentials)
  }
  if (isRecord(authenticatorSelection)) {
    publicKey.authenticatorSelection = toAuthenticatorSelection(authenticatorSelection)
  }
  if (attestation === 'none' || attestation === 'indirect' || attestation === 'direct' || attestation === 'enterprise') {
    publicKey.attestation = attestation
  }
  return publicKey
}

/**
 * navigator.credentials.get() が返した資格情報を、BE(`POST /api/auth/passkey/login` の `credential`)に送る JSON にする。
 * 形は WebAuthn の PublicKeyCredential.toJSON() と同じ(バイト列は base64url)。
 */
export function assertionToJson(credential: PublicKeyCredential): PublicKeyCredentialJSON {
  const response = credential.response as AuthenticatorAssertionResponse
  return {
    id: credential.id,
    rawId: bytesToBase64Url(credential.rawId),
    type: 'public-key',
    response: {
      clientDataJSON: bytesToBase64Url(response.clientDataJSON),
      authenticatorData: bytesToBase64Url(response.authenticatorData),
      signature: bytesToBase64Url(response.signature),
      ...(response.userHandle ? { userHandle: bytesToBase64Url(response.userHandle) } : {}),
    },
    ...(credential.authenticatorAttachment ? { authenticatorAttachment: credential.authenticatorAttachment } : {}),
    clientExtensionResults: credential.getClientExtensionResults(),
  }
}

/**
 * navigator.credentials.create() が返した資格情報を、BE(`POST /api/admin/passkeys` の `credential`)に送る JSON にする(#313)。
 * 形は WebAuthn の PublicKeyCredential.toJSON() と同じ(バイト列は base64url)。
 */
export function attestationToJson(credential: PublicKeyCredential): PublicKeyCredentialJSON {
  const response = credential.response as AuthenticatorAttestationResponse
  return {
    id: credential.id,
    rawId: bytesToBase64Url(credential.rawId),
    type: 'public-key',
    response: {
      clientDataJSON: bytesToBase64Url(response.clientDataJSON),
      attestationObject: bytesToBase64Url(response.attestationObject),
      ...(typeof response.getTransports === 'function' ? { transports: response.getTransports() } : {}),
    },
    ...(credential.authenticatorAttachment ? { authenticatorAttachment: credential.authenticatorAttachment } : {}),
    clientExtensionResults: credential.getClientExtensionResults(),
  }
}
