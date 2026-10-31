import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, test } from 'vitest'
import App from '../App'
import type { components, paths } from '../api/schema'
import { apiRequests, jsonResponse, mockApi, mockApiHandler } from '../test/api'
import { fakeAttestation, stubPasskeyRegistration } from '../test/webauthn'

// パスキーの管理の画面(#313)。ブラウザの WebAuthn の API は test/webauthn.ts で差し替え、BE の API は fetch の層で差し替える

type CurrentAdmin = paths['/admin/me']['get']['responses'][200]['content']['application/json']
type Passkey = components['schemas']['PasskeyResource']

const ADMIN: CurrentAdmin = { id: 1, name: '管理者の太郎' }

const MACBOOK: Passkey = {
  id: 3,
  name: '仕事の MacBook',
  // 日本の時刻で 2026/10/31 01:00
  created_at: '2026-10-30T16:00:00.000000Z',
  // 日本の時刻で 2026/11/01 00:00(日付が変わるところ)
  last_used_at: '2026-10-31T15:00:00.000000Z',
}

const IPHONE: Passkey = { id: 5, name: 'iPhone', created_at: '2026-10-31T14:59:59.000000Z', last_used_at: null }

const renderAt = (path: string) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <App />
    </MemoryRouter>,
  )

const loggedIn = () => mockApi('get', '/admin/me', 200, ADMIN)

/** 一覧の API。登録・削除の後に読み直したときの応答を、呼ばれるたびに list() で決める */
const mockPasskeys = (list: () => Passkey[]) =>
  mockApiHandler('get', '/admin/passkeys', () => jsonResponse({ data: list() }, 200))

/** 登録の開始の API が返す options(BE の WebAuthn::toBrowserArray の形) */
const startRegistration = () =>
  mockApi('post', '/admin/passkeys/registration-options', 200, {
    options: {
      rp: { name: 'カクテルDB', id: 'localhost' },
      user: { name: 'admin@example.com', id: 'CQo', displayName: '管理者の太郎' },
      challenge: 'AQID',
      pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
      timeout: 60000,
      excludeCredentials: [{ type: 'public-key', id: 'BAU' }],
      authenticatorSelection: { userVerification: 'required', residentKey: 'required' },
      attestation: 'none',
    },
  })

const domError = (name: string) => new DOMException('テストの例外', name)

const requestTo = (method: string, path: string) =>
  apiRequests().find((request) => request.method === method && new URL(request.url).pathname === path)

/** 一覧の行(名前で探す) */
const item = (name: string) => screen.findByRole('listitem', { name })

/** 一覧の行の、上から順の文字(名前と日付) */
const listTexts = async () =>
  within(await screen.findByRole('list', { name: '登録したパスキー' }))
    .getAllByRole('listitem')
    .map((row) => [
      row.querySelector('p')?.textContent,
      ...within(row)
        .getAllByRole('definition')
        .map((dd) => dd.textContent),
    ])

const nameInput = () => screen.findByLabelText('端末の名前')
const registerButton = () => screen.findByRole('button', { name: 'パスキーを登録' })

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

