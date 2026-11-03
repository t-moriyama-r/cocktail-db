import { expect, test, type Page } from '@playwright/test'

// 旧サイトが保存した検索条件の移し替え(#175)。E2eSeeder の旧 ID の対応(seedLegacyIdMaps)を使う。
// 旧のカテゴリ 9001 → ジン(子のミドル 9101 → ドライジン)、9002 → ウォッカ(子のミドルなし)、旧の技法 9201 → ビルド。9999 は対応が無い
const LEGACY_USER = JSON.stringify({
  searchMethod: 'having',
  methods: [9201],
  categories: [9001, 9002, 9999],
  middles: [],
  is_easily: true,
})

const header = (page: Page) => page.getByRole('banner')

const openModal = async (page: Page) => {
  await header(page).getByRole('button', { name: '検索をはじめる' }).click()
  const dialog = page.getByRole('dialog', { name: 'カクテル検索' })
  await expect(dialog.getByRole('heading', { name: 'カクテル検索' })).toBeVisible()
  return dialog
}

const storageItem = (page: Page, key: string) => page.evaluate((name) => window.localStorage.getItem(name), key)

test('TC-175-12: 旧の形の値を localStorage に入れて開くと、新しいノードの条件に直して保存し、「読み込み」で戻る。対応の無い ID・壊れた JSON でも画面が壊れない', async ({
  page,
}) => {
  const errors: string[] = []
  page.on('pageerror', (error) => errors.push(error.message))

  // 旧サイトが残した値を入れてから開き直す(同じオリジンの localStorage)。開くのは API を呼ばない 404 の画面(/helpme は #641 で消した)
  await page.goto('/nope')
  await page.evaluate((user) => {
    window.localStorage.setItem('searchUser', user)
    window.localStorage.setItem('searchInstant', '{"searchMethod":')
  }, LEGACY_USER)
  const converted = page.waitForResponse(
    (response) => response.url().endsWith('/api/legacy/search-condition') && response.request().method() === 'POST',
  )
  await page.reload()
  expect((await converted).status()).toBe(200)

  // 対応の無い ID(9999)を捨てたことと、壊れていた値を消したことを出す。本文はそのまま出る
  const notice = page.getByRole('status').filter({ hasText: '旧サイトで保存した検索条件' })
  await expect(notice).toContainText('戻せなかった材料・製法 1 件は、条件から外しています。')
  await expect(notice).toContainText('旧サイトで保存した検索条件が壊れていて読めなかったため、消しました。')
  await expect(page.getByRole('heading', { level: 1, name: 'ページが見つかりません' })).toBeVisible()

  // 旧のキーは消え、新しいキーに保存した
  expect(await storageItem(page, 'searchUser')).toBeNull()
  expect(await storageItem(page, 'searchInstant')).toBeNull()
  expect(await storageItem(page, 'cocktail-db.search.user.v1')).toMatch(/^n=\d+,\d+&mode=having&easy=1&m=\d+$/)
  expect(await storageItem(page, 'cocktail-db.search.instant.v1')).toBeNull()

  // 開いただけでは戻さない。「読み込み」で直した条件に戻る(ジンは子のミドルが 0 個なので、ジン 1 つ(配下もすべて)に直る)
  const dialog = await openModal(page)
  await expect(dialog.getByRole('checkbox', { name: 'ジン', exact: true })).not.toBeChecked()
  await expect(dialog.getByRole('button', { name: 'リロード' })).toBeDisabled()
  await dialog.getByRole('button', { name: '読み込み', exact: true }).click()
  await expect(dialog.getByRole('checkbox', { name: 'ジン', exact: true })).toBeChecked()
  await expect(dialog.getByRole('checkbox', { name: 'ウォッカ' })).toBeChecked()
  await expect(dialog.getByRole('radio', { name: 'あるもの検索' })).toBeChecked()
  await expect(dialog.getByRole('checkbox', { name: 'かんたん検索' })).toBeChecked()
  const methods = dialog.getByRole('group', { name: '製法' })
  await expect(methods.getByRole('checkbox', { name: 'ビルド' })).toBeChecked()
  await expect(methods.getByRole('checkbox', { name: 'シェイク' })).not.toBeChecked()

  // 「閉じる」でお知らせを消す。開き直しても、もう移すものが無いので出ない
  await page.keyboard.press('Escape')
  await notice.getByRole('button', { name: '閉じる' }).click()
  await expect(notice).toHaveCount(0)
  await page.reload()
  await expect(page.getByRole('heading', { level: 1, name: 'ページが見つかりません' })).toBeVisible()
  await expect(page.getByRole('status').filter({ hasText: '旧サイトで保存した検索条件' })).toHaveCount(0)

  expect(errors).toEqual([])
})
