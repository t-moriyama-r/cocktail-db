import { expect, test } from '@playwright/test'

// ビルドした画面を Laravel から配信し、URL を直に開いたとき(リロード・外からのリンク)にどの画面が出るかを確かめる
const pages = [
  { path: '/', heading: 'Hello World from Laravel' },
  { path: '/index', heading: 'Hello World from Laravel' },
  { path: '/helpme', heading: '更新ボランティア募集' },
  { path: '/result/FromNameResult/%E3%82%B8%E3%83%B3', heading: '名前検索の結果' },
  { path: '/result/FromNameResultEx/%E3%82%B8%E3%83%B3', heading: '名前検索の結果' },
  { path: '/result/Cocktails', heading: '条件検索の結果' },
  { path: '/category/1', heading: 'カテゴリの詳細' },
  { path: '/detail/brand/1', heading: '銘柄の詳細' },
  { path: '/detail/cocktail/1', heading: 'カクテルの詳細' },
  { path: '/dictionary', heading: '用語辞典' },
  { path: '/dictionary/1', heading: '用語の詳細' },
]

for (const { path, heading } of pages) {
  test(`TC-97-21: ${path} を開くと「${heading}」の画面が出る`, async ({ page }) => {
    await page.goto(path)
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(heading)
  })
}

for (const path of ['/nope', '/detail/cocktail/abc']) {
  test(`TC-97-22: ${path} を開くと 404 の画面が出る`, async ({ page }) => {
    await page.goto(path)
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('ページが見つかりません')
  })
}

test('TC-97-23: 404 の画面の「トップへ戻る」でトップの画面に移る', async ({ page }) => {
  await page.goto('/nope')
  await page.getByRole('link', { name: 'トップへ戻る' }).click()
  await expect(page).toHaveURL(/\/$/)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Hello World from Laravel')
})

// 詳細ページ(#111): 無い ID・下書きは、サーバーが SPA の HTML を 404 の状態で返し、画面は 404 の画面を出す。
// 下書きのカクテル(ID 2)は E2eSeeder が入れる
for (const path of [
  '/detail/cocktail/999999',
  '/detail/cocktail/2',
  '/category/999999',
  '/detail/brand/999999',
  '/dictionary/999999',
]) {
  test(`TC-111-19: ${path} は 404 の状態で返り、404 の画面が出る`, async ({ page }) => {
    const response = await page.goto(path)
    expect(response?.status()).toBe(404)
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('ページが見つかりません')
  })
}

test('TC-111-20: 詳細ページの HTML(JS を動かす前)に、名前の title・description・canonical が入る', async ({ request }) => {
  const response = await request.get('/detail/cocktail/1')
  expect(response.status()).toBe(200)
  const html = await response.text()
  expect(html).toContain('<title>ジン・トニック(Gin and Tonic) ｜ 宅飲みカクテルデータベース(RC)</title>')
  expect(html).toContain('<meta name="description" content="ジンをトニックウォーターで割った、すっきりした一杯。">')
  expect(html).toMatch(/<link rel="canonical" href="[^"]*\/detail\/cocktail\/1">/)
})
