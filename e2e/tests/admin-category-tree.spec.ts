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

// 根の作成と役割(#310)。作った根は、ほかの E2E の数(TC-123-11 の根の数など)を変えないよう、テストの終わりに消す。
// 根の数を数える TC-123-11 と並んで流れないよう、同じファイルの後ろに置く(ファイルの中のテストは上から順に流れる)。
// 名前検索の件数を変えないよう、名前に「ジン」「ミント」「ビーフィーター」を入れない

const tree = (page: Page) => page.getByRole('list', { name: 'カテゴリ木' })
const roleGroup = (page: Page) => page.getByRole('group', { name: '役割' })

/** 英名。名前の「E2E の」を「E2E 」にしたもの(時刻を含むので、同じ親の下のほかのノードと重ならない) */
const englishName = (name: string) => name.replace('E2E の', 'E2E ')

/** 新規の画面でノードを作って保存し、カテゴリ木へ戻るまで進める。parent が null なら「親なし」で根を作る */
async function createNode(page: Page, name: string, parent: string | null, role?: string) {
  await page.goto('/admin/categories')
  await page.getByRole('link', { name: '新規' }).click()
  await expect(page).toHaveURL(/\/admin\/categories\/new$/)
  if (parent === null) {
    await page.getByRole('checkbox', { name: '親なし(根のノードを作る)' }).check()
    await expect(page.getByRole('list', { name: '親' })).toHaveCount(0)
    if (role !== undefined) {
      await roleGroup(page).getByRole('radio', { name: role, exact: true }).check()
    }
  } else {
    await expect(roleGroup(page)).toHaveCount(0)
    await page.getByRole('list', { name: '親' }).getByRole('radio', { name: parent, exact: true }).check()
  }
  await page.getByLabel('名前', { exact: true }).fill(name)
  await page.getByLabel('英名', { exact: true }).fill(englishName(name))
  await page.getByLabel('説明').fill('E2E で作ったノード')
  await page.getByRole('button', { name: '送信' }).click()
  await expect(page).toHaveURL(/\/admin\/categories$/)
  await expect(page.getByRole('status').filter({ hasText: '保存しました' })).toHaveText(`「${name}」を保存しました`)
}

/** カテゴリ木の画面で、根か根の子を選んで編集フォームを開く(木は根を開いた形で出る) */
async function openNode(page: Page, name: string) {
  await page.goto('/admin/categories')
  await tree(page).getByRole('radio', { name, exact: true }).click()
  await expect(page).toHaveURL(/\/admin\/categories\/\d+$/)
  await expect(page.getByLabel('名前', { exact: true })).toHaveValue(name)
}

/** 根の役割を選び直して保存し、カテゴリ木へ戻るまで進める */
async function changeRole(page: Page, rootName: string, role: string) {
  await openNode(page, rootName)
  await roleGroup(page).getByRole('radio', { name: role, exact: true }).check()
  await page.getByRole('button', { name: '送信' }).click()
  await expect(page).toHaveURL(/\/admin\/categories$/)
}

/**
 * このファイルで作ったノードの名前(子を先に並べる)。テストが途中で落ちても残さないよう、最後(afterAll)にまとめて消す。
 * afterAll はテストと別の時間の枠で動くので、テストが時間切れで終わっても片付けが走る
 */
const created: string[] = []

/** 画面と同じく CSRF のトークンを付けて、名前で探したノードを並びの順に消す(子を先に並べる) */
async function deleteNodes(page: Page, names: readonly string[]) {
  await page.goto('/admin/categories')
  const statuses = await page.evaluate(async (targets) => {
    type Item = { id: number; name: string; children: Item[] }
    const token = document.cookie
      .split('; ')
      .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
      ?.slice('XSRF-TOKEN='.length)
    const headers = { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(token ?? '') }
    const { data } = (await (await fetch('/api/admin/categories', { headers })).json()) as { data: Item[] }
    const ids = new Map<string, number>()
    const walk = (items: Item[]) => {
      for (const item of items) {
        ids.set(item.name, item.id)
        walk(item.children)
      }
    }
    walk(data)
    const result: (number | null)[] = []
    for (const name of targets) {
      const id = ids.get(name)
      result.push(id === undefined ? null : (await fetch(`/api/admin/categories/${id}`, { method: 'DELETE', headers })).status)
    }
    return result
  }, names)
  // 作る前に落ちたノード(null)は消すものが無い
  for (const status of statuses) {
    expect([204, null]).toContain(status)
  }
}

test.afterAll(async ({ browser, baseURL }) => {
  if (created.length === 0) return
  const page = await browser.newPage({ baseURL })
  await openCategoryTree(page, baseURL!)
  await deleteNodes(page, created)
  await page.close()
})

test('TC-310-11: 「新規」から「親なし」で根を作って役割を選べ、木に役割が出て、開き直すと選んだ役割が入っている', async ({ page, baseURL }) => {
  await openCategoryTree(page, baseURL!)
  const name = `E2E の根 ${Date.now()}`
  created.push(name)
  await createNode(page, name, null, 'その他')

  await expect(tree(page).getByRole('radio', { name, exact: true })).toBeVisible()
  await expect(tree(page).getByText(`(${englishName(name)})・役割: その他・メジャーでない`, { exact: true })).toBeVisible()

  await openNode(page, name)
  await expect(page.getByText('(根のノード)', { exact: true })).toBeVisible()
  await expect(roleGroup(page).getByRole('radio', { name: 'その他', exact: true })).toBeChecked()
})

test('TC-310-12: 根以外の編集では役割の欄が出ず、根の役割を変えると、配下のノードの有名な銘柄が必須・任意で切り替わる', async ({ page, baseURL }) => {
  await openCategoryTree(page, baseURL!)
  const stamp = Date.now()
  const rootName = `E2E の切り替えの根 ${stamp}`
  const childName = `E2E の切り替えの子 ${stamp}`
  created.unshift(childName, rootName)
  // ソフトドリンクの根の下では、有名な銘柄が任意なので、選ばずに子を作れる(有名な銘柄の欄は #309)
  await createNode(page, rootName, null, 'ソフトドリンク')
  await createNode(page, childName, rootName)

  // 根以外の編集では、役割の欄が出ない
  await openNode(page, childName)
  await expect(roleGroup(page)).toHaveCount(0)

  // 根の役割を「なし」にすると、配下のノードは有名な銘柄が必須になり、選ばずには保存できない
  await changeRole(page, rootName, 'なし')
  await expect(tree(page).getByText(`(${englishName(rootName)})・役割: なし・メジャーでない`, { exact: true })).toBeVisible()
  await openNode(page, childName)
  const editUrl = page.url()
  await page.getByRole('button', { name: '送信' }).click()
  await expect(page.getByRole('alert')).toContainText('有名な銘柄の名前')
  expect(page.url()).toBe(editUrl)

  // 「その他」に変えると、また任意になり、選ばずに保存できる
  await changeRole(page, rootName, 'その他')
  await openNode(page, childName)
  await page.getByRole('button', { name: '送信' }).click()
  await expect(page).toHaveURL(/\/admin\/categories$/)
  await expect(page.getByRole('status').filter({ hasText: '保存しました' })).toHaveText(`「${childName}」を保存しました`)
})
