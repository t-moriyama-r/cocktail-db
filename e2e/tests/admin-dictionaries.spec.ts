import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test, type Page } from '@playwright/test'

// 管理画面の用語辞典の編集(#66)。E2eSeeder の seedDictionary のデータを使う。
// グラスのタンブラーは、カクテルのジントニックのグラスに使われている。
// 公開の用語辞典の E2E(dictionary.spec.ts)は分類の見出しと単位の用語を見るので、作る用語はグラスの下に置き、テストの中で消す

/**
 * php artisan admin:login-link で、E2eSeeder の管理者の非常用のログイン URL を出す(admin-layout.spec.ts と同じ)。
 * URL の署名にはホストも入るので、APP_URL を E2E が開くオリジンにして出す(環境変数は .env より優先される)。
 */
function issueLoginLink(baseURL: string): string {
  return execFileSync('php', ['artisan', 'admin:login-link', 'e2e-admin@example.com'], {
    cwd: fileURLToPath(new URL('../../backend/', import.meta.url)),
    env: { ...process.env, APP_URL: baseURL },
    encoding: 'utf8',
  }).trim()
}

/** ログインして、メニューから用語辞典の一覧を開く */
async function openDictionaries(page: Page, baseURL: string) {
  await page.goto(issueLoginLink(baseURL))
  await expect(page).toHaveURL(/\/admin$/)
  await page.getByRole('navigation', { name: '管理画面のメニュー' }).getByRole('link', { name: '用語辞典' }).click()
  await expect(page).toHaveURL(/\/admin\/dictionaries$/)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('用語辞典')
}

test('TC-66-20: 用語を作ると、一覧の分類の下に出る(作った用語は消して片付ける)', async ({ page, baseURL }) => {
  await openDictionaries(page, baseURL!)

  await page.getByRole('link', { name: '新規' }).click()
  await expect(page).toHaveURL(/\/admin\/dictionaries\/new$/)
  await page.getByRole('combobox', { name: '分類' }).selectOption({ label: 'グラス(Glass)' })
  await page.getByRole('textbox', { name: '名前' }).fill('E2E のロックグラス')
  await page.getByRole('textbox', { name: '英名' }).fill('E2E Rocks glass')
  await page.getByRole('textbox', { name: '説明' }).fill('背の低い、口の広いグラス。')
  await page.getByRole('button', { name: '保存する' }).click()

  await expect(page).toHaveURL(/\/admin\/dictionaries$/)
  await expect(page.getByText('「E2E のロックグラス(E2E Rocks glass)」を保存しました。')).toBeVisible()
  const glass = page.getByRole('region', { name: 'グラス(Glass)' })
  await expect(glass.getByRole('link', { name: 'E2E のロックグラス(E2E Rocks glass)' })).toBeVisible()

  // 片付け: 作った用語を消す(消せることも確かめる)
  await glass.getByRole('link', { name: 'E2E のロックグラス(E2E Rocks glass)' }).click()
  await expect(page.getByRole('textbox', { name: '名前' })).toHaveValue('E2E のロックグラス')
  page.once('dialog', (dialog) => void dialog.accept())
  await page.getByRole('button', { name: '削除する' }).click()

  await expect(page).toHaveURL(/\/admin\/dictionaries$/)
  await expect(page.getByText('「E2E のロックグラス(E2E Rocks glass)」を消しました。')).toBeVisible()
  await expect(glass.getByRole('link', { name: 'タンブラー(Tumbler)' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'E2E のロックグラス(E2E Rocks glass)' })).toHaveCount(0)
})

test('TC-66-21: グラスに使われている用語を消そうとすると、理由が出て消えない', async ({ page, baseURL }) => {
  await openDictionaries(page, baseURL!)

  await page.getByRole('region', { name: 'グラス(Glass)' }).getByRole('link', { name: 'タンブラー(Tumbler)' }).click()
  await expect(page.getByRole('textbox', { name: '名前' })).toHaveValue('タンブラー')
  page.once('dialog', (dialog) => void dialog.accept())
  await page.getByRole('button', { name: '削除する' }).click()

  const alert = page.getByRole('alert')
  await expect(alert).toContainText('この用語は消せません。')
  await expect(alert.getByRole('listitem')).toHaveText(['カクテルのグラスに使われています'])
  await expect(page).toHaveURL(/\/admin\/dictionaries\/\d+$/)

  await page.getByRole('navigation', { name: '管理画面のメニュー' }).getByRole('link', { name: '用語辞典' }).click()
  await expect(page.getByRole('region', { name: 'グラス(Glass)' }).getByRole('link', { name: 'タンブラー(Tumbler)' })).toBeVisible()
})
