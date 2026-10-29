import { useEffect, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router'
import { toApiResult, type ApiResult } from '../../api/apiResult'
import { api } from '../../api/client'
import type { paths } from '../../api/schema'
import { nameSearchResultPath, parseNameSearchInput } from './nameSearchInput'
import styles from './SiteHeader.module.css'

type NameSearchResponse = paths['/search']['get']['responses'][200]['content']['application/json']

/** 入力が止まってから名前の横断検索を呼ぶまでの時間(旧と同じ 500ms) */
export const NAME_SEARCH_DELAY_MS = 500

/** 語ごとの検索の結果。今の語と違う語の結果は使わない */
type Settled = { word: string; result: ApiResult<NameSearchResponse> }

type ButtonState =
  | { kind: 'empty' }
  | { kind: 'forbidden' }
  | { kind: 'searching' }
  | { kind: 'server-error' }
  | { kind: 'hits'; word: string; count: number }

/** 当たった件数。カクテル・ノード・銘柄・材料名で当たったカクテル(#173)の合計 */
const countHits = ({ cocktails, nodes, brands, ingredient_matches }: NameSearchResponse) =>
  cocktails.length + nodes.length + brands.length + ingredient_matches.length

/**
 * ヘッダーの名前検索(旧 Forms/Search/FromName/fromName.vue)。入力が止まって 500ms たったら名前の横断検索(#172)を呼び、
 * 当たった件数を右のボタンに出す。1 件以上ならボタン(と Enter)で名前検索の結果の画面へ移る。
 */
export default function NameSearchField() {
  const navigate = useNavigate()
  const [input, setInput] = useState('')
  const [settled, setSettled] = useState<Settled | null>(null)
  const parsed = parseNameSearchInput(input)
  const word = parsed.kind === 'word' ? parsed.word : null

  useEffect(() => {
    if (word === null) {
      return
    }
    let current = true
    const timer = setTimeout(() => {
      void toApiResult(() => api.GET('/search', { params: { query: { q: word } } })).then((result) => {
        if (current) {
          setSettled({ word, result })
        }
      })
    }, NAME_SEARCH_DELAY_MS)
    return () => {
      current = false
      clearTimeout(timer)
    }
  }, [word])

  const state = toButtonState(parsed, settled)

  const submit = (event: FormEvent) => {
    event.preventDefault()
    if (state.kind === 'hits' && state.count > 0) {
      void navigate(nameSearchResultPath(state.word))
    }
  }

  return (
    <form role="search" className={styles.nameSearch} onSubmit={submit}>
      <input
        type="search"
        className={styles.nameSearchInput}
        placeholder="名前から検索"
        aria-label="名前から検索"
        value={input}
        onChange={(event) => setInput(event.target.value)}
      />
      <NameSearchButton state={state} onClear={() => setInput('')} />
    </form>
  )
}

function toButtonState(parsed: ReturnType<typeof parseNameSearchInput>, settled: Settled | null): ButtonState {
  if (parsed.kind !== 'word') {
    return parsed
  }
  if (settled === null || settled.word !== parsed.word) {
    return { kind: 'searching' }
  }
  if (settled.result.kind !== 'success') {
    return { kind: 'server-error' }
  }
  return { kind: 'hits', word: parsed.word, count: countHits(settled.result.data) }
}

/**
 * 名前検索の右のボタン。幅 600px 未満では短い表示(「-」・件数の数字だけ)にする。
 * 読み上げる名前は幅によらず長い表示にする。
 */
function NameSearchButton({ state, onClear }: { state: ButtonState; onClear: () => void }) {
  switch (state.kind) {
    case 'empty':
      return (
        <button type="button" className={styles.nameSearchButton} disabled aria-label="入力して下さい">
          <span className={styles.smUp}>入力して下さい</span>
          <span className={styles.xsOnly}>-</span>
        </button>
      )
    case 'forbidden':
      // 押すと入力を空にする(旧と同じ)
      return (
        <button type="button" className={`${styles.nameSearchButton} ${styles.nameSearchError}`} onClick={onClear}>
          Error
        </button>
      )
    case 'searching':
      return (
        <button type="button" className={styles.nameSearchButton} disabled>
          検索中...
        </button>
      )
    case 'server-error':
      return (
        <button type="button" className={styles.nameSearchButton} disabled>
          サーバーエラー
        </button>
      )
    case 'hits':
      return (
        <button
          type="submit"
          className={`${styles.nameSearchButton} ${state.count > 0 ? styles.nameSearchHits : ''}`}
          disabled={state.count === 0}
          aria-label={`${state.count}件ヒット`}
        >
          {state.count}
          <span className={styles.smUp}>件ヒット</span>
        </button>
      )
  }
}
