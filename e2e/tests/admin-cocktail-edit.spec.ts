import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test, type Page } from '@playwright/test'

// 管理画面のカクテルの新規・編集(#127・#128)。相手は本物の管理 API(#291・#292・#129)と、E2eSeeder のカクテル。
// 既にあるカクテルの保存は、材料を 2 件持つカクテルで試す(E2eSeeder の seedAdminCocktailEditData): 公開の「カミカゼ」と、
// 下書きの「E2E の下書き」。どちらも保存しても公開・下書きは変わらないので、登録数(ヘッダーの 8)など、ほかの E2E が見ている数は変わらない。
// 新規の画面で材料の行を入れて保存するテスト(#128)は、試行ごとに名前の変わるカクテルを作り、テストごとに消す(RC-18)。
// 公開で作ったカクテルは、消すまでの数秒だけ登録数などに数えられる。同時に流れる別のファイルの E2E がその数を見ないよう、
// 材料はウォッカ・ミントにし、このファイルの最初の方で流す(登録数・材料の数を見る E2E は、名前の順で後ろのファイルにある)。
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

/** 「下書きとして保存」を押し、新規の保存の API の応答(ここでは 422)が返るまで待つ */
async function saveDraftAndWait(page: Page) {
  const response = page.waitForResponse(
    (r) => new URL(r.url()).pathname === '/api/admin/cocktails' && r.request().method() === 'POST',
  )
  await page.getByRole('button', { name: '下書きとして保存' }).click()
  expect((await response).status()).toBe(422)
}

const switcher = (page: Page) => page.getByRole('navigation', { name: 'カクテルの一覧の切り替え' })

/** カクテルの一覧で名前を絞り込み、その 1 行のリンクを返す */
async function findInList(page: Page, name: string, nameEng: string) {
  await page.getByRole('searchbox', { name: '絞り込み' }).fill(name)
  return page.getByRole('link', { name: `${name}(${nameEng})` })
}

/** カクテルの一覧(公開済み・下書きのどちらか)から、そのカクテルの編集画面を開く */
async function openFromList(page: Page, listPath: string, name: string, nameEng: string) {
  await page.goto(listPath)
  await (await findInList(page, name, nameEng)).click()
  await expect(page).toHaveURL(/\/admin\/cocktails\/\d+$/)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('カクテルの編集')
  await expect(page.getByLabel('名前', { exact: true })).toHaveValue(name)
}

/** 試行ごとに変わる印。作るカクテルの名前に入れ、リトライで同じ名前を作らない */
const RUN = Date.now()

/** このファイルで作ったカクテルの名前。テストが途中で落ちても残さないよう、テストごと(afterEach)に消す */
const created: string[] = []

/** 画面と同じく CSRF のトークンを付けて、名前の完全一致で探したカクテル(公開済み・下書きのどちらでも)を消す */
async function deleteCocktails(page: Page, names: readonly string[]) {
  await page.goto('/admin')
  const statuses = await page.evaluate(async (targets) => {
    type Index = { data: { id: number; name: string }[] }
    const token = document.cookie
      .split('; ')
      .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
      ?.slice('XSRF-TOKEN='.length)
    const headers = { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(token ?? '') }
    const result: number[] = []
    for (const name of targets) {
      for (const status of ['published', 'draft']) {
        const query = new URLSearchParams({ status, q: name })
        const { data } = (await (await fetch(`/api/admin/cocktails?${query}`, { headers })).json()) as Index
        for (const cocktail of data.filter((item) => item.name === name)) {
          result.push((await fetch(`/api/admin/cocktails/${cocktail.id}`, { method: 'DELETE', headers })).status)
        }
      }
    }
    return result
  }, names)
  // 作る前に落ちたカクテルは、消すものが無い
  for (const status of statuses) {
    expect(status).toBe(204)
  }
}

test.afterEach(async ({ page, baseURL }) => {
  if (created.length === 0) return
  await login(page, baseURL!)
  await deleteCocktails(page, created.splice(0))
})

