import type { components } from '../../api/schema'

/** 設定の 1 項目(GET・PUT /api/admin/settings が返すもの。#132) */
export type SettingItem = components['schemas']['SettingResource']

/** 書き換えの API が受けるキー(定義にあるキーの enum) */
export type SettingKey = components['schemas']['UpdateSettingRequest']['key']

/**
 * 秘密の項目の、いまの状態の文言。値そのものは API が返さないので、末尾 4 文字か「設定済み」「未設定」だけを出す
 * (docs/decisions/settings.md の 4)。値が短く末尾 4 文字が返らないときは「設定済み」だけにする
 */
export function secretStatusLabel(item: Pick<SettingItem, 'is_set' | 'last_four'>): string {
  if (!item.is_set) return '未設定'
  return item.last_four === null ? '設定済み' : `設定済み(末尾 ${item.last_four})`
}

/** いま効いている値の出どころの文言(docs/decisions/settings.md の 3) */
export function sourceLabel(source: SettingItem['source']): string {
  switch (source) {
    case 'saved':
      return 'この画面で保存した値'
    case 'env':
      return 'サーバーの .env の値'
    default:
      return 'どこにも値がありません'
  }
}

const UPDATED_AT_FORMAT = new Intl.DateTimeFormat('ja-JP', {
  timeZone: 'Asia/Tokyo',
  year: 'numeric',
  month: 'numeric',
  day: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
  hourCycle: 'h23',
})

/** 変えた日時を、日本時間の `2026年11月3日 09:05` の形にする。読めない値なら null */
export function formatUpdatedAt(updatedAt: string): string | null {
  const date = new Date(updatedAt)
  if (Number.isNaN(date.getTime())) return null
  const parts = Object.fromEntries(UPDATED_AT_FORMAT.formatToParts(date).map(({ type, value }) => [type, value]))
  return `${parts.year}年${parts.month}月${parts.day}日 ${parts.hour}:${parts.minute}`
}

/**
 * 最後に変えた人と日時の文言。画面で一度も変えていなければ、そう出す。
 * 変えた人のユーザーが消されていると updated_by は null になる(#132 の TC-132-14)ので、そのときは名前の代わりにそう出す
 */
export function updatedLabel(item: Pick<SettingItem, 'updated_at' | 'updated_by'>): string {
  if (item.updated_at === null) return 'この画面で変えたことはありません'
  const at = formatUpdatedAt(item.updated_at) ?? item.updated_at
  const name = item.updated_by?.name ?? '削除されたユーザー'
  return `${name}(${at})`
}
