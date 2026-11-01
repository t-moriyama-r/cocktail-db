import { describe, expect, test } from 'vitest'

// src の下のモジュールのパス(画面を足すと自動で対象に入る)。import.meta.glob はファイルの実際の名前を返す
const modulePaths = Object.keys(import.meta.glob(['./**/*.ts', './**/*.tsx']))

/**
 * 拡張子を外したパスを小文字にそろえたときに、同じになるモジュールの組を返す。
 *
 * import は拡張子を書かないので、`./AdminMenu`(AdminMenu.tsx)と `./adminMenu`(adminMenu.ts)が同じディレクトリにあると、
 * 大文字と小文字を区別しないファイルシステム(Windows・macOS の既定)では、TypeScript・Vite が `.ts` を先に探して
 * 別のファイルを読む。Linux の CI では通るので、ここで検出する。
 */
function caseCollisions(paths: string[]): string[][] {
  const groups = new Map<string, string[]>()
  for (const path of paths) {
    const key = path.replace(/\.tsx?$/, '').toLowerCase()
    groups.set(key, [...(groups.get(key) ?? []), path])
  }
  return [...groups.values()].filter((group) => new Set(group.map((p) => p.replace(/\.tsx?$/, ''))).size > 1)
}

describe('モジュールの名前', () => {
  test('TC-119-24: 同じディレクトリに、拡張子を除いた名前が大文字・小文字だけ違うモジュールが無い', () => {
    expect(caseCollisions(modulePaths)).toEqual([])
  })
})
