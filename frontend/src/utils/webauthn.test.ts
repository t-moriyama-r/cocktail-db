import { describe, expect, test } from 'vitest'
import { fakeAssertion } from '../test/webauthn'
import { assertionToJson, base64UrlToBytes, bytesToBase64Url, toRequestOptions } from './webauthn'

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
