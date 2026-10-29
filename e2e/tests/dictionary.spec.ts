import { expect, test, type Page } from '@playwright/test'

// 用語辞典の一覧・詳細(#56)。E2eSeeder の seedDictionary のデータを使う。
// 分類は 製法(ビルド・シェイク)・グラス(カクテル・グラス)・スタイル(用語なし)・単位(ひとつまみ・ティースプーン・ダッシュ)。
// 技法のビルドとグラスのカクテル・グラスは、カクテルのジントニックに結び付いている
const COCKTAILS_HEADING = 'これを使ったカクテル(ランダム7種)'

/** 送った GET /api/cocktails/random のクエリを、送った順に集める */
const collectRandomQueries = (page: Page) => {
  const queries: string[] = []
  page.on('request', (request) => {
    const url = new URL(request.url())
    if (url.pathname === '/api/cocktails/random') {
      queries.push(url.search)
    }
  })
  return queries
}

test('TC-56-17: 一覧は、用語のある分類だけを ID の順に出し、用語をひらがな・カタカナが混ざっても五十音の順に並べる', async ({
  page,
}) => {
  await page.goto('/dictionary')

  await expect(page.getByRole('heading', { level: 2 })).toHaveText(['製法(Method)', 'グラス(Glass)', '単位(Unit)'])
  await expect(page.getByRole('region', { name: '単位(Unit)' }).getByRole('link')).toHaveText([
    'ダッシュ(Dash)',
    'ティースプーン(Teaspoon)',
    'ひとつまみ(Pinch)',
  ])
  await expect(page.getByText('スタイル(Style)')).toHaveCount(0)
})

test('TC-56-18: 一覧から技法の用語を開くと、題・パンくず・説明文と、技法で探したカクテルを出す', async ({ page }) => {
  const randomQueries = collectRandomQueries(page)
  await page.goto('/dictionary')

  await page.getByRole('link', { name: 'ビルド(Build)' }).click()

  await expect(page).toHaveURL(/\/dictionary\/\d+$/)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('ビルド(Build)')
  await expect(page).toHaveTitle(/^ビルド\(Build\) ｜ /)
  await expect(page.getByRole('navigation', { name: 'パンくずリスト' }).getByRole('link')).toHaveText('製法')
  await expect(page.getByText('グラスに直接注いで作る。')).toBeVisible()
  const cocktails = page.getByRole('region', { name: COCKTAILS_HEADING })
  await expect(cocktails.getByRole('link', { name: 'ジントニック' })).toBeVisible()
  await expect(cocktails.getByRole('button', { name: '再取得' })).toBeVisible()
  expect(randomQueries).toHaveLength(1)
  expect(randomQueries[0]).toMatch(/^\?limit=7&method_id=\d+$/)

  await page.getByRole('navigation', { name: 'パンくずリスト' }).getByRole('link', { name: '製法' }).click()
  await expect(page).toHaveURL(/\/dictionary$/)
  await expect(page.getByRole('heading', { level: 2, name: '製法(Method)' })).toBeVisible()
})

test('TC-56-19: 説明文のリンクで技法の用語からグラスの用語へ移ると、カクテルの欄をグラスで取り直す', async ({ page }) => {
  const randomQueries = collectRandomQueries(page)
  await page.goto('/dictionary')
  await page.getByRole('link', { name: 'ビルド(Build)' }).click()
  await expect(page.getByRole('region', { name: COCKTAILS_HEADING }).getByRole('link', { name: 'ジントニック' })).toBeVisible()

  await page.getByRole('link', { name: 'カクテル・グラス', exact: true }).click()

  await expect(page.getByRole('heading', { level: 1 })).toHaveText('カクテル・グラス(Cocktail glass)')
  await expect(page.getByRole('region', { name: COCKTAILS_HEADING }).getByRole('link', { name: 'ジントニック' })).toBeVisible()
  await expect.poll(() => randomQueries.length).toBe(2)
  expect(randomQueries[1]).toMatch(/^\?limit=7&glass_id=\d+$/)
})

test('TC-56-20: 技法・グラスでない用語には、カクテルの欄を出さない', async ({ page }) => {
  const randomQueries = collectRandomQueries(page)
  await page.goto('/dictionary')

  await page.getByRole('link', { name: 'ダッシュ(Dash)' }).click()

  await expect(page.getByRole('heading', { level: 1 })).toHaveText('ダッシュ(Dash)')
  await expect(page.getByText('約 1ml。')).toBeVisible()
  await expect(page.getByRole('heading', { name: COCKTAILS_HEADING })).toHaveCount(0)
  expect(randomQueries).toEqual([])
})

test('TC-56-21: 無い ID・数字でない ID の詳細を開くと 404 の画面を出す', async ({ page }) => {
  for (const path of ['/dictionary/999999', '/dictionary/abc']) {
    await page.goto(path)
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('ページが見つかりません')
  }
})
