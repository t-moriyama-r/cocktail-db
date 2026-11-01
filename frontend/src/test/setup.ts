import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach, vi } from 'vitest'
import { resetApiMocks } from './api'

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

// jsdom は画面を描かないのでスクロールを持たず、window.scrollTo は「未実装」と出力するだけになる。
// 出力で結果が埋もれないよう何もしない関数にする(スクロールの位置は E2E で確かめる)
window.scrollTo = () => {}

afterEach(() => {
  // globals を使わない設定では Testing Library の自動の後片付けが動かないので、テストごとに描画を消す
  cleanup()
  resetApiMocks()
})
