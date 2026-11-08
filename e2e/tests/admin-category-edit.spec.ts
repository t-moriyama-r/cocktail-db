import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test, type Page } from '@playwright/test'

// 管理画面のノードの新規・編集フォーム(#308)。相手は本物の管理 API(#121)と、E2eSeeder の木
// (蒸留酒 > ジン > ドライジン > …、蒸留酒 > ウォッカ、ソフトドリンク、その他 > ミント)。
// ほかの E2E が見ているノードは書き換えず、ここで「その他 > ミント」の下に作ったノードだけを書き換える。
// ミントは木の画面で閉じて出るので、子を足してもカテゴリ木の E2E(admin-category-tree.spec.ts)の数は変わらない。
// その他の部分木は有名な銘柄が任意(#155)なので、有名な銘柄を選ばずに保存できる(有名な銘柄は #309)。
// 名前検索の件数を変えないよう、名前に「ジン」「ミント」「ビーフィーター」を入れない。
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

/** 1x1 の PNG と GIF(画像の差し替えに使う。API は中身から画像の種類を確かめる) */
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64')
const GIF = Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64')

/** 新規の画面で、ミントの下にノードを作って保存し、カテゴリ木へ戻るまで進める。作ったノードの名前を返す */
async function createNode(page: Page, label: string, fill?: (page: Page) => Promise<void>): Promise<string> {
  // 何度流しても取り違えないよう、名前に時刻を入れる
  const stamp = Date.now()
  const name = `E2E のノード ${label} ${stamp}`
  await page.goto('/admin/categories')
  await page.getByRole('link', { name: '新規' }).click()
  await expect(page).toHaveURL(/\/admin\/categories\/new$/)
  await page.getByRole('list', { name: '親' }).getByRole('radio', { name: 'ミント', exact: true }).check()
  await expect(page.getByText('選んでいる親: その他 / ミント')).toBeVisible()
  await page.getByLabel('名前', { exact: true }).fill(name)
  // 英名が同じ親の下のほかのノードと同じだと、保存の後に警告が出るので、英名にも時刻を入れる
  await page.getByLabel('英名').fill(`E2E Node ${label} ${stamp}`)
  await page.getByLabel('説明').fill('E2E で作ったノード')
  await fill?.(page)
  await page.getByRole('button', { name: '送信' }).click()
  await expect(page).toHaveURL(/\/admin\/categories$/)
  // カテゴリ木の読み込み中の表示も status なので、知らせの文で選ぶ
  await expect(page.getByRole('status').filter({ hasText: '保存しました' })).toHaveText(`「${name}」を保存しました`)
  return name
}

/** カテゴリ木の画面で、ミントの下のノードを選んで編集フォームを開く */
async function openNode(page: Page, name: string) {
  await page.goto('/admin/categories')
  await page.getByRole('button', { name: 'ミントを開く', exact: true }).click()
  // 選ぶとすぐ編集フォームへ移り、木の印は残らないので、check ではなく click にする
  await page.getByRole('list', { name: 'カテゴリ木' }).getByRole('radio', { name, exact: true }).click()
  await expect(page).toHaveURL(/\/admin\/categories\/\d+$/)
  await expect(page.getByLabel('名前', { exact: true })).toHaveValue(name)
}

const nodeImage = (page: Page) => page.getByRole('img', { name: 'ノードの画像' })

test('TC-308-21: カテゴリ木の画面で深いノードを選ぶと、そのノードの編集フォームが開く', async ({ page, baseURL }) => {
  await login(page, baseURL!)
  await page.goto('/admin/categories')

  await page.getByRole('button', { name: 'ジンを開く', exact: true }).click()
  await page.getByRole('radio', { name: 'ドライジン', exact: true }).click()

  await expect(page).toHaveURL(/\/admin\/categories\/\d+$/)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('ノードの編集')
  await expect(page.getByText('蒸留酒 / ジン', { exact: true })).toBeVisible()
  await expect(page.getByLabel('名前', { exact: true })).toHaveValue('ドライジン')
  await expect(page.getByRole('region', { name: '登録されている子のノードの一覧' }).getByRole('link')).toHaveText([
    'ロンドンドライジン(ロンドンドライジン)',
  ])
})

