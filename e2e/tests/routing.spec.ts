import { expect, test } from '@playwright/test'

// ビルドした画面を Laravel から配信し、URL を直に開いたとき(リロード・外からのリンク)にどの画面が出るかを確かめる
const pages = [
  { path: '/', heading: '宅飲みカクテルデータベース' },
  { path: '/index', heading: '宅飲みカクテルデータベース' },
  { path: '/helpme', heading: '当DBの更新ボランティア募集について' },
  { path: '/result/FromNameResult/%E3%82%B8%E3%83%B3', heading: '結果' },
  { path: '/result/FromNameResultEx/%E3%82%B8%E3%83%B3', heading: '結果' },
  { path: '/result/Cocktails', heading: '結果' },
  { path: '/category/1', heading: '蒸留酒(蒸留酒)' },
  { path: '/detail/brand/1', heading: '銘柄の詳細' },
  // E2eSeeder のジントニック(#108 で中身を出すようにした)
  { path: '/detail/cocktail/1', heading: 'ジントニック(Gin and Tonic)' },
  { path: '/dictionary', heading: '用語辞典' },
  // E2eSeeder の用語辞典で ID 1 の用語(#56)
  { path: '/dictionary/1', heading: 'タンブラー(Tumbler)' },
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
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('宅飲みカクテルデータベース')
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
  test(`TC-111-19: ${path} は 404 の状態で返る(404 の画面は詳細の画面の issue で出す)`, async ({ page }) => {
    const response = await page.goto(path)
    expect(response?.status()).toBe(404)
  })
}

test('TC-111-20: 詳細ページの HTML(JS を動かす前)に、名前の title・description・canonical が入る', async ({ request }) => {
  const response = await request.get('/detail/cocktail/1')
  expect(response.status()).toBe(200)
  const html = await response.text()
  expect(html).toContain('<title>ジントニック(Gin and Tonic) ｜ 宅飲みカクテルデータベース(RC)</title>')
  expect(html).toContain('<meta name="description" content="定番のロングカクテル。">')
  expect(html).toMatch(/<link rel="canonical" href="[^"]*\/detail\/cocktail\/1">/)
})

// OGP・Twitter カード(#112): SNS のクローラーは JS を動かさないので、サーバーが返す HTML に入っているかを見る。
// E2eSeeder のジントニック(ID 1)は画像が無いので、既定の画像を指す
test('TC-112-14: 詳細ページの HTML(JS を動かす前)に OGP・Twitter カードが入り、既定の画像を取れる', async ({ request }) => {
  const response = await request.get('/detail/cocktail/1')
  expect(response.status()).toBe(200)
  const html = await response.text()
  expect(html).toContain('<meta property="og:title" content="ジントニック | 宅飲みカクテルデータベース">')
  expect(html).toContain('<meta property="og:description" content="定番のロングカクテル。">')
  expect(html).toMatch(/<meta property="og:url" content="[^"]*\/detail\/cocktail\/1">/)
  expect(html).toContain('<meta name="twitter:card" content="summary">')
  const image = html.match(/<meta property="og:image" content="([^"]*)">/)?.[1]
  expect(image).toMatch(/\/og-default\.png$/)

  const imageResponse = await request.get(new URL(image ?? '').pathname)
  expect(imageResponse.status()).toBe(200)
  expect(imageResponse.headers()['content-type']).toBe('image/png')
})
