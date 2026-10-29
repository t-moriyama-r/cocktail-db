import { expect, test, type Page } from '@playwright/test'

// 共通の枠(#296): どのページにもフッターのリンクがあること、画面を移るとタイトルが変わり、一番上から表示されることを確かめる
const SITE_TITLE = '宅飲みカクテルデータベース(RC)'

const pages = [
  { path: '/', title: `トップページ ｜ ${SITE_TITLE}` },
  { path: '/index', title: `トップページ ｜ ${SITE_TITLE}` },
  { path: '/helpme', title: SITE_TITLE },
  { path: '/result/FromNameResult/%E3%82%B8%E3%83%B3', title: SITE_TITLE },
  { path: '/result/FromNameResultEx/%E3%82%B8%E3%83%B3', title: SITE_TITLE },
  { path: '/result/Cocktails', title: `カクテル検索結果 ｜ ${SITE_TITLE}` },
  { path: '/category/1', title: `蒸留酒(蒸留酒) ｜ ${SITE_TITLE}` },
  { path: '/detail/brand/1', title: SITE_TITLE },
  { path: '/detail/cocktail/1', title: SITE_TITLE },
  { path: '/dictionary', title: `用語辞典 ｜ ${SITE_TITLE}` },
  { path: '/dictionary/1', title: SITE_TITLE },
  { path: '/nope', title: `ページが見つかりません ｜ ${SITE_TITLE}` },
]

const footer = (page: Page) => page.getByRole('contentinfo')

for (const { path, title } of pages) {
  test(`TC-296-08: ${path} を開くと、フッターのリンクがあり、タイトルが「${title}」になる`, async ({ page }) => {
    await page.goto(path)

    await expect(footer(page)).toContainText('宅飲みカクテルデータベース ©ろんぐ')
    await expect(footer(page).getByRole('link', { name: 'ろんぐ' })).toHaveAttribute('href', 'https://trc-vlackhistory.net/')
    await expect(footer(page).getByRole('link', { name: '作者の X' })).toHaveAttribute(
      'href',
      'https://twitter.com/virtualDollym',
    )
    await expect(footer(page).getByRole('link', { name: '更新ボランティア募集中...' })).toHaveAttribute('href', '/helpme')
    await expect(page).toHaveTitle(title)
  })
}

test('TC-296-09: 画面を移るとタイトルが変わる', async ({ page }) => {
  await page.goto('/nope')
  await expect(page).toHaveTitle(`ページが見つかりません ｜ ${SITE_TITLE}`)

  await page.getByRole('link', { name: 'トップへ戻る' }).click()
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Hello World from Laravel')
  await expect(page).toHaveTitle(`トップページ ｜ ${SITE_TITLE}`)

  await footer(page).getByRole('link', { name: '更新ボランティア募集中...' }).click()
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('更新ボランティア募集')
  await expect(page).toHaveTitle(SITE_TITLE)
})

/**
 * 中身のまだ無いページは短く、スクロールできない。画面を移っても高さが残るよう、React の外(body の先頭)に高い要素を足し、
 * 下までスクロールする。足さないと、移った先が短いだけで位置が 0 になり、スクロールを戻したかを確かめられない。
 * 先頭に足すので、下までスクロールするとフッターが画面に入り、押すときに Playwright がスクロールし直さない
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

test('TC-296-10: 下までスクロールしてからフッターのリンクで移ると、移った先を一番上から出す', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Hello World from Laravel')
  await scrollToBottomOfTallPage(page)

  await footer(page).getByRole('link', { name: '更新ボランティア募集中...' }).click()

  await expect(page.getByRole('heading', { level: 1 })).toHaveText('更新ボランティア募集')
  expect(await scrollY(page)).toBe(0)
})

test('TC-296-11: ブラウザの「戻る」で戻っても、一番上から出す', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Hello World from Laravel')
  await scrollToBottomOfTallPage(page)
  await footer(page).getByRole('link', { name: '更新ボランティア募集中...' }).click()
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('更新ボランティア募集')
  await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight))
  await expect.poll(() => scrollY(page)).toBeGreaterThan(0)

  await page.goBack()

  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Hello World from Laravel')
  expect(await scrollY(page)).toBe(0)
})

test('TC-296-12: いま開いている URL へのリンクを押しても、スクロールの位置は変えない', async ({ page }) => {
  await page.goto('/helpme')
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('更新ボランティア募集')
  await scrollToBottomOfTallPage(page)
  const before = await scrollY(page)

  await footer(page).getByRole('link', { name: '更新ボランティア募集中...' }).click()

  await expect(page).toHaveURL(/\/helpme$/)
  expect(await scrollY(page)).toBe(before)
})