test('TC-308-22: 全項目を入れて新規で保存し、開き直すと同じ値が出る(開始位置の秒は境界の 59)', async ({ page, baseURL }) => {
  await login(page, baseURL!)
  const name = await createNode(page, 'all', async (page) => {
    await page.getByRole('checkbox', { name: 'メジャー' }).check()
    await page.getByRole('checkbox', { name: 'お気に入り' }).check()
    await page.getByRole('checkbox', { name: '粉もの' }).check()
    await page.getByLabel('YouTube').fill('https://www.youtube.com/watch?v=abcdefghijk')
    await page.getByLabel('時').fill('0')
    await page.getByLabel('分').fill('1')
    await page.getByLabel('秒').fill('59')
    await page.getByLabel('リンクテキスト').fill('E2E のリンク')
    await page.getByLabel('リンクURL').fill('https://example.com/e2e')
  })

  await openNode(page, name)

  await expect(page.getByText('その他 / ミント', { exact: true })).toBeVisible()
  await expect(page.getByLabel('英名')).toHaveValue(/^E2E Node all \d+$/)
  await expect(page.getByLabel('説明')).toHaveValue('E2E で作ったノード')
  for (const label of ['メジャー', 'お気に入り', '粉もの']) {
    await expect(page.getByRole('checkbox', { name: label })).toBeChecked()
  }
  // YouTube は動画 ID にして保存する
  await expect(page.getByLabel('YouTube')).toHaveValue('abcdefghijk')
  await expect(page.getByLabel('時')).toHaveValue('0')
  await expect(page.getByLabel('分')).toHaveValue('1')
  await expect(page.getByLabel('秒')).toHaveValue('59')
  await expect(page.getByLabel('リンクテキスト')).toHaveValue('E2E のリンク')
  await expect(page.getByLabel('リンクURL')).toHaveValue('https://example.com/e2e')

  // チェックを外して保存し直すと、外れたまま出る
  await page.getByRole('checkbox', { name: '粉もの' }).uncheck()
  await page.getByRole('button', { name: '送信' }).click()
  await expect(page).toHaveURL(/\/admin\/categories$/)
  await openNode(page, name)
  await expect(page.getByRole('checkbox', { name: '粉もの' })).not.toBeChecked()
})

test('TC-308-23: 開始位置の秒に 60 を入れて送ると、秒の欄の下にメッセージが出て、画面を移らない', async ({ page, baseURL }) => {
  await login(page, baseURL!)
  const name = await createNode(page, 'sec')
  await openNode(page, name)
  const editUrl = page.url()

  await page.getByLabel('YouTube').fill('abcdefghijk')
  await page.getByLabel('秒').fill('60')
  await page.getByRole('button', { name: '送信' }).click()

  await expect(page.getByRole('alert')).toContainText('保存できませんでした。入力を確かめてください。')
  const second = page.getByLabel('秒')
  await expect(second).toHaveAttribute('aria-invalid', 'true')
  await expect(second).toHaveAccessibleDescription(/\S/)
  await expect(second).toHaveValue('60')
  expect(page.url()).toBe(editUrl)
})

// 名前・英名の重なりの知らせ(#674)。相手は本物の重なりの問い合わせ(#673)と保存の API

const DUPLICATE = '同じ名前が存在しています'

