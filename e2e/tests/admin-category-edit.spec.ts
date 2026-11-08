import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test, type Page } from '@playwright/test'

// 管理画面のノードの新規・編集フォーム(#308)。相手は本物の管理 API(#121)と、E2eSeeder の木
// (蒸留酒 > ジン > ドライジン > …、蒸留酒 > ウォッカ、ソフトドリンク、その他 > ミント)。
// ほかの E2E が見ているノードは書き換えず、ここで「その他 > ミント」の下に作ったノードだけを書き換える。
// ミントは木の画面で閉じて出るので、子を足してもカテゴリ木の E2E(admin-category-tree.spec.ts)の数は変わらない。
// その他の部分木は有名な銘柄が任意(#155)なので、有名な銘柄を選ばずに保存できる。
// 有名な銘柄の欄(#309)は、その場で作る銘柄の欄の名前が「有名な銘柄の英名」のようにノードの欄の名前を含むので、ノードの欄は exact で選ぶ。
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
  await page.getByLabel('英名', { exact: true }).fill(`E2E Node ${label} ${stamp}`)
  await page.getByLabel('説明', { exact: true }).fill('E2E で作ったノード')
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
    await page.getByRole('checkbox', { name: 'メジャー', exact: true }).check()
    await page.getByRole('checkbox', { name: 'お気に入り', exact: true }).check()
    await page.getByRole('checkbox', { name: '粉もの', exact: true }).check()
    await page.getByLabel('YouTube', { exact: true }).fill('https://www.youtube.com/watch?v=abcdefghijk')
    await page.getByLabel('時', { exact: true }).fill('0')
    await page.getByLabel('分', { exact: true }).fill('1')
    await page.getByLabel('秒', { exact: true }).fill('59')
    await page.getByLabel('リンクテキスト', { exact: true }).fill('E2E のリンク')
    await page.getByLabel('リンクURL', { exact: true }).fill('https://example.com/e2e')
  })

  await openNode(page, name)

  await expect(page.getByText('その他 / ミント', { exact: true })).toBeVisible()
  await expect(page.getByLabel('英名', { exact: true })).toHaveValue(/^E2E Node all \d+$/)
  await expect(page.getByLabel('説明', { exact: true })).toHaveValue('E2E で作ったノード')
  for (const label of ['メジャー', 'お気に入り', '粉もの']) {
    await expect(page.getByRole('checkbox', { name: label, exact: true })).toBeChecked()
  }
  // YouTube は動画 ID にして保存する
  await expect(page.getByLabel('YouTube', { exact: true })).toHaveValue('abcdefghijk')
  await expect(page.getByLabel('時', { exact: true })).toHaveValue('0')
  await expect(page.getByLabel('分', { exact: true })).toHaveValue('1')
  await expect(page.getByLabel('秒', { exact: true })).toHaveValue('59')
  await expect(page.getByLabel('リンクテキスト', { exact: true })).toHaveValue('E2E のリンク')
  await expect(page.getByLabel('リンクURL', { exact: true })).toHaveValue('https://example.com/e2e')

  // チェックを外して保存し直すと、外れたまま出る
  await page.getByRole('checkbox', { name: '粉もの', exact: true }).uncheck()
  await page.getByRole('button', { name: '送信' }).click()
  await expect(page).toHaveURL(/\/admin\/categories$/)
  await openNode(page, name)
  await expect(page.getByRole('checkbox', { name: '粉もの', exact: true })).not.toBeChecked()
})

