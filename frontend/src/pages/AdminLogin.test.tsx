import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router'
import { afterEach, describe, expect, test } from 'vitest'
import App from '../App'
import type { paths } from '../api/schema'
import { apiRequests, jsonResponse, mockApi, mockApiHandler } from '../test/api'
import { fakeAssertion, stubPasskeyBrowser } from '../test/webauthn'

// パスキーのログインの画面(#312)。ブラウザの WebAuthn の API は test/webauthn.ts で差し替え、BE の API は fetch の層で差し替える

type CurrentAdmin = paths['/admin/me']['get']['responses'][200]['content']['application/json']

const ADMIN: CurrentAdmin = { id: 1, name: '管理者の太郎' }

const LOGGED_OUT_TEXT = '管理画面を使うには、ログインが必要です。'
const EMERGENCY_TEXT = 'パスキーでログインできないときは、サーバーで発行した非常用のログイン URL を開いてください。'

function CurrentUrl() {
  const { pathname, search, hash } = useLocation()
  return <div data-testid="current-url">{`${pathname}${search}${hash}`}</div>
}

const renderAt = (path: string) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <App />
      <CurrentUrl />
    </MemoryRouter>,
  )

const currentUrl = () => screen.getByTestId('current-url').textContent

const loggedIn = () => mockApi('get', '/admin/me', 200, ADMIN)
const loggedOut = () => mockApiHandler('get', '/admin/me', () => jsonResponse({ message: 'Unauthenticated.' }, 401))

/** ログインの開始の API が返す options(BE の WebAuthn::toBrowserArray の形) */
const startLogin = () =>
  mockApi('post', '/auth/passkey/login-options', 200, {
    options: { challenge: 'AQID', timeout: 60000, rpId: 'localhost', userVerification: 'required' },
  })

/** ログインの完了の API。ログインできたら、その後の管理者の取得はログイン済みにする(サーバーと同じ) */
const finishLogin = () =>
  mockApiHandler('post', '/auth/passkey/login', () => {
    loggedIn()
    return jsonResponse(ADMIN, 200)
  })

const domError = (name: string) => new DOMException('テストの例外', name)

const passkeyButton = () => screen.findByRole('button', { name: 'パスキーでログイン' })

const requestTo = (path: string) => apiRequests().find((request) => new URL(request.url).pathname === path)

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