test('TC-674-14: 新規で親を選び、その親の下に既にある名前・英名を入れて欄を離れると、それぞれの欄の下に「同じ名前が存在しています」が出て、直すと消える', async ({
  page,
  baseURL,
}) => {
  await login(page, baseURL!)
  await page.goto('/admin/categories/new')
  await page.getByRole('list', { name: '親' }).getByRole('radio', { name: '蒸留酒', exact: true }).check()

  // E2eSeeder の蒸留酒の子の「ウォッカ」「ジン」(英名も同じ文字)。保存はしないので、ほかの E2E のデータは変わらない
  const name = page.getByLabel('名前', { exact: true })
  const nameEng = page.getByLabel('英名')
  await name.fill('ウォッカ')
  await name.blur()
  await nameEng.fill('ジン')
  await nameEng.blur()

  await expect(name).toHaveAccessibleDescription(DUPLICATE)
  await expect(nameEng).toHaveAccessibleDescription(DUPLICATE)
  await expect(name).toHaveAttribute('aria-invalid', 'false')

  await name.fill('E2E の重ならないノード')
  await name.blur()
  await expect(name).not.toHaveAccessibleDescription(DUPLICATE)
  await expect(nameEng).toHaveAccessibleDescription(DUPLICATE)
})

test('TC-674-15: 同じ親の下に既にある英名を入れて知らせが出ても保存でき、カテゴリ木の知らせに英名の警告が出る', async ({ page, baseURL }) => {
  await login(page, baseURL!)
  // ミントの下に、英名の重なる相手を先に作る
  const nameEng = `E2E Node dup ${Date.now()}`
  await createNode(page, 'dup-first', async (page) => {
    await page.getByLabel('英名').fill(nameEng)
  })

  await page.goto('/admin/categories/new')
  await page.getByRole('list', { name: '親' }).getByRole('radio', { name: 'ミント', exact: true }).check()
  const name = `E2E のノード dup-second ${Date.now()}`
  await page.getByLabel('名前', { exact: true }).fill(name)
  const eng = page.getByLabel('英名')
  await eng.fill(nameEng)
  await eng.blur()
  await expect(eng).toHaveAccessibleDescription(DUPLICATE)
  await page.getByLabel('説明').fill('E2E で作ったノード')
  await page.getByRole('button', { name: '送信' }).click()

  await expect(page).toHaveURL(/\/admin\/categories$/)
  await expect(page.getByRole('status').filter({ hasText: '保存しました' })).toHaveText(`「${name}」を保存しました。英名: ${DUPLICATE}`)
})

test('TC-308-24: 画像を上げて、差し替えて、消せる', async ({ page, baseURL }) => {
  await login(page, baseURL!)
  const name = await createNode(page, 'image', async (page) => {
    await page.getByLabel('画像', { exact: true }).setInputFiles({ name: 'first.png', mimeType: 'image/png', buffer: PNG })
    await expect(nodeImage(page)).toHaveAttribute('src', /^data:image\/png;base64,/)
  })

  // 上げた画像が出る
  await openNode(page, name)
  await expect(nodeImage(page)).toHaveAttribute('src', /^\/storage\/.+\.png$/)
  await expect(nodeImage(page)).toHaveJSProperty('naturalWidth', 1)

  // 差し替える
  await page.getByLabel('画像', { exact: true }).setInputFiles({ name: 'second.gif', mimeType: 'image/gif', buffer: GIF })
  await page.getByRole('button', { name: '送信' }).click()
  await expect(page).toHaveURL(/\/admin\/categories$/)
  await openNode(page, name)
  await expect(nodeImage(page)).toHaveAttribute('src', /^\/storage\/.+\.gif$/)

  // 消す
  await page.getByRole('checkbox', { name: '画像を消す' }).check()
  await expect(nodeImage(page)).toHaveCount(0)
  await page.getByRole('button', { name: '送信' }).click()
  await expect(page).toHaveURL(/\/admin\/categories$/)
  await openNode(page, name)
  await expect(nodeImage(page)).toHaveCount(0)
  await expect(page.getByRole('checkbox', { name: '画像を消す' })).toHaveCount(0)
})

// 親の付け替えと削除(#125)。ここでもミントの下に作ったノードだけを移す・消す。
// 根をまたぐ付け替え(役割が変わる)は、ほかの E2E が見ている根の下を変えないよう、Vitest(TC-125-03)で確かめる

/** 開いている編集画面のノードの ID(URL の末尾) */
const nodeIdOf = (page: Page): number => Number(new URL(page.url()).pathname.split('/').pop())

const publicBreadcrumb = (page: Page) => page.getByRole('navigation', { name: 'パンくず' })

