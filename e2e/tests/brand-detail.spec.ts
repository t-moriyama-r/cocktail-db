import { expect, test, type Page } from '@playwright/test'

// 銘柄の詳細(#54)。E2eSeeder の木(蒸留酒 > ジン > ドライジン > ロンドンドライジン > ビーフィーター)と、銘柄:
// - ビーフィーター 40度(ビーフィーター。深さ 5。ヘッダーのジントニックの材料)
// - スピリタス(根の蒸留酒。深さ 1)
// - タンカレー(画像あり)・ゴードン(画像なし)(ドライジン。深さ 3。どちらも商品の行を持つ)
// 銘柄の ID には頼らず、名前検索の結果の銘柄のリンクから開く
const title = (page: Page) => page.getByRole('heading', { level: 1 })
const breadcrumb = (page: Page) => page.getByRole('navigation', { name: 'パンくず' })
const listHeadings = (page: Page) => page.getByRole('main').getByRole('heading', { level: 2 })
const inImageAffiliate = (page: Page) => page.getByTestId('affiliate-in-image').getByRole('complementary', { name: '商品' })
const bottomAffiliate = (page: Page) => page.getByTestId('affiliate-bottom').getByRole('complementary', { name: '商品' })

const openBrand = async (page: Page, name: string, heading: string) => {
  // 名前検索は空白で語を分けるので、先頭の語で検索する
  await page.goto(`/result/FromNameResult/${encodeURIComponent(name.split(' ')[0])}`)
  await page.getByRole('region', { name: '銘柄検索結果' }).getByRole('link', { name, exact: true }).click()
  await expect(title(page)).toHaveText(heading)
}

test('TC-54-18: 所属ノードの深さ 1・3・5 の銘柄で、パンくずの段数が合い、根の下の段はノードの詳細へ移れる', async ({ page }) => {
  await openBrand(page, 'スピリタス', 'スピリタス(Spirytus)')
  await expect(breadcrumb(page)).toHaveText('蒸留酒')
  await expect(breadcrumb(page).getByRole('link')).toHaveCount(0)

  await openBrand(page, 'タンカレー', 'タンカレー(Tanqueray)')
  await expect(page).toHaveTitle('タンカレー(Tanqueray) ｜ 宅飲みカクテルデータベース(RC)')
  await expect(breadcrumb(page)).toHaveText('蒸留酒 / ジン / ドライジン')
  await expect(breadcrumb(page).getByRole('link')).toHaveText(['ジン', 'ドライジン'])

  await openBrand(page, 'ビーフィーター 40度', 'ビーフィーター 40度(Beefeater 40)')
  await expect(breadcrumb(page)).toHaveText('蒸留酒 / ジン / ドライジン / ロンドンドライジン / ビーフィーター')

  await breadcrumb(page).getByRole('link', { name: 'ドライジン', exact: true }).click()
  await expect(title(page)).toHaveText('ドライジン(ドライジン)')
})

test('TC-54-19: Data・説明文・これを使ったカクテルを出す', async ({ page }) => {
  await openBrand(page, 'ビーフィーター 40度', 'ビーフィーター 40度(Beefeater 40)')
  await expect(page.getByRole('region', { name: 'Data' })).toContainText(/Strength:\d+(\.\d+)?%/)
  // ジントニックの材料がビーフィーター 40度
  const using = page.getByRole('region', { name: 'これを使ったカクテル(ランダム5種)' })
  await expect(using.getByRole('link', { name: 'ジントニック', exact: true })).toBeVisible()

  await openBrand(page, 'タンカレー', 'タンカレー(Tanqueray)')
  await expect(page.getByRole('region', { name: 'Data' })).toHaveText('DataStrength:47.3%')
  await expect(page.getByText('ロンドンの辛口。')).toBeVisible()
})