describe('一覧', () => {
  test('TC-313-04: 登録したパスキーを、名前・登録日・最後に使った日(日本の日付)で登録した順に出す。使っていなければそう出す', async () => {
    stubPasskeyRegistration(async () => fakeAttestation())
    loggedIn()
    mockPasskeys(() => [MACBOOK, IPHONE])
    renderAt('/admin/passkeys')

    expect(await listTexts()).toEqual([
      ['仕事の MacBook', '2026/10/31', '2026/11/01'],
      ['iPhone', '2026/10/31', 'まだ使っていません'],
    ])
    const row = await item('仕事の MacBook')
    expect(within(row).getByText('登録日')).toBeInTheDocument()
    expect(within(row).getByText('最後に使った日')).toBeInTheDocument()
  })

  test('TC-313-05: 1 つも無ければ、無いことと、非常用のログイン URL でしか入れないことを出す', async () => {
    stubPasskeyRegistration(async () => fakeAttestation())
    loggedIn()
    mockPasskeys(() => [])
    renderAt('/admin/passkeys')

    expect(
      await screen.findByText('登録したパスキーはありません。いまは非常用のログイン URL でしかログインできません。'),
    ).toBeInTheDocument()
    expect(screen.queryByRole('list', { name: '登録したパスキー' })).not.toBeInTheDocument()
  })

  test('TC-313-06: 一覧を読み込めなければ、エラーと読み直すボタンを出し、押すと読み直す', async () => {
    stubPasskeyRegistration(async () => fakeAttestation())
    loggedIn()
    mockApiHandler('get', '/admin/passkeys', () => jsonResponse({ message: 'Server Error' }, 500))
    renderAt('/admin/passkeys')

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'データを読み込めませんでした(エラー 500)。時間をおいて、もう一度お試しください。',
    )
    mockPasskeys(() => [IPHONE])
    fireEvent.click(screen.getByRole('button', { name: 'もう一度読み込む' }))

    expect(await item('iPhone')).toBeInTheDocument()
  })

  test('TC-313-07: メニューの「パスキー」でこの画面が開き、ページの題が「パスキー」になる', async () => {
    stubPasskeyRegistration(async () => fakeAttestation())
    loggedIn()
    mockPasskeys(() => [IPHONE])
    renderAt('/admin')

    const menu = await screen.findByRole('navigation', { name: '管理画面のメニュー' })
    fireEvent.click(within(menu).getByRole('link', { name: 'パスキー' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'パスキー' })).toBeInTheDocument()
    expect(within(menu).getByRole('link', { name: 'パスキー' })).toHaveAttribute('aria-current', 'page')
    expect(document.title).toBe('パスキー ｜ 宅飲みカクテルデータベース(RC)')
  })
})

