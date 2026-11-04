import '@testing-library/jest-dom/vitest'
import { cleanup, configure } from '@testing-library/react'
import { afterEach, vi } from 'vitest'
import { resetApiMocks } from './api'
import { resetPasskeyBrowser } from './webauthn'

// API の差し替えはここの 1 か所に置く(テストのファイルごとに vi.mock を書かない)。
// 自前の client を別物にはせず、同じ作り方(createApiClient。CSRF のトークンを付ける処理も入る)で、
// 絶対 URL と差し替えた fetch(./api の fetchApi)にして作り直す。
// 応答はテストから ./api の mockApi・mockApiHandler で登録する
vi.mock('../api/client', async (importOriginal) => {
  const original = await importOriginal<typeof import('../api/client')>()
  const { TEST_API_BASE_URL, fetchApi } = await import('./api')
  return {
    ...original,
    api: original.createApiClient({ baseUrl: TEST_API_BASE_URL, fetch: fetchApi }),
  }
})

// findBy…・waitFor が画面の変化を待つ長さ(既定の 1 秒)を 5 秒にする。
// 画面は API の応答(差し替えた fetch)を受けてから描くので、CI や手元で負荷がかかると 1 秒を超えて、
// 期待どおりに描いていても落ちることがあった(#663)。待つ長さを延ばすだけで、確かめる中身は変えない
configure({ asyncUtilTimeout: 5000 })
// 1 件のテストの上限(既定の 5 秒)も延ばす。画面を何度か移って待つテストは、負荷がかかると 5 秒を超えた(#663)。
// 上の待ちを何回か重ねても収まる長さにする
vi.setConfig({ testTimeout: 30000 })

// jsdom は画面を描かないのでスクロールを持たず、window.scrollTo は「未実装」と出力するだけになる。
// 出力で結果が埋もれないよう何もしない関数にする(スクロールの位置は E2E で確かめる)
window.scrollTo = () => {}

afterEach(() => {
  // globals を使わない設定では Testing Library の自動の後片付けが動かないので、テストごとに描画を消す
  cleanup()
  resetApiMocks()
  resetPasskeyBrowser()
})
