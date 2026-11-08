import { expect, test, type Locator, type Page } from '@playwright/test'

// カクテルの詳細(#108)の基本情報と材料。E2eSeeder のジントニック(ID 1)を使う:
// ベースはジン、技法はビルド(用語のビルドに結び付く)、グラスはタンブラー・氷はキューブアイス、甘さ 0・評価 3・度数 12.5、補足あり。
// 材料は 1 にビーフィーター 40度(所属は 蒸留酒 > ジン > … > ビーフィーター)の 45ml、2 に任意のミント(その他 > ミント)の適量。
// E2E のノードは英名に日本語の名前を入れているので、Material の列も日本語になる
const PATH = '/detail/cocktail/1'

/** 表の体の行(列の見出しの行を除く)。見出しの行はセルを持たないので、セルを持つ行だけを取る */
const bodyRows = (table: Locator) => table.getByRole('row').filter({ has: table.page().getByRole('cell') })
const ingredientRows = (page: Page) => bodyRows(page.getByRole('table', { name: '材料' }))

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
  await expect(rows.nth(0).getByRole('cell').nth(0)).toHaveText('ジン / ビーフィーター (ビーフィーター 40度)')
  await expect(rows.nth(0).getByRole('cell').nth(2)).toHaveText('45ml')
  await expect(rows.nth(1).getByRole('cell').nth(0)).toHaveText('ミント(任意)')
  await expect(rows.nth(1).getByRole('cell').nth(2)).toHaveText('適量')

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

// 同じ名前・別名・同じレシピ・材料が似た・同じベースのカクテル(#109)。シーダーのカクテルはジントニックの 1 件だけなので、
// 同じ名前・同じレシピ・材料が似たカクテルは 0 件、同じベース(ジン)のカクテルはジントニック自身の 1 件。別名は「G&T」
test('TC-109-12: シーダーのカクテルを開くと、別名を出し、0 件の同じ名前・同じレシピは出さず、材料が似た・同じベースは見出しを出す', async ({
  page,
}) => {
  await page.goto(PATH)

  await expect(page.getByRole('region', { name: '別名' })).toHaveText('別名: G&T')
  await expect(bodyRows(page.getByRole('table', { name: '材料が似たカクテル' }))).toHaveCount(0)
  await expect(page.getByRole('heading', { level: 2, name: '材料が似たカクテル' })).toBeVisible()
  const sameBase = page.getByRole('table', { name: '同じベースのカクテル' })
  await expect(sameBase.getByRole('link', { name: 'ジントニック' }).first()).toHaveAttribute('href', '/detail/cocktail/1')
  await expect(page.getByText('同じ名前のカクテルがあります！')).toHaveCount(0)
  await expect(page.getByText('同じレシピのカクテルがあります！')).toHaveCount(0)
})

for (const width of [375, 768, 1024, 1440]) {
  test(`TC-109-13: 幅 ${width}px で、別名・材料が似た・同じベースの欄を出しても横スクロールが出ない`, async ({ page }) => {
    await page.setViewportSize({ width, height: 800 })
    await page.goto(PATH)
    await expect(bodyRows(page.getByRole('table', { name: '同じベースのカクテル' }))).toHaveCount(1)
    await expect(page.getByRole('region', { name: '別名' })).toBeVisible()

    const { scrollWidth, clientWidth } = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }))
    expect(scrollWidth).toBeLessThanOrEqual(clientWidth)
  })
}

// 画像(#110)。E2eSeeder のモスコミュール(ID 3)は、画像(1x1 の PNG)と出典の文字「E2E の出典」を持つ
const IMAGE_PATH = '/detail/cocktail/3'

test('TC-110-10: 画像のあるカクテルを開くと、画像を /storage/<ファイル名> から読み込み、その下に出典を出す', async ({ page }) => {
  await page.goto(IMAGE_PATH)

  // 後から読み込まれる同じベースのカクテルの表にも、動画のアイコン(名前「モスコミュールの動画(YouTube)」の img)が出る。
  // 名前の部分一致だと、表が出た後は 2 つに当たって落ちる(#710)ので、画像の欄の中で名前の完全一致で取る
  const image = page.getByRole('figure').getByRole('img', { name: 'モスコミュール', exact: true })
  await expect(image).toHaveAttribute('src', '/storage/e2e-vodka.png')
  await expect.poll(() => image.evaluate((el: HTMLImageElement) => el.complete && el.naturalWidth)).toBeGreaterThan(0)
  await expect(page.getByRole('figure').getByText('E2E の出典')).toBeVisible()
})

