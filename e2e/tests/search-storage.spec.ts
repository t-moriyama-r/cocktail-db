import { expect, test, type Page } from '@playwright/test'

// 条件検索の「保存」「読み込み」「リロード」(#104)。E2eSeeder の木(蒸留酒 > ジン、蒸留酒 > ウォッカ)を使う。
// 保存先は localStorage なので、ページを読み直しても残ることを確かめる(テストごとに新しいブラウザのコンテキストで、保存は空から始まる)
const header = (page: Page) => page.getByRole('banner')

const openModal = async (page: Page) => {
  await header(page).getByRole('button', { name: '検索をはじめる' }).click()
  const dialog = page.getByRole('dialog', { name: 'カクテル検索' })
  await expect(dialog.getByRole('heading', { name: 'カクテル検索' })).toBeVisible()
  return dialog
}

test('TC-104-14: 「保存」した条件は、読み直した後に開いても戻らず、「読み込み」で戻る。「検索」した条件は「リロード」で戻る', async ({
  page,
}) => {
  await page.goto('/helpme')

  // 保存が無いあいだは「読み込み」「リロード」を押せない
  let dialog = await openModal(page)
  await expect(dialog.getByRole('button', { name: '読み込み', exact: true })).toBeDisabled()
  await expect(dialog.getByRole('button', { name: 'リロード' })).toBeDisabled()

  // ジンを選んで保存する
  await dialog.getByRole('checkbox', { name: 'ジン', exact: true }).check()
  await dialog.getByRole('radio', { name: 'あるもの検索' }).check()
  await dialog.getByRole('button', { name: '保存', exact: true }).click()

  // 読み直して開くと、既定の状態のまま(自動では戻さない)。「読み込み」で保存した条件に戻る
  await page.reload()
  dialog = await openModal(page)
  await expect(dialog.getByRole('checkbox', { name: 'ジン', exact: true })).not.toBeChecked()
  await expect(dialog.getByRole('radio', { name: 'OR検索' })).toBeChecked()
  await expect(dialog.getByRole('button', { name: 'リロード' })).toBeDisabled()
  await dialog.getByRole('button', { name: '読み込み', exact: true }).click()
  await expect(dialog.getByRole('checkbox', { name: 'ジン', exact: true })).toBeChecked()
  await expect(dialog.getByRole('radio', { name: 'あるもの検索' })).toBeChecked()

  // ウォッカに替えて AND 検索する(検索した条件が「リロード」用に残る)
  await dialog.getByRole('checkbox', { name: 'ジン', exact: true }).uncheck()
  await dialog.getByRole('checkbox', { name: 'ウォッカ' }).check()
  await dialog.getByRole('radio', { name: 'AND検索' }).check()
  await dialog.getByRole('button', { name: '検索', exact: true }).click()
  await expect(page).toHaveURL(/\/result\/Cocktails\?n=\d+&mode=and$/)

  // 別の画面を開き直してから、リセットして「リロード」で検索した条件に戻る
  await page.goto('/helpme')
  dialog = await openModal(page)
  await expect(dialog.getByRole('checkbox', { name: 'ウォッカ' })).not.toBeChecked()
  await dialog.getByRole('button', { name: 'リロード' }).click()
  await expect(dialog.getByRole('checkbox', { name: 'ウォッカ' })).toBeChecked()
  await expect(dialog.getByRole('checkbox', { name: 'ジン', exact: true })).not.toBeChecked()
  await expect(dialog.getByRole('radio', { name: 'AND検索' })).toBeChecked()

  // 「読み込み」は「保存」した条件(ジン・あるもの検索)のまま
  await dialog.getByRole('button', { name: '読み込み', exact: true }).click()
  await expect(dialog.getByRole('checkbox', { name: 'ジン', exact: true })).toBeChecked()
  await expect(dialog.getByRole('checkbox', { name: 'ウォッカ' })).not.toBeChecked()
  await expect(dialog.getByRole('radio', { name: 'あるもの検索' })).toBeChecked()
})