describe('登録', () => {
  test('TC-313-08: 名前を入れて登録すると、開始の options で認証器に頼み、名前(前後の空白を除く)と資格情報を CSRF のトークン付きで送り、登録したことを出して一覧を読み直す', async () => {
    document.cookie = 'XSRF-TOKEN=token'
    const received = stubPasskeyRegistration(async () => fakeAttestation())
    loggedIn()
    let passkeys = [MACBOOK]
    mockPasskeys(() => passkeys)
    startRegistration()
    mockApiHandler('post', '/admin/passkeys', () => {
      passkeys = [MACBOOK, IPHONE]
      return jsonResponse({ data: IPHONE }, 201)
    })
    renderAt('/admin/passkeys')

    fireEvent.change(await nameInput(), { target: { value: '  iPhone  ' } })
    fireEvent.click(await registerButton())

    expect(await screen.findByText('「iPhone」を登録しました。')).toBeInTheDocument()
    expect(await item('iPhone')).toBeInTheDocument()
    expect(await nameInput()).toHaveValue('')
    expect(received).toEqual([
      {
        publicKey: {
          rp: { name: 'カクテルDB', id: 'localhost' },
          user: { name: 'admin@example.com', id: new Uint8Array([9, 10]), displayName: '管理者の太郎' },
          challenge: new Uint8Array([1, 2, 3]),
          pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
          timeout: 60000,
          excludeCredentials: [{ type: 'public-key', id: new Uint8Array([4, 5]) }],
          authenticatorSelection: { userVerification: 'required', residentKey: 'required' },
          attestation: 'none',
        },
      },
    ])
    const saved = requestTo('POST', '/api/admin/passkeys')!
    expect(saved.headers.get('X-XSRF-TOKEN')).toBe('token')
    expect(await saved.clone().json()).toEqual({
      name: 'iPhone',
      credential: {
        id: 'Bwg',
        rawId: 'Bwg',
        type: 'public-key',
        response: { clientDataJSON: 'AQ', attestationObject: 'CQ', transports: ['internal'] },
        authenticatorAttachment: 'platform',
        clientExtensionResults: {},
      },
    })
    expect(requestTo('POST', '/api/admin/passkeys/registration-options')!.headers.get('X-XSRF-TOKEN')).toBe('token')
  })

  test.each([
    { name: '空', value: '' },
    { name: '空白だけ', value: ' 　 ' },
  ])('TC-313-09: 名前が$nameなら、名前を入れるよう出し、登録を始めない', async ({ value }) => {
    const received = stubPasskeyRegistration(async () => fakeAttestation())
    loggedIn()
    mockPasskeys(() => [])
    renderAt('/admin/passkeys')

    fireEvent.change(await nameInput(), { target: { value } })
    fireEvent.click(await registerButton())

    expect(await screen.findByRole('alert')).toHaveTextContent('端末の名前を入れてください。')
    expect(requestTo('POST', '/api/admin/passkeys/registration-options')).toBeUndefined()
    expect(received).toEqual([])
  })

  test('TC-313-10: 名前の欄は 255 文字までしか入らない', async () => {
    stubPasskeyRegistration(async () => fakeAttestation())
    loggedIn()
    mockPasskeys(() => [])
    renderAt('/admin/passkeys')

    expect(await nameInput()).toHaveAttribute('maxLength', '255')
  })

  test.each([
    {
      name: '認証器の画面を閉じた(NotAllowedError)',
      create: () => Promise.reject(domError('NotAllowedError')),
      message: 'パスキーの登録が取り消されたか、時間切れになりました。もう一度お試しください。',
    },
    {
      name: 'この端末のパスキーがもう登録してある(InvalidStateError)',
      create: () => Promise.reject(domError('InvalidStateError')),
      message: 'この端末のパスキーは、もう登録してあります。',
    },
    {
      name: 'ブラウザがほかの理由で断った(SecurityError)',
      create: () => Promise.reject(domError('SecurityError')),
      message: 'パスキーを登録できませんでした(SecurityError)。もう一度お試しください。',
    },
  ])('TC-313-11: $name ときは、メッセージを出し、登録の完了を送らない', async ({ create, message }) => {
    stubPasskeyRegistration(create)
    loggedIn()
    mockPasskeys(() => [])
    startRegistration()
    renderAt('/admin/passkeys')

    fireEvent.change(await nameInput(), { target: { value: 'iPhone' } })
    fireEvent.click(await registerButton())

    expect(await screen.findByRole('alert')).toHaveTextContent(message)
    expect(requestTo('POST', '/api/admin/passkeys')).toBeUndefined()
    expect(await registerButton()).toBeEnabled()
    expect(await nameInput()).toHaveValue('iPhone')
  })

  test.each([
    {
      name: '登録の完了が 422(資格情報を確かめられない)',
      path: '/admin/passkeys' as const,
      response: () => jsonResponse({ message: 'x', errors: { credential: ['Unable to verify passkey.'] } }, 422),
      message: 'パスキーを確かめられませんでした。もう一度お試しください。',
    },
    {
      name: '登録の完了が 422(名前)',
      path: '/admin/passkeys' as const,
      response: () => jsonResponse({ message: 'x', errors: { name: ['The name field must not be greater than 255 characters.'] } }, 422),
      message: '端末の名前は 255 文字までで入れてください。',
    },
    {
      name: '登録の開始が 500',
      path: '/admin/passkeys/registration-options' as const,
      response: () => jsonResponse({ message: 'Server Error' }, 500),
      message: 'パスキーを登録できませんでした(エラー 500)。時間をおいて、もう一度お試しください。',
    },
    {
      name: '登録の完了が届かない',
      path: '/admin/passkeys' as const,
      response: () => Promise.reject(new TypeError('Failed to fetch')),
      message: 'パスキーを登録できませんでした。通信に失敗しました。接続を確かめて、もう一度お試しください。',
    },
  ])('TC-313-12: $name ときは、メッセージを出して一覧を変えない', async ({ path, response, message }) => {
    stubPasskeyRegistration(async () => fakeAttestation())
    loggedIn()
    mockPasskeys(() => [MACBOOK])
    startRegistration()
    mockApiHandler('post', path, response)
    renderAt('/admin/passkeys')

    fireEvent.change(await nameInput(), { target: { value: 'iPhone' } })
    fireEvent.click(await registerButton())

    expect(await screen.findByRole('alert')).toHaveTextContent(message)
    expect(await listTexts()).toEqual([['仕事の MacBook', '2026/10/31', '2026/11/01']])
  })

  test('TC-313-13: 登録の応答を待つあいだは、登録のボタンを押せない', async () => {
    stubPasskeyRegistration(() => new Promise(() => {}))
    loggedIn()
    mockPasskeys(() => [])
    startRegistration()
    renderAt('/admin/passkeys')

    fireEvent.change(await nameInput(), { target: { value: 'iPhone' } })
    fireEvent.click(await registerButton())

    await waitFor(async () => expect(await registerButton()).toBeDisabled())
  })

  test('TC-313-14: ブラウザがパスキーの登録に対応していなければ、フォームの代わりにそう出し、一覧は出す', async () => {
    loggedIn()
    mockPasskeys(() => [IPHONE])
    renderAt('/admin/passkeys')

    expect(
      await screen.findByText('このブラウザはパスキーの登録に対応していません。パスキーに対応したブラウザで開いてください。'),
    ).toBeInTheDocument()
    expect(screen.queryByLabelText('端末の名前')).not.toBeInTheDocument()
    expect(await item('iPhone')).toBeInTheDocument()
  })
})