const ingredients = (page: Page) => page.getByRole('region', { name: '材料' })
const ingredientRow = (page: Page, position: number) => ingredients(page).getByRole('group', { name: `${position} 行目` })

/** 行の「ノードを選ぶ」で木を開き、ノードを選ぶ。開く枝(根の直下にないノードの祖先)は openNames に上から並べる */
async function pickNode(page: Page, position: number, name: string, openNames: readonly string[] = []) {
  await ingredientRow(page, position).getByRole('button', { name: `${position} 行目のノードを選ぶ` }).click()
  const tree = page.getByRole('list', { name: `${position} 行目のノード` })
  for (const open of openNames) {
    await tree.getByRole('button', { name: `${open}を開く`, exact: true }).click()
  }
  await tree.getByRole('radio', { name, exact: true }).click()
  await expect(tree).toHaveCount(0)
}

/** 行の銘柄の欄で選んでいる選択肢の文字(銘柄の選択肢は、行のノードの銘柄を読み込んでから出る) */
const selectedBrand = (page: Page, position: number) =>
  ingredientRow(page, position)
    .getByLabel('銘柄')
    .evaluate((select: HTMLSelectElement) => select.selectedOptions[0]?.textContent ?? '')

/** 新規の画面で、基本情報の欄(名前・英名・製法。公開するなら説明も)を入れる */
async function fillBasics(page: Page, name: string, description = '') {
  await page.getByLabel('名前', { exact: true }).fill(name)
  await page.getByLabel('英名').fill(`E2E Ingredients ${RUN}`)
  await page.getByLabel('製法').selectOption({ label: 'シェイク' })
  await page.getByLabel('説明').fill(description)
}

test('TC-128-25: 新規の画面で材料を 2 行入れて「下書きとして保存」で保存でき、開き直すと行が並びのまま、量が整って入っている', async ({
  page,
  baseURL,
}) => {
  await login(page, baseURL!)
  await page.goto('/admin/cocktails/new')
  const name = `E2E の材料の下書き ${RUN}`
  await fillBasics(page, name)
  // 1 行目: ジンを選ぶと空のベースにも入り、銘柄(タンカレー)を選ぶとノードが銘柄の所属(ドライジン)になる
  await pickNode(page, 1, 'ジン')
  await expect(page.getByText('選んでいるベース: ジン', { exact: true })).toBeVisible()
  await ingredientRow(page, 1).getByLabel('銘柄').selectOption({ label: 'タンカレー(Tanqueray)' })
  await expect(ingredientRow(page, 1).getByText('ノード: ジン / ドライジン', { exact: true })).toBeVisible()
  await ingredientRow(page, 1).getByLabel('量').fill('45')
  await pickNode(page, 2, 'ウォッカ')
  await ingredientRow(page, 2).getByLabel('量').fill('15')

  created.push(name)
  await page.getByRole('button', { name: '下書きとして保存' }).click()

  await expect(page).toHaveURL(/\/admin\/cocktails\?status=draft$/)
  await openFromList(page, '/admin/cocktails?status=draft', name, `E2E Ingredients ${RUN}`)
  await expect(ingredientRow(page, 1).getByText('ノード: ジン / ドライジン', { exact: true })).toBeVisible()
  await expect.poll(() => selectedBrand(page, 1)).toBe('タンカレー(Tanqueray)')
  await expect(ingredientRow(page, 1).getByLabel('量')).toHaveValue('45ml')
  await expect(ingredientRow(page, 2).getByText('ノード: ウォッカ', { exact: true })).toBeVisible()
  await expect(ingredientRow(page, 2).getByLabel('量')).toHaveValue('15ml')
})

