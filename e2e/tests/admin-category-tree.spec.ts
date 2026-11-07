import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test, type Page } from '@playwright/test'

// 管理画面のカテゴリ木(#123)。木は E2eSeeder の seedIngredientTree のもの:
//   蒸留酒 > ジン > ドライジン > ロンドンドライジン > ビーフィーター
//          > ウォッカ
//   ソフトドリンク(役割: ソフトドリンク)
//   その他(役割: その他) > ミント
// 英名は名前と同じ文字にしてある。ログインは、E2eSeeder の管理者で非常用のログイン URL(#115)を出して開く(admin-layout.spec.ts と同じ)

/** php artisan admin:login-link で、E2eSeeder の管理者の非常用のログイン URL を出す(APP_URL は E2E が開くオリジンにする) */
function issueLoginLink(baseURL: string): string {
  return execFileSync('php', ['artisan', 'admin:login-link', 'e2e-admin@example.com'], {
    cwd: fileURLToPath(new URL('../../backend/', import.meta.url)),
    env: { ...process.env, APP_URL: baseURL },
    encoding: 'utf8',
  }).trim()
}

async function openCategoryTree(page: Page, baseURL: string) {
  await page.goto(issueLoginLink(baseURL))
  await expect(page).toHaveURL(/\/admin$/)
  await page.getByRole('navigation', { name: '管理画面のメニュー' }).getByRole('link', { name: 'カテゴリ木' }).click()
  await expect(page).toHaveURL(/\/admin\/categories$/)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('カテゴリ木')
}

test('TC-123-11: メニューからカテゴリ木を開くと、根が開いた木が出て、行に(英名)・根の役割・メジャーかが出る', async ({ page, baseURL }) => {
  await openCategoryTree(page, baseURL!)

  const tree = page.getByRole('list', { name: 'カテゴリ木' })
  await expect(tree.getByRole('radio')).toHaveCount(6)
  await expect(tree.getByRole('radio', { name: 'ジン', exact: true })).toBeVisible()
  await expect(tree.getByText('(ジン)・メジャー', { exact: true })).toBeVisible()
  await expect(tree.getByText('(ソフトドリンク)・役割: ソフトドリンク・メジャー', { exact: true })).toBeVisible()
  await expect(tree.getByText('(蒸留酒)・役割: なし・メジャー', { exact: true })).toBeVisible()
})

// 深いノードを選ぶと編集フォームが開くことは、admin-category-edit.spec.ts の TC-308-21 で見る(#308 で、選ぶと編集フォームへ移るようにした)
test('TC-123-12: 深いノードまで開け、閉じると子が隠れる', async ({ page, baseURL }) => {
  await openCategoryTree(page, baseURL!)

  await page.getByRole('button', { name: 'ジンを開く', exact: true }).click()
  await page.getByRole('button', { name: 'ドライジンを開く', exact: true }).click()
  await page.getByRole('button', { name: 'ロンドンドライジンを開く', exact: true }).click()
  await expect(page.getByRole('radio', { name: 'ビーフィーター', exact: true })).toBeVisible()

  await page.getByRole('button', { name: 'ジンを閉じる', exact: true }).click()
  await expect(page.getByRole('radio', { name: 'ドライジン', exact: true })).toHaveCount(0)
})

test('TC-123-13: 名前で絞ると、当たったノードと祖先だけが出る', async ({ page, baseURL }) => {
  await openCategoryTree(page, baseURL!)

  await page.getByRole('searchbox', { name: '名前で絞り込む' }).fill('ビーフ')

  const tree = page.getByRole('list', { name: 'カテゴリ木' })
  await expect(tree.getByRole('radio')).toHaveCount(5)
  for (const name of ['蒸留酒', 'ジン', 'ドライジン', 'ロンドンドライジン', 'ビーフィーター']) {
    await expect(tree.getByRole('radio', { name, exact: true })).toBeVisible()
  }
  await expect(tree.getByRole('radio', { name: 'ウォッカ', exact: true })).toHaveCount(0)
  await expect(tree.getByRole('radio', { name: 'ミント', exact: true })).toHaveCount(0)
})
