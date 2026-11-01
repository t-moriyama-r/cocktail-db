import { expect, test, type Page } from '@playwright/test'

// 条件検索の条件と URL(#102)。E2eSeeder の木(蒸留酒 > ジン > … > ビーフィーター、蒸留酒 > ウォッカ)と、
// 材料にジンの配下の銘柄(ビーフィーター 40 度)を使うジントニックを使う。ノードの ID は決め打ちせず、URL の形で確かめる
const header = (page: Page) => page.getByRole('banner')
const results = (page: Page) => page.getByRole('region', { name: 'カクテル検索結果' })

const openModal = async (page: Page) => {
  await header(page).getByRole('button', { name: '検索をはじめる' }).click()
  const dialog = page.getByRole('dialog', { name: 'カクテル検索' })
  await expect(dialog.getByRole('heading', { name: 'カクテル検索' })).toBeVisible()
  return dialog
}

test('TC-102-20: モーダルで検索すると条件が URL に入り、読み直しても同じ条件と結果、戻るボタンで前の条件と結果に戻る', async ({
  page,
}) => {
  await page.goto('/helpme')

  // 1 回目: ジンを OR 検索
  let dialog = await openModal(page)
  await dialog.getByRole('checkbox', { name: 'ジン', exact: true }).check()
  await dialog.getByRole('button', { name: '検索' }).click()

  await expect(page).toHaveURL(/\/result\/Cocktails\?n=\d+$/)
  const ginUrl = page.url()
  await expect(results(page).getByRole('link', { name: 'ジントニック' })).toBeVisible()

  // 読み直しても、URL の条件で同じ結果を出し、モーダルにも条件が戻る
  await page.reload()
  await expect(results(page).getByRole('link', { name: 'ジントニック' })).toBeVisible()
  dialog = await openModal(page)
  await expect(dialog.getByRole('checkbox', { name: 'ジン', exact: true })).toBeChecked()

  // 2 回目: ウォッカに替えて AND 検索
  await dialog.getByRole('checkbox', { name: 'ジン', exact: true }).uncheck()
  await dialog.getByRole('checkbox', { name: 'ウォッカ' }).check()
  await dialog.getByRole('radio', { name: 'AND検索' }).check()
  await dialog.getByRole('button', { name: '検索' }).click()

  await expect(page).toHaveURL(/\/result\/Cocktails\?n=\d+&mode=and$/)
  expect(page.url()).not.toBe(ginUrl)
  await expect(results(page)).toBeVisible()

  // 戻るボタンで、1 回目の条件と結果に戻る
  await page.goBack()
  await expect(page).toHaveURL(ginUrl)
  await expect(results(page).getByRole('link', { name: 'ジントニック' })).toBeVisible()
  dialog = await openModal(page)
  await expect(dialog.getByRole('radio', { name: 'OR検索' })).toBeChecked()
  await expect(dialog.getByRole('checkbox', { name: 'ジン', exact: true })).toBeChecked()
  await expect(dialog.getByRole('checkbox', { name: 'ウォッカ' })).not.toBeChecked()
})

test('TC-102-24: 不正なクエリは無視し、材料が残らなければ条件の無い検索の画面を出す', async ({ page }) => {
  await page.goto('/result/Cocktails?n=abc,-1&mode=xor&page=-3')

  const form = page.getByRole('main').getByRole('form', { name: 'カクテル検索' })
  await expect(form).toBeVisible()
  await expect(form.getByText('材料は必須です。')).toBeVisible()
  await expect(form.getByRole('radio', { name: 'OR検索' })).toBeChecked()
  await expect(results(page)).toHaveCount(0)
})

test('TC-103-08: 結果の「次へ」・ページの番号で、同じ条件の別のページへ移り、URL にページが入り、戻るボタンで前のページに戻る', async ({
  page,
}) => {
  // E2eSeeder の材料にウォッカを使う公開のカクテルは 7 件で、1 ページ(50 件)に収まる。ページの移り方を本物の API で確かめるため、
  // 送る本文に 1 ページの件数(per_page。画面は送らない)を 3 件と足す。7 件が 3・3・1 件の 3 ページになる(並びは ID の昇順)
  await page.route('**/api/cocktails/search', (route) =>
    route.continue({ postData: JSON.stringify({ ...route.request().postDataJSON(), per_page: 3 }) }),
  )
  await page.goto('/helpme')
  const dialog = await openModal(page)
  await dialog.getByRole('checkbox', { name: 'ウォッカ' }).check()
  await dialog.getByRole('button', { name: '検索' }).click()

  const rows = results(page).getByRole('table').locator('tbody > tr')
  const pager = page.getByRole('navigation', { name: 'カクテル検索結果のページ' })
  await expect(page).toHaveURL(/\/result\/Cocktails\?n=\d+$/)
  const firstUrl = page.url()
  await expect(results(page)).toContainText('カクテル検索結果 7件ヒットしました。')
  await expect(rows).toHaveCount(3)
  await expect(results(page).getByRole('link', { name: 'モスコミュール', exact: true })).toBeVisible()
  await expect(pager.getByText('1', { exact: true })).toHaveAttribute('aria-current', 'page')

  await pager.getByRole('link', { name: '次へ' }).click()
  await expect(page).toHaveURL(/\/result\/Cocktails\?n=\d+&page=2$/)
  const secondUrl = page.url()
  await expect(results(page).getByRole('link', { name: 'ブラッディメアリー', exact: true })).toBeVisible()
  await expect(rows).toHaveCount(3)
  await expect(results(page)).toContainText('カクテル検索結果 7件ヒットしました。')

  await pager.getByRole('link', { name: '3 ページ目' }).click()
  await expect(page).toHaveURL(/\/result\/Cocktails\?n=\d+&page=3$/)
  await expect(results(page).getByRole('link', { name: 'バラライカ', exact: true })).toBeVisible()
  await expect(rows).toHaveCount(1)
  await expect(pager.getByRole('link', { name: '次へ' })).toHaveCount(0)

  await page.goBack()
  await expect(page).toHaveURL(secondUrl)
  await expect(results(page).getByRole('link', { name: 'ブラッディメアリー', exact: true })).toBeVisible()
  await page.goBack()
  await expect(page).toHaveURL(firstUrl)
  await expect(results(page).getByRole('link', { name: 'モスコミュール', exact: true })).toBeVisible()
})
