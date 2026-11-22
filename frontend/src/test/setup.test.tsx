import { render } from '@testing-library/react'
import { useEffect, useState } from 'react'
import { describe, expect, test } from 'vitest'
import { api } from '../api/client'
import { apiRequests, jsonResponse, mockApiHandler } from './api'

/**
 * setup.ts の後片付けが、前のテストの画面が送りかけたリクエストを次のテストの記録に持ち越さないことを確かめる(#664)。
 * 2 つのテストは、書いた順に流れる前提で組む(1 つ目が後片付けの直前の状態を作り、2 つ目が記録を見る)。
 */

/** React の act の外で画面を変えるときに切り替える、React の決まった名前のグローバル(Testing Library の findBy… も同じことをする) */
const actEnvironment = globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }

/** 少し後に見出しと、描いた後(useEffect)に GET /api/affiliates を呼ぶ部品を出す */
function LateAffiliateCaller() {
  const [shown, setShown] = useState(false)
  useEffect(() => {
    const timer = setTimeout(() => setShown(true), 10)
    return () => clearTimeout(timer)
  }, [])
  return shown ? <AffiliateCaller /> : null
}

function AffiliateCaller() {
  useEffect(() => {
    void api.GET('/affiliates', { params: { query: { category_id: 664 } } })
  }, [])
  return <h1>見出し</h1>
}

/** 見出しが DOM に入った直後(useEffect が動く前)まで待つ */
const waitForHeadingInDom = (container: HTMLElement) =>
  new Promise<void>((resolve) => {
    const observer = new MutationObserver(() => {
      if (container.querySelector('h1')) {
        observer.disconnect()
        resolve()
      }
    })
    observer.observe(container, { childList: true, subtree: true })
  })

describe('テストごとの後片付け(setup.ts)', () => {
  test('TC-664-01: 見出しが出て、まだ API を呼んでいないうちにテストを終える(次のテストの前提を作る)', async () => {
    mockApiHandler('get', '/affiliates', () => jsonResponse({ message: 'No query results for model.' }, 404))
    const { container } = render(<LateAffiliateCaller />)

    actEnvironment.IS_REACT_ACT_ENVIRONMENT = false
    try {
      await waitForHeadingInDom(container)
    } finally {
      actEnvironment.IS_REACT_ACT_ENVIRONMENT = true
    }

    // 呼ぶのは後片付け(cleanup)の中。ここではまだ記録に無い
    expect(apiRequests()).toHaveLength(0)
  })

  test('TC-664-02: 前のテストの画面が後片付けの中で送ったリクエストは、次のテストの記録に入らない', () => {
    expect(apiRequests()).toHaveLength(0)
  })
})
