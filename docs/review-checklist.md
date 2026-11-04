# レビューで繰り返し出た指摘

2 件以上の PR のレビューで出た種類の指摘を、ルールにしたもの。コードを書く前と、PR にする前に読む。
CodeRabbit(`.coderabbit.yaml` の `knowledge_base.code_guidelines`)と代わりの Claude のレビュー(`ai-claude-review.yml`)も、これを基準にレビューする。

- ルールは、週 1 回の振り返り(スキル集の `ai-driven-dev/references/knowledge-loop.md`)が足す。足す PR は人が承認してからマージする
- ID(`RC-<連番 2 桁>`)は変えない。振り返りが、ルールにした後に同じ指摘がまた出た回数を ID で数える
- 実態と合わなくなったルールは、月 1 回の棚卸しで消す。消した ID は使い回さない

## RC-01: コマンドの失敗を見逃さない

追加: 2026-10-27 / 出た PR: [#148](https://github.com/t-moriyama-r/cocktail-db/pull/148)・[#197](https://github.com/t-moriyama-r/cocktail-db/pull/197)・[#198](https://github.com/t-moriyama-r/cocktail-db/pull/198)・[#208](https://github.com/t-moriyama-r/cocktail-db/pull/208)・[#213](https://github.com/t-moriyama-r/cocktail-db/pull/213)・[#222](https://github.com/t-moriyama-r/cocktail-db/pull/222)・[#224](https://github.com/t-moriyama-r/cocktail-db/pull/224)・[#230](https://github.com/t-moriyama-r/cocktail-db/pull/230)・[#233](https://github.com/t-moriyama-r/cocktail-db/pull/233)・[#237](https://github.com/t-moriyama-r/cocktail-db/pull/237)・[#253](https://github.com/t-moriyama-r/cocktail-db/pull/253)

シェル(ワークフローの `run`・`.github/scripts`・`ops/`)で、途中のコマンドが失敗しても先へ進み、空の値や途中までのファイルで後ろの処理が動く。原因の場所ではなく、後ろの処理で分かりにくく落ちる。

- `echo "x=$(cmd)"`・`local x=$(cmd)` は、`cmd` の終了コードを捨てる。代入を別の文にする
- パイプは最後のコマンドの終了コードしか見ない。`set -o pipefail` にする(ワークフローの `run` は `shell:` を書かなければ bash の `-e` だけで、`pipefail` は付かない)
- `|| true`・`|| echo ...` で黙らせるのは、失敗しても本来の仕事に関係ない処理(見た目の印・通知)だけにする。`gh api` の失敗を「無かった」と同じに扱わない(権限・レート制限・通信の失敗を、存在しない issue と区別する)
- 途中で落ちても後片付け(一時ファイル・コンテナ・目印のラベル)が残らないよう、`trap` か `if: always()` で片付ける。最終名のファイルは、全部書けてから `mv` で置く

```bash
# 悪い: draft-candidates.sh が落ちても、ステップは成功し prs は空になる
echo "prs=$(bash .github/scripts/draft-candidates.sh "$REPO")" >> "$GITHUB_OUTPUT"

# 良い: 代入で失敗をステップの失敗にする
prs=$(bash .github/scripts/draft-candidates.sh "$REPO")
echo "prs=$prs" >> "$GITHUB_OUTPUT"
```

## RC-02: Claude Code に渡す権限を、その仕事に要るだけに絞る

追加: 2026-10-27 / 出た PR: [#146](https://github.com/t-moriyama-r/cocktail-db/pull/146)・[#196](https://github.com/t-moriyama-r/cocktail-db/pull/196)・[#215](https://github.com/t-moriyama-r/cocktail-db/pull/215)・[#233](https://github.com/t-moriyama-r/cocktail-db/pull/233)・[#237](https://github.com/t-moriyama-r/cocktail-db/pull/237)

公開リポジトリなので、Claude Code が読む issue・PR のコメントには、外の人が書いた指示(プロンプトインジェクション)が混ざりうる。渡した権限は、その指示でも使える。

- Claude Code を動かすジョブには、ワークフローを起こす権限(`actions: write`)を渡さない。ワークフローを呼ぶのは、Claude Code を動かさない別のジョブにする
- `--allowedTools` の `Bash(cmd:*)` は前方一致なので、引数で絞る。PR の番号で絞るときは、番号の後ろまで書く(`gh pr comment 12:*` は `gh pr comment 123` にも効く)
- `Bash(gh api:*)`・`Bash(jq:*)` のように引数を絞れないものは、環境変数(トークン)を読める・何でも書けると考えて、渡すかを決める

```yaml
# 悪い: 番号の前方一致で、ほかの PR にもコメント・編集できる
--allowedTools "Bash(gh pr comment ${{ env.PR }}:*),Bash(gh pr edit:*)"

# 良い: 番号の後ろのオプションまで書き、使う操作だけを渡す
--allowedTools "Bash(gh pr comment ${{ env.PR }} --body:*),Bash(gh pr edit ${{ env.PR }} --add-label needs-human)"
```

## RC-03: concurrency は、ジョブの if より前に評価される

追加: 2026-10-27 / 出た PR: [#178](https://github.com/t-moriyama-r/cocktail-db/pull/178)・[#217](https://github.com/t-moriyama-r/cocktail-db/pull/217)

ワークフローの `concurrency` は、ジョブの `if` で飛ばされる起動(関係ないコメント・ラベルの付け外し)も同じグループに入れる。`cancel-in-progress` や、待てるのは 1 つだけという決まりで、本来動かすべき実行が取り消される。

- 投稿者(`github.event.comment.user.login`)も条件に入れる。本文だけで絞ると、誰でも同じ文言を書いてグループに入れてしまう
- グループには、動かす起動だけを PR ごとのグループに入れ、ほかは実行ごとのグループ(`github.run_id`)に逃がす
- 番号の無い起動(`schedule`・`workflow_run`)を、PR ごとのグループと同じ名前にまとめない

```yaml
# 良い: レビューする起動だけを PR ごとに並べ、ほかは実行ごとに分ける(ai-claude-review.yml)
concurrency:
  group: >-
    ${{ (github.event_name == 'workflow_dispatch'
      || (github.event.comment.user.login == 'coderabbitai[bot]'
        && (contains(github.event.comment.body, 'Review limit reached') || contains(github.event.comment.body, 'Review rate limited'))))
      && format('ai-claude-review-{0}', github.event.issue.number || inputs.number)
      || format('ai-claude-review-run-{0}', github.run_id) }}
```

## RC-04: checkout に資格情報を残さない

追加: 2026-10-27 / 出た PR: [#86](https://github.com/t-moriyama-r/cocktail-db/pull/86)・[#237](https://github.com/t-moriyama-r/cocktail-db/pull/237)

`actions/checkout` は既定で、ジョブのトークンを `.git/config` に書き残す。その後に動くもの(依存のインストールスクリプト・PR 側のスクリプト・Claude Code)がトークンを読めて、ジョブの書き込みの権限を使える。

- checkout には `persist-credentials: false` を付ける。push が要るジョブだけ外し、外す理由をコメントに書く
- 書き込みの権限を持つジョブ(`pull_request_target`・`pull_request_review` など)で、PR 側のコードを checkout して動かさない。スクリプトは既定のブランチから取る

```yaml
# 良い: 既定のブランチのスクリプトだけを取り、トークンを残さない(ai-auto-merge.yml)
- uses: actions/checkout@v6
  with:
    ref: ${{ github.event.repository.default_branch }}
    path: .ai-dev
    sparse-checkout: .github/scripts
    persist-credentials: false
```

## RC-05: 目印・キーワードで判定するときは、書いた人も確かめる

追加: 2026-10-27 / 出た PR: [#146](https://github.com/t-moriyama-r/cocktail-db/pull/146)・[#178](https://github.com/t-moriyama-r/cocktail-db/pull/178)・[#196](https://github.com/t-moriyama-r/cocktail-db/pull/196)

公開リポジトリでは、誰でも PR・issue にコメントを書ける。コメントの目印(`<!-- ai-dev:... -->`)や本文のキーワード(`Review limit reached` など)だけで判定すると、外の人が同じ文字列を書くだけで、見回りから外す・通知に自分の文を載せる・実行を取り消す、ができてしまう。

- 目印・キーワードで判定するときは、書いた人(`author_association` がオーナー・メンバーか、`user.login` が決まった bot か)も条件に入れる。`.user.type == "Bot"` だと、どの GitHub App の bot でも通る。Bot 全般を信頼するなら、その前提を書き、そうでなければ `user.login` の許可リストにする
- ワークフローの `if`・`concurrency` で本文を見るときも、投稿者を一緒に見る

```jq
# 良い: 信頼できる人か bot が書いたものから、目印のあるコメントを選ぶ(notify-needs-human.yml)
# `.user.type == "Bot"` は Bot 全般を信頼する前提。絞るなら `.user.login` の許可リストにする
["OWNER","MEMBER","COLLABORATOR"] as $trusted
  | $comments | map(select((.author_association as $a | $trusted | index($a)) or .user.type == "Bot"))
  | map(select(.body | contains("ai-dev:\($label)"))) | last
```

## RC-06: GitHub の API は、全部のページを取ってから数える

追加: 2026-10-27 / 出た PR: [#196](https://github.com/t-moriyama-r/cocktail-db/pull/196)・[#202](https://github.com/t-moriyama-r/cocktail-db/pull/202)

GraphQL の `first: 100`、`gh pr view --json commits`(先頭 100 件)などは、それより多いと黙って途中までを返す。「返信待ちが 0 件」「いちばん新しいコミット」を、途中までの結果で判定してしまう。

- 件数が上限を超えうるものは、`--paginate`(GraphQL は `pageInfo { hasNextPage endCursor }` と `$endCursor`)で全部取る。`--paginate` はページごとに結果を出すので、最後に合計する
- いちばん新しいコミットは `headRefOid` で取る。`.commits[-1]` を使わない

```bash
# 良い: 全ページを取り、ページごとの数を足す(unanswered-review-threads.sh)
gh api graphql --paginate -F owner="${repo%%/*}" -F name="${repo#*/}" -F number="$number" -f query='
  query($owner: String!, $name: String!, $number: Int!, $endCursor: String) {
    repository(owner: $owner, name: $name) { pullRequest(number: $number) {
      reviewThreads(first: 100, after: $endCursor) { pageInfo { hasNextPage endCursor } nodes { isResolved } } } } }' \
  --jq '[.data.repository.pullRequest.reviewThreads.nodes[] | select(.isResolved | not)] | length' | jq -s add
```

## RC-07: 判断する直前に、今の状態を取り直す

追加: 2026-10-27 / 出た PR: [#146](https://github.com/t-moriyama-r/cocktail-db/pull/146)・[#196](https://github.com/t-moriyama-r/cocktail-db/pull/196)・[#237](https://github.com/t-moriyama-r/cocktail-db/pull/237)

候補を選ぶジョブと、それを処理するジョブ(または同じジョブの後ろのほう)のあいだには、数十分〜数時間空くことがある。そのあいだに人がラベルを付け外しした・push があった・別の実行が先に処理した、という変化を見ずに、選んだときの状態で処理すると、二重の通知・古いコミットでの判断・人が止めたものの続行が起きる。

- Claude Code を動かす・ラベルを付ける・通知する直前に、PR・issue の今の状態(ラベル・head・開いているか)を取り直し、選んだときと違えば見送る
- 「処理した」という目印は、選んだときのコミット(sha)に結び付けて書く

```yaml
# 良い: イベントのラベルはコメントした時点のもの。動く前に、今も付いているかを取り直す(ai-decision-loop.yml)
- name: いまも needs-decision が付いているか
  id: live
  run: |
    labels=$(gh issue view "$ISSUE" -R "$REPO" --json labels --jq '[.labels[].name] | join(",")')
    if [[ ",$labels," == *",needs-decision,"* ]]; then echo "open=true" >> "$GITHUB_OUTPUT"; fi
```

## RC-08: 画面を変える PR の証跡を、幅の区切りごとにそろえる

追加: 2026-10-28 / 出た PR: [#427](https://github.com/t-moriyama-r/cocktail-db/pull/427)(オーナーの依頼でルールにした。#432)

FE の画面は、幅の区切り(xs 〜599px・sm 600px〜・md 960px〜・lg 1264px〜)で見た目が変わる。証跡を「PC 幅」と「スマホ幅 375px」の 2 つだけで撮ると、その間の幅(sm・md)で崩れていても証跡に出ない。

- `.css` を変えた FE の画面の PR の「## 証跡」は、`docs/design/style.md` の「証跡を撮る幅」の 4 つ(xs 375px・sm 768px・md 1024px・lg 1440px)すべてで、修正前後の静止画をそろえる。`.css` を変えず `.tsx` だけを変えた PR は、xs 375px・lg 1440px の 2 つでよい(#604)。比較画像は幅ごとに 1 枚。動画は操作で変わるものがある幅で撮る(少なくとも lg と xs)
- 区切りの境目(599/600px・959/960px)で出し方を変える CSS を足した・変えたときは、境目の両側の幅もそろえる
- `<!-- ai-dev:refactor-evidence 理由 -->` で修正前後を省いている PR は、差分が本当に振る舞いを変えないか(画面・API の出す値が変わらないか)を確かめる
- 撮った幅は `<数字>px` で書く(`evidence-gate.sh` が数える)。`<!-- ai-dev:no-viewport-evidence 理由 -->` で検査を飛ばしている PR は、理由のとおり画面に出ない変更か(純関数・型・テストだけか)を差分で確かめる

```markdown
<!-- 悪い: sm・md の幅が無い -->
## 証跡
PC 幅(1440px)とスマホ幅(375px)の修正前・修正後

<!-- 良い: 区切りごとに 1 つずつ撮り、幅を書く -->
## 証跡
xs 375px・sm 768px・md 1024px・lg 1440px の修正前・修正後(比較画像は幅ごとに 1 枚)
```

## RC-09: 旧と挙動を変えたら、「旧との差分」の 3 か所をそろえる

追加: 2026-11-01 / 出た PR: [#320](https://github.com/t-moriyama-r/cocktail-db/pull/320)・[#343](https://github.com/t-moriyama-r/cocktail-db/pull/343)・[#345](https://github.com/t-moriyama-r/cocktail-db/pull/345)・[#406](https://github.com/t-moriyama-r/cocktail-db/pull/406)・[#415](https://github.com/t-moriyama-r/cocktail-db/pull/415)・[#418](https://github.com/t-moriyama-r/cocktail-db/pull/418)・[#426](https://github.com/t-moriyama-r/cocktail-db/pull/426)・[#428](https://github.com/t-moriyama-r/cocktail-db/pull/428)・[#433](https://github.com/t-moriyama-r/cocktail-db/pull/433)・[#440](https://github.com/t-moriyama-r/cocktail-db/pull/440)・[#470](https://github.com/t-moriyama-r/cocktail-db/pull/470)・[#510](https://github.com/t-moriyama-r/cocktail-db/pull/510)・[#527](https://github.com/t-moriyama-r/cocktail-db/pull/527)・[#529](https://github.com/t-moriyama-r/cocktail-db/pull/529)

旧と挙動を変えるコード(入力の上限・エラーの出し方・計算できないときの例外・リンクの形など)を足したのに、決めた文書の「旧との差分」の節・PR 本文のテストケースの表(`差分あり(意図: …)`)・PR 本文の「旧との差分の根拠」の引用のどれかが抜ける。レビュアーが意図した仕様変更か回帰かを見分けられず、あとで差分の根拠をたどれなくなる。

- 旧と変えたコードを足したら、(1) 決めた issue か `docs/decisions/` の文書の「旧との差分」に 1 行、(2) PR 本文の表の「旧での結果」に `差分あり(意図: <その文書>)`、(3) 「旧との差分の根拠」にその 1 行の引用、の 3 つをそろえる
- 「旧での結果」は、旧を動かした実測で書く。ソースを読んだだけなら `未確認(理由)` にする。`同じ` と書いた行が、足した差分と食い違っていないか見直す
- 旧に画面や API が無い新しい仕様は `旧に無い`。旧が違う挙動だったと書いたなら、`差分あり` にする
- 差分をレビューで指摘されて文書に足したら、表と引用も同じ PR で足す

```markdown
<!-- 悪い: 件数の上限 1000(旧は上限が無い)を足したが、PR 本文の表に TC-163-20 の行も、根拠の節の引用も無い(#426) -->
| TC-163-19 | CSRF トークンなしで POST | 200 | 旧に無い |

<!-- 良い(文書名は例): 文書の「旧との差分」に 1 行 → 表で指す → 根拠の節で引く -->
| TC-163-20 | category_ids が 1001 件 | 422 | 差分あり(意図: docs/decisions/search-limits.md) |

### 旧との差分の根拠
TC-163-20 → docs/decisions/search-limits.md: 旧は件数の上限が無く巨大な配列も通る → 新は 1000 件を超えたら 422
```

## RC-10: トランザクションの中で、画像のファイルを先に消さない・置いたまま残さない

追加: 2026-11-01 / 出た PR: [#415](https://github.com/t-moriyama-r/cocktail-db/pull/415)・[#439](https://github.com/t-moriyama-r/cocktail-db/pull/439)

DB の行はトランザクションでロールバックできるが、ファイルは戻らない。トランザクションの中で前のファイルを先に消すと、そのあとで保存が落ちたとき、行は元に戻るのにファイルだけ無くなって画像が壊れる。逆に、新しいファイルを置いたあとで落ちると、行に結び付かないファイルが残る。

- 前のファイルを消すのは、行の保存が終わり、コミットされたあと(`DB::afterCommit`)にする
- 新しいファイルを置くのは保存の直前にして、落ちたときに置いたファイルを消す後片付けを付ける(`AdminImageStore`)。外側のトランザクションがロールバックされる場合も考える
- 行の削除と画像の削除を同じ処理でするときも同じ。行を消してから、コミット後にファイルを消す

```php
// 悪い: update() がファイルをすぐ消していた(#439 のときの AdminDictionaryWriter)。あとの delete() が落ちてロールバックされても、ファイルは戻らない
$this->images->update(null, true, $dictionary->image);
$dictionary->delete();

// 良い: トランザクションの中なら、コミットしてから消す。置いたファイルは、ロールバックされたら消す(AdminImageStore)
DB::afterRollBack(fn () => $this->disk()->delete($stored));
DB::afterCommit(fn () => $this->disk()->delete($current));
```

## RC-11: 外から来る値は、型・形・件数・長さを検査してから使う

追加: 2026-11-01 / 出た PR: [#370](https://github.com/t-moriyama-r/cocktail-db/pull/370)・[#406](https://github.com/t-moriyama-r/cocktail-db/pull/406)・[#415](https://github.com/t-moriyama-r/cocktail-db/pull/415)・[#426](https://github.com/t-moriyama-r/cocktail-db/pull/426)・[#470](https://github.com/t-moriyama-r/cocktail-db/pull/470)・[#565](https://github.com/t-moriyama-r/cocktail-db/pull/565)

認証の要らない公開の API や、保存した値を画面にそのまま出す管理の API では、想定外の値が来る。検査が足りないと、巨大な配列で負荷をかけられる・範囲外の数字で 500 になる・`true` や `1.0` が ID として通る・記法が壊れた値が保存されて画面で XSS や壊れた表示になる。

- 配列は、件数の上限を `max:` で付ける。入れ子は、内側の配列にも付ける(外側だけだと、内側に巨大な配列を入れられる)
- 数字の ID は、`integer` だけだと `true`・`1.0` も通る。JSON の整数だけを受けるなら、型を確かめる規則を足す。URL の ID は桁数も絞る(範囲を超えると 500 になる)
- URL・動画の ID など、画面で属性や URL に埋め込む文字列は、`url:http,https`・正規表現と `max:` で形と長さを絞る。空配列・省略した欄の扱い(`prohibited_if` が空配列を通すなど)も試す
- 正規表現で記法を読むときは、`@` が 2 つ以上・改行を含む・数字の先頭が 0 のような端の入力を、テストに入れる

```php
// 悪い: 形も長さも見ない。javascript: も 255 文字を超える値も保存できる(#415 の image_url)
'image_url' => ['nullable', 'string'],

// 良い: 形と長さを絞る。ID は bool・float を弾く(SaveBrandRequest・CocktailSearchRequest)
'image_url' => ['nullable', 'url:http,https', 'max:2048'],
'category_ids' => ['required_unless:mode,and', 'array', 'list', 'max:'.self::MAX_CATEGORY_IDS],
'category_ids.*' => ['required', 'integer', self::notBoolOrFloat(...)],
```

## RC-12: API の入力の定義を変えたら、`openapi.json` を作り直して中身を読む

追加: 2026-11-01 / 出た PR: [#415](https://github.com/t-moriyama-r/cocktail-db/pull/415)・[#426](https://github.com/t-moriyama-r/cocktail-db/pull/426)・[#440](https://github.com/t-moriyama-r/cocktail-db/pull/440)・[#470](https://github.com/t-moriyama-r/cocktail-db/pull/470)

入力の規則(必須・上限・型)や形式(multipart など)を変えたのに、`openapi/openapi.json` を作り直し忘れる。または作り直しても、規則が定義に出ない・実装と食い違う。CI の「Check OpenAPI is up to date」が落ちるだけでなく、FE の型の元が実装とずれ、本文を省略できるような定義になる。

- 入力の規則を変えたら、`php artisan scramble:export --path=../openapi/openapi.json` を流し、`git diff openapi/openapi.json` で、変えた規則(`required`・`maxItems`・`maxLength`・型)が定義に出ているかを読む
- 規則が定義に出ない形(クロージャの規則・`prohibited_if` など)は、定義が実装より緩くなる。FE が頼る部分(本文が必須か・上限)が合っているかを確かめる
- multipart のフォームは、真偽値の送り方(`"1"`・`"0"` など)が定義と合うかを確かめる

```bash
# 悪い: 上限を足したが再生成しない → openapi.json に maxItems が無い(#426)
# 良い: 再生成して差分を読む
cd backend && php artisan scramble:export --path=../openapi/openapi.json
git diff ../openapi/openapi.json | grep -nE 'maxItems|maxLength|required|"type"'
```

## RC-13: 挙動を変えたら、それを説明する文書・コメント・例も同じ PR で直す

追加: 2026-11-01 / 出た PR: [#149](https://github.com/t-moriyama-r/cocktail-db/pull/149)・[#271](https://github.com/t-moriyama-r/cocktail-db/pull/271)・[#344](https://github.com/t-moriyama-r/cocktail-db/pull/344)・[#348](https://github.com/t-moriyama-r/cocktail-db/pull/348)・[#353](https://github.com/t-moriyama-r/cocktail-db/pull/353)・[#356](https://github.com/t-moriyama-r/cocktail-db/pull/356)・[#413](https://github.com/t-moriyama-r/cocktail-db/pull/413)・[#454](https://github.com/t-moriyama-r/cocktail-db/pull/454)・[#476](https://github.com/t-moriyama-r/cocktail-db/pull/476)・[#541](https://github.com/t-moriyama-r/cocktail-db/pull/541)・[#556](https://github.com/t-moriyama-r/cocktail-db/pull/556)・[#603](https://github.com/t-moriyama-r/cocktail-db/pull/603)

ワークフロー・スクリプト・ルールの挙動を変えたのに、それを説明する文書(`docs/labels.md`・`README.md`・`CLAUDE.md`)・ワークフローの冒頭のコメント・ルールの「良い例」・PR 本文が、変える前のまま残る。あとで読んだエージェントが古い説明どおりに動く。同じ決まりを 2 か所に書くと、片方だけ直して食い違う。

- 変えた名前・挙動(ラベル・ジョブ・コマンド・数字)で `CLAUDE.md`・`README.md`・`docs/`・`.github/` を grep し、説明が残っていないかを確かめる
- 例外を書く決まりは、本文と例が矛盾しないようにする(「例外は無い」と書いて例外を並べない。「良い例」が本文の注意と同じ書き方をしていない)
- PR 本文に「ワークフローは変えていない」と書くなら、差分にワークフローが無いことを確かめる
- 同じ決まりは 1 か所に書き、ほかからはそこを指す

```markdown
<!-- 悪い: pr-status.yml が下書きのラベルでも動くよう変えたのに、labels.md が古いまま(#476) -->
下書きの PR へのラベルの付け外しでは pr-status.yml が動かない。見回りなどで反映される。

<!-- 良い: 変更と同じ PR で、実際の動きに直す -->
下書きの PR でも、待ちのラベルの付け外しですぐ付け替わる。
```

## RC-14: テストの名前・ケース ID を、確かめている中身と合わせる

追加: 2026-11-01 / 出た PR: [#426](https://github.com/t-moriyama-r/cocktail-db/pull/426)・[#500](https://github.com/t-moriyama-r/cocktail-db/pull/500)・[#551](https://github.com/t-moriyama-r/cocktail-db/pull/551)

テストの名前・ケース ID・PR 本文の表の期待値が、テストが実際に確かめていることと食い違う。名前を信じて安心するが、確かめていないので、壊れても通る。ゆるい検証(個数が 0 より大きいだけ、など)は、エラー応答でも通る。

- テスト名は、本体が確かめていることを書く(「戻るとチェックが残る」と書いて、トップが出ることだけを見ない)
- ケース ID(`TC-<issue 番号>-<連番>`)は、PR 本文の表と同じ中身を指す。同じ ID を別のテストに使わない
- 状態を確かめるときは、成功した状態だけが満たす条件にする(`toBeGreaterThan(0)` や「`role="status"` が 1 件」は、エラー表示でも満たせないか見直す)

```ts
// 悪い: 読み込み表示が 1 つ残っていても通る(#551)
expect(screen.getAllByRole('status').length).toBeGreaterThan(0)

// 良い: 4 つの一覧の読み込み表示が出ること、消えたあとに一覧が出ることを、それぞれ確かめる
expect(screen.getAllByRole('status')).toHaveLength(4)
await waitFor(() => expect(screen.queryAllByRole('status')).toHaveLength(0))
expect(screen.getAllByRole('listitem').length).toBeGreaterThan(0)
```
