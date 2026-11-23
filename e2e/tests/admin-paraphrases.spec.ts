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

/**
 * 前の実行が途中で落ちて残った語を、始める前に消す。足した語は同じテストの最後に消すが、途中の確認で落ちると残り、
 * 再実行が一意制約の 422 で別の理由で落ちるため
 */
async function removeLeftover(page: Page, formName: string, listName: string, word: string, itemName: string | RegExp) {
  const form = page.getByRole('form', { name: formName })
  const row = page.getByRole('region', { name: listName }).getByRole('button', { name: itemName })
  if ((await row.count()) === 0) return
  page.once('dialog', (dialog) => void dialog.accept())
  await row.first().click()
  await form.getByRole('button', { name: '削除する' }).click()
  await expect(form.getByText(`「${word}」を消しました`)).toBeVisible()
}

test('TC-67-26: 木からノードを選ぶと変換後・ミドルに名前が入り、保存すると一覧に解決した先と一緒に出る(消して片付ける)', async ({
  page,
  baseURL,
}) => {
  await openScreen(page, baseURL!)
  const form = page.getByRole('form', { name: '言い換えのフォーム' })
  const list = page.getByRole('region', { name: '言い換えの一覧' })
  await removeLeftover(page, '言い換えのフォーム', '言い換えの一覧', 'E2E のドライ・ジン', 'E2E のドライ・ジン')

  await form.getByRole('textbox', { name: '変換前' }).fill('E2E のドライ・ジン')
  await form.getByRole('button', { name: 'ジンを開く' }).click()
  await form.getByRole('radio', { name: 'ドライジン' }).check()
  await expect(form.getByRole('textbox', { name: '変換後' })).toHaveValue('ジン')
  await expect(form.getByRole('textbox', { name: 'ミドル' })).toHaveValue('ドライジン')
  await form.getByRole('button', { name: '保存する' }).click()

  await expect(form.getByText('「E2E のドライ・ジン」を保存しました')).toBeVisible()
  const item = list.getByRole('listitem').filter({ hasText: 'E2E のドライ・ジン' })
  await expect(item).toHaveText('E2E のドライ・ジン → ジン 【ドライジン】蒸留酒 / ジン / ドライジン')

  page.once('dialog', (dialog) => void dialog.accept())
  await item.getByRole('button', { name: 'E2E のドライ・ジン' }).click()
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
  await removeLeftover(page, 'スキップ語のフォーム', 'スキップ語の一覧', 'E2E のお好みで', 'E2E のお好みで')

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

test.describe('足すときの冪等キー(#762)', () => {
  // 試行ごとに変わる名前にし(リトライで一意制約の 422 にならないよう)、後始末は失敗しても動く afterEach に置く(RC-18)
  const word = `E2E のスキップ語 retry ${Date.now()}`

  test.afterEach(async ({ page, baseURL }) => {
    await page.unrouteAll({ behavior: 'ignoreErrors' })
    await openScreen(page, baseURL!)
    await removeLeftover(page, 'スキップ語のフォーム', 'スキップ語の一覧', word, word)
  })

  test('TC-762-19: スキップ語を足す保存の応答が失われて保存し直しても、語は 1 件だけできる', async ({ page, baseURL }) => {
    await openScreen(page, baseURL!)
    const form = page.getByRole('form', { name: 'スキップ語のフォーム' })
    const list = page.getByRole('region', { name: 'スキップ語の一覧' })
    await form.getByRole('textbox', { name: 'スキップ語' }).fill(word)

    // 1 回目の送信は BE まで届けて保存させ、応答だけを失わせる
    const keys: (string | null)[] = []
    await page.route('**/api/admin/skip-words', async (route) => {
      if (route.request().method() !== 'POST') return route.continue()
      keys.push(await route.request().headerValue('Idempotency-Key'))
      if (keys.length > 1) return route.continue()
      await route.fetch()
      await route.abort('failed')
    })
    await form.getByRole('button', { name: '保存する' }).click()
    await expect(form.getByRole('alert')).toContainText('通信に失敗して保存できませんでした')

    await form.getByRole('button', { name: '保存する' }).click()
    await expect(form.getByText(`「${word}」を保存しました`)).toBeVisible()
    await page.unrouteAll()

    expect(keys).toHaveLength(2)
    expect(keys[0]).toMatch(/^[0-9a-f-]{36}$/)
    expect(keys[1]).toBe(keys[0])
    await expect(list.getByRole('button', { name: word, exact: true })).toHaveCount(1)
  })
})
