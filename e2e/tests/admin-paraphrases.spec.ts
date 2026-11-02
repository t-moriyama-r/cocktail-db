import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test, type Page } from '@playwright/test'

// 管理画面の言い換え辞書・スキップ語(#67)。ログインは、E2eSeeder の管理者で非常用のログイン URL(#115)を出して開く。
// 木は E2eSeeder のもの(蒸留酒 > ジン > ドライジン、蒸留酒 > ウォッカ)。足した語は、同じテストの中で消して片付ける

/** php artisan admin:login-link で、E2eSeeder の管理者の非常用のログイン URL を出す(admin-layout.spec.ts と同じ) */
function issueLoginLink(baseURL: string): string {
  return execFileSync('php', ['artisan', 'admin:login-link', 'e2e-admin@example.com'], {
    cwd: fileURLToPath(new URL('../../backend/', import.meta.url)),
    env: { ...process.env, APP_URL: baseURL },
    encoding: 'utf8',
  }).trim()
}

async function openScreen(page: Page, baseURL: string) {
  await page.goto(issueLoginLink(baseURL))
  await expect(page).toHaveURL(/\/admin$/)
  await page.getByRole('navigation', { name: '管理画面のメニュー' }).getByRole('link', { name: '言い換え・スキップ語' }).click()
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('言い換え・スキップ語')
}

test('TC-67-26: 木からノードを選ぶと変換後・ミドルに名前が入り、保存すると一覧に解決した先と一緒に出る(消して片付ける)', async ({
  page,
  baseURL,
}) => {
  await openScreen(page, baseURL!)
  const form = page.getByRole('form', { name: '言い換えのフォーム' })
  const list = page.getByRole('region', { name: '言い換えの一覧' })

  await form.getByRole('textbox', { name: '変換前' }).fill('E2E のドライ・ジン')
  await form.getByRole('button', { name: 'ジンを開く' }).click()
  await form.getByRole('radio', { name: 'ドライジン' }).check()
  await expect(form.getByRole('textbox', { name: '変換後' })).toHaveValue('ジン')
  await expect(form.getByRole('textbox', { name: 'ミドル' })).toHaveValue('ドライジン')
  await form.getByRole('button', { name: '保存する' }).click()

  await expect(form.getByText('「E2E のドライ・ジン」を保存しました')).toBeVisible()
  const item = list.getByRole('listitem').filter({ hasText: 'E2E のドライ・ジン' })
  await expect(item).toHaveText('E2E のドライ・ジン → ジン / ドライジン蒸留酒 / ジン / ドライジン')

  page.once('dialog', (dialog) => void dialog.accept())
  await item.getByRole('button').click()
  await form.getByRole('button', { name: '削除する' }).click()
  await expect(form.getByText('「E2E のドライ・ジン」を消しました')).toBeVisible()
  await expect(list.getByRole('listitem').filter({ hasText: 'E2E のドライ・ジン' })).toHaveCount(0)
})

test('TC-67-27: 解決できない変換後で保存すると、欄の下に API の誤りが出て保存されない', async ({ page, baseURL }) => {
  await openScreen(page, baseURL!)
  const form = page.getByRole('form', { name: '言い換えのフォーム' })

  await form.getByRole('textbox', { name: '変換前' }).fill('E2E の解決できない語')
  await form.getByRole('textbox', { name: '変換後' }).fill('E2E の無いノード')
  await form.getByRole('button', { name: '保存する' }).click()

  await expect(form.getByText('この名前のノード・銘柄がありません。')).toBeVisible()
  await expect(form.getByRole('textbox', { name: '変換後' })).toHaveAttribute('aria-invalid', 'true')
  await expect(page.getByRole('region', { name: '言い換えの一覧' }).getByText('E2E の解決できない語')).toHaveCount(0)
})

test('TC-67-28: スキップ語を足すと一覧に出て、消すと一覧から消える', async ({ page, baseURL }) => {
  await openScreen(page, baseURL!)
  const form = page.getByRole('form', { name: 'スキップ語のフォーム' })
  const list = page.getByRole('region', { name: 'スキップ語の一覧' })

  await form.getByRole('textbox', { name: 'スキップ語' }).fill('E2E のお好みで')
  await form.getByRole('button', { name: '保存する' }).click()
  await expect(form.getByText('「E2E のお好みで」を保存しました')).toBeVisible()
  await expect(list.getByRole('button', { name: 'E2E のお好みで' })).toBeVisible()

  page.once('dialog', (dialog) => void dialog.accept())
  await list.getByRole('button', { name: 'E2E のお好みで' }).click()
  await form.getByRole('button', { name: '削除する' }).click()
  await expect(form.getByText('「E2E のお好みで」を消しました')).toBeVisible()
  await expect(list.getByRole('button', { name: 'E2E のお好みで' })).toHaveCount(0)
})
