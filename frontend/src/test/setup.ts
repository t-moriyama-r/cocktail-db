import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach, vi } from 'vitest'
import type { paths } from '../api/schema'
import { resetApiMocks } from './api'

// API の差し替えはここの 1 か所に置く(テストのファイルごとに vi.mock を書かない)。
// 自前の client を別物にはせず、同じ型付きのクライアントを、絶対 URL と差し替えた fetch(./api の fetchApi)で作り直す。
// 応答はテストから ./api の mockApi・mockApiHandler で登録する
vi.mock('../api/client', async () => {
  const { default: createClient } = await import('openapi-fetch')
  const { TEST_API_BASE_URL, fetchApi } = await import('./api')
  return {
    api: createClient<paths>({ baseUrl: TEST_API_BASE_URL, fetch: fetchApi }),
  }
})

afterEach(() => {
  // globals を使わない設定では Testing Library の自動の後片付けが動かないので、テストごとに描画を消す
  cleanup()
  resetApiMocks()
})