describe('削除', () => {
  test('TC-313-15: 「削除」を押すと、消すかを確かめ、まだ消さない。「やめる」で元に戻る', async () => {
    stubPasskeyRegistration(async () => fakeAttestation())
    loggedIn()
    mockPasskeys(() => [MACBOOK, IPHONE])
    renderAt('/admin/passkeys')

    fireEvent.click(within(await item('iPhone')).getByRole('button', { name: '「iPhone」を削除' }))

    const confirm = screen.getByRole('group', { name: '「iPhone」の削除の確認' })
    expect(confirm).toHaveTextContent('「iPhone」を消しますか? 消すと、このパスキーではログインできなくなります。')
    expect(confirm).not.toHaveTextContent('これが最後のパスキーです。')
    expect(requestTo('DELETE', '/api/admin/passkeys/5')).toBeUndefined()

    fireEvent.click(within(confirm).getByRole('button', { name: 'やめる' }))

    expect(screen.queryByRole('group', { name: '「iPhone」の削除の確認' })).not.toBeInTheDocument()
    expect(within(await item('iPhone')).getByRole('button', { name: '「iPhone」を削除' })).toBeInTheDocument()
    expect(requestTo('DELETE', '/api/admin/passkeys/5')).toBeUndefined()
  })

  test('TC-313-16: 確かめて「消す」を押すと、CSRF のトークン付きで消し、消したことを出して一覧を読み直す', async () => {
    document.cookie = 'XSRF-TOKEN=token'
    stubPasskeyRegistration(async () => fakeAttestation())
    loggedIn()
    let passkeys = [MACBOOK, IPHONE]
    mockPasskeys(() => passkeys)
    mockApiHandler('delete', '/admin/passkeys/{passkey}', () => {
      passkeys = [MACBOOK]
      return new Response(null, { status: 204 })
    })
    renderAt('/admin/passkeys')

    fireEvent.click(within(await item('iPhone')).getByRole('button', { name: '「iPhone」を削除' }))
    fireEvent.click(within(screen.getByRole('group', { name: '「iPhone」の削除の確認' })).getByRole('button', { name: '消す' }))

    expect(await screen.findByText('「iPhone」を消しました。')).toBeInTheDocument()
    await waitFor(async () => expect(await listTexts()).toEqual([['仕事の MacBook', '2026/10/31', '2026/11/01']]))
    expect(requestTo('DELETE', '/api/admin/passkeys/5')!.headers.get('X-XSRF-TOKEN')).toBe('token')
  })

  test('TC-313-17: 最後の 1 つを消すときは、消すと非常用のログイン URL でしか入れなくなることも出して確かめる', async () => {
    stubPasskeyRegistration(async () => fakeAttestation())
    loggedIn()
    mockPasskeys(() => [IPHONE])
    renderAt('/admin/passkeys')

    fireEvent.click(within(await item('iPhone')).getByRole('button', { name: '「iPhone」を削除' }))

    expect(screen.getByRole('group', { name: '「iPhone」の削除の確認' })).toHaveTextContent(
      'これが最後のパスキーです。消すと、次からは非常用のログイン URL でしかログインできません。',
    )
  })

  test('TC-313-18: 消せなかったら(500)、その行にメッセージを出し、一覧から消さない', async () => {
    stubPasskeyRegistration(async () => fakeAttestation())
    loggedIn()
    mockPasskeys(() => [MACBOOK, IPHONE])
    mockApiHandler('delete', '/admin/passkeys/{passkey}', () => jsonResponse({ message: 'Server Error' }, 500))
    renderAt('/admin/passkeys')

    fireEvent.click(within(await item('iPhone')).getByRole('button', { name: '「iPhone」を削除' }))
    fireEvent.click(within(screen.getByRole('group', { name: '「iPhone」の削除の確認' })).getByRole('button', { name: '消す' }))

    expect(await within(await item('iPhone')).findByRole('alert')).toHaveTextContent(
      'パスキーを消せませんでした(エラー 500)。時間をおいて、もう一度お試しください。',
    )
    expect(screen.queryByText(/を消しました。/)).not.toBeInTheDocument()
    expect(await listTexts()).toHaveLength(2)
  })

  test('TC-313-19: もう無かったら(404。ほかの画面で消した、など)、消せたときと同じく消したことを出して一覧を読み直す', async () => {
    stubPasskeyRegistration(async () => fakeAttestation())
    loggedIn()
    let passkeys = [MACBOOK, IPHONE]
    mockPasskeys(() => passkeys)
    mockApiHandler('delete', '/admin/passkeys/{passkey}', () => {
      passkeys = [MACBOOK]
      return jsonResponse({ message: 'Not Found' }, 404)
    })
    renderAt('/admin/passkeys')

    fireEvent.click(within(await item('iPhone')).getByRole('button', { name: '「iPhone」を削除' }))
    fireEvent.click(within(screen.getByRole('group', { name: '「iPhone」の削除の確認' })).getByRole('button', { name: '消す' }))

    expect(await screen.findByText('「iPhone」を消しました。')).toBeInTheDocument()
    await waitFor(async () => expect(await listTexts()).toHaveLength(1))
  })

  test('TC-313-20: 消す応答を待つあいだは、「消す」「やめる」を押せない', async () => {
    stubPasskeyRegistration(async () => fakeAttestation())
    loggedIn()
    mockPasskeys(() => [IPHONE])
    mockApiHandler('delete', '/admin/passkeys/{passkey}', () => new Promise(() => {}))
    renderAt('/admin/passkeys')

    fireEvent.click(within(await item('iPhone')).getByRole('button', { name: '「iPhone」を削除' }))
    const confirm = screen.getByRole('group', { name: '「iPhone」の削除の確認' })
    await act(async () => {
      fireEvent.click(within(confirm).getByRole('button', { name: '消す' }))
    })

    expect(within(confirm).getByRole('button', { name: '消す' })).toBeDisabled()
    expect(within(confirm).getByRole('button', { name: 'やめる' })).toBeDisabled()
  })
})
