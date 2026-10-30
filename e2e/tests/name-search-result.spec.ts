import { expect, test, type Page } from '@playwright/test'

// 名前検索の結果の画面(#52)。E2eSeeder のデータを使う。
// 「ジン」: カクテルのジントニック 1 件と、ノードのジン・ドライジン・ロンドンドライジン 3 件(材料名・銘柄では当たらない)
// 「ミント」: 材料名で当たったカクテルのジントニック 1 件と、ノードのミント 1 件
// 「ビーフィーター」: ノードのビーフィーター 1 件と、銘柄のビーフィーター 40度 1 件
const resultPath = (word: string) => `/result/FromNameResult/${encodeURIComponent(word)}`

const regionLabels = (page: Page) =>
  page.getByRole('main').getByRole('region').evaluateAll((regions) => regions.map((r) => r.getAttribute('aria-label')))

test('TC-52-14: /result/FromNameResult/ジン を直に開くと、カクテルとカテゴリーの欄だけが出る', async ({ page }) => {
  await page.goto(resultPath('ジン'))

  await expect(page.getByRole('heading', { level: 1 })).toHaveText('結果')
  const cocktails = page.getByRole('region', { name: 'カクテル検索結果' })
  await expect(cocktails).toContainText('カクテル検索結果 1件ヒットしました。(検索語句：ジン)')
  await expect(cocktails.getByRole('link', { name: 'ジントニック' })).toBeVisible()
  const nodes = page.getByRole('region', { name: 'カテゴリー検索結果' })
  await expect(nodes).toContainText('カテゴリー検索結果 3件ヒットしました。(検索語句：ジン)')
  // ロンドンドライジン(蒸留酒 > ジン > ドライジン > ロンドンドライジン)の親は「ジン / ドライジン」
  await expect(nodes.getByRole('row').filter({ hasText: 'ロンドンドライジン' })).toContainText('ジン / ドライジン')
  expect(await regionLabels(page)).toEqual(['カクテル検索結果', 'カテゴリー検索結果'])
})

test('TC-52-15: /result/FromNameResultEx/ミント を開くと、材料名で当たったカクテルがインテリジェンスサーチの欄に出る', async ({
  page,
}) => {
  await page.goto(`/result/FromNameResultEx/${encodeURIComponent('ミント')}`)

  const intelligence = page.getByRole('region', { name: 'インテリジェンスサーチ結果' })
  await expect(intelligence).toContainText('インテリジェンスサーチ結果 1件ヒットしました。(検索語句：ミント)')
  await expect(intelligence).toContainText('列名をクリックするとソートします。')
  await expect(intelligence.getByRole('link', { name: 'ジントニック' })).toBeVisible()
  expect(await regionLabels(page)).toEqual(['インテリジェンスサーチ結果', 'カテゴリー検索結果'])
})

test('TC-52-16: 銘柄に当たると銘柄の欄が出て、銘柄の詳細へのリンクになる', async ({ page }) => {
  await page.goto(resultPath('ビーフィーター'))

  const brands = page.getByRole('region', { name: '銘柄検索結果' })
  await expect(brands).toContainText('銘柄検索結果 1件ヒットしました。(検索語句：ビーフィーター)')
  await expect(brands.getByRole('link', { name: 'ビーフィーター 40度' })).toHaveAttribute('href', /^\/detail\/brand\/\d+$/)
  expect(await regionLabels(page)).toEqual(['カテゴリー検索結果', '銘柄検索結果'])
})

test('TC-52-17: どれにも当たらない語では、「結果」の見出しだけが出る', async ({ page }) => {
  await page.goto(resultPath('どれにも当たらない語'))

  await expect(page.getByRole('heading', { level: 1 })).toHaveText('結果')
  expect(await regionLabels(page)).toEqual([])
})

// 証跡を撮る幅(docs/design/style.md の「幅の区切り」)で、横スクロールが出ない
for (const width of [375, 768, 1024, 1440]) {
  test(`TC-52-18: 幅 ${width}px で、4 種類の欄のどれを出しても横スクロールが出ない`, async ({ page }) => {
    await page.setViewportSize({ width, height: 720 })
    for (const word of ['ジン', 'ミント', 'ビーフィーター']) {
      await page.goto(resultPath(word))
      await expect(page.getByRole('main').getByRole('region').first()).toBeVisible()

      const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth)
      expect(scrollWidth, `「${word}」の結果`).toBeLessThanOrEqual(width)
    }
  })
}
