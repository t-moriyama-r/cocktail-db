import { expect, test } from '@playwright/test'

// ビルドした画面を Laravel から配信し、URL を直に開いたとき(リロード・外からのリンク)にどの画面が出るかを確かめる
const pages = [
  { path: '/', heading: '宅飲みカクテルデータベース' },
  { path: '/index', heading: '宅飲みカクテルデータベース' },
  { path: '/result/FromNameResult/%E3%82%B8%E3%83%B3', heading: '結果' },
  { path: '/result/FromNameResultEx/%E3%82%B8%E3%83%B3', heading: '結果' },
  { path: '/result/Cocktails', heading: '結果' },
  { path: '/category/1', heading: '蒸留酒(蒸留酒)' },
  // E2eSeeder のビーフィーター 40度(#54 で中身を出すようにした)
  { path: '/detail/brand/1', heading: 'ビーフィーター 40度(Beefeater 40)' },
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

// 更新ボランティア募集の画面(旧 /helpme)は #641 で消した。URL を直に開くと 404 の画面が出て、募集の文は出ない
test('TC-641-01: /helpme を開くと 404 の画面が出る', async ({ page }) => {
  await page.goto('/helpme')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('ページが見つかりません')
  await expect(page.getByRole('main')).not.toContainText('ボランティア')
})

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

// 旧のカテゴリ・ミドルの詳細の URL(#78): サーバーが legacy_id_maps で引いた新しいノードの詳細へ 301 で移す。
// E2eSeeder の対応は、旧のカテゴリ 9001 → ジン、旧のミドル 9101 → ドライジン。ノードの ID には頼らず、名前で確かめる
for (const { path, heading } of [
  { path: '/detail/category/9001', heading: 'ジン(ジン)' },
  { path: '/detail/middle/9101', heading: 'ドライジン(ドライジン)' },
]) {
  test(`TC-78-10: 旧の ${path} を開くと、/category/<新しい ID> の「${heading}」の画面に移る`, async ({ page, request }) => {
    const response = await request.get(path, { maxRedirects: 0 })
    expect(response.status()).toBe(301)

    await page.goto(path)
    await expect(page).toHaveURL(/\/category\/[0-9]+$/)
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(heading)
  })
}

test('TC-78-11: 対応表に無い旧のカテゴリの URL は、移らずに 404 の状態で 404 の画面が出る', async ({ page }) => {
  const response = await page.goto('/detail/category/999999')
  expect(response?.status()).toBe(404)
  await expect(page).toHaveURL(/\/detail\/category\/999999$/)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('ページが見つかりません')
})

// 旧の SNS カードの URL(#113): サーバーが同じものの詳細ページへ 301 で移す。カテゴリ・ミドルは #78 と同じ付け替え。
// E2eSeeder のジントニック(カクテル 1)・ビーフィーター 40度(銘柄 1)・旧のミドル 9101 → ドライジン を使う
for (const { path, url, heading } of [
  { path: '/card/cocktail/1', url: /\/detail\/cocktail\/1$/, heading: 'ジントニック(Gin and Tonic)' },
  { path: '/card/Brand/1', url: /\/detail\/brand\/1$/, heading: 'ビーフィーター 40度(Beefeater 40)' },
  { path: '/card/middle/9101', url: /\/category\/[0-9]+$/, heading: 'ドライジン(ドライジン)' },
]) {
  test(`TC-113-13: 旧の ${path} を開くと、詳細の「${heading}」の画面に移る`, async ({ page, request }) => {
    const response = await request.get(path, { maxRedirects: 0 })
    expect(response.status()).toBe(301)

    await page.goto(path)
    await expect(page).toHaveURL(url)
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(heading)
  })
}

test('TC-113-14: 下書きのカクテルの旧 SNS カードの URL は、移らずに 404 の状態で 404 の画面が出る', async ({ page }) => {
  const response = await page.goto('/card/cocktail/2')
  expect(response?.status()).toBe(404)
  await expect(page).toHaveURL(/\/card\/cocktail\/2$/)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('ページが見つかりません')
})

// SNS のクローラーは 301 をたどって、移った先の HTML(JS を動かす前)の OGP を読む
test('TC-113-15: 旧の SNS カードの URL をたどると、移った先の HTML に OGP が入っている', async ({ request }) => {
  const response = await request.get('/card/Cocktail/1')
  expect(response.status()).toBe(200)
  expect(new URL(response.url()).pathname).toBe('/detail/cocktail/1')
  const html = await response.text()
  expect(html).toContain('<meta property="og:title" content="ジントニック | 宅飲みカクテルデータベース">')
  expect(html).toMatch(/<meta property="og:url" content="[^"]*\/detail\/cocktail\/1">/)
})
