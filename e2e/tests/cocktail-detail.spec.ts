import { expect, test, type Page } from '@playwright/test'

// カクテルの詳細(#108)の基本情報と材料。E2eSeeder のジントニック(ID 1)を使う:
// ベースはジン、技法はビルド(用語なし)、グラスはタンブラー・氷はキューブアイス、甘さ 0・評価 3・度数 12.5、補足あり。
// 材料は 1 にビーフィーター 40度(所属は 蒸留酒 > ジン > … > ビーフィーター)の 45ml、2 に任意のミント(その他 > ミント)の適量。
// E2E のノードは英名に日本語の名前を入れているので、Material の列も日本語になる
const PATH = '/detail/cocktail/1'

const ingredientRows = (page: Page) => page.getByRole('table', { name: '材料' }).locator('tbody tr')

test('TC-108-22: シーダーのカクテルを開くと、見出し・欄・グラス・材料・Note を出す', async ({ page }) => {
  await page.goto(PATH)

  await expect(page.getByRole('heading', { level: 1 })).toHaveText('ジントニック(Gin and Tonic)')
  await expect(page.getByRole('link', { name: 'ジン(ジン)' })).toHaveAttribute('href', '/category/2')
  await expect(page.getByRole('definition').filter({ hasText: 'ビルド' })).toHaveText('ビルド')
  await expect(page.getByRole('definition').filter({ hasText: '★' })).toHaveText('★★★')
  await expect(page.getByRole('definition').filter({ hasText: '%' })).toHaveText('12.5%')
  await expect(page.getByRole('link', { name: 'キューブアイスタンブラー' })).toHaveAttribute('href', '/dictionary/1')

  const rows = ingredientRows(page)
  await expect(rows).toHaveCount(2)
  await expect(rows.nth(0).locator('td').nth(0)).toHaveText('ジン / ビーフィーター (ビーフィーター 40度)')
  await expect(rows.nth(0).locator('td').nth(2)).toHaveText('45ml')
  await expect(rows.nth(1).locator('td').nth(0)).toHaveText('ミント(任意)')
  await expect(rows.nth(1).locator('td').nth(2)).toHaveText('適量')

  await expect(page.getByRole('region', { name: 'Note' })).toContainText('ライムを添える。')
})

test('TC-108-23: 材料の銘柄のリンクで銘柄の詳細へ移る', async ({ page }) => {
  await page.goto(PATH)

  await ingredientRows(page).nth(0).getByRole('link', { name: 'ビーフィーター 40度' }).click()

  await expect(page).toHaveURL(/\/detail\/brand\/1$/)
})

// 幅の区切りごとの出し方(xs 〜599px・sm 600px〜・md 960px〜)。境目の両側(599/600px・959/960px)も見る
const widths = [
  { width: 375, material: false, baseOwnRow: true, divider: true },
  { width: 599, material: false, baseOwnRow: true, divider: true },
  { width: 600, material: true, baseOwnRow: false, divider: true },
  { width: 768, material: true, baseOwnRow: false, divider: true },
  { width: 959, material: true, baseOwnRow: false, divider: true },
  { width: 960, material: true, baseOwnRow: false, divider: false },
  { width: 1024, material: true, baseOwnRow: false, divider: false },
  { width: 1440, material: true, baseOwnRow: false, divider: false },
]

for (const { width, material, baseOwnRow, divider } of widths) {
  test(`TC-108-24: 幅 ${width}px で、Material の列・ベースの行・§Description§ の出し方が旧と同じで、横スクロールが出ない`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 800 })
    await page.goto(PATH)
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('ジントニック(Gin and Tonic)')

    await expect(page.getByRole('columnheader', { name: 'Material' })).toBeVisible({ visible: material })
    await expect(page.getByText('§Description§')).toBeVisible({ visible: divider })

    // ベースだけが 1 行目にあるとき、製法の見出しはベースの見出しより下にある
    const baseTop = (await page.getByRole('term').filter({ hasText: 'ベース' }).boundingBox())!.y
    const methodTop = (await page.getByRole('term').filter({ hasText: '製法' }).boundingBox())!.y
    expect(methodTop > baseTop).toBe(baseOwnRow)

    const { scrollWidth, clientWidth } = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }))
    expect(scrollWidth).toBeLessThanOrEqual(clientWidth)
  })
}
