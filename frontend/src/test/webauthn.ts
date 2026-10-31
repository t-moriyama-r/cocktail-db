import { vi } from 'vitest'

/**
 * テストでパスキーのブラウザの API(PublicKeyCredential・navigator.credentials)を差し替える仕組み(#312)。
 * jsdom には WebAuthn の API が無く、本物は認証器(端末の外の部品)につながるので、プロセスの境界として差し替える。
 * 本物の認証器で通すのは E2E(Playwright の仮想の認証器)。差し替えはテストごとに setup.ts が resetPasskeyBrowser で戻す。
 */

const buffer = (...values: number[]) => new Uint8Array(values).buffer

/** navigator.credentials.get() が返す、ログインの資格情報の偽物 */
export class FakePublicKeyCredential implements PublicKeyCredential {
  readonly type = 'public-key'
  readonly authenticatorAttachment = 'platform'
  readonly id: string
  readonly rawId: ArrayBuffer
  readonly response: AuthenticatorAssertionResponse

  constructor(
    id: string,
    rawId: ArrayBuffer,
    response: { clientDataJSON: ArrayBuffer; authenticatorData: ArrayBuffer; signature: ArrayBuffer; userHandle: ArrayBuffer | null },
  ) {
    this.id = id
    this.rawId = rawId
    this.response = response
  }

  getClientExtensionResults(): AuthenticationExtensionsClientOutputs {
    return {}
  }

  toJSON(): PublicKeyCredentialJSON {
    throw new Error('テストの偽物では使わない(アプリは utils/webauthn.ts の assertionToJson で変換する)')
  }
}

/** 中身の決まった資格情報。ID は [4, 5](base64url で `BAU`)、本文のバイト列はそれぞれ 1 バイト */
export const fakeAssertion = () =>
  new FakePublicKeyCredential('BAU', buffer(4, 5), {
    clientDataJSON: buffer(1),
    authenticatorData: buffer(2),
    signature: buffer(3),
    userHandle: buffer(6),
  })

/**
 * このブラウザがパスキーに対応しているようにし、navigator.credentials.get() を get で答える。
 * get が受け取った options を返り値の配列に残す(アプリが認証器に渡したものを確かめるため)。
 */
export const stubPasskeyBrowser = (get: (options: CredentialRequestOptions) => Promise<Credential | null>) => {
  const received: CredentialRequestOptions[] = []
  vi.stubGlobal('PublicKeyCredential', FakePublicKeyCredential)
  Object.defineProperty(navigator, 'credentials', {
    configurable: true,
    value: {
      get: (options: CredentialRequestOptions) => {
        received.push(options)
        return get(options)
      },
    },
  })
  return received
}

/** 差し替えた API を戻す(setup.ts がテストごとに呼ぶ) */
export const resetPasskeyBrowser = () => {
  vi.unstubAllGlobals()
  // jsdom の navigator には credentials が無いので、足したものを消せば元に戻る
  Reflect.deleteProperty(navigator, 'credentials')
}
