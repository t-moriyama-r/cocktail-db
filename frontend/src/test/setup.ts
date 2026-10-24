import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach } from 'vitest'

// globals を使わない設定では Testing Library の自動の後片付けが動かないので、テストごとに描画を消す
afterEach(() => {
  cleanup()
})
