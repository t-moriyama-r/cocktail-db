/**
 * 幅の区切り。旧(Vuetify)の区切り(旧 components/mq.scss)と同じ値にしている(docs/design/style.md の「幅の区切り」)。
 *
 * - xs: 〜599px(スマホ)
 * - sm: 600px〜
 * - md: 960px〜
 * - lg: 1264px〜
 *
 * CSS の変数はメディアクエリに使えないので、CSS Modules には `@media (min-width: 600px)` のように数値で書く。
 * その数値がここの値からずれていないことは breakpoints.test.ts が確かめる。
 * JS で幅を見る(matchMedia など)ときは、数値を書かずにここの値を使う。
 */
export const breakpoints = {
  sm: 600,
  md: 960,
  lg: 1264,
} as const

export type Breakpoint = keyof typeof breakpoints

/** その区切り以上の幅で当てはまるメディアクエリ(例: `(min-width: 600px)`) */
export const mediaUp = (name: Breakpoint) => `(min-width: ${breakpoints[name]}px)`

/** その区切り未満の幅で当てはまるメディアクエリ(例: `(max-width: 599px)`) */
export const mediaDown = (name: Breakpoint) => `(max-width: ${breakpoints[name] - 1}px)`
