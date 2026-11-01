/** 入れた語を、一覧の API に渡す絞り込みの語にする。前後の空白(全角の空白を含む)を除き、空なら絞り込まない(undefined) */
export function toAdminListQuery(input: string): string | undefined {
  const trimmed = input.trim()
  return trimmed === '' ? undefined : trimmed
}
