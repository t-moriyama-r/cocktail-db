import { describe, expect, test } from 'vitest'
import { adminLoginPath, afterAdminLoginPath } from './adminPaths'

const redirectQuery = (target: string) => `?redirect=${encodeURIComponent(target)}`

describe('ログインの後に戻す URL', () => {
  test.each([
    ['/admin/cocktails', '/admin/cocktails'],
    ['/admin/cocktails?page=2&q=%E3%82%B8%E3%83%B3#list', '/admin/cocktails?page=2&q=%E3%82%B8%E3%83%B3#list'],
    ['/admin', '/admin'],
    ['/admin?x=1', '/admin?x=1'],
  ])('TC-119-16: 管理画面の URL %s は、そのまま %s へ戻す', (target, expected) => {
    expect(afterAdminLoginPath(redirectQuery(target))).toBe(expected)
  })

  test.each([
    ['redirect が無い', ''],
    ['redirect が空', '?redirect='],
    ['ほかのサイト', redirectQuery('https://example.com/admin')],
    ['プロトコルを省いたほかのサイト', redirectQuery('//example.com/admin')],
    ['バックスラッシュでほかのサイト', redirectQuery('/\\example.com/admin')],
    ['公開ページ', redirectQuery('/detail/cocktail/1')],
    ['/admin で始まるが管理画面でない', redirectQuery('/administrator')],
    ['管理画面から出るパス', redirectQuery('/admin/../detail/cocktail/1')],
    ['ログインの画面', redirectQuery('/admin/login?redirect=/admin/x')],
    ['末尾に / が付いたログインの画面', redirectQuery('/admin/login/')],
    ['相対パス', redirectQuery('admin/cocktails')],
  ])('TC-119-17: %s のときは、メニュー(/admin)へ移す', (_, search) => {
    expect(afterAdminLoginPath(search)).toBe('/admin')
  })

  test('TC-119-18: ログインの画面へ移すときに持たせた URL は、ログインの後にそのまま戻る', () => {
    const location = { pathname: '/admin/cocktails', search: '?q=a&b=c%26d', hash: '#x' }
    const loginUrl = new URL(adminLoginPath(location), 'http://localhost')

    expect(loginUrl.pathname).toBe('/admin/login')
    expect(afterAdminLoginPath(loginUrl.search)).toBe('/admin/cocktails?q=a&b=c%26d#x')
  })
})
