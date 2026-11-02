# 判断表

オーナーが過去に答えた判断を、規則として書いたもの。人に判断を頼む(`needs-human`・`needs-decision`)前に、エージェントがこの表を引く。当てはまる規則があれば、聞かずにその判断で進め、どの規則で決めたかをコメントに残す(下の「規則で進めたとき」)。

人に聞く回数を減らすためのもの。2026-11-02 までに頼んだ 154 回のうち、オーナー本人が答えたのは 72 回で、その答えを読むと、決まった型の問いではほぼ毎回おすすめどおりに答えていた(下の「数字」)。毎回同じ答えが返る問いは、聞いても人の手間が増えるだけなので、規則にして聞かずに進める。

規則には、何を選んだかだけでなく、**なぜそう選んだか(背景)と、その背景が成り立つ前提**を書く。背景の無い規則は、場面の見た目だけで当てはめられ、少し違う場面で誤る。背景があれば、エージェントは「前提が崩れていれば聞く」と判断できる(下の「規則の書き方」)。

Actions の Claude Code(`ai-review-loop.yml`・`ai-draft-sweep.yml`)は、PR の head ではなく既定のブランチのこの表を読む。PR の中でこの表を書き換えて、聞かずに進める範囲を自分で広げることはできない。この表を変える PR は、閉じる issue に `needs-review` を付け、オーナーが確かめてからマージする。

## 規則の書き方

一般に勧められている次のやり方に合わせる。

