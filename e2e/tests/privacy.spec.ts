import { expect, test } from '@playwright/test'

// プライバシーポリシー(/privacy)とアクセス解析(GA4)のタグ(#59)。決めたことは docs/decisions/analytics.md。
// E2eSeeder は GA4 の測定 ID を入れない(CI の .env にも無い)ので、タグが無いときの画面を確かめる

test('TC-59-15: フッターのリンクからプライバシーポリシーを開け、GA4 を使っていることが書いてある', async ({ page }) => {
  await page.goto('/dictionary')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('用語辞典')

  await page.getByRole('contentinfo').getByRole('link', { name: 'プライバシーポリシー' }).click()

  await expect(page).toHaveURL(/\/privacy$/)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('プライバシーポリシー')
  await expect(page.getByRole('main')).toContainText('「Google アナリティクス(GA4)」を使っています')
  await expect(page).toHaveTitle('プライバシーポリシー ｜ 宅飲みカクテルデータベース(RC)')
})

test('TC-59-16: 測定 ID が未設定なら、GA4 のタグ(gtag.js)を読み込まず、画面を移っても Google に送らない', async ({ page }) => {
  const googleRequests: string[] = []
  page.on('request', (request) => {
    const host = new URL(request.url()).hostname
    if (/(^|\.)(googletagmanager|google-analytics)\.com$/.test(host)) googleRequests.push(request.url())
  })

  await page.goto('/privacy')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('プライバシーポリシー')
  await page.getByRole('banner').getByRole('link', { name: '宅飲みカクテルデータベース' }).click()
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('宅飲みカクテルデータベース')

  await expect(page.locator('script[src*="googletagmanager.com"]')).toHaveCount(0)
  expect(await page.evaluate(() => typeof (window as { gtag?: unknown }).gtag)).toBe('undefined')
  expect(googleRequests).toEqual([])
})
