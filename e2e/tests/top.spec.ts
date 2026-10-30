import { expect, test, type Locator, type Page } from '@playwright/test'

// トップ(#50)。E2eSeeder の seedTopData のデータを使う。
// 公開で評価 3 以上が 7 件(うち「モスコミュール」が新着動画)、ほかに公開のジントニック(評価なし)と、
// 更新・動画の公開がいちばん新しい評価 5 の下書き「下書きのカクテル」がある
const DRAFT = '下書きのカクテル'
const LISTS = ['★最近更新されたカクテル', '★おすすめカクテル', '★気まぐれセレクト']

const list = (page: Page, title: string) => page.getByRole('region', { name: title })
/** 表の体の行(列の見出しの行を除く) */
const bodyRows = (region: Locator) => region.getByRole('table').locator('tbody > tr')

test('TC-50-13: トップを開くと、新着動画・メニュー・3 つの一覧がどれも出て、一覧はそれぞれ 7 件で下書きを出さない', async ({
  page,
}) => {
  await page.goto('/')

  await expect(page.getByRole('heading', { level: 1 })).toHaveText('宅飲みカクテルデータベース')
  const video = page.getByRole('region', { name: '新作動画' })
  await expect(video.getByRole('heading', { level: 2 })).toHaveText('新作動画：モスコミュール(Moscow Mule)')
  await expect(video.getByTitle('YouTube の動画')).toHaveAttribute('src', 'https://www.youtube.com/embed/x9gaP5Q8lc4')
  await expect(video.getByRole('link', { name: 'ウォッカ(ウォッカ)' })).toHaveAttribute('href', /^\/category\/\d+$/)
  await expect(video).toContainText('銅のマグで飲む、ウォッカのカクテル')
  await expect(page.getByRole('region', { name: 'Menu' }).getByRole('link', { name: 'カクテル検索' })).toBeVisible()
  await expect(page.getByRole('region', { name: 'What is this!?' })).toContainText('個人的に作ったカクテルのデータベースです。')

  for (const title of LISTS) {
    const region = list(page, title)
    await expect(bodyRows(region)).toHaveCount(7)
    await expect(region.getByRole('link', { name: DRAFT })).toHaveCount(0)
  }
  // 最近更新された順の先頭は、更新がいちばん新しい下書きではなく、公開のカクテル
  await expect(bodyRows(list(page, LISTS[0])).first()).not.toContainText(DRAFT)
})

test('TC-50-14: 「再取得」を押すと、その一覧の API を呼び直し、7 件の一覧を出し直す', async ({ page }) => {
  await page.goto('/')
  for (const [title, sort] of [
    ['★おすすめカクテル', 'recommend'],
    ['★気まぐれセレクト', 'random'],
  ] as const) {
    const region = list(page, title)
    await expect(bodyRows(region)).toHaveCount(7)

    const refetched = page.waitForResponse(
      (response) => response.url().includes(`/api/cocktails?sort=${sort}&limit=7`) && response.status() === 200,
    )
    await region.getByRole('button', { name: '再取得' }).click()
    await refetched

    await expect(bodyRows(region)).toHaveCount(7)
    await expect(region.getByRole('link', { name: DRAFT })).toHaveCount(0)
  }
})

test('TC-50-15: 動画のあるカクテルが無い(新着動画の API が 404)ときは、新着動画の欄を出さず、メニューと一覧は出す', async ({
  page,
}) => {
  // 動画のある公開のカクテルを消せないので、ブラウザの通信の層で 404 を返す(BE の 404 と同じ本文)
  await page.route('**/api/cocktails/latest-video', (route) =>
    route.fulfill({ status: 404, json: { message: 'Not Found' } }),
  )

  await page.goto('/')

  await expect(bodyRows(list(page, LISTS[0]))).toHaveCount(7)
  await expect(page.getByRole('region', { name: 'Menu' })).toBeVisible()
  await expect(page.getByRole('region', { name: 'What is this!?' })).toBeVisible()
  await expect(page.getByRole('region', { name: '新作動画' })).toHaveCount(0)
  await expect(page.getByRole('alert')).toHaveCount(0)
})

test('TC-50-16: メニューの「カクテル検索」を押すと、同じ画面の条件検索のフォームの置き場所へ移る', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 667 })
  await page.goto('/')
  await expect(bodyRows(list(page, LISTS[2]))).toHaveCount(7)

  await page.getByRole('region', { name: 'Menu' }).getByRole('link', { name: 'カクテル検索' }).click()

  await expect(page).toHaveURL(/\/#cocktailform_anchor$/)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('宅飲みカクテルデータベース')
  await expect(page.getByRole('region', { name: 'カクテル検索' })).toBeInViewport()
})

// 幅で出し分けるもの(docs/design/style.md の「幅の区切り」)。区切りの境目の両側と、証跡を撮る幅で確かめる
const widths = [
  { width: 375, subtitle: false },
  { width: 599, subtitle: false },
  { width: 600, subtitle: true },
  { width: 768, subtitle: true },
  { width: 959, subtitle: true },
  { width: 960, subtitle: true },
  { width: 1024, subtitle: true },
  { width: 1263, subtitle: true },
  { width: 1264, subtitle: true },
  { width: 1440, subtitle: true },
]

for (const { width, subtitle } of widths) {
  test(`TC-50-17: 幅 ${width}px で、副題を${subtitle ? '出し' : '出さず'}、横スクロールが出ない`, async ({ page }) => {
    await page.setViewportSize({ width, height: 720 })
    await page.goto('/')
    await expect(page.getByRole('region', { name: '新作動画' })).toBeVisible()
    for (const title of LISTS) {
      await expect(bodyRows(list(page, title))).toHaveCount(7)
    }

    await expect(page.getByText('―宅飲み指向データベース。新たな出会いをあなたに。')).toBeVisible({ visible: subtitle })
    const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth)
    expect(scrollWidth).toBeLessThanOrEqual(width)
  })
}