test('TC-128-28: 開いたカクテルの材料の 2 行目を上へ動かして保存すると、開き直したときに入れ替わっている', async ({ page, baseURL }) => {
  await login(page, baseURL!)
  // E2eSeeder の下書き「E2E の下書き」(ウォッカ 45ml と、銘柄スミノフ 15ml)。入れ替えたまま残るので、
  // リトライ・ほかのテストの後でもよいよう、開いたときの 2 行の中身を読んでから入れ替える
  await openFromList(page, '/admin/cocktails?status=draft', 'E2E の下書き', 'Save Test')
  const amountOf = (position: number) => ingredientRow(page, position).getByLabel('量').inputValue()
  const [first, second] = [await amountOf(1), await amountOf(2)]
  expect([first, second].sort()).toEqual(['15ml', '45ml'])

  await ingredientRow(page, 2).getByRole('button', { name: '2 行目を上へ' }).click()
  await page.getByRole('button', { name: '下書きとして保存' }).click()

  await expect(page).toHaveURL(/\/admin\/cocktails\?status=draft$/)
  await openFromList(page, '/admin/cocktails?status=draft', 'E2E の下書き', 'Save Test')
  await expect(ingredientRow(page, 1).getByLabel('量')).toHaveValue(second)
  await expect(ingredientRow(page, 2).getByLabel('量')).toHaveValue(first)
})

test('TC-128-26: 新規の画面で材料を 2 行入れて「公開する」で保存でき、公開済みの一覧に出る', async ({ page, baseURL }) => {
  await login(page, baseURL!)
  await page.goto('/admin/cocktails/new')
  const name = `E2E の材料の公開 ${RUN}`
  await fillBasics(page, name, '材料の行を入れて公開する E2E。')
  await pickNode(page, 1, 'ウォッカ')
  await ingredientRow(page, 1).getByLabel('量').fill('30')
  await pickNode(page, 2, 'ミント')
  await ingredientRow(page, 2).getByLabel('量').fill('-')
  await ingredientRow(page, 2).getByRole('checkbox', { name: '任意' }).check()

  created.push(name)
  await page.getByRole('button', { name: '公開する' }).click()

  await expect(page).toHaveURL(/\/admin\/cocktails$/)
  await expect(await findInList(page, name, `E2E Ingredients ${RUN}`)).toBeVisible()
})

test('TC-128-27: 新規の画面で材料を 1 行にして保存すると、API の件数のメッセージを材料の欄に出して画面に残る', async ({ page, baseURL }) => {
  await login(page, baseURL!)
  await page.goto('/admin/cocktails/new')
  const name = `E2E の材料 1 行 ${RUN}`
  await fillBasics(page, name)
  await pickNode(page, 1, 'ウォッカ')
  await ingredientRow(page, 1).getByLabel('量').fill('30')
  await ingredientRow(page, 2).getByRole('button', { name: '2 行目を消す' }).click()
  await expect(ingredientRow(page, 2)).toHaveCount(0)

  created.push(name)
  await saveDraftAndWait(page)

  await expect(page.getByRole('alert')).toContainText('保存できませんでした。入力を確かめてください。')
  await expect(ingredients(page).getByText('材料は2 個以上にしてください。', { exact: true })).toBeVisible()
  await expect(page).toHaveURL(/\/admin\/cocktails\/new$/)
})

test('TC-127-27: カクテルの一覧の「新規」から、新規のフォームを開く', async ({ page, baseURL }) => {
  await login(page, baseURL!)
  await page.goto('/admin/cocktails')

  await page.getByRole('link', { name: '新規' }).click()

  await expect(page).toHaveURL(/\/admin\/cocktails\/new$/)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('カクテルの新規')
  await expect(page.getByRole('button', { name: '下書きとして保存' })).toBeVisible()
  await expect(page.getByRole('button', { name: '公開する' })).toBeVisible()
  await expect(page.getByRole('button', { name: '削除' })).toHaveCount(0)
})

