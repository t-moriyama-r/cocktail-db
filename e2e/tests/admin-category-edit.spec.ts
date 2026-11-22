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
  const nameEng = page.getByLabel('英名', { exact: true })
  await name.fill('ウォッカ')
  await name.blur()
  await nameEng.fill('ジン')
  await nameEng.blur()

  await expect(name).toHaveAccessibleDescription(DUPLICATE)
  await expect(nameEng).toHaveAccessibleDescription(DUPLICATE)
  await expect(name).toHaveAttribute('aria-invalid', 'false')
  // 説明の文が CSS で隠れていないこと(欄の説明だけでは見えているかが分からない)
  const notices = page.getByText(DUPLICATE, { exact: true })
  await expect(notices).toHaveCount(2)
  await expect(notices.nth(0)).toBeVisible()
  await expect(notices.nth(1)).toBeVisible()

  await name.fill('E2E の重ならないノード')
  await name.blur()
  await expect(name).not.toHaveAccessibleDescription(DUPLICATE)
  await expect(nameEng).toHaveAccessibleDescription(DUPLICATE)
  await expect(notices).toHaveCount(1)
  await expect(notices.first()).toBeVisible()
})

test('TC-674-15: 同じ親の下に既にある英名を入れて知らせが出ても保存でき、カテゴリ木の知らせに英名の警告が出る', async ({ page, baseURL }) => {
  await login(page, baseURL!)
  // ミントの下に、英名の重なる相手を先に作る
  const nameEng = `E2E Node dup ${Date.now()}`
  await createNode(page, 'dup-first', async (page) => {
    await page.getByLabel('英名', { exact: true }).fill(nameEng)
  })

  await page.goto('/admin/categories/new')
  await page.getByRole('list', { name: '親' }).getByRole('radio', { name: 'ミント', exact: true }).check()
  const name = `E2E のノード dup-second ${Date.now()}`
  await page.getByLabel('名前', { exact: true }).fill(name)
  const eng = page.getByLabel('英名', { exact: true })
  await eng.fill(nameEng)
  await eng.blur()
  await expect(eng).toHaveAccessibleDescription(DUPLICATE)
  await page.getByLabel('説明', { exact: true }).fill('E2E で作ったノード')
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

// その場で作る銘柄の名前・英名の重なりの知らせ(#723)。相手は本物の銘柄の重なりの問い合わせ(#632)。保存はしないので、ほかの E2E のデータは変わらない

test('TC-723-09: ノードの新規の画面で、既にある銘柄の名前・英名を、その場で作る銘柄の欄に入れて離れると、それぞれの欄の下に知らせが出て、直すと消える', async ({
  page,
  baseURL,
}) => {
  await login(page, baseURL!)
  // ミントの下のノード(銘柄はその他の部分木に作る)。E2eSeeder の「スピリタス」(Spirytus)は蒸留酒の下の銘柄で、所属によらず銘柄全体で比べる
  await fillNewNode(page, 'ミント', 'brand-dup')
  const name = brandField(page, '名前')
  const nameEng = brandField(page, '英名')
  await name.fill('スピリタス')
  await name.blur()
  await nameEng.fill('Spirytus')
  await nameEng.blur()

  await expect(name).toHaveAccessibleDescription(DUPLICATE)
  await expect(nameEng).toHaveAccessibleDescription(DUPLICATE)
  await expect(name).toHaveAttribute('aria-invalid', 'false')
  // 説明の文が CSS で隠れていないこと。ノードの欄には出ない
  const notices = famousBrand(page).getByText(DUPLICATE, { exact: true })
  await expect(notices).toHaveCount(2)
  await expect(notices.nth(0)).toBeVisible()
  await expect(notices.nth(1)).toBeVisible()
  await expect(page.getByText(DUPLICATE, { exact: true })).toHaveCount(2)

  await name.fill(`E2E の重ならない銘柄 ${Date.now()}`)
  await name.blur()
  await expect(name).not.toHaveAccessibleDescription(DUPLICATE)
  await expect(nameEng).toHaveAccessibleDescription(DUPLICATE)
  await expect(notices).toHaveCount(1)
})

// 親の付け替えと削除(#125)。ここでもミントの下に作ったノードだけを移す・消す。
// 根をまたぐ付け替え(役割が変わる)は、ほかの E2E が見ている根の下を変えないよう、Vitest(TC-125-03)で確かめる

/** 開いている編集画面のノードの ID(URL の末尾) */
const nodeIdOf = (page: Page): number => Number(new URL(page.url()).pathname.split('/').pop())

const publicBreadcrumb = (page: Page) => page.getByRole('navigation', { name: 'パンくず' })

test('TC-125-13: ノードを別の親の下へ移すと、確かめてから移し、カテゴリ木・管理の編集画面・公開側のパンくずで新しい親の下に出る', async ({ page, baseURL }) => {
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
  // カテゴリ木の画面(移した後に戻った画面)で新しい親を開くと、移したノードがその下に出る。
  // 木は根だけを開いて出るので、ミントと新しい親を順に開く。新しい親を開く前は出ない(ミントの直下には無い)
  const adminTree = page.getByRole('list', { name: 'カテゴリ木' })
  await adminTree.getByRole('button', { name: 'ミントを開く', exact: true }).click()
  await expect(adminTree.getByRole('radio', { name: parentName, exact: true })).toBeVisible()
  await expect(adminTree.getByRole('radio', { name, exact: true })).toHaveCount(0)
  await adminTree.getByRole('button', { name: `${parentName}を開く`, exact: true }).click()
  await expect(adminTree.getByRole('radio', { name, exact: true })).toBeVisible()

  // 管理の編集画面を開き直すと、フォームの親の表示と付け替えの欄の今の親が、新しい親までになる
  await page.goto(`/admin/categories/${id}`)
  await expect(page.getByLabel('名前', { exact: true })).toHaveValue(name)
  await expect(page.getByText(`その他 / ミント / ${parentName}`, { exact: true })).toBeVisible()
  await expect(move.getByText(`今の親: その他 / ミント / ${parentName}`, { exact: true })).toBeVisible()

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
