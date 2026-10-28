/// <reference types="node" />
import { readFileSync } from 'node:fs'
import { describe, expect, test } from 'vitest'
import { breakpoints, mediaDown, mediaUp } from './breakpoints'

// src の下の CSS をすべて文字列で読む(画面を足すと自動で対象に入る)。
// Vitest は CSS Modules を ?raw でも空のオブジェクトにするので、import.meta.glob ではパスだけを取り、中身はファイルから読む
const cssFiles = Object.fromEntries(
  Object.keys(import.meta.glob('../**/*.css')).map((path) => [path, readFileSync(new URL(path, import.meta.url), 'utf8')]),
)

const allowedMinWidths = Object.values(breakpoints).map((px) => `${px}px`)
const allowedMaxWidths = Object.values(breakpoints).map((px) => `${px - 1}px`)

// 旧が 700px で切っていた、ベースの列だけの例外(docs/design/style.md の「幅の区切り」)
const extraMaxWidths: Record<string, string[]> = {
  '../components/CocktailTable/CocktailTable.module.css': ['699px'],
}

/** CSS のメディアクエリに出てくる min-width・max-width の値を、ファイルごとに抜き出す */
const mediaWidths = (css: string) =>
  [...css.matchAll(/@media[^{]*/g)].flatMap(([query]) =>
    [...query.matchAll(/\((min|max)-width:\s*([^)]+)\)/g)].map(([, kind, value]) => ({ kind, value: value.trim() })),
  )

describe('幅の区切り', () => {
  test('TC-295-01: 区切りは旧と同じ 600px・960px・1264px', () => {
    expect(breakpoints).toEqual({ sm: 600, md: 960, lg: 1264 })
  })

  test.each([
    ['sm', '(min-width: 600px)', '(max-width: 599px)'],
    ['md', '(min-width: 960px)', '(max-width: 959px)'],
    ['lg', '(min-width: 1264px)', '(max-width: 1263px)'],
  ] as const)('TC-295-02: %s の区切りのメディアクエリは、以上が %s・未満が %s', (name, up, down) => {
    expect(mediaUp(name)).toBe(up)
    expect(mediaDown(name)).toBe(down)
  })

  test('TC-295-03: CSS を 1 つ以上読めている(読めていないと次のテストが空で通る)', () => {
    expect(Object.keys(cssFiles).length).toBeGreaterThan(0)
  })

  test.each(Object.entries(cssFiles))(
    'TC-295-04: %s のメディアクエリの幅は、区切りの値(以上は 600・960・1264px、未満は 599・959・1263px)だけを使う',
    (path, css) => {
      for (const { kind, value } of mediaWidths(css)) {
        expect(kind === 'min' ? allowedMinWidths : [...allowedMaxWidths, ...(extraMaxWidths[path] ?? [])]).toContain(value)
      }
    },
  )
})
