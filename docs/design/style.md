# 画面のスタイル

FE(`frontend/`)の画面のスタイルの書き方と、見た目の基準(色・余白・文字の大きさ・幅の区切り)。

> **まだ決まっていない(#294 で人が選ぶ)。** 今は、選ぶ前の段階として方式の候補と見た目の案を並べている。PR のコメントで選ばれたら、この文書を選ばれた方式と案 1 つに絞る。

## 試作の画面の開き方

見た目の案は、開発サーバーだけで開ける試作の画面(`frontend/design.html` → `frontend/src/design-proposals/`)で見比べる。本番のビルド(`vite.config.ts` の `build.rollupOptions.input` は `src/main.tsx` だけ)には入らない。

```bash
cd frontend
npm run dev
# http://localhost:5173/spa/design.html?proposal=a&screen=top
#   proposal: a(旧に寄せる)・b(夜のバー)・c(白地でシンプル)
#   screen:   top(トップ)・detail(カクテル詳細)・result(条件検索の結果の表)
#   bar=0 を付けると上の切り替えの帯を消す(スクリーンショット用)
```

試作は見本のデータ(`sampleData.ts`)を出すだけで、API は呼ばない。

## 方式の候補

| | CSS Modules + CSS 変数(試作で使ったもの) | Tailwind CSS v4 | vanilla-extract |
|---|---|---|---|
| 足す依存 | 無し(Vite が標準で扱う) | `tailwindcss`・`@tailwindcss/vite` | `@vanilla-extract/css`・`@vanilla-extract/vite-plugin` |
| ビルドの大きさ | 書いた分だけ。試作の 3 案 × 3 画面で CSS 10.56 kB(gzip 2.53 kB)。実行時のコードは無い | 使ったクラスの分だけ生成する。実行時のコードは無い(試作では測っていない) | 書いた分だけ。実行時のコードは無い(試作では測っていない) |
| 型の付き方 | `vite/client` の型では、`styles.xxx` は何でも `string` になる(クラス名の打ち間違いを型で見つけられない) | クラス名は文字列なので型は付かない | スタイルを TS で書くので、変数名・クラス名の打ち間違いを型で見つけられる |
| oxlint との相性 | oxlint は CSS を見ないので、ぶつからない。CSS の検査を足すなら別の道具(Stylelint など) | ぶつからない。クラスの並び順などの検査は、oxlint には無い(Prettier のプラグインなど別の道具) | スタイルが TS なので、oxlint と `tsc -b` がそのまま見る |
| 書き方 | 画面ごとの `*.module.css` に普通の CSS を書く。案の違いは CSS 変数(トークン)に寄せる | JSX にユーティリティのクラスを並べる。トークンは `@theme` に書く | `*.css.ts` に TS のオブジェクトで書く。トークンは `createTheme` で作る |
| 旧からの写しやすさ | 旧の SCSS(`mq.scss` の区切りなど)をほぼそのまま CSS に直せる | クラスに書き直す | TS に書き直す |

おすすめは **CSS Modules + CSS 変数**。依存を足さずに始められ、試作でそのまま 3 案を切り替えられた。クラス名に型が要るとなったときは、後から `typed-css-modules` などで型を出す道を足せる。

## 見た目の案

3 案とも画面の部品(`frontend/src/design-proposals/*.tsx` と `*.module.css`)は同じで、違いは `frontend/src/design-proposals/tokens.css` のトークンだけにある。スクリーンショットは #294 の PR にある。

| トークン | 案 A: 旧に寄せる | 案 B: 夜のバー | 案 C: 白地でシンプル |
|---|---|---|---|
| 地の色 `--color-bg` | `#ffffff` | `#12172b` | `#fafaf7` |
| 面の色 `--color-surface` | `#ffffff` | `#1b2140` | `#ffffff` |
| 文字 `--color-text` | `#212121` | `#e8e6e1` | `#1f2a24` |
| 薄い文字 `--color-text-muted` | `#616161` | `#a9a8b8` | `#5f6b64` |
| リンク `--color-link` | `#337ab7` | `#f2b84b` | `#1e7a55` |
| 差し色(検索のボタン) `--color-accent` | `#e91e63` | `#f2b84b` | `#1e7a55` |
| ヘッダーの地 `--color-header-bg` | `#99ddff` | `#0b0f1f` | `#ffffff` |
| 線 `--color-border` | `#bbddff` | `#2e3660` | `#e3e6e1` |
| 表の縞 `--color-row-alt` | `#f5faff` | `#20274a` | `#f4f6f2` |
| 見出しの線 `--color-heading-line` | `#4499ff`(左 5px・下 2px) | 下 1px の線だけ | 線なし |
| 評価の星 `--color-rate` | `#f9a825` | `#f2b84b` | `#c98a12` |
| 文字の大きさ `--font-size-base` | 15px | 15px | 16px |
| 行の高さ `--line-height` | 1.6 | 1.7 | 1.75 |
| 余白の倍率 `--space-scale` | 1 | 1.1 | 1.25 |
| 角の丸み `--radius` | 2px | 8px | 12px |
| ヘッダーの高さ `--header-height` | 40px | 52px | 56px |

案 A の色は旧の画面から取った(ヘッダーの水色 `#99DDFF`・リンクの青 `#337ab7`・見出しの線 `#4499FF`・区切りの線 `#BBDDFF`)。

### 3 案で共通のもの

- 余白の段階: `--space-1`〜`--space-6` = 4・8・12・16・24・32px × 余白の倍率
- 文字の大きさの段階: 小 0.875 倍・大 1.25 倍・特大 1.5 倍・ページの題 2.25 倍(960px 以上。未満は 1.5 倍)
- 文字の種類: システムの日本語のフォント(Web フォントは読み込まない)
- 中身の幅の上限: 1200px

## 幅の区切り

旧(Vuetify)の区切りをそのまま使う(旧の `components/mq.scss`)。CSS 変数はメディアクエリに使えないので、各 `*.module.css` に数値で書く。

| 名前 | 幅 | 変わるもの(試作) |
|---|---|---|
| xs | 〜599px | 表を 2 段(名前の段と数値の段)に組み替える。英名・ベースの列は出さない。ヘッダーの登録数を出さない |
| sm | 600px〜 | 表を普通の表で出す。トップの副題を出す |
| md | 960px〜 | ヘッダーにサイト名を出す。トップの動画と説明、詳細の説明とレシピを左右に並べる |
| lg | 1264px〜 | (試作では変えていない) |
