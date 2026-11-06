import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test, type Page } from '@playwright/test'

// 管理画面の設定の画面(#133)。ログインは、E2eSeeder の管理者で非常用のログイン URL(#115)を出して開く(admin-layout.spec.ts と同じ)

/** php artisan admin:login-link で、E2eSeeder の管理者の非常用のログイン URL を出す */
function issueLoginLink(baseURL: string): string {
  return execFileSync('php', ['artisan', 'admin:login-link', 'e2e-admin@example.com'], {
    cwd: fileURLToPath(new URL('../../backend/', import.meta.url)),
    env: { ...process.env, APP_URL: baseURL },
    encoding: 'utf8',
  }).trim()
}

async function openSettings(page: Page, baseURL: string) {
  await page.goto(issueLoginLink(baseURL))
  await expect(page).toHaveURL(/\/admin$/)
  await page.getByRole('navigation', { name: '管理画面のメニュー' }).getByRole('link', { name: '設定' }).click()
  await expect(page).toHaveURL(/\/admin\/settings$/)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('設定')
}

/** 画面と同じく CSRF のトークンを付けて、設定の 1 項目を .env の値に戻す(ほかの E2E に保存した値を残さないため) */
async function resetSetting(page: Page, key: string) {
  const status = await page.evaluate(async (settingKey) => {
    const token = document.cookie
      .split('; ')
      .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
      ?.slice('XSRF-TOKEN='.length)
    const response = await fetch('/api/admin/settings', {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-XSRF-TOKEN': decodeURIComponent(token ?? ''),
      },
      body: JSON.stringify({ key: settingKey, value: null }),
    })
    return response.status
  }, key)
  expect(status).toBe(200)
}

const SECRET_LABEL = 'Amazon Creators API の Credential Secret'

test('TC-133-17: 秘密の値を保存すると末尾 4 文字だけが出て、画面の HTML に平文が出ず、空のまま保存しても消えない', async ({ page, baseURL }) => {
  const secret = 'e2e-plain-secret-Q7w9'
  await openSettings(page, baseURL!)
  try {
    const section = page.getByRole('region', { name: SECRET_LABEL })
    await section.getByLabel('新しい値').fill(secret)
    await section.getByRole('button', { name: '保存' }).click()
    await expect(section.getByRole('status')).toHaveText('保存しました。')
    await expect(section.getByText('設定済み(末尾 Q7w9)')).toBeVisible()
    await expect(section.getByText(/^E2E の管理者\(/)).toBeVisible()
    expect(await page.content()).not.toContain(secret)

    // 開き直しても平文は出ない。空のまま保存しても、値は消えない
    await page.reload()
    const reloaded = page.getByRole('region', { name: SECRET_LABEL })
    await expect(reloaded.getByText('設定済み(末尾 Q7w9)')).toBeVisible()
    await expect(reloaded.getByLabel('新しい値')).toHaveValue('')
    expect(await page.content()).not.toContain(secret)

    await reloaded.getByRole('button', { name: '保存' }).click()
    await expect(reloaded.getByRole('status')).toHaveText('値が空なので、変えていません。')
    await page.reload()
    await expect(page.getByRole('region', { name: SECRET_LABEL }).getByText('設定済み(末尾 Q7w9)')).toBeVisible()
  } finally {
    await resetSetting(page, 'amazon.credential_secret')
  }
})

test('TC-133-18: 規則に合わない値は、その項目の下にメッセージが出て、保存されない', async ({ page, baseURL }) => {
  await openSettings(page, baseURL!)
  const section = page.getByRole('region', { name: 'Amazon のパートナータグ' })
  const before = await section.getByLabel('値').inputValue()

  await section.getByLabel('値').fill('bad tag')
  await section.getByRole('button', { name: '保存' }).click()

  await expect(section.getByRole('alert')).toContainText('この値は保存できません。')
  await expect(section.getByLabel('値')).toHaveAttribute('aria-invalid', 'true')
  await page.reload()
  await expect(page.getByRole('region', { name: 'Amazon のパートナータグ' }).getByLabel('値')).toHaveValue(before)
})

test('TC-133-19: 画面から変えられない設定(APP_KEY など)の説明が出る', async ({ page, baseURL }) => {
  await openSettings(page, baseURL!)

  const fixed = page.getByRole('region', { name: '画面から変えられない設定' })
  await expect(fixed).toContainText('APP_KEY')
  await expect(fixed).toContainText('APP_URL')
  await expect(fixed).toContainText('DB の接続')
})
