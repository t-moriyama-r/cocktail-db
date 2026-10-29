import { expect, test } from '@playwright/test'

// トップの下の材料の欄(#305)。E2eSeeder の木(蒸留酒 > ジン > ドライジン > ロンドンドライジン > ビーフィーター)を使う
test('TC-305-12: 深さ 5 のノードを開いてチェックでき、名前を押すと詳細へ移り、戻るとトップが出る', async ({ page }) => {
  await page.goto('/')

  await page.getByRole('button', { name: 'ジンを開く' }).click()
  await page.getByRole('button', { name: 'ドライジンを開く' }).click()
  await page.getByRole('button', { name: 'ロンドンドライジンを開く' }).click()
  await page.getByRole('checkbox', { name: 'ビーフィーター' }).check()
  await expect(page.getByRole('checkbox', { name: 'ビーフィーター' })).toBeChecked()

  await page.getByRole('button', { name: 'ビーフィーター', exact: true }).click()
  await expect(page).toHaveURL(/\/category\/\d+$/)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('カテゴリの詳細')

  // 欄はトップにあるので、戻ると選び直しになる。チェックが残るのは、モーダルの中の欄(#99)で確かめる
  await page.goBack()
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Hello World from Laravel')
})