test('TC-125-13: ノードを別の親の下へ移すと、確かめてから移し、公開側のパンくずが新しい親までになる', async ({ page, baseURL }) => {
  await login(page, baseURL!)
  const parentName = await createNode(page, 'move-parent')
  const name = await createNode(page, 'move-child')
  await openNode(page, name)
  const id = nodeIdOf(page)

  await page.goto(`/category/${id}`)
  await expect(publicBreadcrumb(page)).toHaveText('その他 / ミント')

  await page.goto(`/admin/categories/${id}`)
  const move = page.getByRole('region', { name: '親の付け替え' })
  await expect(move.getByText('今の親: その他 / ミント', { exact: true })).toBeVisible()
  await move.getByRole('button', { name: '移す先を選ぶ' }).click()
  const tree = move.getByRole('list', { name: '移す先' })
  await tree.getByRole('button', { name: 'ミントを開く', exact: true }).click()
  await tree.getByRole('radio', { name: parentName, exact: true }).check()
  await expect(move.getByText(`移す先: その他 / ミント / ${parentName}`, { exact: true })).toBeVisible()
  // 同じ根(その他)の中なので、役割は変わらない
  await expect(move.getByText(/役割が/)).toHaveCount(0)

  const dialog = page.waitForEvent('dialog')
  const clicked = move.getByRole('button', { name: '移す', exact: true }).click()
  const confirm = await dialog
  expect(confirm.message()).toBe(`「${name}」を「その他 / ミント / ${parentName}」の下へ移します。よろしいですか?`)
  await confirm.accept()
  await clicked

  await expect(page).toHaveURL(/\/admin\/categories$/)
  await expect(page.getByRole('status').filter({ hasText: '移しました' })).toHaveText(
    `「${name}」を「その他 / ミント / ${parentName}」の下へ移しました`,
  )
  await page.goto(`/category/${id}`)
  await expect(publicBreadcrumb(page)).toHaveText(`その他 / ミント / ${parentName}`)
})

test('TC-125-14: 消せないノードを開くと、消せない理由が出て、消すボタンを押せない', async ({ page, baseURL }) => {
  await login(page, baseURL!)
  await page.goto('/admin/categories')
  await page.getByRole('list', { name: 'カテゴリ木' }).getByRole('radio', { name: 'ジン', exact: true }).click()
  await expect(page.getByLabel('名前', { exact: true })).toHaveValue('ジン')

  const deletion = page.getByRole('region', { name: 'ノードの削除' })
  await expect(deletion.getByText('このノードは消せません', { exact: true })).toBeVisible()
  // ジンは子(ドライジン)を持ち、カクテルのベースに使われている(E2eSeeder)
  await expect(deletion.getByRole('listitem').filter({ hasText: '子のノードがあります' })).toHaveCount(1)
  await expect(deletion.getByRole('listitem').filter({ hasText: 'カクテルのベースに使われています' })).toHaveCount(1)
  await expect(deletion.getByRole('button', { name: 'このノードを消す' })).toBeDisabled()
})

test('TC-125-15: 消せるノードは、確かめてから消し、カテゴリ木に消したことが出て、公開側でも見つからなくなる', async ({ page, baseURL }) => {
  await login(page, baseURL!)
  const name = await createNode(page, 'delete')
  await openNode(page, name)
  const id = nodeIdOf(page)

  const deletion = page.getByRole('region', { name: 'ノードの削除' })
  await expect(deletion.getByText('消すと元に戻せません。', { exact: true })).toBeVisible()
  const dialog = page.waitForEvent('dialog')
  const clicked = deletion.getByRole('button', { name: 'このノードを消す' }).click()
  const confirm = await dialog
  expect(confirm.message()).toBe(`「${name}」を消します。よろしいですか?`)
  await confirm.accept()
  await clicked

  await expect(page).toHaveURL(/\/admin\/categories$/)
  await expect(page.getByRole('status').filter({ hasText: '消しました' })).toHaveText(`「${name}」を消しました`)
  await page.goto(`/category/${id}`)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('ページが見つかりません')
})