| やり方 | この表での形 |
|---|---|
| 決定の記録(Architecture Decision Record。[Michael Nygard の形](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions)・[adr.github.io](https://adr.github.io/)): 決定ごとに、状況(Context)・決定(Decision)・結果(Consequences)・状態(Status)を残し、変えるときは消さずに置き換える | 規則ごとに「場面」「既定の判断」「背景」「前提」「選ぶと起きること」「状態」を書く。規則を変えるときは、前の中身と置き換えた理由を規則の節に残す |
| 指示には理由を添える([Claude のプロンプトの指針](https://platform.claude.com/docs/en/build-with-claude/prompt-engineering/claude-prompting-best-practices)): 理由の付いた指示は、書いていない場面にも正しく広げて使われる。理由の無い指示はもろい | エージェントは、規則の「場面」に当たり、かつ「前提」がいまの場面でも成り立つときだけ聞かずに進める。進めたコメントに、成り立つと見た前提を書く |
| 暗黙の判断基準は、決めた直後に、何を見て決めたか・何のためか・どう変われば逆を選ぶかを聞いて引き出す([Critical Decision Method](https://www.globalcognition.org/cognitive-task-analysis/)) | 人に頼むときに、答えに「決め手」を一言添えられる形にする。おすすめと違う答えには、決め手を短く聞く(下の「背景を集める」) |
| 取り返しのつく決定(two-way door)は軽く任せ、取り返しのつかない決定(one-way door)は慎重に人が決める([Amazon の 2016 年の株主への手紙](https://www.aboutamazon.com/news/company-news/2016-letter-to-shareholders)) | 規則にするのは、git で戻せて、利用者に見える前に戻せるものだけ。取り返しのつかないものは下の「この表では上書きできないもの」に置き、規則にしない |
| 例で示す(同じ指針): 例は、言葉で説明するより確かに動きを伝える | 規則ごとに、根拠になった問いと答えのリンクを並べ、おすすめと違う答えが返った例を「それでも聞く」に挙げる |

### 背景の確かさ

背景には、出どころを書き分ける。

- **人の言葉**: オーナーが答えや issue に書いた理由。そのまま引く
- **文書**: `CLAUDE.md` や、オーナーが書いた issue の決まり
- **推測(要確認)**: エージェントが答えの並びから推した理由。オーナーが「A」とだけ答えた問いでは、選んだ理由はエージェントが書いたおすすめの理由で、オーナーがそれに同意したかは分からない。推測の背景は、この表を変える PR(`needs-review`)でオーナーに確かめてもらい、人の言葉に置き換える

推測しか無い規則でも、前提を狭く書き、「それでも聞く」を広めに取れば使ってよい。前提の外では聞く。

### 背景を集める

- 頼むコメントの答え方の例を「A で(決め手を一言)」の形にする。決め手は任意で、無くても答えとして扱う
- オーナーの答えがおすすめと違ったとき、答えのとおりに直したうえで、対応したコメントの最後に「決め手を一言もらえると、判断表に残せます(任意)」と添える。答えを待って止めない
- 答えに理由が書いてあれば、対応したコメントに「判断の背景(オーナーの言葉)」として引いておく。週 1 の振り返りが、そこから集める

## この表では上書きできないもの

取り返しのつかない決定(one-way door)と、守る範囲を広げる決定。次のものは、規則に当てはまるように見えても聞く(`CLAUDE.md` の「人に判断を頼むとき」と、各ワークフローの決まりのとおり)。

- セキュリティを広げる変更(permissions を足す・起動のきっかけを変える・外部への送信を足す・外部のアクションの追加や版の変更・Claude Code に渡す権限やツールを広げる・Secret を渡す先や出す先を増やす)
- データを消す・費用がかかる
- `needs-review` の付いた issue の見た目の案
- 意図の書けない旧との差分(規則 JC-04 に当たるものは、JC-04 が意図になる)
- 昇格の PR(`staging`・`release`)のマージ。デプロイになるので、必ず人がする
- 1 つの問いに、規則に当たる点と当たらない点が混ざっているときは、当たらない点だけを聞く

## 規則

| ID | 場面 | 既定の判断 | 実装の場所 |
|---|---|---|---|
| JC-01 | 生成ファイル(`openapi/openapi.json`・`frontend/src/api/schema.ts`)の衝突・作り直し | 手で混ぜずに作り直す | `ai-review-loop.yml`・`ai-conflict-resolve.yml`(目印 `ai-dev:regenerate`) |
| JC-02 | 指摘への対応の回数が上限(2 回)を超えた後の、小さな直し | 直す | この表(`ai-review-loop.yml` が引く) |
| JC-03 | 権限を広げないワークフローの直し(AI レビュアーの指摘が出どころ) | 直す(push は手元に頼む) | `ai-review-loop.yml`・`ai-draft-sweep.yml` の「ワークフローを変えるとき」 |
| JC-04 | 旧が 500 を返す・壊れた値を保存する・意味の無い結果を返す入力を、404・422 などにする旧との差分 | 差分として入れ、「旧との差分」に書く | この表 |
| JC-05 | この issue の範囲の外の残り(指摘が求める広げた直し・別の画面や機能の作業) | この PR はこの issue の範囲で仕上げ、残りは別の issue に回す | `ai-review-loop.yml` の「人に頼む前に、issue に回せば済むものではないか」 |

「実装の場所」がこの表のものは、この表が決まりの本体。ワークフローに書いてあるものは、ワークフローの文面が本体で、この表は索引と根拠。

### JC-01: 生成ファイルは作り直す

- 既定の判断: develop を取り込んで生成ファイルがぶつかった・API の形を変えたときは、人に聞かずに作り直す(`CLAUDE.md` の「API を変えたら」の手順)。手で混ぜない
- 背景(推測・要確認): 生成ファイルは元(ルートとコントローラ)から決まるので、手で混ぜると、どちらの変更も入っていない形になりうる。作り直した結果が正しいかは CI の「Check OpenAPI is up to date」で確かめられる
- 前提: 決まった手順で作り直せる。CI が差分を見つけられる
- 選ぶと起きること: 人の手を待たずに取り込みが進む。作り直しに失敗したときは手元の作業(`needs-local`)になる
- 状態: 使う(ワークフローに組み込み済み)
- 根拠: 6 回聞いて、6 回とも「作り直す」だった([#415](https://github.com/t-moriyama-r/cocktail-db/issues/415#issuecomment-6470370971)・[#426](https://github.com/t-moriyama-r/cocktail-db/issues/426#issuecomment-6471394374)・[#430](https://github.com/t-moriyama-r/cocktail-db/issues/430#issuecomment-6471406855)・[#430](https://github.com/t-moriyama-r/cocktail-db/issues/430#issuecomment-6473438059)・[#442](https://github.com/t-moriyama-r/cocktail-db/issues/442#issuecomment-6473414306)・[#588](https://github.com/t-moriyama-r/cocktail-db/issues/588#issuecomment-6526993202))。今はワークフローに組み込み済みで、聞かなくなった
- それでも聞く: 無い(作り直せないときは、判断ではなく作業なので `needs-local` で手元に頼む)

### JC-02: 回数の上限を超えても、小さな直しは直す

- 場面: 指摘への対応の回数(目印 `ai-dev:round`)が 2 を超えていて、指摘を「直すか、見送るか」の二択で、直すほうがおすすめのとき
- 既定の判断: 直す。直しが次のどれかに当たるもの
  - 文書・PR 本文の表・コメントの直し(テストケースの表に行を足す、「旧との差分」に 1 行足す、など)
  - 数行の直しで、直さないと通知・データ・機能を失う経路を塞ぐもの
  - セキュリティを締める直し(入力の検査を足す・権限を減らす、など)
- 背景(文書・人の言葉): 回数の上限は、堂々めぐりを止めるためのもので、直すほうが当然のものを止めるためのものではない。「明らかに不適切なもの(通知・データ・機能を失う、など)は直すことを前提にする。この程度のことで判断を仰がない」([#555](https://github.com/t-moriyama-r/cocktail-db/issues/555))。「なんならやってくれても良いんじゃないの？」([#382](https://github.com/t-moriyama-r/cocktail-db/issues/382#issuecomment-6445976526))
- 前提: 直しが小さく、直しても仕様・画面・API の形が変わらない。間違っていても、次のレビューか revert で戻せる
- 選ぶと起きること: PR が人の返事を待たずに進む。その代わり、レビューがもう 1 周する
- 状態: 使う
- 根拠: この型の問い 19 回のうち 17 回が「直す」だった([#222](https://github.com/t-moriyama-r/cocktail-db/issues/222#issuecomment-6406123889)・[#230](https://github.com/t-moriyama-r/cocktail-db/issues/230#issuecomment-6406115578)・[#256](https://github.com/t-moriyama-r/cocktail-db/issues/256#issuecomment-6409586053)・[#257](https://github.com/t-moriyama-r/cocktail-db/issues/257#issuecomment-6408549299)・[#415](https://github.com/t-moriyama-r/cocktail-db/issues/415#issuecomment-6459208745)・[#426](https://github.com/t-moriyama-r/cocktail-db/issues/426#issuecomment-6457932534)・[#426](https://github.com/t-moriyama-r/cocktail-db/issues/426#issuecomment-6462059066)・[#452](https://github.com/t-moriyama-r/cocktail-db/issues/452#issuecomment-6473469597)・[#470](https://github.com/t-moriyama-r/cocktail-db/issues/470#issuecomment-6494996692)・[#470](https://github.com/t-moriyama-r/cocktail-db/issues/470#issuecomment-6509086913)・[#497](https://github.com/t-moriyama-r/cocktail-db/issues/497#issuecomment-6489845707)・[#497](https://github.com/t-moriyama-r/cocktail-db/issues/497#issuecomment-6512660025)・[#523](https://github.com/t-moriyama-r/cocktail-db/issues/523#issuecomment-6509047632) など)。「なんならやってくれても良いんじゃないの？」とも言われている([#382](https://github.com/t-moriyama-r/cocktail-db/issues/382#issuecomment-6445976526))
- それでも聞く:
  - 直し方が 2 つ以上あり、どれにも選ぶ理由があるとき(直すかどうかではなく、どう直すかの問い)。同じ PR の 3 回の問いで、2 回はおすすめと違う直し方が選ばれた([#213](https://github.com/t-moriyama-r/cocktail-db/issues/213#issuecomment-6397606113)・[#213](https://github.com/t-moriyama-r/cocktail-db/issues/213#issuecomment-6397694654))
  - 直すと仕様・画面の見た目・API の形が変わるとき
  - 堂々めぐり(前の回に直したところを戻す・別の形に変えるよう言われた、など。`ai-review-loop.yml` の決まり)

### JC-03: 権限を広げないワークフローの直しは直す

- 場面: AI レビュアーの指摘が、`.github/workflows/` の変更を求めていて、その変更がセキュリティを広げない(上の「上書きできないもの」に当たらない)とき
- 既定の判断: 聞かずに直す。この環境ではワークフローを push できないので、当てる差分を `needs-local`(`kind=push`)で手元に頼む
- 背景(人の言葉): 人が見るのはセキュリティ上の懸念がある変更だけでよい。「その変更がセキュリティ上の懸念がある変更の場合は、僕に許可をもらいにいくようなフローにして」([#215](https://github.com/t-moriyama-r/cocktail-db/issues/215#issuecomment-6396438025))
- 前提: 変更が permissions・起動のきっかけ・外部への送信・外部のアクション・Claude Code に渡すツール・Secret の行き先のどれも変えない
- 選ぶと起きること: 手元が差分を当てるだけで進む(手元は、広げる変更が混ざっていないことを確かめてから当てる)
- 状態: 使う
- 根拠: 12 回聞いて、11 回が「直す」([#233](https://github.com/t-moriyama-r/cocktail-db/issues/233#issuecomment-6406228541)・[#237](https://github.com/t-moriyama-r/cocktail-db/issues/237#issuecomment-6406140266)・[#281](https://github.com/t-moriyama-r/cocktail-db/issues/281#issuecomment-6416192690)・[#344](https://github.com/t-moriyama-r/cocktail-db/issues/344#issuecomment-6437055226)・[#361](https://github.com/t-moriyama-r/cocktail-db/issues/361#issuecomment-6438678707)・[#382](https://github.com/t-moriyama-r/cocktail-db/issues/382#issuecomment-6442856336)・[#461](https://github.com/t-moriyama-r/cocktail-db/issues/461#issuecomment-6473452502)・[#486](https://github.com/t-moriyama-r/cocktail-db/issues/486#issuecomment-6484584890)・[#525](https://github.com/t-moriyama-r/cocktail-db/issues/525#issuecomment-6508719837)・[#562](https://github.com/t-moriyama-r/cocktail-db/issues/562#issuecomment-6518771042) など)。残りの 1 回も、おすすめ(見送る)より直すほうを選んだ([#452](https://github.com/t-moriyama-r/cocktail-db/issues/452#issuecomment-6470256636))。ワークフローには「広げる変更を含まない直しは聞かずに直す」がもう書いてあるが、それでも聞いた回が続いていた(#461・#562)ので、この表にも規則として置く
- それでも聞く: セキュリティを広げる変更(上の「上書きできないもの」)

### JC-04: 旧の 500・壊れた値の保存・意味の無い結果は、404・422 などに変えてよい

- 場面: 画面からふだん起きない入力(無い ID・形の壊れた値・記号だけの検索語など)で、旧が 500 を返す・壊れた値をそのまま保存する・全件のような意味の無い結果を返すとき
- 既定の判断: 旧に合わせず、404・422(または結果なし)にする差分として入れる。差分は、CLAUDE.md の「旧との差分」の決まりのとおり、`docs/decisions/` の文書の「旧との差分」の節に 1 行書き、テストの表で `差分あり(意図: <その文書>)` と指す。節の行には「判断表の JC-04 による」と書き添える
- 背景(推測・要確認): 「旧の挙動を期待値にする」決まりは、利用者から見える挙動を勝手に変えないためのもの。画面からふだん起きない入力のエラーの形は、利用者がふだん目にしないので、その目的に触れない。旧の 500 や壊れた値の保存を持ち込む利点は無い
- 前提: その入力は画面のふだんの操作では起きない。変えても、ふだんの操作の結果(並び・件数・出す値)は変わらない
- 選ぶと起きること: 旧との差分が 1 行増える。差分は「旧との差分」の節で読める
- 状態: 使う
- 根拠: 6 回聞いて、6 回とも差分を入れるほうを選んだ([#384](https://github.com/t-moriyama-r/cocktail-db/issues/384#issuecomment-6459309462)・[#415](https://github.com/t-moriyama-r/cocktail-db/issues/415#issuecomment-6486113437)・[#431](https://github.com/t-moriyama-r/cocktail-db/issues/431#issuecomment-6456324706)・[#435](https://github.com/t-moriyama-r/cocktail-db/issues/435#issuecomment-6486190486)・[#440](https://github.com/t-moriyama-r/cocktail-db/issues/440#issuecomment-6486162080)・[#493](https://github.com/t-moriyama-r/cocktail-db/issues/493#issuecomment-6485386059))。カクテルの詳細でも、旧の 500 は 404 にしている(`docs/decisions/cocktail-detail.md`)
- それでも聞く:
  - 画面からふだん起きる操作で、旧が意味のある結果を返しているもの(並び・件数・出す値が変わるもの)
  - 上限の数を新しく決めるもの(どの数にするかは人が決める。[#470](https://github.com/t-moriyama-r/cocktail-db/issues/470#issuecomment-6488749441) では、おすすめの 30 ではなく 20 になった)
  - 同じ問いの中に、仕様を足す話が混ざるとき([#440](https://github.com/t-moriyama-r/cocktail-db/issues/440#issuecomment-6486162080) では「自分自身は似たカクテルに出さない」が足された)

### JC-05: 範囲の外の残りは別の issue に回す

- 場面: 指摘や途中で見つけたことが、この issue の範囲の外の作業(ほかの画面・ほかの機能・広げた直し)を求めているとき
- 既定の判断: この PR はこの issue の範囲で仕上げ、残りは別の issue に回す(回す先と足す文を `needs-local`(`kind=other`)で手元に頼む)
- 背景(文書・推測): 1 PR = 1 issue(`CLAUDE.md`)。範囲を広げた PR は、確かめる範囲も広がり、止まりやすい。[#538](https://github.com/t-moriyama-r/cocktail-db/issues/538#issuecomment-6511093369) では、この PR で済ませる案を勧めたのに、オーナーは別の issue に任せた(理由は書かれていない。推測・要確認)
- 前提: 回した先が入るまで、利用者に壊れた画面や誤ったデータが出ない
- 選ぶと起きること: issue が 1 件増え、この PR は早く閉じる
- 状態: 使う
- 根拠: 4 回聞いて、4 回とも「回す」だった([#283](https://github.com/t-moriyama-r/cocktail-db/issues/283#issuecomment-6488924512)・[#430](https://github.com/t-moriyama-r/cocktail-db/issues/430#issuecomment-6456339469)・[#488](https://github.com/t-moriyama-r/cocktail-db/issues/488#issuecomment-6484843507)・[#538](https://github.com/t-moriyama-r/cocktail-db/issues/538#issuecomment-6511093369))。[#538](https://github.com/t-moriyama-r/cocktail-db/issues/538#issuecomment-6511093369) は、この PR で済ませる案を勧めたのに、オーナーは別の issue に任せる案を選んだ
- それでも聞く: 回した先が入るまで、利用者に壊れた画面や誤ったデータが出るもの・意図の書けない旧との差分(`ai-review-loop.yml` の決まり)

## 規則で進めたとき

規則で進めたら、PR にコメントを書く。コメントの先頭には `<!-- ai-dev:agent --> <!-- ai-dev:auto-judged rule=JC-xx -->` を付ける(規則が 2 つなら、規則ごとに目印を書く)。中身は次の 3 つ。

1. 何を決めたか(1 文)
2. どの規則によるか(`docs/judgment-criteria.md` の JC-xx)と、その規則の前提がいまの場面で成り立つと見た理由(1〜2 文)
3. 違うときの戻し方: 「違う判断なら、`needs-human` を付けてから、このコメントに返信してください」

`needs-human` は付けない。オーナーが `needs-human` を付けて返信すると、`ai-review-loop.yml` の `resume` が、その返信を判断として扱って直す。戻された回は、週 1 の振り返りで規則を直す材料にする(下の「育て方」)。戻されたときは、どの前提が崩れていたかをオーナーの返信から読み、規則の「前提」か「それでも聞く」に足す。

## 判断の傾向

聞かずに進める規則にはしないが、おすすめの案を選ぶときの手がかりにするもの。答えに出てきた考え方で、2 回以上同じ向きのもの。おすすめを書くときに傾向を使ったら、どの傾向(JT-xx)によるかを書き、オーナーがその傾向に同意したかを答えで確かめられるようにする。

| ID | 傾向 | 出てきた答え |
|---|---|---|
| JT-01 | 人の手作業が残る案より、エージェントが自分で済ませる案を選ぶ | [#208](https://github.com/t-moriyama-r/cocktail-db/issues/208#issuecomment-6395975639)(人がブランチに直接入れる案より B)・[#382](https://github.com/t-moriyama-r/cocktail-db/issues/382#issuecomment-6445976526)(「なんならやってくれても良い」) |
| JT-02 | 通知・データが黙って消える経路は、手間が増えても塞ぐ | [#222](https://github.com/t-moriyama-r/cocktail-db/issues/222#issuecomment-6406123889)・[#237](https://github.com/t-moriyama-r/cocktail-db/issues/237#issuecomment-6406140266)・[#497](https://github.com/t-moriyama-r/cocktail-db/issues/497#issuecomment-6512660025) |
| JT-03 | オーナー以外の人のコメントは、止めるより無いものとして扱う | [#215](https://github.com/t-moriyama-r/cocktail-db/issues/215#issuecomment-6396438025)・[#237](https://github.com/t-moriyama-r/cocktail-db/issues/237#issuecomment-6399440091)・[#497](https://github.com/t-moriyama-r/cocktail-db/issues/497#issuecomment-6489048563) |
| JT-04 | 同じ意味の値(色・上限など)は 1 か所で決め、ほかはそれに揃える | [#436](https://github.com/t-moriyama-r/cocktail-db/issues/436#issuecomment-6486174389)・[#415](https://github.com/t-moriyama-r/cocktail-db/issues/415#issuecomment-6453944833) |
| JT-05 | 上限の数は、実際のカクテルで起きる大きさから決める(余裕を見すぎない) | [#470](https://github.com/t-moriyama-r/cocktail-db/issues/470#issuecomment-6488749441)(材料のまとまりは 30 ではなく 20) |
| JT-06 | 検索では、より細かい条件を優先する | [#258](https://github.com/t-moriyama-r/cocktail-db/issues/258#issuecomment-6408354314)・[#404](https://github.com/t-moriyama-r/cocktail-db/issues/404#issuecomment-6459261461) |

## 育て方

- 週 1 の振り返りで、`.github/scripts/judgment-history.sh <owner/repo> <前の週の日付>` を流し、その週の問いと答え・規則で進めた回を読む
- 同じ型の問いに、3 回以上同じ向きの答えが返り、反対の答えが無ければ、規則の候補にする。2 回以上同じ向きの考え方が答えに出ていれば、傾向の候補にする
- 規則で進めた回に、オーナーが戻した(`auto_replied`)ものは、どの前提が崩れていたかを読み、規則の「前提」・「それでも聞く」に足すか、規則を外す(状態を「外した」にし、節は消さない)
- オーナーの答えや対応のコメントに引いた「判断の背景(オーナーの言葉)」を集め、推測の背景を置き換える候補にする
- 背景が推測のままの規則は、候補の issue でオーナーに「この理由で合っているか、違えば一言」と確かめる
- 候補は issue にし、この表を変える PR(閉じる issue に `needs-review`)でオーナーに確かめてもらう。エージェントが自分の判断で規則を足さない

## 数字

2026-11-02 に `judgment-history.sh` と、問いと答えを読んで数えたもの。

| | 回数 |
|---|---|
| 人に判断を頼んだ(`needs-human`・`needs-decision` の目印) | 154 |
| オーナー本人が答えた(自動の依頼・エージェントの書き込みを除く) | 72 |
| 答えの無いまま片付いた(手元の作業で済んだ・スレッドを人が解決した、など) | 82 |
| 本人が答えた 72 回のうち、JC-01〜05 の型に当たる問い | 47 |
| そのうち、おすすめどおりの答え | 42 |
| 本人が答えた 72 回のうち、おすすめどおりの答え(読んで数えたもの。スクリプトの目安は 44) | 56 |

「本人が答えた」には、オーナーのアカウントからエージェントが目印を付けずに書いた報告が少なくとも 2 件([#213](https://github.com/t-moriyama-r/cocktail-db/issues/213#issuecomment-6397569793)・[#344](https://github.com/t-moriyama-r/cocktail-db/issues/344#issuecomment-6437055226))混ざっている。除くと本人の答えは 70 回、規則の型は 45 回中 40 回がおすすめどおりで、規則の選び方は変わらない。本人の答え 72 回のうち、20 文字を超えて理由らしいことを書いた答えは 15 回(うち 2 回は上の報告)で、ほとんどの規則の背景は推測になっている。

答えの無い 82 回の多くは、証跡の撮影・ワークフローの push など、人の判断ではない作業を `needs-human` で頼んでいた回で、#496 で `needs-local`・`needs-manual` に分けた。
