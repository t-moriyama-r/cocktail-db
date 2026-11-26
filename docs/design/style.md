# 画面のスタイル

FE(`frontend/`)の画面のスタイルの書き方と、見た目の基準(色・余白・文字の大きさ・幅の区切り)。

> **方式は CSS Modules + CSS 変数、見た目は案 A(旧に寄せる)に決まった。** #294 の試作で案を並べ、#348 で案 A に決め、#100 で 2026-11-26 にオーナーがこれを最終の選定にした。画面の実装は、下の「見た目(案 A)」のトークンを使う。

## 画面の実装での使い方

画面の実装が使う土台は `frontend/src/styles/` にある(#295)。`src/main.tsx` が、画面の CSS Modules より先に読む。

| ファイル | 中身 |
|---|---|
| `tokens.css` | 色・余白・文字の大きさなどの変数(`:root`)。値は案 A。見た目を変えるときは、画面の CSS ではなくここを変える |
| `base.css` | ページ全体の土台(`box-sizing: border-box`・`body` の余白を消す・地の色と文字・リンクの色・画像を親の幅に収める) |
| `breakpoints.ts` | 幅の区切りの値(下の「幅の区切り」)と、メディアクエリの文字列を作る `mediaUp`・`mediaDown`。JS で幅を見るときに使う |

- 画面ごとのスタイルは `*.module.css` に書き、色・余白・文字の大きさは数値ではなく `tokens.css` の変数を使う
- 試作(`src/design-proposals/`)は、見た目を決めるときに使った見本。変数を `src/design-proposals/tokens.css` に別に持っていて、`styles/` の土台は読まない。画面の実装は試作の変数を使わない

## 試作の画面の開き方

見た目を決めるときに使った試作の画面(`frontend/design.html` → `frontend/src/design-proposals/`)は、開発サーバーだけで開ける。本番のビルド(`vite.config.ts` の `build.rollupOptions.input` は `src/main.tsx` だけ)には入らない。

```bash
cd frontend
npm run dev
# http://localhost:5173/spa/design.html?proposal=a&screen=top
#   proposal: a(案 A。決まった見た目)
#   screen:   top(トップ)・detail(カクテル詳細)・result(条件検索の結果の表)
#   bar=0 を付けると上の切り替えの帯を消す(スクリーンショット用)
```

試作は見本のデータ(`sampleData.ts`)を出すだけで、API は呼ばない。試作のコードには、選ばなかった案も比べたときのまま残っている。画面の実装は、それに従わない。

## 方式の候補

方式を決めたときに比べた候補(#294)。決めたのは CSS Modules + CSS 変数で、ほかの 2 つは使わない。

| | CSS Modules + CSS 変数(試作で使ったもの) | Tailwind CSS v4 | vanilla-extract |
|---|---|---|---|
| 足す依存 | 無し(Vite が標準で扱う) | `tailwindcss`・`@tailwindcss/vite` | `@vanilla-extract/css`・`@vanilla-extract/vite-plugin` |
| ビルドの大きさ | 書いた分だけ。試作(比べた案すべて × 3 画面)で CSS 10.56 kB(gzip 2.53 kB)。実行時のコードは無い | 使ったクラスの分だけ生成する。実行時のコードは無い(試作では測っていない) | 書いた分だけ。実行時のコードは無い(試作では測っていない) |
| 型の付き方 | `vite/client` の型では、`styles.xxx` は何でも `string` になる(クラス名の打ち間違いを型で見つけられない) | クラス名は文字列なので型は付かない | スタイルを TS で書くので、変数名・クラス名の打ち間違いを型で見つけられる |
| oxlint との相性 | oxlint は CSS を見ないので、ぶつからない。CSS の検査を足すなら別の道具(Stylelint など) | ぶつからない。クラスの並び順などの検査は、oxlint には無い(Prettier のプラグインなど別の道具) | スタイルが TS なので、oxlint と `tsc -b` がそのまま見る |
| 書き方 | 画面ごとの `*.module.css` に普通の CSS を書く。案の違いは CSS 変数(トークン)に寄せる | JSX にユーティリティのクラスを並べる。トークンは `@theme` に書く | `*.css.ts` に TS のオブジェクトで書く。トークンは `createTheme` で作る |
| 旧からの写しやすさ | 旧の SCSS(`mq.scss` の区切りなど)をほぼそのまま CSS に直せる | クラスに書き直す | TS に書き直す |

決めたのは **CSS Modules + CSS 変数**。依存を足さずに始められ、試作で画面の CSS を変えずに変数だけで見た目の案を切り替えられた。クラス名に型が要るとなったときは、後から `typed-css-modules` などで型を出す道を足せる。

## 見た目(案 A)

**決定: 案 A(旧に寄せる)**(オーナーの判断。#348 で案 A に決め、#100 で 2026-11-26 に最終の選定にした)。

値は `frontend/src/styles/tokens.css` にあり、下の表はその値と同じにする。見比べた試作のスクリーンショットは #294 の PR にある。

| トークン | 案 A: 旧に寄せる |
|---|---|
| 地の色 `--color-bg` | `#ffffff` |
| 面の色 `--color-surface` | `#ffffff` |
| 文字 `--color-text` | `#212121` |
| 薄い文字 `--color-text-muted` | `#616161` |
| リンク `--color-link` | `#337ab7` |
| 差し色(検索のボタン) `--color-accent` | `#e91e63` |
| ヘッダーの地 `--color-header-bg` | `#99ddff` |
| 線 `--color-border` | `#bbddff` |
| 表の縞 `--color-row-alt` | `#f5faff` |
| 見出しの線 `--color-heading-line` | `#4499ff`(左 5px・下 2px) |
| 評価の星 `--color-rate` | `#a15c00`(旧は `#f9a825` だが白地でコントラストが足りず濃くした) |
| 文字の大きさ `--font-size-base` | 15px |
| 行の高さ `--line-height` | 1.6 |
| 余白の倍率 `--space-scale` | 1 |
| 角の丸み `--radius` | 2px |
| ヘッダーの高さ `--header-height` | 40px |

試作の後に、部品を作るときに `frontend/src/styles/tokens.css` へ足したトークン(試作の `design-proposals/tokens.css` には無い):

| トークン | 値 | 使うところ |
|---|---|---|
| 動画のある行のリンク `--color-has-movie` | `#c24e00`(旧は `#ff6600` だが、白地で 2.94:1 のため 4.5:1 を満たすよう濃くした。#298) | カクテルの一覧の表で、動画のあるカクテルの行のリンクと、説明文の中の動画があるリンク(#298・#301) |
| YouTube のアイコン `--color-youtube` | `#c4302b`(旧と同じ) | カクテルの一覧の表の、動画の列のアイコン(#298) |
| 材料の欄の根の背景 `--color-ingredient-liquor-bg`・`--color-ingredient-soft-drink-bg`・`--color-ingredient-other-bg` | `#ddeeff`・`#ffffff`・`#eeeeee`(旧の `form.vue` の種別ごとの背景と同じ。リンクの色 `#337ab7` は `#ddeeff` で 3.85:1・`#eeeeee` で 3.93:1 で、4.5:1 に届かない。旧と同じ) | 検索フォームの材料の欄で、根(旧の種別)ごとに分ける背景。役割なし(蒸留酒・醸造酒・混成酒)・ソフトドリンク・その他(#305) |
| 押せないボタンの地 `--color-disabled-bg` | `#e0e0e0`(文字は `--color-text-muted` で 4.69:1) | ヘッダーの名前検索のボタンで、入力が空・検索中・0 件・サーバーエラーのとき(#99) |
| 誤りの地 `--color-danger` | `#c62828`(文字は `--color-on-accent` で 5.62:1) | ヘッダーの名前検索のボタンで、禁止文字を含むときの「Error」(#99) |
| カテゴリの詳細の欄の線 `--color-famous-line`・`--color-children-line` | `#006699`・`#ff6600`(旧の `Detail/CategoryDetail.vue` の有名な銘柄・ミドルカテゴリの欄と同じ。線だけで文字には使わない) | カテゴリの詳細の、有名な銘柄と子のノードの欄の線(#105) |
| 詳細の欄の線 `--color-detail-data-line`・`--color-detail-recipe-line`・`--color-detail-note-line` | `#006699`・`#ff6600`・`#98d4d6`(旧の `Detail/CocktailDetail.vue` と同じ。文字ではない線なので 4.5:1 の対象外) | カクテルの詳細の、ベースなどの欄の左の線・材料の欄の左の線・Note の上の線(#108) |
| 詳細の関連の欄の線 `--color-detail-same-name-line`・`--color-detail-similar-line`・`--color-detail-same-base-line` | `#fae125`・`#3399ff`・`#1f0d85`(旧の `Detail/CocktailDetail.vue` と同じ。文字ではない線なので 4.5:1 の対象外) | カクテルの詳細の、同じ名前のカクテルの欄(旧に無い別名・同じレシピの欄も同じ線)・材料が似たカクテルの欄・同じベースのカクテルの欄の左の線(#109) |
| 区切りの文字 `--color-section-label` | `#1177ff`(旧と同じ。白地で 4.11:1 で、4.5:1 に届かない) | カクテルの詳細の、960px 未満で説明文の上に出す「§Description§」(#108) |
| モーダルの後ろ `--color-overlay` | `rgb(33 33 33 / 46%)`(旧の Vuetify の v-overlay と同じ色と濃さ) | ヘッダーの条件検索のモーダル(#99) |
| 用語辞典の分類の見出しの線 `--color-dictionary-category-line` | `#006699`(旧の `Dictionary/index.vue` と同じ。旧でもほかの見出しの線 `#4499FF` と違った) | 用語辞典の一覧の、分類の見出しの左と下の線(#56) |
| 用語辞典のカクテルの欄の線 `--color-dictionary-method-line`・`--color-dictionary-glass-line` | `#3399ff`・`#1f0d85`(旧の `Dictionary/detail.vue` と同じ。飾りの線で、文字には使わない) | 用語辞典の詳細の「これを使ったカクテル」の左の線。技法とグラスで色を分ける(#56) |
| 商品の欄 `--color-affiliate-border`・`--color-affiliate-price`・`--color-amazon`・`--color-rakuten`・`--color-affiliate-lower` | `#dfdfdf`・`#ff0000`・`#f79901`・`#bf0000`・`#ffff00`(旧の `Detail/component/AffiliateContainer.vue` と同じ。価格の赤は白地で 4.0:1、Amazon のボタンの地は白い文字で 2.2:1 で、4.5:1 に届かない。旧と同じ) | 商品の欄(#303)の枠の線・本体の価格の文字・「Amazonで探す」「楽天で探す」のボタンの地・楽天のボタンの安い方の価格の文字 |

案 A の色は旧の画面から取った(ヘッダーの水色 `#99DDFF`・リンクの青 `#337ab7`・見出しの線 `#4499FF`・区切りの線 `#BBDDFF`)。

### 余白・文字・幅の段階

- 余白の段階: `--space-1`〜`--space-6` = 4・8・12・16・24・32px × 余白の倍率(案 A は 1 なので、そのままの px)
- 文字の大きさの段階: 小 0.875 倍・大 1.25 倍・特大 1.5 倍・ページの題 2.25 倍(960px 以上。未満は 1.5 倍)
- 文字の種類: システムの日本語のフォント(Web フォントは読み込まない)
- 中身の幅の上限: 1200px

## 幅の区切り

旧(Vuetify)の区切りをそのまま使う(旧の `components/mq.scss`)。値は `frontend/src/styles/breakpoints.ts` にまとめている。CSS 変数はメディアクエリに使えないので、各 `*.module.css` には数値で書く(以上は `min-width: 600px`・`960px`・`1264px`、未満は `max-width: 599px`・`959px`・`1263px`)。ほかの値を書くと `breakpoints.test.ts` が落ちる。例外は、カクテルの一覧の表のベースの列(#298)と、銘柄の一覧の表の所属するノードの段(#299)を 700px 未満で隠す `max-width: 699px` と、商品の欄(#303)を 768px 以上で画像の右に題名・ボタンを並べる `min-width: 768px` だけ(旧と同じ見た目にするため。テストに許す値として、ファイルごとに書いてある)。

| 名前 | 幅 | 変わるもの(試作) | 証跡を撮る幅 |
|---|---|---|---|
| xs | 〜599px | 表を 2 段(名前の段と数値の段)に組み替える。英名・ベースの列は出さない。ヘッダーの登録数を出さない | 375px |
| sm | 600px〜 | 表を普通の表で出す。トップの副題を出す | 768px |
| md | 960px〜 | ヘッダーにサイト名を出す。トップの動画と説明、詳細の説明とレシピを左右に並べる | 1024px |
| lg | 1264px〜 | (試作では変えていない) | 1440px |

### 証跡を撮る幅

FE の画面を変える PR の証跡(CLAUDE.md の「ブランチ・コミット・PR」の 5)は、上の表の「証跡を撮る幅」で撮る(#432)。どれかの区切りで崩れていても、証跡に出るようにするため。

- `.css` を変えた PR は、4 つの幅すべてで修正前・修正後の静止画を撮る。比較画像は幅ごとに 1 枚
- `.css` を変えず `.tsx` だけを変えた PR(文言・リンク先・画像の `src` など)は、両端の xs 375px・lg 1440px の 2 つで撮る。崩れは CSS の差分から出ることがほとんどで、間の幅まで撮る手間に見合わないため(#604)
- 動画は、操作で変わるものがある幅で撮る(少なくとも lg と xs)
- 区切りの境目(599/600px・959/960px)で出し方を変える CSS を足した・変えたときは、境目の両側の幅も撮る
- 「## 証跡」には、撮った幅を `<数字>px` で書く(例: `xs 375px`。HTML コメントの中に書いた幅は数えない)。`frontend/src/` の `.tsx`・`.css` を変えた PR では、`evidence-gate.sh` が、撮るべき区切り(`.css` を変えたら 4 つ、`.tsx` だけなら xs・lg)それぞれに入る幅が書かれているかを確かめ、足りない区切りの名前と幅を出して落とす
- 振る舞いを変えないリファクタで、本文に `<!-- ai-dev:refactor-evidence 理由 -->` を書いた PR は、幅ごとに撮らない(CLAUDE.md の手順 5)
- 画面に出ない FE の変更(純関数・型・テストだけ)で `.tsx`・`.css` を変えたときは、本文に `<!-- ai-dev:no-viewport-evidence 理由 -->` を書けば、この検査を飛ばす(理由の無い印では飛ばさない)
- BE だけの PR(画面が無い)は対象外