test('TC-127-28: 新規で必須の欄を空のまま保存すると、ベース・名前・英名・製法と材料に API のメッセージを出して画面に残る。名前は 255 文字まで通り 256 文字で止まる', async ({
  page,
  baseURL,
}) => {
  await login(page, baseURL!)
  await page.goto('/admin/cocktails/new')
  const name = page.getByLabel('名前', { exact: true })

  await saveDraftAndWait(page)

  await expect(page.getByRole('alert')).toContainText('保存できませんでした。入力を確かめてください。')
  await expect(page).toHaveURL(/\/admin\/cocktails\/new$/)
  for (const field of [name, page.getByLabel('英名'), page.getByLabel('製法')]) {
    await expect(field).toHaveAttribute('aria-invalid', 'true')
    await expect(field).not.toHaveAccessibleDescription('')
  }
  await expect(page.getByRole('group', { name: 'ベース' })).not.toHaveAccessibleDescription('')
  // 空の材料の行は飛ばさずに送るので、行ごとにノード・量のメッセージが付く
  await expect(page.getByRole('button', { name: '1 行目のノードを選ぶ' })).not.toHaveAccessibleDescription('')
  await expect(page.getByRole('group', { name: '1 行目' }).getByLabel('量')).toHaveAttribute('aria-invalid', 'true')

  // 直前の「必須」のメッセージのままでは通らないよう、256 文字の応答で説明の文が変わる(長さのメッセージになる)ことを見る
  const requiredMessage = await name.evaluate((el) => {
    const ids = el.getAttribute('aria-describedby') ?? ''
    return ids
      .split(' ')
      .map((id) => document.getElementById(id)?.textContent ?? '')
      .join(' ')
  })
  expect(requiredMessage).not.toBe('')
  await name.fill('あ'.repeat(256))
  await saveDraftAndWait(page)
  await expect(name).toHaveAttribute('aria-invalid', 'true')
  await expect(name).not.toHaveAccessibleDescription(requiredMessage)
  await expect(name).toHaveValue('あ'.repeat(256))

  // ほかの欄(ベース・材料など)の誤りは残るが、名前は 255 文字なら誤りにならない
  await name.fill('あ'.repeat(255))
  await saveDraftAndWait(page)
  await expect(page.getByLabel('英名')).toHaveAttribute('aria-invalid', 'true')
  await expect(name).toHaveAttribute('aria-invalid', 'false')
})

test('TC-127-29: 「下書きとして保存」で保存したカクテルは、下書きの一覧に出て、公開済みの一覧に出ない', async ({ page, baseURL }) => {
  await login(page, baseURL!)
  await openFromList(page, '/admin/cocktails?status=draft', 'E2E の下書き', 'Save Test')

  await page.getByRole('button', { name: '下書きとして保存' }).click()

  await expect(page).toHaveURL(/\/admin\/cocktails\?status=draft$/)
  await expect(switcher(page).getByRole('link', { name: '下書き' })).toHaveAttribute('aria-current', 'page')
  await expect(await findInList(page, 'E2E の下書き', 'Save Test')).toBeVisible()

  await switcher(page).getByRole('link', { name: '公開済み' }).click()
  await expect(switcher(page).getByRole('link', { name: '公開済み' })).toHaveAttribute('aria-current', 'page')
  await expect(page.getByRole('link', { name: 'カミカゼ(Kamikaze)' })).toBeVisible()
  // 公開済みは 8 件で 1 ページに収まるので、絞り込まずに無いことを見る
  await expect(page.getByRole('link', { name: 'E2E の下書き(Save Test)' })).toHaveCount(0)
})

test('TC-127-30: 「公開する」で保存したカクテルは、公開済みの一覧に出て、下書きの一覧に出ない', async ({ page, baseURL }) => {
  await login(page, baseURL!)
  await openFromList(page, '/admin/cocktails', 'カミカゼ', 'Kamikaze')

  await page.getByRole('button', { name: '公開する' }).click()

  await expect(page).toHaveURL(/\/admin\/cocktails$/)
  await expect(switcher(page).getByRole('link', { name: '公開済み' })).toHaveAttribute('aria-current', 'page')
  await expect(await findInList(page, 'カミカゼ', 'Kamikaze')).toBeVisible()

  await switcher(page).getByRole('link', { name: '下書き' }).click()
  await expect(switcher(page).getByRole('link', { name: '下書き' })).toHaveAttribute('aria-current', 'page')
  await expect(page.getByRole('link', { name: 'E2E の下書き(Save Test)' })).toBeVisible()
  // 下書きは 3 件で 1 ページに収まるので、絞り込まずに無いことを見る
  await expect(page.getByRole('link', { name: 'カミカゼ(Kamikaze)' })).toHaveCount(0)
})

