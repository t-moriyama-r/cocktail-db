/**
 * 見た目の案と、案ごとに見比べる画面の一覧。
 * 案の中身(色・余白・文字の大きさ)は tokens.css の `[data-proposal=<id>]` に置き、ここには名前と説明だけを持つ。
 */

export type ProposalId = 'a' | 'b' | 'c'
export type ScreenId = 'top' | 'detail' | 'result'

export type Proposal = {
  id: ProposalId
  name: string
  summary: string
}

export const PROPOSALS: Proposal[] = [
  { id: 'a', name: '案 A: 旧に寄せる', summary: '水色のヘッダー・青のリンク・左線の見出し。旧の配色と並びをできるだけ残す' },
  { id: 'b', name: '案 B: 夜のバー', summary: '濃紺の地に琥珀色の差し色。写真や動画が映える暗い配色' },
  { id: 'c', name: '案 C: 白地でシンプル', summary: '白地に緑の差し色 1 色。余白を広めに取り、表を読みやすくする' },
]

export const SCREENS: { id: ScreenId; name: string }[] = [
  { id: 'top', name: 'トップ' },
  { id: 'detail', name: 'カクテル詳細' },
  { id: 'result', name: '検索結果の表' },
]

/** URL の値が案の ID なら返し、そうでなければ最初の案(旧に寄せる案)を返す */
export function toProposalId(value: string | null): ProposalId {
  return PROPOSALS.find((p) => p.id === value)?.id ?? PROPOSALS[0].id
}

/** URL の値が画面の ID なら返し、そうでなければトップを返す */
export function toScreenId(value: string | null): ScreenId {
  return SCREENS.find((s) => s.id === value)?.id ?? SCREENS[0].id
}