test('TC-308-23: 開始位置の秒に 60 を入れて送ると、秒の欄の下にメッセージが出て、画面を移らない', async ({ page, baseURL }) => {
  await login(page, baseURL!)
  const name = await createNode(page, 'sec')
  await openNode(page, name)
  const editUrl = page.url()

  await page.getByLabel('YouTube', { exact: true }).fill('abcdefghijk')
  await page.getByLabel('秒', { exact: true }).fill('60')
  await page.getByRole('button', { name: '送信' }).click()

  await expect(page.getByRole('alert')).toContainText('保存できませんでした。入力を確かめてください。')
  const second = page.getByLabel('秒', { exact: true })
  await expect(second).toHaveAttribute('aria-invalid', 'true')
  await expect(second).toHaveAccessibleDescription(/\S/)
  await expect(second).toHaveValue('60')
  expect(page.url()).toBe(editUrl)
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

// 有名な銘柄の選択と、その場での作成(#309)。必須かどうかは、本物の保存の API(#121)が根の役割で決める(#155・#158)。
// 洋酒の部分木(蒸留酒の下)では保存が 422 になるところまでだけを見て、ノードを作らない(ほかの E2E が見ている木を変えない)。
// ソフトドリンクの下にノードを作ると、カテゴリ木の E2E(TC-123-11)の数が変わるので、ソフトドリンクで空でよいことは API の TC-121-13 で見る

const famousBrand = (page: Page) => page.getByRole('group', { name: '有名な銘柄' })
const brandField = (page: Page, name: string) => page.getByRole('textbox', { name: `有名な銘柄の${name}`, exact: true })
const requiredMessage = (name: string) => `有名な銘柄が無いときは、有名な銘柄の${name}を入力してください。`

/** 新規の画面を開いて親を選び、ノードの必須の欄を入れる(送信はしない)。入れたノードの名前を返す */
async function fillNewNode(page: Page, parent: string, label: string): Promise<string> {
  const stamp = Date.now()
  const name = `E2E のノード ${label} ${stamp}`
  await page.goto('/admin/categories/new')
  await page.getByRole('list', { name: '親' }).getByRole('radio', { name: parent, exact: true }).check()
  await page.getByLabel('名前', { exact: true }).fill(name)
  await page.getByLabel('英名', { exact: true }).fill(`E2E Node ${label} ${stamp}`)
  await page.getByLabel('説明', { exact: true }).fill('E2E で作ったノード')
  return name
}

test('TC-309-11: 洋酒の部分木で、有名な銘柄を選ばず作らずに送ると、銘柄の名前・英名・度数・説明の欄の下にメッセージが出て、画面を移らない', async ({
  page,
  baseURL,
}) => {
  await login(page, baseURL!)
  await fillNewNode(page, '蒸留酒', 'liquor')
  await expect(famousBrand(page).getByText('-有名な銘柄を入力して下さい-', { exact: true })).toBeVisible()

  await page.getByRole('button', { name: '送信' }).click()

  await expect(page.getByRole('alert')).toContainText('保存できませんでした。入力を確かめてください。')
  for (const name of ['名前', '英名', '度数', '説明']) {
    const field = brandField(page, name === '度数' ? '度数(%)' : name)
    await expect(field).toHaveAttribute('aria-invalid', 'true')
    await expect(field).toHaveAccessibleDescription(requiredMessage(name))
  }
  await expect(page).toHaveURL(/\/admin\/categories\/new$/)
})

test('TC-309-12: その他の部分木では、有名な銘柄を空のまま保存でき、開き直しても有名な銘柄は無いまま', async ({ page, baseURL }) => {
  await login(page, baseURL!)
  const name = await createNode(page, 'no-brand')

  await openNode(page, name)

  await expect(famousBrand(page).getByText('-有名な銘柄を入力して下さい-', { exact: true })).toBeVisible()
  await expect(page.getByRole('region', { name: '登録されている銘柄の一覧' })).toHaveText(/銘柄はありません/)
})

test('TC-309-13: その他の部分木で、銘柄の名前だけを入れて送ると、残りの英名・度数・説明の欄の下にメッセージが出て、名前には出ない', async ({
  page,
  baseURL,
}) => {
  await login(page, baseURL!)
  await fillNewNode(page, 'ミント', 'name-only')
  await brandField(page, '名前').fill(`E2E の銘柄 name-only ${Date.now()}`)

  await page.getByRole('button', { name: '送信' }).click()

  await expect(page.getByRole('alert')).toContainText('保存できませんでした。入力を確かめてください。')
  for (const name of ['英名', '度数', '説明']) {
    await expect(brandField(page, name === '度数' ? '度数(%)' : name)).toHaveAccessibleDescription(requiredMessage(name))
  }
  await expect(brandField(page, '名前')).toHaveAttribute('aria-invalid', 'false')
  await expect(page).toHaveURL(/\/admin\/categories\/new$/)
})

test('TC-309-14: その場で作った銘柄が、ノードの直下の銘柄と有名な銘柄に入り、YouTube・リンクは銘柄の欄の値で保存される', async ({ page, baseURL }) => {
  await login(page, baseURL!)
  const brandName = `E2E の銘柄 famous ${Date.now()}`
  const name = await createNode(page, 'famous', async (page) => {
    // ノードの YouTube・リンクは、銘柄とは別の値にする(旧はノードの YouTube を銘柄に入れ、リンクを保存しなかった)
    await page.getByLabel('YouTube', { exact: true }).fill('nodevideo01')
    await page.getByLabel('リンクテキスト', { exact: true }).fill('E2E のノードのリンク')
    await brandField(page, '名前').fill(brandName)
    await brandField(page, '英名').fill('E2E Famous Brand')
    await brandField(page, '度数(%)').fill('12.5')
    await brandField(page, '説明').fill('E2E で作った有名な銘柄')
    await brandField(page, 'YouTube').fill('brandvideo1')
    await brandField(page, 'リンクテキスト').fill('E2E の銘柄のリンク')
    await brandField(page, 'リンクURL').fill('https://example.com/e2e-brand')
  })

  await openNode(page, name)

  const brandLabel = `${brandName}(E2E Famous Brand)`
  // 有名な銘柄の選択に、作った銘柄が選ばれて出る(候補は作った銘柄だけ)
  const select = famousBrand(page).getByRole('combobox', { name: '有名な銘柄', exact: true })
  await expect(select.getByRole('option')).toHaveText(['(新しく作成する)', brandLabel])
  await expect(select.locator('option:checked')).toHaveText(brandLabel)
  const brands = page.getByRole('region', { name: '登録されている銘柄の一覧' })
  await expect(brands.getByRole('link')).toHaveText([brandLabel])

  // 銘柄の編集画面で、銘柄の欄の値で保存されたことを見る
  await brands.getByRole('link', { name: brandLabel }).click()
  await expect(page).toHaveURL(/\/admin\/brands\/\d+$/)
  await expect(page.getByLabel('名前', { exact: true })).toHaveValue(brandName)
  await expect(page.getByLabel('度数(%)', { exact: true })).toHaveValue('12.5')
  await expect(page.getByLabel('YouTube', { exact: true })).toHaveValue('brandvideo1')
  await expect(page.getByLabel('リンクテキスト', { exact: true })).toHaveValue('E2E の銘柄のリンク')
  await expect(page.getByLabel('リンクURL', { exact: true })).toHaveValue('https://example.com/e2e-brand')
})
