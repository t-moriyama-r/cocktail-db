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
    publicKey.allowCredentials = allowCredentials.filter(isRecord).flatMap((descriptor) =>
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
