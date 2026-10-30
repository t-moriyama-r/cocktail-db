import { expect, test, type Page } from '@playwright/test'

// ヘッダー(#99)。E2eSeeder のデータ(ノードのジン・ドライジン・ロンドンドライジン、カクテルのジントニック、銘柄 1 件)を使う。
// 名前検索「ジン」は 5 件に当たり(ノード 3 件・カクテル 1 件と、材料にジンの配下の銘柄を使うジントニックの 1 件)、
// 登録数(公開のカクテルの数)は 1
const header = (page: Page) => page.getByRole('banner')
const nameInput = (page: Page) => header(page).getByRole('searchbox', { name: '名前から検索' })

test('TC-99-23: 名前に「ジン」と入れると件数が出て、ボタンで名前検索の結果へ移る', async ({ page }) => {
  await page.goto('/helpme')

  await expect(header(page).getByRole('button', { name: '入力して下さい' })).toBeDisabled()
  await nameInput(page).fill('ジン')
  const button = header(page).getByRole('button', { name: '5件ヒット' })
  await expect(button).toBeEnabled()

  await button.click()

  await expect(page).toHaveURL(`/result/FromNameResult/${encodeURIComponent('ジン')}`)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('結果')
})

test('TC-99-24: 件数が出たあと Enter でも移る。禁止文字では「Error」になり、押すと入力が空になる', async ({ page }) => {
  await page.goto('/helpme')

  await nameInput(page).fill('ジン?')
  await header(page).getByRole('button', { name: 'Error' }).click()
  await expect(nameInput(page)).toHaveValue('')

  await nameInput(page).fill('ジン')
  await expect(header(page).getByRole('button', { name: '5件ヒット' })).toBeEnabled()
  await nameInput(page).press('Enter')

  await expect(page).toHaveURL(`/result/FromNameResult/${encodeURIComponent('ジン')}`)
})

test('TC-99-25: 「検索をはじめる」でモーダルが開き、Close で閉じる', async ({ page }) => {
  await page.goto('/helpme')

  await header(page).getByRole('button', { name: '検索をはじめる' }).click()
  const dialog = page.getByRole('dialog', { name: 'カクテル検索' })
  await expect(dialog).toBeVisible()

  await dialog.getByRole('button', { name: 'Close' }).click()
  await expect(dialog).toBeHidden()
})

test('TC-99-26: 「現在の登録数：1」のリンクで、ランダムウォークの行き先(詳細のどれか)へ移る', async ({ page }) => {
  await page.goto('/helpme')

  const link = header(page).getByRole('link', { name: '現在の登録数：1' })
  await expect(link).toHaveAttribute('href', /^\/(detail\/cocktail|category|detail\/brand)\/\d+$/)
  const href = await link.getAttribute('href')

  await link.click()

  await expect(page).toHaveURL(href!)
})

// 幅で出し分けるもの(docs/design/style.md の「幅の区切り」)。区切りの境目の両側(599/600px・959/960px)で確かめる
const widths = [
  { width: 375, siteName: false, search: false, startRest: false, count: false },
  { width: 599, siteName: false, search: false, startRest: false, count: false },
  { width: 600, siteName: false, search: true, startRest: false, count: true },
  { width: 959, siteName: false, search: true, startRest: false, count: true },
  { width: 960, siteName: true, search: true, startRest: true, count: true },
  { width: 1440, siteName: true, search: true, startRest: true, count: true },
]

for (const { width, siteName, search, startRest, count } of widths) {
  test(`TC-99-27: 幅 ${width}px で、サイト名の文字・「検索」・「をはじめる」・登録数を出し分け、横スクロールが出ない`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 720 })
    await page.goto('/helpme')
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('当DBの更新ボランティア募集について')
    // 登録数は API の応答の後に出るので、出る幅では出るまで待つ
    if (count) {
      await expect(header(page).getByText('現在の登録数：1')).toBeVisible()
    }

    const visible = (locator: ReturnType<Page['getByText']>) => locator.isVisible()
    expect(await visible(header(page).getByText('宅飲みカクテルデータベース', { exact: true }))).toBe(siteName)
    expect(await visible(header(page).getByText('検索', { exact: true }))).toBe(search)
    expect(await visible(header(page).getByText('をはじめる', { exact: true }))).toBe(startRest)
    expect(await visible(header(page).getByText('現在の登録数：1'))).toBe(count)
    // 空のときのボタンは、600px 未満で「-」、以上で「入力して下さい」
    expect(await visible(header(page).getByText('-', { exact: true }))).toBe(!search)
    expect(await visible(header(page).getByText('入力して下さい', { exact: true }))).toBe(search)

    const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth)
    expect(scrollWidth).toBeLessThanOrEqual(width)
  })
}