test('TC-54-20: 同じノードにほかの銘柄があれば同じノードの一覧を出し、無ければ出さない', async ({ page }) => {
  await openBrand(page, 'タンカレー', 'タンカレー(Tanqueray)')
  await expect(listHeadings(page)).toHaveText(['これを使ったカクテル(ランダム5種)', 'ドライジンの銘柄の一覧', 'ジン銘柄の一覧'])
  const sameNode = page.getByRole('region', { name: 'ドライジンの銘柄の一覧' })
  await expect(sameNode.getByRole('link', { name: 'ゴードン', exact: true })).toBeVisible()
  await expect(page.getByRole('region', { name: 'ジン銘柄の一覧' }).getByRole('link', { name: 'ビーフィーター 40度', exact: true })).toBeVisible()

  // 一覧のリンクで、同じノードのほかの銘柄の詳細へ移る
  await sameNode.getByRole('link', { name: 'ゴードン', exact: true }).click()
  await expect(title(page)).toHaveText("ゴードン(Gordon's)")
  await expect(page.getByRole('region', { name: 'ドライジンの銘柄の一覧' }).getByRole('link', { name: 'タンカレー', exact: true })).toBeVisible()

  // ビーフィーターにほかの銘柄は無く、ロンドンドライジンの配下にも別のノードの銘柄は無い
  await openBrand(page, 'ビーフィーター 40度', 'ビーフィーター 40度(Beefeater 40)')
  await expect(listHeadings(page)).toHaveText(['これを使ったカクテル(ランダム5種)'])

  // 根の銘柄は、同じノードにほかの銘柄が無く、親も無い
  await openBrand(page, 'スピリタス', 'スピリタス(Spirytus)')
  await expect(listHeadings(page)).toHaveText(['これを使ったカクテル(ランダム5種)'])
})

// 画像の有無と幅の境目(959/960px)で、商品の欄の場所が変わり、いつも 1 つだけ見える(旧と同じ)
for (const { width, wide } of [
  { width: 959, wide: false },
  { width: 960, wide: true },
]) {
  test(`TC-54-21: 幅 ${width}px で、画像の有無によって商品の欄の場所が変わり、1 つだけ見える`, async ({ page }) => {
    await page.setViewportSize({ width, height: 800 })

    // 画像あり: 幅によらず下に出す
    await openBrand(page, 'タンカレー', 'タンカレー(Tanqueray)')
    await expect(page.getByRole('img', { name: 'タンカレー', exact: true })).toBeVisible()
    await expect(bottomAffiliate(page)).toBeVisible()
    await expect(inImageAffiliate(page)).toHaveCount(0)

    // 画像なし: 960px 以上は画像の場所、未満は下に出す
    await openBrand(page, 'ゴードン', "ゴードン(Gordon's)")
    await expect(inImageAffiliate(page)).toBeVisible({ visible: wide })
    await expect(bottomAffiliate(page)).toBeVisible({ visible: !wide })
    await expect(page.getByRole('complementary', { name: '商品' }).filter({ visible: true })).toHaveCount(1)
  })
}

test('TC-54-22: 無い銘柄の ID を開くと 404 の画面を出す', async ({ page }) => {
  await page.goto('/detail/brand/999999')
  await expect(title(page)).toHaveText('ページが見つかりません')
})

// 証跡を撮る幅(docs/design/style.md)で、一覧と画像のある詳細・商品の欄のある詳細に横スクロールが出ない
for (const width of [375, 768, 1024, 1440]) {
  test(`TC-54-23: 幅 ${width}px で、銘柄の詳細に横スクロールが出ない`, async ({ page }) => {
    await page.setViewportSize({ width, height: 800 })
    for (const [name, heading] of [
      ['タンカレー', 'タンカレー(Tanqueray)'],
      ['ゴードン', "ゴードン(Gordon's)"],
    ]) {
      await openBrand(page, name, heading)
      await expect(page.getByRole('complementary', { name: '商品' }).filter({ visible: true })).toHaveCount(1)
      const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth)
      expect(scrollWidth, name).toBeLessThanOrEqual(width)
    }
  })
}
