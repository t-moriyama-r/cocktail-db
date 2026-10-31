import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test, type Page } from '@playwright/test'

// 管理画面の枠とメニュー(#119)。ログインは、E2eSeeder の管理者で非常用のログイン URL(#115)を出して開く

const LOGGED_OUT_TEXT = '管理画面を使うには、ログインが必要です。'

/**
 * php artisan admin:login-link で、E2eSeeder の管理者の非常用のログイン URL を出す。
 * URL の署名にはホストも入るので、APP_URL を E2E が開くオリジンにして出す(環境変数は .env より優先される)。
 */
function issueLoginLink(baseURL: string): string {
  return execFileSync('php', ['artisan', 'admin:login-link', 'e2e-admin@example.com'], {
    cwd: fileURLToPath(new URL('../../backend/', import.meta.url)),
    env: { ...process.env, APP_URL: baseURL },
    encoding: 'utf8',
  }).trim()
}

async function login(page: Page, baseURL: string) {
  await page.goto(issueLoginLink(baseURL))
  await expect(page).toHaveURL(/\/admin$/)
  await expect(page.getByRole('navigation', { name: '管理画面のメニュー' })).toBeVisible()
}

test('TC-119-19: 未ログインで管理画面の URL を開くと、開こうとしていた URL を持たせてログインの画面へ移る', async ({ page }) => {
  await page.goto('/admin/nope?page=2')

  await expect(page.getByText(LOGGED_OUT_TEXT)).toBeVisible()
  const url = new URL(page.url())
  expect(url.pathname).toBe('/admin/login')
  expect(url.searchParams.get('redirect')).toBe('/admin/nope?page=2')
  await expect(page.getByRole('navigation', { name: '管理画面のメニュー' })).toHaveCount(0)
})

test('TC-119-20: ログインすると、メニューに管理者の名前・画面のある項目(パスキー。#313)・ログアウトが出て、オートインプットは出ない', async ({ page, baseURL }) => {
  await login(page, baseURL!)

  await expect(page.getByRole('heading', { level: 1 })).toHaveText('管理画面')
  await expect(page.getByText('E2E の管理者 でログイン中')).toBeVisible()
  const menu = page.getByRole('navigation', { name: '管理画面のメニュー' })
  await expect(menu.getByRole('listitem')).toHaveText(['パスキー', 'ログアウト'])
  await expect(menu.getByText(/オートインプット/)).toHaveCount(0)
})

test('TC-119-21: ログインしてからログインの画面を開くと、redirect の URL へ戻る', async ({ page, baseURL }) => {
  await login(page, baseURL!)

  await page.goto(`/admin/login?redirect=${encodeURIComponent('/admin/nope?page=2')}`)

  await expect(page).toHaveURL(/\/admin\/nope\?page=2$/)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('ページが見つかりません')
  await expect(page.getByRole('navigation', { name: '管理画面のメニュー' })).toBeVisible()
})

test('TC-119-22: ログアウトするとログインの画面へ移り、その後は管理画面を開いてもログインへ移る', async ({ page, baseURL }) => {
  await login(page, baseURL!)

  await page.getByRole('button', { name: 'ログアウト' }).click()

  await expect(page.getByText(LOGGED_OUT_TEXT)).toBeVisible()
  await expect(page).toHaveURL(/\/admin\/login$/)

  await page.goto('/admin')
  await expect(page.getByText(LOGGED_OUT_TEXT)).toBeVisible()
  await expect(page).toHaveURL(/\/admin\/login$/)
})
