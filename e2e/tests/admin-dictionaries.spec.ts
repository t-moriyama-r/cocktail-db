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

/** 1x1 の PNG(画像を付けて保存するのに使う。API は中身から画像の種類を確かめる) */
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64')

test.describe('新規の冪等キー(#791)', () => {
  // 試行ごとに変わる名前にし、作った用語は途中で落ちても afterEach で消す(RC-18)
  const name = `E2E の再送のグラス ${Date.now()}`
  const label = `${name}(E2E Retry glass)`

  test.afterEach(async ({ page }) => {
    await page.unrouteAll()
    await page.goto('/admin/dictionaries')
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('用語辞典')
    const links = page.getByRole('link', { name: label, exact: true })
    // 一覧の読み込みを待ってから数える(作っていなければ何もしない)
    await expect(page.getByRole('region', { name: 'グラス(Glass)' })).toBeVisible()
    while ((await links.count()) > 0) {
      await links.first().click()
      await expect(page.getByRole('textbox', { name: '名前' })).toHaveValue(name)
      page.once('dialog', (dialog) => void dialog.accept())
      await page.getByRole('button', { name: '削除する' }).click()
      await expect(page).toHaveURL(/\/admin\/dictionaries$/)
      await expect(page.getByText(`「${label}」を消しました。`)).toBeVisible()
    }
  })

  test('TC-791-07: 新規の保存の応答が失われて送り直しても、用語(画像を含む)は 1 件だけできて一覧へ戻る', async ({ page, baseURL }) => {
    await openDictionaries(page, baseURL!)
    await page.goto('/admin/dictionaries/new')
    await page.getByRole('combobox', { name: '分類' }).selectOption({ label: 'グラス(Glass)' })
    await page.getByRole('textbox', { name: '名前' }).fill(name)
    await page.getByRole('textbox', { name: '英名' }).fill('E2E Retry glass')
    await page.getByRole('textbox', { name: '説明' }).fill('E2E で作った用語')
    await page.getByLabel('画像', { exact: true }).setInputFiles({ name: 'retry.png', mimeType: 'image/png', buffer: PNG })

    // 1 回目の送信は BE まで届けて保存させ、応答だけを失わせる
    const keys: (string | null)[] = []
    await page.route('**/api/admin/dictionaries', async (route) => {
      if (route.request().method() !== 'POST') return route.continue()
      keys.push(await route.request().headerValue('Idempotency-Key'))
      if (keys.length > 1) return route.continue()
      await route.fetch()
      await route.abort('failed')
    })
    await page.getByRole('button', { name: '保存する' }).click()
    await expect(page.getByRole('alert')).toContainText('通信に失敗しました')
    await expect(page).toHaveURL(/\/admin\/dictionaries\/new$/)

    await page.getByRole('button', { name: '保存する' }).click()
    await expect(page).toHaveURL(/\/admin\/dictionaries$/)
    await expect(page.getByText(`「${label}」を保存しました。`)).toBeVisible()
    await page.unrouteAll()

    expect(keys).toHaveLength(2)
    expect(keys[0]).toMatch(/^[0-9a-f-]{36}$/)
    expect(keys[1]).toBe(keys[0])
    // グラスの下に、同じ名前の用語が 1 件だけあり、開くと画像も入っている
    const glass = page.getByRole('region', { name: 'グラス(Glass)' })
    await expect(glass.getByRole('link', { name: label, exact: true })).toHaveCount(1)
    await glass.getByRole('link', { name: label, exact: true }).click()
    await expect(page.getByRole('textbox', { name: '名前' })).toHaveValue(name)
    await expect(page.getByRole('img', { name: '今の画像' })).toHaveAttribute('src', /^\/storage\/.+\.png$/)
  })
})
