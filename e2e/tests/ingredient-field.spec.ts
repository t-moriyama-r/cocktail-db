import { expect, test, type Page } from '@playwright/test'

// ヘッダーのモーダルの中の、条件検索のフォーム(#307)と材料の欄(#305)。
// E2eSeeder の木(蒸留酒 > ジン > ドライジン > ロンドンドライジン > ビーフィーター)を使う
const openSearchModal = async (page: Page) => {
  await page.getByRole('banner').getByRole('button', { name: '検索をはじめる' }).click()
  const dialog = page.getByRole('dialog', { name: 'カクテル検索' })
  await expect(dialog.getByRole('heading', { name: 'カクテル検索' })).toBeVisible()
  return dialog
}

const openToBeefeater = async (page: Page) => {
  await page.getByRole('button', { name: 'ジンを開く' }).click()
  await page.getByRole('button', { name: 'ドライジンを開く' }).click()
  await page.getByRole('button', { name: 'ロンドンドライジンを開く' }).click()
}

test('TC-305-12: 深さ 5 のノードを開いてチェックでき、名前を押すと詳細へ移ってモーダルが閉じ、開き直すとチェックが残る', async ({ page }) => {
  await page.goto('/helpme')
  await openSearchModal(page)

  await openToBeefeater(page)
  await page.getByRole('checkbox', { name: 'ビーフィーター' }).check()
  await expect(page.getByRole('checkbox', { name: 'ビーフィーター' })).toBeChecked()

  await page.getByRole('button', { name: 'ビーフィーター', exact: true }).click()
  await expect(page).toHaveURL(/\/category\/\d+$/)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('ビーフィーター(ビーフィーター)')
  await expect(page.getByRole('dialog')).toBeHidden()

  // フォームの値はヘッダーが持つので、開き直すとチェックが残る(旧のモーダルも中身を残していた)
  await openSearchModal(page)
  await openToBeefeater(page)
  await expect(page.getByRole('checkbox', { name: 'ビーフィーター' })).toBeChecked()
})

test('TC-307-17・TC-102-19: 開くと材料が無いので検索を押せず、材料を 1 つ選ぶと押せ、押すと条件を URL に入れて結果の画面へ移って閉じる', async ({ page }) => {
  await page.goto('/helpme')
  const dialog = await openSearchModal(page)

  await expect(dialog.getByRole('radio', { name: 'OR検索' })).toBeChecked()
  await expect(dialog.getByRole('group', { name: '製法' }).getByRole('checkbox', { name: 'ビルド' })).toBeChecked()
  await expect(dialog.getByText('材料は必須です。')).toBeVisible()
  const search = dialog.getByRole('button', { name: '検索', exact: true })
  await expect(search).toBeDisabled()

  await dialog.getByRole('checkbox', { name: 'ジン', exact: true }).check()
  await expect(search).toBeEnabled()
  await search.click()

  // 条件を URL のクエリに入れて移る(#102)。ノードの ID は決め打ちしない
  await expect(page).toHaveURL(/\/result\/Cocktails\?n=\d+$/)
  await expect(page.getByRole('dialog')).toBeHidden()
})

test('TC-307-20・TC-102-19: トップのメニューの「カクテル検索」で条件検索のフォームへ移り、材料を選んで検索すると条件を URL に入れて結果の画面へ移る', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('region', { name: 'Menu' }).getByRole('link', { name: 'カクテル検索' }).click()

  const section = page.getByRole('region', { name: 'カクテル検索' })
  await expect(section.getByRole('heading', { name: 'カクテル検索' })).toBeInViewport()
  await expect(section.getByText('材料は必須です。')).toBeVisible()
  const search = section.getByRole('button', { name: '検索', exact: true })
  await expect(search).toBeDisabled()

  await section.getByRole('checkbox', { name: 'ジン', exact: true }).check()
  await expect(search).toBeEnabled()
  await search.click()

  // 条件を URL のクエリに入れて移る(#102)。ノードの ID は決め打ちしない
  await expect(page).toHaveURL(/\/result\/Cocktails\?n=\d+$/)
})

// 検索ボタンはモーダルの左下に固定する(旧の sendBtnFixed)。木を開いて長くしても、スクロールせずに見える。横スクロールも出ない
for (const width of [375, 768, 1024, 1440]) {
  test(`TC-307-18: 幅 ${width}px で、検索ボタンがモーダルの下に見え、横スクロールが出ない`, async ({ page }) => {
    await page.setViewportSize({ width, height: 720 })
    await page.goto('/helpme')
    const dialog = await openSearchModal(page)
    await openToBeefeater(page)
    await page.getByRole('button', { name: 'メジャーでない項目の表示／非表示' }).click()

    await expect(dialog.getByRole('button', { name: '検索', exact: true })).toBeInViewport()
    const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth)
    expect(scrollWidth).toBeLessThanOrEqual(width)
  })
}

type Box = { x: number; y: number; width: number; height: number }
const overlaps = (a: Box, b: Box) =>
  a.x < b.x + b.width && b.x < a.x + a.width && a.y < b.y + b.height && b.y < a.y + a.height

// 「材料は必須です。」は検索ボタンと一緒にモーダルの下端に貼り付け、ボタンに隠れない(#576)。境目(599/600・959/960px)の両側も見る
for (const width of [375, 599, 600, 768, 959, 960, 1024, 1440]) {
  test(`TC-576-01: 幅 ${width}px・高さ 800px で、開いた直後に「材料は必須です。」が画面の中に見え、検索ボタンと重ならない`, async ({ page }) => {
    await page.setViewportSize({ width, height: 800 })
    await page.goto('/helpme')
    const dialog = await openSearchModal(page)

    const error = dialog.getByText('材料は必須です。')
    const search = dialog.getByRole('button', { name: '検索', exact: true })
    await expect(error).toBeInViewport({ ratio: 1 })
    await expect(search).toBeInViewport({ ratio: 1 })
    const errorBox = await error.boundingBox()
    const searchBox = await search.boundingBox()
    expect(errorBox).not.toBeNull()
    expect(searchBox).not.toBeNull()
    expect(overlaps(errorBox!, searchBox!)).toBe(false)
    // 文言はボタンの上に出す(旧と同じ並び)
    expect(errorBox!.y + errorBox!.height).toBeLessThanOrEqual(searchBox!.y)
  })
}

test('TC-576-02: 木を開いて下までスクロールしても、「材料は必須です。」と検索ボタンが重ならずに見える', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 800 })
  await page.goto('/helpme')
  const dialog = await openSearchModal(page)
  await openToBeefeater(page)
  await page.getByRole('button', { name: 'メジャーでない項目の表示／非表示' }).click()
  // モーダルの中でスクロールする枠(overflow-y: auto の祖先)を、いちばん下まで送る
  await dialog.getByRole('button', { name: '検索', exact: true }).evaluate((element) => {
    let node = element.parentElement
    while (node !== null && getComputedStyle(node).overflowY !== 'auto') {
      node = node.parentElement
    }
    node?.scrollTo({ top: node.scrollHeight })
  })

  const error = dialog.getByText('材料は必須です。')
  const search = dialog.getByRole('button', { name: '検索', exact: true })
  await expect(error).toBeInViewport({ ratio: 1 })
  await expect(search).toBeInViewport({ ratio: 1 })
  expect(overlaps((await error.boundingBox())!, (await search.boundingBox())!)).toBe(false)
})
