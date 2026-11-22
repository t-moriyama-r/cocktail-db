import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test, type Page } from '@playwright/test'

// 管理画面のカクテルの一覧(公開済み・下書き。#126)。相手は本物の管理 API(#289)と、E2eSeeder のカクテル
// (公開済み: ジントニック・モスコミュールなど 8 件 / 下書き: 「下書きのカクテル」2 件(英名は Draft と Draft Cocktail)と、
// カクテルの編集の E2E(#127)が使う「E2E の下書き」(英名は Save Test))。
// ログインは、admin-layout.spec.ts と同じく、E2eSeeder の管理者で非常用のログイン URL(#115)を出して開く

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

test('TC-126-09: 公開したカクテルは公開済みの一覧に、下書きのカクテルは下書きの一覧に出て、戻るで公開済みの一覧に戻る', async ({
  page,
  baseURL,
}) => {
  await login(page, baseURL!)
  await page.getByRole('navigation', { name: '管理画面のメニュー' }).getByRole('link', { name: 'カクテル' }).click()

  await expect(page).toHaveURL(/\/admin\/cocktails$/)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('カクテル')
  const switcher = page.getByRole('navigation', { name: 'カクテルの一覧の切り替え' })
  await expect(switcher.getByRole('link', { name: '公開済み' })).toHaveAttribute('aria-current', 'page')
  await expect(page.getByRole('link', { name: 'ジントニック(Gin and Tonic)' })).toHaveAttribute('href', /\/admin\/cocktails\/\d+$/)
  await expect(page.getByRole('link', { name: 'モスコミュール(Moscow Mule)' })).toBeVisible()
  await expect(page.getByRole('link', { name: /^下書きのカクテル/ })).toHaveCount(0)

  await switcher.getByRole('link', { name: '下書き' }).click()

  await expect(page).toHaveURL(/\/admin\/cocktails\?status=draft$/)
  await expect(switcher.getByRole('link', { name: '下書き' })).toHaveAttribute('aria-current', 'page')
  await expect(page.getByRole('link', { name: /^下書きのカクテル/ })).toHaveText([
    '下書きのカクテル(Draft)',
    '下書きのカクテル(Draft Cocktail)',
  ])
  await expect(page.getByRole('link', { name: 'ジントニック(Gin and Tonic)' })).toHaveCount(0)

  await page.goBack()

  await expect(page).toHaveURL(/\/admin\/cocktails$/)
  await expect(page.getByRole('link', { name: 'ジントニック(Gin and Tonic)' })).toBeVisible()
  await expect(page.getByRole('link', { name: /^下書きのカクテル/ })).toHaveCount(0)
})

test('TC-126-10: 下書きの一覧を名前の一部で絞り込める(部分一致)', async ({ page, baseURL }) => {
  await login(page, baseURL!)
  await page.goto('/admin/cocktails?status=draft')
  await expect(page.getByRole('link', { name: /^下書きのカクテル/ })).toHaveCount(2)

  await page.getByRole('searchbox', { name: '絞り込み' }).fill('Cocktail')

  await expect(page.getByRole('link', { name: /^下書きのカクテル/ })).toHaveText(['下書きのカクテル(Draft Cocktail)'])
})
