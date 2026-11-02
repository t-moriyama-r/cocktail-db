import { expect, test, type Page } from '@playwright/test'

// カテゴリの詳細(#105)。E2eSeeder の木(蒸留酒 > ジン > ドライジン > ロンドンドライジン > ビーフィーター、蒸留酒 > ウォッカ)と、
// ジンの有名な銘柄(ビーフィーター 40度)を使う。E2eSeeder では名前と英名が同じ。
// /category/1 は最初に作るノード(根の蒸留酒)。ほかのノードの ID には頼らず、子のノードのリンクを辿って開く
const title = (page: Page) => page.getByRole('heading', { level: 1 })
const breadcrumb = (page: Page) => page.getByRole('navigation', { name: 'パンくず' })
const famous = (page: Page) => page.getByRole('region', { name: '有名な銘柄' })
const children = (page: Page) => page.getByRole('region', { name: '子のノード' })

const openGin = async (page: Page) => {
  await page.goto('/category/1')
  await children(page).getByRole('link', { name: 'ジン(ジン)' }).click()
  await expect(title(page)).toHaveText('ジン(ジン)')
}

test('TC-105-16: 根から子のノードを辿ると、深さごとにパンくずの段数が変わり、葉では子の欄に「登録なし」と出る', async ({
  page,
}) => {
  await page.goto('/category/1')
  await expect(title(page)).toHaveText('蒸留酒(蒸留酒)')
  await expect(breadcrumb(page)).toHaveCount(0)
  await expect(children(page).getByRole('link')).toHaveText(['ジン(ジン)', 'ウォッカ(ウォッカ)'])

  await children(page).getByRole('link', { name: 'ジン(ジン)' }).click()
  await expect(title(page)).toHaveText('ジン(ジン)')
  await expect(breadcrumb(page)).toHaveText('蒸留酒')
  await expect(breadcrumb(page).getByRole('link')).toHaveCount(0)

  await children(page).getByRole('link', { name: 'ドライジン(ドライジン)' }).click()
  await expect(title(page)).toHaveText('ドライジン(ドライジン)')
  await expect(breadcrumb(page)).toHaveText('蒸留酒 / ジン')

  await children(page).getByRole('link', { name: 'ロンドンドライジン(ロンドンドライジン)' }).click()
  await children(page).getByRole('link', { name: 'ビーフィーター(ビーフィーター)' }).click()
  await expect(title(page)).toHaveText('ビーフィーター(ビーフィーター)')
  await expect(page).toHaveTitle('ビーフィーター(ビーフィーター) ｜ 宅飲みカクテルデータベース(RC)')
  await expect(breadcrumb(page)).toHaveText('蒸留酒 / ジン / ドライジン / ロンドンドライジン')
  await expect(children(page)).toContainText('子のノードの登録なし')
  await expect(children(page).getByRole('link')).toHaveCount(0)

  // パンくずのリンクで祖先のノードへ移る
  await breadcrumb(page).getByRole('link', { name: 'ジン', exact: true }).click()
  await expect(title(page)).toHaveText('ジン(ジン)')
})

test('TC-105-17: 有名な銘柄があるノードは欄を出して銘柄の詳細へ移れ、無いノードは欄を出さない', async ({ page }) => {
  await page.goto('/category/1')
  await expect(title(page)).toHaveText('蒸留酒(蒸留酒)')
  await expect(famous(page)).toHaveCount(0)

  await children(page).getByRole('link', { name: 'ジン(ジン)' }).click()
  const brand = famous(page).getByRole('link', { name: 'ビーフィーター 40度(Beefeater 40)' })
  await expect(brand).toHaveAttribute('href', /^\/detail\/brand\/\d+$/)
  await brand.click()
  await expect(page).toHaveURL(/\/detail\/brand\/\d+$/)
})

// 960px 以上では有名な銘柄と子のノードの欄を横に並べ、未満では縦に積む(旧と同じ)
for (const { width, sideBySide } of [
  { width: 959, sideBySide: false },
  { width: 960, sideBySide: true },
]) {
  test(`TC-105-18: ${width}px では、有名な銘柄と子のノードの欄を${sideBySide ? '横に並べる' : '縦に積む'}`, async ({ page }) => {
    await page.setViewportSize({ width, height: 800 })
    await openGin(page)

    const famousBox = (await famous(page).boundingBox())!
    const childrenBox = (await children(page).boundingBox())!
    if (sideBySide) {
      expect(famousBox.x + famousBox.width).toBeLessThanOrEqual(childrenBox.x)
      expect(Math.abs(famousBox.y - childrenBox.y)).toBeLessThan(famousBox.height)
    } else {
      expect(famousBox.y + famousBox.height).toBeLessThanOrEqual(childrenBox.y)
    }
  })
}

// 証跡を撮る幅(docs/design/style.md)で、深いパンくずと有名な銘柄のある詳細に横スクロールが出ない
for (const width of [375, 768, 1024, 1440]) {
  test(`TC-105-19: ${width}px で、ジンと葉のノードの詳細に横スクロールが出ない`, async ({ page }, testInfo) => {
    await page.setViewportSize({ width, height: 800 })
    await openGin(page)
    const noHorizontalScroll = async () => {
      const { scrollWidth, clientWidth } = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
      }))
      expect(scrollWidth).toBeLessThanOrEqual(clientWidth)
    }
    await noHorizontalScroll()

    await children(page).getByRole('link', { name: 'ドライジン(ドライジン)' }).click()
    await children(page).getByRole('link', { name: 'ロンドンドライジン(ロンドンドライジン)' }).click()
    await children(page).getByRole('link', { name: 'ビーフィーター(ビーフィーター)' }).click()
    await expect(breadcrumb(page)).toHaveText('蒸留酒 / ジン / ドライジン / ロンドンドライジン')
    await noHorizontalScroll()

    await testInfo.attach(`category-detail-leaf-${width}px.png`, {
      body: await page.screenshot({ fullPage: true }),
      contentType: 'image/png',
    })
  })
}