describe('ブラウザがパスキーに対応しているとき', () => {
  test('TC-312-05: ログインの画面に「パスキーでログイン」のボタンと、非常用のログイン URL の案内が出る', async () => {
    stubPasskeyBrowser(async () => fakeAssertion())
    loggedOut()
    renderAt('/admin/login')

    expect(await passkeyButton()).toBeEnabled()
    expect(screen.getByText(LOGGED_OUT_TEXT)).toBeInTheDocument()
    expect(screen.getByText(EMERGENCY_TEXT)).toBeInTheDocument()
    expect(screen.queryByText('このブラウザはパスキーに対応していません。')).not.toBeInTheDocument()
  })

  test('TC-312-07: ボタンを押すと、開始の options で認証器に頼み、署名を CSRF のトークン付きで送ってログインし、メニューへ移る', async () => {
    document.cookie = 'XSRF-TOKEN=token'
    const received = stubPasskeyBrowser(async () => fakeAssertion())
    loggedOut()
    startLogin()
    finishLogin()
    renderAt('/admin/login')

    fireEvent.click(await passkeyButton())

    expect(await screen.findByText('管理者の太郎 でログイン中')).toBeInTheDocument()
    expect(currentUrl()).toBe('/admin')
    expect(received).toEqual([
      {
        publicKey: {
          challenge: new Uint8Array([1, 2, 3]),
          timeout: 60000,
          rpId: 'localhost',
          userVerification: 'required',
        },
      },
    ])
    const login = requestTo('/api/auth/passkey/login')!
    expect(login.headers.get('X-XSRF-TOKEN')).toBe('token')
    expect(await login.clone().json()).toEqual({
      credential: {
        id: 'BAU',
        rawId: 'BAU',
        type: 'public-key',
        response: { clientDataJSON: 'AQ', authenticatorData: 'Ag', signature: 'Aw', userHandle: 'Bg' },
        authenticatorAttachment: 'platform',
        clientExtensionResults: {},
      },
    })
    expect(requestTo('/api/auth/passkey/login-options')!.headers.get('X-XSRF-TOKEN')).toBe('token')
  })

  test('TC-312-08: redirect の付いたログインの画面でログインすると、開こうとしていた管理画面の URL へ戻る', async () => {
    stubPasskeyBrowser(async () => fakeAssertion())
    loggedOut()
    startLogin()
    finishLogin()
    renderAt(`/admin/login?redirect=${encodeURIComponent('/admin/nope?page=2#a')}`)

    fireEvent.click(await passkeyButton())

    expect(await screen.findByRole('heading', { level: 1, name: 'ページが見つかりません' })).toBeInTheDocument()
    expect(currentUrl()).toBe('/admin/nope?page=2#a')
  })

  test.each([
    { name: '取り消し・時間切れ(NotAllowedError)', error: 'NotAllowedError' },
    { name: '中断(AbortError)', error: 'AbortError' },
  ])('TC-312-09: 認証器が $name で終わると、取り消されたと出して画面に残り、完了の API は呼ばない', async ({ error }) => {
    stubPasskeyBrowser(async () => {
      throw domError(error)
    })
    loggedOut()
    startLogin()
    renderAt('/admin/login')

    fireEvent.click(await passkeyButton())

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'パスキーでのログインが取り消されたか、時間切れになりました。もう一度お試しください。',
    )
    expect(currentUrl()).toBe('/admin/login')
    expect(await passkeyButton()).toBeEnabled()
    expect(requestTo('/api/auth/passkey/login')).toBeUndefined()
  })

  test('TC-312-10: 完了の API が 422(登録していない・消したパスキー)なら、確かめられなかったと出して画面に残る', async () => {
    stubPasskeyBrowser(async () => fakeAssertion())
    loggedOut()
    startLogin()
    mockApi('post', '/auth/passkey/login', 422, {
      message: 'Unable to verify passkey. Please try again.',
      errors: { credential: ['Unable to verify passkey. Please try again.'] },
    })
    renderAt('/admin/login')

    fireEvent.click(await passkeyButton())

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'パスキーを確かめられませんでした。このサイトに登録したパスキーを選んで、もう一度お試しください。',
    )
    expect(currentUrl()).toBe('/admin/login')
  })

  test('TC-312-11: 開始の API が 429(試す回数が多すぎる)なら、待つように出し、認証器には頼まない', async () => {
    const received = stubPasskeyBrowser(async () => fakeAssertion())
    loggedOut()
    mockApiHandler('post', '/auth/passkey/login-options', () => jsonResponse({ message: 'Too Many Attempts.' }, 429))
    renderAt('/admin/login')

    fireEvent.click(await passkeyButton())

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'ログインを試した回数が多すぎます。1 分ほど待ってから、もう一度お試しください。',
    )
    expect(received).toEqual([])
  })

  test.each([
    {
      name: '完了の API が 500',
      mock: () => {
        startLogin()
        mockApiHandler('post', '/auth/passkey/login', () => jsonResponse({ message: 'Server Error' }, 500))
      },
      message: 'ログインできませんでした(エラー 500)。時間をおいて、もう一度お試しください。',
    },
    {
      name: '開始の API が 419(CSRF のトークンの期限切れ)',
      mock: () =>
        mockApiHandler('post', '/auth/passkey/login-options', () => jsonResponse({ message: 'CSRF token mismatch.' }, 419)),
      message: 'ログインできませんでした(エラー 419)。時間をおいて、もう一度お試しください。',
    },
    {
      name: '開始の API に届かない',
      mock: () =>
        mockApiHandler('post', '/auth/passkey/login-options', () => {
          throw new TypeError('Failed to fetch')
        }),
      message: 'ログインできませんでした。通信に失敗しました。接続を確かめて、もう一度お試しください。',
    },
  ])('TC-312-12: $name なら、エラーを出して画面に残る', async ({ mock, message }) => {
    stubPasskeyBrowser(async () => fakeAssertion())
    loggedOut()
    mock()
    renderAt('/admin/login')

    fireEvent.click(await passkeyButton())

    expect(await screen.findByRole('alert')).toHaveTextContent(message)
    expect(currentUrl()).toBe('/admin/login')
  })

  test('TC-312-13: 認証器がほかの理由で断ると(SecurityError など)、例外の名前を添えて出す', async () => {
    stubPasskeyBrowser(async () => {
      throw domError('SecurityError')
    })
    loggedOut()
    startLogin()
    renderAt('/admin/login')

    fireEvent.click(await passkeyButton())

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'パスキーでログインできませんでした(SecurityError)。もう一度お試しください。',
    )
  })

  test('TC-312-14: 認証器の応答を待つあいだはボタンを押せず、終わると押せる', async () => {
    let answer: ((credential: Credential | null) => void) | undefined
    stubPasskeyBrowser(() => new Promise<Credential | null>((resolve) => (answer = resolve)))
    loggedOut()
    startLogin()
    renderAt('/admin/login')

    fireEvent.click(await passkeyButton())

    expect(await passkeyButton()).toBeDisabled()
    await waitFor(() => expect(answer).toBeDefined())
    await act(async () => answer!(null))
    expect(await screen.findByRole('alert')).toHaveTextContent('パスキーでのログインが取り消されたか、時間切れになりました。')
    expect(await passkeyButton()).toBeEnabled()
  })
})

describe('ブラウザがパスキーに対応していないとき', () => {
  test('TC-312-06: ボタンを出さず、対応していないことと、非常用のログイン URL の案内を出す', async () => {
    loggedOut()
    renderAt('/admin/login')

    expect(await screen.findByText('このブラウザはパスキーに対応していません。')).toBeInTheDocument()
    expect(screen.getByText(EMERGENCY_TEXT)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'パスキーでログイン' })).not.toBeInTheDocument()
  })
})
