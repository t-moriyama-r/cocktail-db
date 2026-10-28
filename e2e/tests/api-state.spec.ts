import { expect, test, type Route } from '@playwright/test'

// API の読み込み中・エラーの共通の表示(#98)を、ビルドした画面で確かめる。
// BE を落とせないので、ブラウザの通信の層で GET /api/hello の応答を差し替える(トップがこの API を呼ぶ)
const HELLO = 'Hello World from Laravel'
const RETRY = 'もう一度読み込む'

test('TC-98-11: API が 500 を返したらエラーの表示を出し、もう一度読み込むでトップを出す', async ({ page }) => {
  let failing = true
  await page.route('**/api/hello', (route: Route) =>
    failing ? route.fulfill({ status: 500, json: { message: 'Server Error' } }) : route.continue(),
  )

  await page.goto('/')
  await expect(page.getByRole('alert')).toHaveText(/データを読み込めませんでした\(エラー 500\)/)

  failing = false
  await page.getByRole('button', { name: RETRY }).click()

  await expect(page.getByRole('heading', { level: 1 })).toHaveText(HELLO)
  await expect(page.getByRole('alert')).toHaveCount(0)
})

test('TC-98-12: 通信に失敗したら通信の失敗の表示を出し、alert もページの読み直しもしない', async ({ page }) => {
  await page.route('**/api/hello', (route: Route) => route.abort('internetdisconnected'))
  // 旧は alert を出してページを読み直していた。新はどちらもしない
  const dialogs: string[] = []
  page.on('dialog', (dialog) => {
    dialogs.push(dialog.message())
    void dialog.dismiss()
  })
  // 移動の前から数える。load が 1 回(最初の読み込み)より多く起きたら、読み直した
  let loads = 0
  page.on('load', () => {
    loads += 1
  })
  await page.goto('/')

  await expect(page.getByRole('alert')).toHaveText(/通信に失敗しました/)
  await expect(page.getByRole('button', { name: RETRY })).toBeVisible()
  expect(dialogs).toEqual([])
  expect(loads).toBe(1)
})

test('TC-98-13: 応答が返るまでは読み込み中の表示を出す', async ({ page }) => {
  let release: () => void = () => {}
  const released = new Promise<void>((resolve) => {
    release = resolve
  })
  await page.route('**/api/hello', async (route: Route) => {
    await released
    await route.continue()
  })

  await page.goto('/')
  await expect(page.getByRole('status')).toHaveText('読み込み中…')

  release()
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(HELLO)
  await expect(page.getByRole('status')).toHaveCount(0)
})
