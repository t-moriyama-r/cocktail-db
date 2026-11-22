import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test, type Page } from '@playwright/test'

// 旧の管理画面の一覧・編集の URL(#626)。サーバーが新の画面へ 302 で移し、ログインの確かめは移った先の画面がする。
// 決めたことは docs/decisions/legacy-admin-urls.md。相手は E2eSeeder のカクテル(下書き: 「下書きのカクテル」2 件)。
// ログインは、admin-cocktails.spec.ts と同じく、E2eSeeder の管理者で非常用のログイン URL(#115)を出して開く

/** php artisan admin:login-link で、E2eSeeder の管理者の非常用のログイン URL を出す(APP_URL を E2E が開くオリジンにする) */
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
}

test('TC-626-09: ログイン中に旧の下書きの一覧の URL を開くと、新のカクテルの一覧の「下書き」が出る', async ({ page, baseURL }) => {
  await login(page, baseURL!)

  await page.goto('/admin/list/CocktailDraft')

  await expect(page).toHaveURL(/\/admin\/cocktails\?status=draft$/)
  const switcher = page.getByRole('navigation', { name: 'カクテルの一覧の切り替え' })
  await expect(switcher.getByRole('link', { name: '下書き' })).toHaveAttribute('aria-current', 'page')
  await expect(page.getByRole('link', { name: /^下書きのカクテル/ })).toHaveText([
    '下書きのカクテル(Draft)',
    '下書きのカクテル(Draft Cocktail)',
  ])
})

test('TC-626-10: 未ログインで旧の一覧の URL を開くと、移った先の URL を持たせてログインの画面へ移る', async ({ page }) => {
  await page.goto('/admin/list/Brand')

  await expect(page).toHaveURL(/\/admin\/login\?redirect=%2Fadmin%2Fbrands$/)
})
