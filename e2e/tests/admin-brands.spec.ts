import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test, type Page } from '@playwright/test'

// 管理画面の銘柄の一覧・新規・編集(#64)。相手は本物の管理 API(#314)と、E2eSeeder の木
// (蒸留酒 > ジン > ドライジン > …、蒸留酒 > ウォッカ、その他 > ミント)。
// ほかの E2E が見ている銘柄(ビーフィーター 40度)は変えず、ここで作った銘柄だけを書き換える。
// 名前検索の件数を変えないよう、名前に「ジン」を入れない。
// ログインは、admin-layout.spec.ts と同じく、E2eSeeder の管理者で非常用のログイン URL(#115)を出して開く

/** php artisan admin:login-link で、E2eSeeder の管理者の非常用のログイン URL を出す(APP_URL を E2E が開くオリジンにする) */
function issueLoginLink(baseURL: string): string {
  return execFileSync('php', ['artisan', 'admin:login-link', 'e2e-admin@example.com'], {
    cwd: fileURLToPath(new URL('../../backend/', import.meta.url)),
    env: { ...process.env, APP_URL: baseURL },
    encoding: 'utf8',
  }).trim()
}

async function login(page: Page, baseURL: string) {
  await page.goto(issueLoginLink(baseURL))
  await expect(page).toHaveURL(/\/admin$/)
}

/** 新規の画面で、ウォッカに所属する銘柄を作って保存し、一覧に戻るまで進める。作った銘柄の名前を返す */
async function createBrand(page: Page, label: string): Promise<string> {
  // 何度流しても一覧で取り違えないよう、名前に時刻を入れる
  const name = `E2E の銘柄 ${label} ${Date.now()}`
  await page.goto('/admin/brands')
  await page.getByRole('link', { name: '新規' }).click()
  await expect(page).toHaveURL(/\/admin\/brands\/new$/)
  await page.getByRole('list', { name: '所属' }).getByRole('radio', { name: 'ウォッカ' }).check()
  await page.getByLabel('名前', { exact: true }).fill(name)
  await page.getByLabel('英名').fill(`E2E Brand ${label}`)
  await page.getByLabel('度数(%)').fill('40')
  await page.getByLabel('説明').fill('E2E で作った銘柄')
  await page.getByRole('button', { name: '送信' }).click()
  await expect(page).toHaveURL(/\/admin\/brands$/)
  return name
}

test('TC-64-22: 新規で入力して送信すると一覧へ戻り、作った銘柄が所属と一緒に一覧に出る', async ({ page, baseURL }) => {
  await login(page, baseURL!)

  const name = await createBrand(page, 'new')

  await page.getByRole('searchbox', { name: '絞り込み' }).fill(name)
  const row = page.getByRole('listitem').filter({ has: page.getByRole('link', { name: `${name}(E2E Brand new)` }) })
  await expect(row).toHaveText(`${name}(E2E Brand new)(ウォッカ)`)
})

test('TC-64-23: 所属を変えて送信し、開き直すと新しい所属が出る', async ({ page, baseURL }) => {
  await login(page, baseURL!)
  const name = await createBrand(page, 'move')
  await page.getByRole('searchbox', { name: '絞り込み' }).fill(name)
  await page.getByRole('link', { name: `${name}(E2E Brand move)` }).click()
  await expect(page).toHaveURL(/\/admin\/brands\/\d+$/)
  const editUrl = page.url()
  await expect(page.getByText('選んでいる所属: ウォッカ')).toBeVisible()

  await page.getByRole('list', { name: '所属' }).getByRole('radio', { name: 'ミント' }).check()
  await page.getByRole('button', { name: '送信' }).click()
  await expect(page).toHaveURL(/\/admin\/brands$/)

  await page.goto(editUrl)
  await expect(page.getByText('選んでいる所属: ミント')).toBeVisible()
  await expect(page.getByRole('list', { name: '所属' }).getByRole('radio', { name: 'ミント' })).toBeChecked()
})

test('TC-64-24: 度数の欄に文字を入れても、数字と「.」だけが残る', async ({ page, baseURL }) => {
  await login(page, baseURL!)
  await page.goto('/admin/brands/new')

  const strength = page.getByLabel('度数(%)')
  await strength.pressSequentially('4a0.b5%')

  await expect(strength).toHaveValue('40.5')
})

test('TC-64-26: 度数・開始位置の欄に文字の混ざった値を貼り付けても、数字(度数は「.」も)だけが桁数まで残る', async ({
  page,
  baseURL,
}) => {
  await login(page, baseURL!)
  await page.goto('/admin/brands/new')

  // fill は貼り付けと同じく、値をまとめて 1 度に入れる
  const strength = page.getByLabel('度数(%)')
  await strength.fill('4a0.5')
  await expect(strength).toHaveValue('40.5')

  const minute = page.getByLabel('分')
  await minute.fill('6a7')
  await expect(minute).toHaveValue('67')
})

test('TC-654-06: 深さ 5 のノードを所属に持つ銘柄の編集を開くと、そこまでの枝が開いていて、そのラジオがチェックされて見えている', async ({
  page,
  baseURL,
}) => {
  await login(page, baseURL!)
  await page.goto('/admin/brands')
  await page.getByRole('searchbox', { name: '絞り込み' }).fill('ビーフィーター 40度')
  await page.getByRole('link', { name: 'ビーフィーター 40度(Beefeater 40)' }).click()
  await expect(page).toHaveURL(/\/admin\/brands\/\d+$/)

  const tree = page.getByRole('list', { name: '所属' })
  await expect(page.getByText('選んでいる所属: ジン / ドライジン / ロンドンドライジン / ビーフィーター')).toBeVisible()
  await expect(tree.getByRole('radio', { name: 'ビーフィーター', exact: true })).toBeVisible()
  await expect(tree.getByRole('radio', { name: 'ビーフィーター', exact: true })).toBeChecked()
})
