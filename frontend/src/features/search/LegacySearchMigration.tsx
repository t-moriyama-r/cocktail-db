import { useEffect, useState } from 'react'
import { api } from '../../api/client'
import { toApiResult } from '../../api/apiResult'
import {
  legacySearchMessages,
  migrateLegacySearches,
  type ConvertLegacySearch,
  type LegacySearchMigrationResult,
} from './legacySearchStorage'
import styles from './LegacySearchMigration.module.css'

/** 旧の ID を、POST /api/legacy/search-condition(#290)で新しい ID に直す。失敗(通信・4xx・5xx)なら null */
const convertWithApi: ConvertLegacySearch = async (body) => {
  const result = await toApiResult(() => api.POST('/legacy/search-condition', { body }))
  return result.kind === 'success' ? result.data : null
}

// 開発の StrictMode は effect を 2 回動かす。同じ回の移し替えを 2 回呼ばないよう、動いているあいだは同じ Promise を返す
let running: Promise<LegacySearchMigrationResult> | null = null

function runOnce(): Promise<LegacySearchMigrationResult> {
  running ??= migrateLegacySearches(convertWithApi).finally(() => {
    running = null
  })
  return running
}

/**
 * 旧サイトが保存した検索条件を、開いたときに新しい保存に移し(legacySearchStorage.ts。#175)、
 * 戻せなかった条件・壊れていた保存があれば、そのことを本文の上に出す。閉じるまで、画面を移っても出したままにする。
 * 公開ページの枠(SiteLayout)に置く
 */
export default function LegacySearchMigration() {
  const [messages, setMessages] = useState<readonly string[]>([])

  useEffect(() => {
    void runOnce().then((result) => setMessages(legacySearchMessages(result)))
  }, [])

  if (messages.length === 0) return null
  return (
    <div role="status" className={styles.notice}>
      <div className={styles.body}>
        {messages.map((message) => (
          <p key={message} className={styles.message}>
            {message}
          </p>
        ))}
      </div>
      <button type="button" className={styles.close} onClick={() => setMessages([])}>
        閉じる
      </button>
    </div>
  )
}