test('TC-127-31: 新規で名前を入れると、同じ名前のカクテルを左に出し、そのカクテルの編集画面へ移れる', async ({ page, baseURL }) => {
  await login(page, baseURL!)
  await page.goto('/admin/cocktails/new')
  const duplicates = page.getByRole('complementary', { name: '同じ名前・同じレシピのカクテル' })
  await expect(page.getByRole('button', { name: '公開する' })).toBeVisible()
  await expect(duplicates).toHaveCount(0)

  await page.getByLabel('名前', { exact: true }).fill('カミカゼ')

  const sameName = duplicates.getByRole('region', { name: '同じ名前のカクテル' })
  await expect(sameName.getByRole('link', { name: 'カミカゼ' })).toBeVisible()
  const reloaded = page.waitForResponse((r) => new URL(r.url()).pathname === '/api/admin/cocktails/duplicates' && r.ok())
  await sameName.getByRole('link', { name: 'カミカゼ' }).click()

  await expect(page).toHaveURL(/\/admin\/cocktails\/\d+$/)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('カクテルの編集')
  await expect(page.getByLabel('名前', { exact: true })).toHaveValue('カミカゼ')
  // 編集では自分を除くので、ほかに同じ名前のカクテルが無ければ同じ名前の一覧を出さない(開いた後の問い合わせが返ってから見る)。
  // 左の欄そのものは見ない。下書き「E2E の下書き」はカミカゼと同じ材料で、両方を API で保存した後(TC-127-29・30)は、
  // 材料の組(recipe_signature。保存のときに作る)がそろって同じレシピの一覧に出るため、流す順で欄の有る無しが変わる
  await reloaded
  await expect(sameName).toHaveCount(0)
})

test('TC-127-32: md の幅のいちばん狭いところ(960px)でも、左の同じ名前の欄を、材料の名前が読める幅で出し、横にはみ出さない', async ({
  page,
  baseURL,
}) => {
  await page.setViewportSize({ width: 960, height: 900 })
  await login(page, baseURL!)
  await page.goto('/admin/cocktails/new')
  await expect(page.getByRole('button', { name: '公開する' })).toBeVisible()

  await page.getByLabel('名前', { exact: true }).fill('カミカゼ')

  const duplicates = page.getByRole('complementary', { name: '同じ名前・同じレシピのカクテル' })
  await expect(duplicates.getByRole('link', { name: 'カミカゼ' })).toBeVisible()
  // 欄が 100px ほどまで縮むと、材料の行の名前(ウォッカ)が 1 文字ずつ折り返して量(45ml)と重なる。
  // 名前の列 2 つと量の列(5em)が並ぶ幅を下回らないこと
  const box = await duplicates.boundingBox()
  expect(box!.width).toBeGreaterThanOrEqual(200)
  const { scrollWidth, clientWidth } = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }))
  expect(scrollWidth).toBeLessThanOrEqual(clientWidth)
})

test('TC-127-33: xs の幅(375px)で、製法・評価・甘さの行が折り返しても、メジャーのチェックがほかの欄に隠れず、押して入れられる', async ({
  page,
  baseURL,
}) => {
  await page.setViewportSize({ width: 375, height: 812 })
  await login(page, baseURL!)
  await page.goto('/admin/cocktails/new')
  const major = page.getByRole('checkbox', { name: 'メジャー' })
  await expect(major).not.toBeChecked()

  // click は、押す位置でほかの要素(下の説明の欄)が受け取るなら押せずに待つので、隠れていると落ちる
  await major.click({ timeout: 5000 })

  await expect(major).toBeChecked()
})
