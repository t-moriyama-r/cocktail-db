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
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Hello World from Laravel')
})
