import { describe, expect, test } from 'vitest'
import { fakeAssertion, fakeAttestation } from '../test/webauthn'
import {
  assertionToJson,
  attestationToJson,
  base64UrlToBytes,
  bytesToBase64Url,
  toCreationOptions,
  toRequestOptions,
} from './webauthn'

const bytes = (...values: number[]) => new Uint8Array(values)

describe('base64url', () => {
  test.each([
    { name: '+ と / に当たるバイト', input: bytes(0xfb, 0xff, 0xbf), encoded: '-_-_' },
    { name: '詰め物が 2 つ要る長さ(1 バイト)', input: bytes(0x61), encoded: 'YQ' },
    { name: '詰め物が 1 つ要る長さ(2 バイト)', input: bytes(0x61, 0x62), encoded: 'YWI' },
    { name: '空', input: bytes(), encoded: '' },
  ])('TC-312-01: $name を、詰め物なしの base64url と行き来できる', ({ input, encoded }) => {
    expect(bytesToBase64Url(input)).toBe(encoded)
    expect(base64UrlToBytes(encoded)).toEqual(input)
  })

  test('TC-312-01: 詰め物の付いた base64url も読める', () => {
    expect(base64UrlToBytes('YQ==')).toEqual(bytes(0x61))
  })
})

describe('toRequestOptions', () => {
  test('TC-312-02: BE の options のチャレンジ・資格情報の ID をバイト列にし、ほかの値はそのまま渡す', () => {
    const publicKey = toRequestOptions({
      challenge: 'AQID',
      timeout: 60000,
      rpId: 'localhost',
      userVerification: 'required',
      allowCredentials: [{ type: 'public-key', id: 'BAU', transports: ['internal'] }],
    })

    expect(publicKey).toEqual({
      challenge: bytes(1, 2, 3),
      timeout: 60000,
      rpId: 'localhost',
      userVerification: 'required',
      allowCredentials: [{ type: 'public-key', id: bytes(4, 5), transports: ['internal'] }],
    })
  })

  test('TC-312-03: チャレンジの無い options は TypeError', () => {
    expect(() => toRequestOptions({ rpId: 'localhost' })).toThrow(TypeError)
  })
})

describe('assertionToJson', () => {
  test('TC-312-04: ブラウザが返した資格情報のバイト列を base64url にし、BE が読む形にする', () => {
    const credential = fakeAssertion()

    expect(assertionToJson(credential)).toEqual({
      id: 'BAU',
      rawId: 'BAU',
      type: 'public-key',
      response: { clientDataJSON: 'AQ', authenticatorData: 'Ag', signature: 'Aw', userHandle: 'Bg' },
      authenticatorAttachment: 'platform',
      clientExtensionResults: {},
    })
  })
})

/** 登録の開始の API が返す options(BE の WebAuthn::toBrowserArray の形。null の値は BE が省く) */
const creationOptions = (): Record<string, unknown> => ({
  rp: { name: 'カクテルDB', id: 'localhost' },
  user: { name: 'admin@example.com', id: 'CQo', displayName: '管理者の太郎' },
  challenge: 'AQID',
  pubKeyCredParams: [
    { type: 'public-key', alg: -7 },
    { type: 'public-key', alg: -257 },
  ],
  timeout: 60000,
  excludeCredentials: [{ type: 'public-key', id: 'BAU' }],
  authenticatorSelection: { userVerification: 'required', residentKey: 'required', requireResidentKey: true },
  attestation: 'none',
})

describe('toCreationOptions', () => {
  test('TC-313-01: BE の登録の options のチャレンジ・ユーザーの ID・登録済みの資格情報の ID をバイト列にし、ほかの値はそのまま渡す', () => {
    expect(toCreationOptions(creationOptions())).toEqual({
      rp: { name: 'カクテルDB', id: 'localhost' },
      user: { name: 'admin@example.com', id: bytes(9, 10), displayName: '管理者の太郎' },
      challenge: bytes(1, 2, 3),
      pubKeyCredParams: [
        { type: 'public-key', alg: -7 },
        { type: 'public-key', alg: -257 },
      ],
      timeout: 60000,
      excludeCredentials: [{ type: 'public-key', id: bytes(4, 5) }],
      authenticatorSelection: { userVerification: 'required', residentKey: 'required', requireResidentKey: true },
      attestation: 'none',
    })
  })

  test.each([
    { name: 'チャレンジ', key: 'challenge' },
    { name: 'RP', key: 'rp' },
    { name: 'ユーザー', key: 'user' },
    { name: 'アルゴリズム', key: 'pubKeyCredParams' },
  ])('TC-313-02: $name の無い options は TypeError', ({ key }) => {
    const options = creationOptions()
    delete options[key]
    expect(() => toCreationOptions(options)).toThrow(TypeError)
  })
})

describe('attestationToJson', () => {
  test('TC-313-03: ブラウザが作った資格情報のバイト列を base64url にし、BE が読む形にする', () => {
    expect(attestationToJson(fakeAttestation())).toEqual({
      id: 'Bwg',
      rawId: 'Bwg',
      type: 'public-key',
      response: { clientDataJSON: 'AQ', attestationObject: 'CQ', transports: ['internal'] },
      authenticatorAttachment: 'platform',
      clientExtensionResults: {},
    })
  })
})
