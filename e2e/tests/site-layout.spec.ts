import { expect, test, type Page } from '@playwright/test'

// 共通の枠(#296): どのページにもフッターのリンクがあること(更新ボランティア募集へのリンクは #641 で消した)、画面を移るとタイトルが変わり、一番上から表示されることを確かめる
const SITE_TITLE = '宅飲みカクテルデータベース(RC)'

const pages = [
  { path: '/', title: `トップページ ｜ ${SITE_TITLE}` },
  { path: '/index', title: `トップページ ｜ ${SITE_TITLE}` },
  { path: '/result/FromNameResult/%E3%82%B8%E3%83%B3', title: SITE_TITLE },
  { path: '/result/FromNameResultEx/%E3%82%B8%E3%83%B3', title: SITE_TITLE },
  { path: '/result/Cocktails', title: `カクテル検索結果 ｜ ${SITE_TITLE}` },
  { path: '/category/1', title: `蒸留酒(蒸留酒) ｜ ${SITE_TITLE}` },
  { path: '/detail/brand/1', title: SITE_TITLE },
  { path: '/detail/cocktail/1', title: SITE_TITLE },
  { path: '/dictionary', title: `用語辞典 ｜ ${SITE_TITLE}` },
  // E2eSeeder の用語辞典で ID 1 の用語(#56)。詳細はタイトルに用語の名前を出す(TC-56-06)
  { path: '/dictionary/1', title: `タンブラー(Tumbler) ｜ ${SITE_TITLE}` },
  { path: '/nope', title: `ページが見つかりません ｜ ${SITE_TITLE}` },
]

const footer = (page: Page) => page.getByRole('contentinfo')
const header = (page: Page) => page.getByRole('banner')
const siteNameLink = (page: Page) => header(page).getByRole('link', { name: '宅飲みカクテルデータベース' })

for (const { path, title } of pages) {
  test(`TC-296-08: ${path} を開くと、フッターのリンクがあり、タイトルが「${title}」になる`, async ({ page }) => {
    await page.goto(path)

    await expect(footer(page)).toContainText('宅飲みカクテルデータベース ©ろんぐ')
    await expect(footer(page).getByRole('link', { name: 'ろんぐ' })).toHaveAttribute('href', 'https://trc-vlackhistory.net/')
    await expect(footer(page).getByRole('link', { name: '作者の X' })).toHaveAttribute(
      'href',
      'https://twitter.com/virtualDollym',
    )
    await expect(page).toHaveTitle(title)
  })

  test(`TC-641-02: ${path} のフッターに、更新ボランティア募集へのリンクが無い`, async ({ page }) => {
    await page.goto(path)

    await expect(footer(page)).toContainText('宅飲みカクテルデータベース ©ろんぐ')
    await expect(footer(page).getByRole('link')).toHaveCount(2)
    await expect(footer(page)).not.toContainText('ボランティア')
    await expect(page.locator('a[href="/helpme"]')).toHaveCount(0)
  })
}

test('TC-296-09: 画面を移るとタイトルが変わる', async ({ page }) => {
  await page.goto('/nope')
  await expect(page).toHaveTitle(`ページが見つかりません ｜ ${SITE_TITLE}`)

  await page.getByRole('link', { name: 'トップへ戻る' }).click()
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('宅飲みカクテルデータベース')
  await expect(page).toHaveTitle(`トップページ ｜ ${SITE_TITLE}`)

  // ページ名を渡さない名前検索の結果へ、ヘッダーの名前検索で移る(E2eSeeder の「ジン」は 5 件に当たる)
  await header(page).getByRole('searchbox', { name: '名前から検索' }).fill('ジン')
  await header(page).getByRole('button', { name: '5件ヒット' }).click()
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('結果')
  await expect(page).toHaveTitle(SITE_TITLE)
})

/**
 * 中身のまだ無いページは短く、スクロールできない。画面を移っても高さが残るよう、React の外(body の先頭)に高い要素を足し、
 * 下までスクロールする。足さないと、移った先が短いだけで位置が 0 になり、スクロールを戻したかを確かめられない。
 * ヘッダーは画面の上に固定なので、下までスクロールしても押すときに Playwright がスクロールし直さない
 */
const scrollToBottomOfTallPage = async (page: Page) => {
  await page.evaluate(() => {
    const spacer = document.createElement('div')
    spacer.style.height = '3000px'
    document.body.prepend(spacer)
    window.scrollTo(0, document.documentElement.scrollHeight)
  })
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(0)
}

const scrollY = (page: Page) => page.evaluate(() => window.scrollY)

test('TC-296-10: 下までスクロールしてからヘッダーのリンクで移ると、移った先を一番上から出す', async ({ page }) => {
  await page.goto('/nope')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('ページが見つかりません')
  await scrollToBottomOfTallPage(page)

  await siteNameLink(page).click()

  await expect(page.getByRole('heading', { level: 1 })).toHaveText('宅飲みカクテルデータベース')
  expect(await scrollY(page)).toBe(0)
})

test('TC-296-11: ブラウザの「戻る」で戻っても、一番上から出す', async ({ page }) => {
  await page.goto('/nope')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('ページが見つかりません')
  await scrollToBottomOfTallPage(page)
  await siteNameLink(page).click()
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('宅飲みカクテルデータベース')
  await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight))
  await expect.poll(() => scrollY(page)).toBeGreaterThan(0)

  await page.goBack()

  await expect(page.getByRole('heading', { level: 1 })).toHaveText('ページが見つかりません')
  expect(await scrollY(page)).toBe(0)
})

test('TC-296-12: いま開いている URL へのリンクを押しても、スクロールの位置は変えない', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('宅飲みカクテルデータベース')
  await scrollToBottomOfTallPage(page)
  const before = await scrollY(page)

  await siteNameLink(page).click()

  await expect(page).toHaveURL(/\/$/)
  expect(await scrollY(page)).toBe(before)
})
