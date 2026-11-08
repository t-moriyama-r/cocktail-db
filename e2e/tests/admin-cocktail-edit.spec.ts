import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { expect, test, type Page } from '@playwright/test'

// 管理画面のカクテルの新規・編集(#127)。相手は本物の管理 API(#291・#129)と、E2eSeeder のカクテル。
// 保存の API は材料を 2 件以上求め、この画面はまだ材料を変えられない(#128)ので、保存は材料を 2 件持つカクテルで試す
// (E2eSeeder の seedAdminCocktailEditData): 公開の「カミカゼ」と、下書きの「E2E の下書き」。
// どちらも保存しても公開・下書きは変わらないので、登録数(ヘッダーの 8)など、ほかの E2E が見ている数は変わらない。
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
  await expect(page.getByRole('region', { name: '材料' }).getByRole('listitem')).not.toHaveCount(0)

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

test.describe('別名(#83)', () => {
  // 別名は下書き「E2E の下書き」で試す(下書きなので、名前検索・登録数など、ほかの E2E が見ているものに別名が入らない)。
  // ジントニック(E2eSeeder の seedHeaderData)が別名「G&T」を持つので、同じ別名を足すと保存の後に警告が返る。
  // 試行ごとに変わる別名にし、途中で落ちても別名が残らないよう、後始末で下書きの別名を全部消す(RC-18)
  const RUN = Date.now()
  const aliases = (page: Page) => page.getByRole('region', { name: '別名' })

  /** 別名の欄の値を、上から並べる */
  const aliasValues = (page: Page) =>
    aliases(page)
      .getByRole('textbox')
      .evaluateAll((inputs) => inputs.map((input) => (input as HTMLInputElement).value))

  test.afterEach(async ({ page, baseURL }) => {
    await login(page, baseURL!)
    await openFromList(page, '/admin/cocktails?status=draft', 'E2E の下書き', 'Save Test')
    const removeButtons = aliases(page).getByRole('button', { name: /^別名 \d+ を削除$/ })
    if ((await removeButtons.count()) === 0) return
    while ((await removeButtons.count()) > 0) {
      await removeButtons.first().click()
    }
    await page.getByRole('button', { name: '下書きとして保存' }).click()
    await expect(page).toHaveURL(/\/admin\/cocktails\?status=draft$/)
  })

  test('TC-83-15: 別名を足して保存すると、ほかのカクテルと同じ別名の警告を一覧に出し、開き直すと別名が残る。検索用の名前が同じ別名は 422 で止まり、消して保存すると無くなる', async ({
    page,
    baseURL,
  }) => {
    const own = `E2E の別名 ${RUN}`
    await login(page, baseURL!)
    await openFromList(page, '/admin/cocktails?status=draft', 'E2E の下書き', 'Save Test')
    await expect(aliases(page).getByText('別名はありません。')).toBeVisible()

    // 足す: 自分だけの別名と、ジントニックの別名と同じ「G&T」
    await aliases(page).getByRole('button', { name: '別名を足す' }).click()
    await aliases(page).getByLabel('別名 1', { exact: true }).fill(own)
    await aliases(page).getByRole('button', { name: '別名を足す' }).click()
    await aliases(page).getByLabel('別名 2', { exact: true }).fill('G&T')
    await page.getByRole('button', { name: '下書きとして保存' }).click()

    // 警告は保存を止めない。戻った一覧の上に、重なったカクテルへのリンクと一緒に出す
    await expect(page).toHaveURL(/\/admin\/cocktails\?status=draft$/)
    const notice = page.getByRole('status').filter({ hasText: '「E2E の下書き」を保存しました' })
    await expect(notice).toContainText('別名「G&T」: 「ジントニック」の別名と同じです')
    await expect(notice.getByRole('link', { name: '「ジントニック」を開く' })).toHaveAttribute('href', /\/admin\/cocktails\/\d+$/)

    // 開き直すと、保存した別名が並びのまま残っている
    await openFromList(page, '/admin/cocktails?status=draft', 'E2E の下書き', 'Save Test')
    await expect.poll(() => aliasValues(page)).toEqual([own, 'G&T'])

    // 大文字・小文字だけが違う別名は、同じ別名として 422 になり、その行の欄の下に出て画面に残る
    await aliases(page).getByRole('button', { name: '別名を足す' }).click()
    await aliases(page).getByLabel('別名 3', { exact: true }).fill('g&t')
    await page.getByRole('button', { name: '下書きとして保存' }).click()
    await expect(aliases(page).getByLabel('別名 3', { exact: true })).toHaveAttribute('aria-invalid', 'true')
    await expect(aliases(page).getByLabel('別名 3', { exact: true })).toHaveAccessibleDescription('同じ別名がほかにあります')
    await expect(page).toHaveURL(/\/admin\/cocktails\/\d+$/)

    // 消す: 3 行とも消して保存すると、開き直したときに別名が無い
    for (let i = 0; i < 3; i++) {
      await aliases(page).getByRole('button', { name: '別名 1 を削除' }).click()
    }
    await page.getByRole('button', { name: '下書きとして保存' }).click()
    await expect(page).toHaveURL(/\/admin\/cocktails\?status=draft$/)
    await expect(page.getByRole('status').filter({ hasText: 'を保存しました' })).toHaveCount(0)
    await openFromList(page, '/admin/cocktails?status=draft', 'E2E の下書き', 'Save Test')
    await expect(aliases(page).getByText('別名はありません。')).toBeVisible()
  })

  test('TC-83-16: xs の幅(375px)で別名の行を足しても、欄と「削除」が横にはみ出さない', async ({ page, baseURL }) => {
    await page.setViewportSize({ width: 375, height: 812 })
    await login(page, baseURL!)
    await page.goto('/admin/cocktails/new')

    await aliases(page).getByRole('button', { name: '別名を足す' }).click()
    await aliases(page).getByLabel('別名 1', { exact: true }).fill('とても長い別名'.repeat(10))

    await expect(aliases(page).getByRole('button', { name: '別名 1 を削除' })).toBeInViewport()
    const { scrollWidth, clientWidth } = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }))
    expect(scrollWidth).toBeLessThanOrEqual(clientWidth)
  })
})