test('TC-105-20: 無いノードの ID を開くと 404 の画面を出す', async ({ page }) => {
  await page.goto('/category/999999')
  await expect(title(page)).toHaveText('ページが見つかりません')
})

test('TC-585-04: 画像のあるノードの詳細で、画像を /storage/<ファイル名> から取って読み込める', async ({ page }) => {
  await page.goto('/category/1')
  await children(page).getByRole('link', { name: 'ウォッカ(ウォッカ)' }).click()
  await expect(title(page)).toHaveText('ウォッカ(ウォッカ)')
  const image = page.getByRole('img', { name: 'ウォッカ' })
  await expect(image).toHaveAttribute('src', '/storage/e2e-vodka.png')
  await expect
    .poll(() => image.evaluate((el: HTMLImageElement) => el.complete && el.naturalWidth))
    .toBeGreaterThan(0)
})

// 銘柄の一覧・これを使ったカクテル・商品の欄(#106)。ジンの配下(ビーフィーター)の銘柄「ビーフィーター 40度」と、
// それを材料に使うジントニック(E2eSeeder の seedHeaderData)、ウォッカを材料に使う公開の 7 件(seedTopData)、
// 画像の無いドライジンと画像のあるウォッカの商品の行(seedAffiliates)を使う
const brandList = (page: Page) => page.getByRole('region', { name: '登録されている銘柄の一覧' })
const cocktails = (page: Page) => page.getByRole('region', { name: 'これを使ったカクテル(ランダム5種)' })
const affiliateInImage = (page: Page) => page.getByTestId('affiliate-in-image').getByRole('complementary', { name: '商品' })
const affiliateBottom = (page: Page) => page.getByTestId('affiliate-bottom').getByRole('complementary', { name: '商品' })

test('TC-106-11: ジンの詳細に、配下のノードの銘柄と、配下のノードの銘柄を使うカクテルを出す', async ({ page }) => {
  await openGin(page)

  const brandRows = brandList(page).getByRole('row')
  // 見出しの行と、銘柄 1 行
  await expect(brandRows).toHaveCount(2)
  await expect(brandRows.nth(1).getByRole('cell').nth(0)).toHaveText('ビーフィーター 40度')
  await expect(brandRows.nth(1).getByRole('cell').nth(2)).toHaveText('ジン(ジン) / ビーフィーター(ビーフィーター)')

  // 材料はビーフィーター(ジンの 3 段下)の銘柄で、ジンを起点にしても当たる
  await expect(cocktails(page).getByRole('link', { name: 'ジントニック' })).toBeVisible()
  await expect(cocktails(page).getByRole('button', { name: '再取得' })).toBeVisible()
})

test('TC-106-12: 銘柄の無いウォッカの詳細は、銘柄の一覧を出さず、これを使ったカクテルを 5 件出し、再取得で取り直す', async ({
  page,
}) => {
  await page.goto('/category/1')
  await children(page).getByRole('link', { name: 'ウォッカ(ウォッカ)' }).click()
  await expect(title(page)).toHaveText('ウォッカ(ウォッカ)')

  // 見出しの行と、カクテル 5 行(当たるのは公開の 7 件)
  await expect(cocktails(page).getByRole('row')).toHaveCount(6)
  await expect(brandList(page)).toHaveCount(0)
  await expect(page.getByText('登録されている銘柄の一覧')).toHaveCount(0)

  const regot = page.waitForResponse((response) => response.url().includes('/api/cocktails/random') && response.ok())
  await cocktails(page).getByRole('button', { name: '再取得' }).click()
  await regot
  await expect(cocktails(page).getByRole('row')).toHaveCount(6)
})

// 商品の欄は、画像が無ければ 960px 以上で画像の場所に、960px 未満と画像があるときはいちばん下に出す(どの幅でも 1 つだけ見える)
for (const { width, wide } of [
  { width: 959, wide: false },
  { width: 960, wide: true },
]) {
  test(`TC-106-13: ${width}px では、画像の無いドライジンの商品の欄を${wide ? '画像の場所' : 'いちばん下'}に出し、画像のあるウォッカはいちばん下に出す`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 800 })
    await openGin(page)
    await children(page).getByRole('link', { name: 'ドライジン(ドライジン)' }).click()
    await expect(title(page)).toHaveText('ドライジン(ドライジン)')

    if (wide) {
      await expect(affiliateInImage(page)).toBeVisible()
      await expect(affiliateBottom(page)).toBeHidden()
    } else {
      await expect(affiliateBottom(page)).toBeVisible()
      await expect(affiliateInImage(page)).toBeHidden()
    }
    // 下の欄は、これを使ったカクテルの欄より下にある
    if (!wide) {
      const cocktailsBox = (await cocktails(page).boundingBox())!
      const bottomBox = (await affiliateBottom(page).boundingBox())!
      expect(cocktailsBox.y + cocktailsBox.height).toBeLessThanOrEqual(bottomBox.y)
    }

    await page.goto('/category/1')
    await children(page).getByRole('link', { name: 'ウォッカ(ウォッカ)' }).click()
    await expect(title(page)).toHaveText('ウォッカ(ウォッカ)')
    await expect(affiliateBottom(page)).toBeVisible()
    await expect(page.getByTestId('affiliate-in-image')).toHaveCount(0)
  })
}
