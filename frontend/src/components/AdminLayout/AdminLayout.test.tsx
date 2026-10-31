import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router'
import { afterEach, describe, expect, test } from 'vitest'
import App from '../../App'
import type { paths } from '../../api/schema'
import { apiRequests, jsonResponse, mockApi, mockApiHandler } from '../../test/api'

type CurrentAdmin = paths['/admin/me']['get']['responses'][200]['content']['application/json']

const ADMIN: CurrentAdmin = { id: 1, name: '管理者の太郎' }

/** 未ログインのときの管理 API の応答(Laravel の auth が返すもの。スキーマには無い) */
const unauthenticated = () => jsonResponse({ message: 'Unauthenticated.' }, 401)

/** いまの URL(パス・クエリ・ハッシュ)を画面に出す。移った先の URL を確かめるため */
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
const loggedOut = () => mockApiHandler('get', '/admin/me', unauthenticated)

/** ログアウトの API に応答を登録する。ログアウトできた(204)なら、その後の管理者の取得は 401 にする(サーバーと同じ) */
const mockLogout = (respond: () => Response | Promise<Response>) =>
  mockApiHandler('post', '/admin/logout', async () => {
    const response = await respond()
    if (response.status === 204) {
      loggedOut()
    }
    return response
  })

afterEach(() => {
  // テストで置いた CSRF のトークンのクッキーを消す
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

describe('未ログインのとき', () => {
  test('TC-119-01: 管理画面の URL を開くと、開こうとしていた URL(パス・クエリ・ハッシュ)を持たせてログインの画面へ移る', async () => {
    loggedOut()
    renderAt('/admin/cocktails?page=2#list')

    expect(await screen.findByText('管理画面を使うには、ログインが必要です。')).toBeInTheDocument()
    expect(currentUrl()).toBe(`/admin/login?redirect=${encodeURIComponent('/admin/cocktails?page=2#list')}`)
  })

  test('TC-119-02: メニュー(/admin)を開くと、戻る URL を持たせずにログインの画面へ移る', async () => {
    loggedOut()
    renderAt('/admin')

    expect(await screen.findByText('管理画面を使うには、ログインが必要です。')).toBeInTheDocument()
    expect(currentUrl()).toBe('/admin/login')
  })

  test('TC-119-03: ログインの画面は、ログインが要ることと非常用のログイン URL の案内を出し、メニューは出さない', async () => {
    loggedOut()
    renderAt('/admin/login')

    expect(await screen.findByText('管理画面を使うには、ログインが必要です。')).toBeInTheDocument()
    expect(
      screen.getByText('パスキーでログインできないときは、サーバーで発行した非常用のログイン URL を開いてください。'),
    ).toBeInTheDocument()
    expect(screen.queryByRole('navigation', { name: '管理画面のメニュー' })).not.toBeInTheDocument()
    expect(document.title).toBe('ログイン ｜ 宅飲みカクテルデータベース(RC)')
  })
})

describe('ログインしているとき', () => {
  test('TC-119-04: メニュー(/admin)を開くと、管理画面の題・ログインしている管理者の名前・メニュー・見出しが出る', async () => {
    loggedIn()
    renderAt('/admin')

    expect(await screen.findByRole('heading', { level: 1, name: '管理画面' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: '管理画面' })).toHaveAttribute('href', '/admin')
    expect(screen.getByText('管理者の太郎 でログイン中')).toBeInTheDocument()
    expect(screen.getByRole('navigation', { name: '管理画面のメニュー' })).toBeInTheDocument()
    expect(document.title).toBe('管理画面 ｜ 宅飲みカクテルデータベース(RC)')
  })

  test('TC-119-05: メニューは、まだ画面の無い項目と引き継がない項目(オートインプット)を出さず、画面のある項目(パスキー。#313)とログアウトを出す', async () => {
    loggedIn()
    renderAt('/admin')

    const menu = await screen.findByRole('navigation', { name: '管理画面のメニュー' })
    const items = within(menu)
      .getAllByRole('listitem')
      .map((item) => item.textContent)
    expect(items).toEqual(['パスキー', 'ログアウト'])
    expect(within(menu).queryByText(/オートインプット/)).not.toBeInTheDocument()
  })

  test('TC-119-06: まだ無い管理画面の URL では、メニューを出したまま 404 の画面を出す', async () => {
    loggedIn()
    renderAt('/admin/nope')

    expect(await screen.findByRole('heading', { level: 1, name: 'ページが見つかりません' })).toBeInTheDocument()
    expect(screen.getByRole('navigation', { name: '管理画面のメニュー' })).toBeInTheDocument()
  })

  test('TC-119-07: ログインの画面を開くと、redirect の URL(管理画面)へ戻す', async () => {
    loggedIn()
    renderAt(`/admin/login?redirect=${encodeURIComponent('/admin/nope?page=2#list')}`)

    expect(await screen.findByRole('heading', { level: 1, name: 'ページが見つかりません' })).toBeInTheDocument()
    expect(currentUrl()).toBe('/admin/nope?page=2#list')
  })

  test('TC-119-08: ログインの画面を redirect の無い URL・管理画面の外を指す URL で開くと、メニューへ移す', async () => {
    loggedIn()
    renderAt(`/admin/login?redirect=${encodeURIComponent('https://example.com/admin')}`)

    expect(await screen.findByRole('heading', { level: 1, name: '管理画面' })).toBeInTheDocument()
    expect(currentUrl()).toBe('/admin')
  })

  test('TC-119-09: ログアウトを押すと、CSRF のトークンを付けてログアウトの API を呼び、ログインの画面へ移る', async () => {
    document.cookie = `XSRF-TOKEN=${encodeURIComponent('token+/=')}`
    loggedIn()
    mockLogout(() => new Response(null, { status: 204 }))
    renderAt('/admin')

    fireEvent.click(await screen.findByRole('button', { name: 'ログアウト' }))

    expect(await screen.findByText('管理画面を使うには、ログインが必要です。')).toBeInTheDocument()
    expect(currentUrl()).toBe('/admin/login')
    const logout = apiRequests().find((request) => request.method === 'POST')
    expect(new URL(logout!.url).pathname).toBe('/api/admin/logout')
    expect(logout!.headers.get('X-XSRF-TOKEN')).toBe('token+/=')
  })

  test('TC-119-10: 読み取り(GET)には CSRF のトークンを付けない', async () => {
    document.cookie = 'XSRF-TOKEN=token'
    loggedIn()
    renderAt('/admin')

    await screen.findByRole('heading', { level: 1, name: '管理画面' })
    const me = apiRequests().find((request) => new URL(request.url).pathname === '/api/admin/me')
    expect(me!.headers.has('X-XSRF-TOKEN')).toBe(false)
  })

  test('TC-119-11: ログアウトに失敗したら、メッセージを出して画面に残る', async () => {
    loggedIn()
    mockApiHandler('post', '/admin/logout', () => jsonResponse({ message: 'CSRF token mismatch.' }, 419))
    renderAt('/admin')

    fireEvent.click(await screen.findByRole('button', { name: 'ログアウト' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'ログアウトできませんでした(エラー 419)。時間をおいて、もう一度お試しください。',
    )
    expect(currentUrl()).toBe('/admin')
    expect(screen.getByRole('button', { name: 'ログアウト' })).toBeEnabled()
  })

  test('TC-119-12: ログアウトが 401(もうログインしていない)なら、ログアウトできたときと同じくログインの画面へ移る', async () => {
    loggedIn()
    mockApiHandler('post', '/admin/logout', () => {
      loggedOut()
      return unauthenticated()
    })
    renderAt('/admin')

    fireEvent.click(await screen.findByRole('button', { name: 'ログアウト' }))

    expect(await screen.findByText('管理画面を使うには、ログインが必要です。')).toBeInTheDocument()
    expect(currentUrl()).toBe('/admin/login')
  })

  test('TC-119-13: ログアウトの応答を待つあいだは、ログアウトを押せない', async () => {
    loggedIn()
    let respond: ((response: Response) => void) | undefined
    mockLogout(() => new Promise<Response>((resolve) => (respond = resolve)))
    renderAt('/admin')

    fireEvent.click(await screen.findByRole('button', { name: 'ログアウト' }))

    expect(screen.getByRole('button', { name: 'ログアウト' })).toBeDisabled()
    await waitFor(() => expect(respond).toBeDefined())
    await act(async () => respond!(new Response(null, { status: 204 })))
    expect(await screen.findByText('管理画面を使うには、ログインが必要です。')).toBeInTheDocument()
    expect(currentUrl()).toBe('/admin/login')
  })
})

describe('ログインしているかを確かめられないとき', () => {
  test('TC-119-14: 管理者の取得が 500 なら、メニューを出さずにエラーを出し、もう一度読み込むとメニューが出る', async () => {
    mockApiHandler('get', '/admin/me', () => jsonResponse({ message: 'Server Error' }, 500))
    renderAt('/admin')

    expect(await screen.findByRole('alert')).toHaveTextContent('データを読み込めませんでした(エラー 500)')
    expect(screen.queryByRole('navigation', { name: '管理画面のメニュー' })).not.toBeInTheDocument()

    loggedIn()
    fireEvent.click(screen.getByRole('button', { name: 'もう一度読み込む' }))

    expect(await screen.findByRole('navigation', { name: '管理画面のメニュー' })).toBeInTheDocument()
    expect(currentUrl()).toBe('/admin')
  })

  test('TC-119-15: 応答を待つあいだは、読み込み中を出してメニューを出さない', async () => {
    mockApiHandler('get', '/admin/me', () => new Promise<Response>(() => {}))
    renderAt('/admin')

    expect(await screen.findByRole('status')).toHaveTextContent('読み込み中…')
    expect(screen.queryByRole('navigation', { name: '管理画面のメニュー' })).not.toBeInTheDocument()
  })
})