// 材料が似たカクテル・同じベースのカクテルの表は後から読み込まれ、出ると左の欄が表の幅まで広がって画像の欄が右へずれる。
// 置き場所は、両方の表に行が出て、並びが落ち着いてから測る
const waitForRelatedTables = async (page: Page) => {
  for (const name of ['材料が似たカクテル', '同じベースのカクテル']) {
    await expect(page.getByRole('table', { name }).getByRole('cell').first()).toBeVisible()
  }
}

// 960px 未満は画像を説明文の上に、960px 以上は説明文の右に置く(旧の cocktail_block の column-reverse)。境目の両側も見る
for (const { width, imageBeside } of [
  { width: 375, imageBeside: false },
  { width: 768, imageBeside: false },
  { width: 959, imageBeside: false },
  { width: 960, imageBeside: true },
  { width: 1024, imageBeside: true },
  { width: 1440, imageBeside: true },
]) {
  test(`TC-110-11: 幅 ${width}px で、画像を${imageBeside ? '説明文の右' : '説明文の上'}に置き、横スクロールが出ない`, async ({ page }) => {
    await page.setViewportSize({ width, height: 800 })
    await page.goto(IMAGE_PATH)
    await waitForRelatedTables(page)

    const image = (await page.getByRole('figure').getByRole('img', { name: 'モスコミュール' }).boundingBox())!
    const description = (await page.getByText('銅のマグで飲む', { exact: false }).boundingBox())!
    if (imageBeside) {
      expect(image.x).toBeGreaterThanOrEqual(description.x + description.width)
    } else {
      expect(image.y + image.height).toBeLessThanOrEqual(description.y)
    }

    // 960px は、左の欄を表の幅まで広げる(旧と同じ。#639 で A に決めた)結果、12px ほど横スクロールが出るのを受け入れる
    if (width !== 960) {
      const { scrollWidth, clientWidth } = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
      }))
      expect(scrollWidth).toBeLessThanOrEqual(clientWidth)
    }
  })
}

test('TC-110-15: 960px 以上で、左の欄の表の右端が、画像の欄の左端より左にある(表が画像の下に潜らない)', async ({ page }) => {
  for (const width of [960, 1024, 1440]) {
    await page.setViewportSize({ width, height: 800 })
    await page.goto(IMAGE_PATH)
    await waitForRelatedTables(page)

    const image = (await page.getByRole('figure').getByRole('img', { name: 'モスコミュール' }).boundingBox())!
    const tables = page.getByRole('table')
    for (const table of await tables.all()) {
      const box = (await table.boundingBox())!
      expect(box.x + box.width, `幅 ${width}px`).toBeLessThanOrEqual(image.x + 1)
    }
  }
})

// 材料ごとの商品の欄(#110)。ジントニックは、材料の銘柄(ビーフィーター 40度)にだけ商品の行があり、ミントには無い。
// E2E では Amazon の設定が無いので、商品の欄はボタン「Amazonで探す」だけになる
test('TC-110-12: ジントニックを開くと、「使われている材料はこちら」の下に、銘柄の商品の欄を 1 つだけ出す', async ({ page }) => {
  await page.goto(PATH)

  const affiliates = page.getByRole('region', { name: '使われている材料' })
  await expect(affiliates.getByRole('heading', { level: 2, name: '使われている材料はこちら' })).toBeVisible()
  await expect(affiliates.getByRole('complementary', { name: '商品' })).toHaveCount(1)
  await expect(affiliates.getByRole('link', { name: 'Amazonで探す' })).toHaveAttribute('href', /amazon\.co\.jp/)
})

test('TC-110-13: 商品の行が 1 つも無いカクテル(モスコミュール)では、「使われている材料はこちら」を出さない', async ({ page }) => {
  const affiliateResponse = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/affiliates')
  await page.goto(IMAGE_PATH)
  expect((await affiliateResponse).status()).toBe(404)

  await expect(page.getByRole('heading', { level: 1 })).toHaveText('モスコミュール(Moscow Mule)')
  await expect(page.getByText('使われている材料はこちら')).toHaveCount(0)
})

for (const width of [375, 768, 1024, 1440]) {
  test(`TC-110-14: 幅 ${width}px で、商品の欄を出しても横スクロールが出ない`, async ({ page }) => {
    await page.setViewportSize({ width, height: 800 })
    await page.goto(PATH)
    await expect(page.getByRole('complementary', { name: '商品' })).toBeVisible()

    const { scrollWidth, clientWidth } = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }))
    expect(scrollWidth).toBeLessThanOrEqual(clientWidth)
  })
}
