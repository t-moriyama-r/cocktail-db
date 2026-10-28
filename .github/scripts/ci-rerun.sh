#!/usr/bin/env bash
# CI(test.yml)の pull_request の回が、いまのコミットで走っていない PR に、pull_request の回を走らせる
#
# 使い方:
#   ci-rerun.sh <owner/repo>              既定のブランチ向けの開いている PR すべてを見る
#   ci-rerun.sh <owner/repo> <PR の番号>  その PR だけを見る
# 環境変数:
#   GH_TOKEN         PR と回を読み、承認待ちの回を承認するトークン(Actions: write が要る。ワークフローの GITHUB_TOKEN)
#   PR_REOPEN_TOKEN  PR を閉じて開き直すトークン(オーナーの PAT。このリポジトリの Pull requests: write)。無ければ開き直さずに人に頼む
#
# ブランチ保護の必須のチェックに数えられるのは、PR に結び付いた回(pull_request)のチェックだけ。workflow_dispatch で流した回は、
# 同じコミットに同じ名前のチェックが成功で付いても PR のチェックに数えられず、マージは BLOCKED のまま(#389)。
# そのため、何が起きたかではなく「いまのコミットに pull_request の回があり、承認待ちでないか」で決める。
# draft では CI の全ジョブを飛ばす(test.yml。#410)ので、draft は見ない。飛ばした回(conclusion が skipped)は走ったに数えない。
# GITHUB_TOKEN で ready にすると ready_for_review の回が起きず、draft のときに飛ばした回だけが残るため
# - 承認待ち(conclusion が action_required)の回がある: Actions の Claude(claude[bot])や GITHUB_TOKEN が push したコミットでは、
#   pull_request の回が「Approve and run」を待ったまま走らない(#353・#365)。その回を承認して走らせる。test.yml のほかの
#   pull_request のワークフローの回も、待ったままだと自動マージが止まる(すべてのチェックの成功を見る)ので、まとめて承認する
# - pull_request の回が 1 つも無い: 土台がマージされて向き先が付け替わった(付け替えは push ではない)、GITHUB_TOKEN で push した、など。
#   PR を閉じて開き直すと、reopened で pull_request の回が起きる。GITHUB_TOKEN で開き直してもワークフローは起きないので、オーナーの PAT で行う。
#   push の直後は、pull_request の回が作られる前のことがあるので、少し待つ(MIN_AGE_MINUTES)
# - 承認できなかったときも、開き直す(オーナーの開き直しで起きた回は承認が要らない)
# 走った回(承認待ちでない回)ができると、次からは「走った」とみなして重ねて何もしない。何度呼んでもよい。
# 閉じて開き直すと、closed・reopened で動くワークフロー(Slack の通知に「済」を付ける mark-done など)も動く。どれも動いたときの PR の状態で
# 決め、そのときにはもう開き直しているので害は無い。PR の下書きの状態・ラベル・レビューはそのまま残る。
# 開き直せなかったときに閉じたまま残すと、開いている PR しか見ない見回りが二度と拾わないので、GITHUB_TOKEN でも開き直し、人に頼む。
# コンフリクトしているものは、pull_request の回が作られない。ai-conflict-resolve.yml が develop を取り込んで直す(その push で回が起きる)。
# ここで needs-human を付けると、needs-human の付いた PR を見送る ai-conflict-resolve.yml が動かなくなるので、何もせずに見送る。
# 走らせられないもの(PAT が無い・期限切れ・権限不足)は、人に頼む。人が動くのは Slack に知らせが来たときだけなので、
# 頼むコメント(目印 ai-dev:needs-human)を書いて needs-human を付け、notify-needs-human.yml を呼ぶ。
# オーナーが返信すると ai-review-loop.yml の Claude Code が引き継ぐ。頼むのはコミットごとに 1 回だけ
set -euo pipefail

WORKFLOW=${CI_WORKFLOW:-test.yml}
# push の直後は、pull_request の回が作られる前のことがある。その間に開き直さない
MIN_AGE_MINUTES=${MIN_AGE_MINUTES:-10}
# PR_REOPEN_TOKEN に渡している secret の名前。人に頼むときに、どれを入れ直せばよいかを書く
REOPEN_SECRET=CODERABBIT_REQUEST_TOKEN

ci_rerun() {
  local repo=$1 number=${2:-} default n
  default=$(gh api "repos/$repo" --jq .default_branch)
  if [ -n "$number" ]; then
    rerun_one "$repo" "$default" "$number"
    return
  fi
  for n in $(gh pr list -R "$repo" --state open --base "$default" --limit 100 --json number --jq '.[].number'); do
    # 1 件の失敗で、後ろの PR を見ないまま止まらない
    rerun_one "$repo" "$default" "$n" || echo "#$n 失敗: 次の見回りでやり直す"
  done
}

rerun_one() {
  local repo=$1 default=$2 n=$3 pr sha
  skip() { echo "#$n 見送り: $*"; }
  pr=$(gh pr view "$n" -R "$repo" --json state,isDraft,baseRefName,headRefOid,isCrossRepository,mergeable,updatedAt,labels) || return 1  # || で呼ばれると set -e が効かないので、読めなかったら先へ進まない
  sha=$(jq -r .headRefOid <<<"$pr")

  if [ "$(jq -r .state <<<"$pr")" != OPEN ]; then skip "閉じている"; return; fi
  if [ "$(jq -r .isDraft <<<"$pr")" = true ]; then skip "draft(CI は ready にしてから流す)"; return; fi
  # フォークの PR の回は、こちらから承認しない(動かすのも危ない)
  if [ "$(jq -r .isCrossRepository <<<"$pr")" = true ]; then skip "フォークから"; return; fi
  if [ "$(jq -r .baseRefName <<<"$pr")" != "$default" ]; then skip "向き先が $default ではない(積んでいる途中)"; return; fi
  # いまのコミットの pull_request の回(どのワークフローも)。1 つのコミットの回は多くても十数個なので、100 件で足りる。
  # 先頭は CI の回のうち承認待ちでも飛ばした回でもない数、続けて承認待ちの回の ID(空白区切り)
  local runs ran waiting
  runs=$(gh api "repos/$repo/actions/runs?head_sha=$sha&event=pull_request&per_page=100" \
    --jq "[([.workflow_runs[] | select((.path | endswith(\"/$WORKFLOW\")) and (.conclusion | IN(\"action_required\", \"skipped\") | not))] | length | tostring)]
          + [.workflow_runs[] | select(.conclusion == \"action_required\") | .id | tostring] | join(\" \")") || return 1  # 読めなかったのを「回が無い」と取り違えて開き直さない
  read -r ran waiting <<<"$runs"
  if [ "$ran" != 0 ] && [ -z "$waiting" ]; then
    skip "$WORKFLOW の pull_request の回はいまのコミットで走った・走っている"
    return
  fi
  if [ "$(jq -r .mergeable <<<"$pr")" = CONFLICTING ]; then
    skip "コンフリクトは ai-conflict-resolve.yml が直す"
    return
  fi
  if [ -n "$waiting" ] && approve "$repo" "$n" "$waiting"; then
    return
  fi
  if [ "$ran" != 0 ]; then
    # CI は走っていて、ほかのワークフローの承認待ちだけが残った。開き直すと CI まで流し直すので、次の見回りで承認をやり直す
    skip "承認待ちの回を承認できなかった(次の見回りでやり直す)"
    return
  fi
  if [ -z "$waiting" ]; then
    # コミットの日付は push の時刻ではない(古い日付のコミットを push できる)ので、push に近い PR の更新時刻で測る
    local updated age
    updated=$(jq -r .updatedAt <<<"$pr")
    age=$((($(date -u +%s) - $(date -u -d "$updated" +%s)) / 60))
    if [ "$age" -lt "$MIN_AGE_MINUTES" ]; then skip "PR の更新から $age 分しかたっていない"; return; fi
  fi
  reopen "$repo" "$default" "$n" "$sha" "$(jq -r 'any(.labels[]?; .name == "needs-human")' <<<"$pr")"
}

# 承認待ちの回をすべて承認する。1 つでも承認できなければ失敗を返す
approve() {
  local repo=$1 n=$2 ids=$3 id out failed=
  for id in $ids; do
    if ! out=$(gh api -X POST "repos/$repo/actions/runs/$id/approve" 2>&1); then
      echo "::warning::#$n の回 $id を承認できなかった: $out"
      failed=1
    fi
  done
  if [ -n "$failed" ]; then return 1; fi
  echo "#$n で承認待ちの回を承認した($ids)"
}

# オーナーの PAT で PR を閉じて開き直し、pull_request の回を起こす
reopen() {
  local repo=$1 default=$2 n=$3 sha=$4 asked=$5 out
  local why="いまのコミット(${sha:0:7})で CI の pull_request の回が走っていないが、"
  local ask="$default を取り込んで push してほしい(オーナーの push なら CI が動く)。このコメントに「取り込んで」と返信すれば、Actions の Claude Code が取り込んで push する(その回が承認待ちになれば、次の見回りで承認する)"
  if [ -z "${PR_REOPEN_TOKEN:-}" ]; then
    tell "$repo" "$n" "$sha" "$asked" "${why}PR を開き直すトークン(secret $REOPEN_SECRET)が無いので、こちらから走らせられなかった。$ask"
    return
  fi
  if ! out=$(GH_TOKEN=$PR_REOPEN_TOKEN gh api -X PATCH "repos/$repo/pulls/$n" -f state=closed 2>&1 >/dev/null); then
    if grep -qE 'HTTP 40[134]' <<<"$out"; then
      tell "$repo" "$n" "$sha" "$asked" "${why}PR を開き直すトークン(secret $REOPEN_SECRET)で PR を閉じられなかった(期限切れ・権限不足。Pull requests: Read and write が要る)。トークンを入れ直すか、$ask"
    else
      # API の一時的なエラー・レート制限など。人に頼まず、次の見回りに任せる
      echo "#$n 見送り: PR を閉じられなかったが、トークンのためではなさそう(次の見回りでやり直す): $out"
    fi
    return
  fi
  if GH_TOKEN=$PR_REOPEN_TOKEN gh api -X PATCH "repos/$repo/pulls/$n" -f state=open >/dev/null; then
    echo "#$n を閉じて開き直した(${sha:0:7} で pull_request の回が起きる)"
    return
  fi
  # 閉じたまま残すと、開いている PR しか見ない見回りが二度と拾わない。GITHUB_TOKEN でも開き直す(CI は起きない)
  gh api -X PATCH "repos/$repo/pulls/$n" -f state=open >/dev/null || echo "::warning::#$n を GITHUB_TOKEN でも開き直せなかった(閉じたまま)"
  tell "$repo" "$n" "$sha" "$asked" "いまのコミット(${sha:0:7})で CI の pull_request の回を起こすために PR を閉じたが、オーナーのトークンで開き直せなかった。PR が閉じたままなら開き直してほしい(オーナーが開き直せば CI が動く)。開いていれば、$ask"
}

# 同じコミットで同じことを何度も頼まない(見回りは 1 時間ごとに動く)
tell() {
  local repo=$1 n=$2 sha=$3 asked=$4 message=$5 marker bodies
  marker="ai-dev:ci-rerun sha=$sha"
  # grep -q に直接つなぐと、見つけた時点で gh が SIGPIPE で止まり、pipefail で「見つからない」扱いになって頼み直してしまう
  bodies=$(gh api --paginate "repos/$repo/issues/$n/comments?per_page=100" --jq '.[].body')
  if grep -qF "$marker" <<<"$bodies"; then
    echo "#$n 見送り: このコミットではもう頼んだ"
    return
  fi
  # 目印入りのコメントは最後に書く。先に書くと、途中で失敗したときに「もう頼んだ」と見送られ、ラベルも通知も届かないまま止まる。
  # 呼び出し側が || で囲むと set -e が効かなくなるので、&& でつないで、失敗したらコメントを書かずに戻す。
  # needs-human がもう付いているのは、前の回でコメントだけ書けなかったとき。Slack に知らせ直さず、コメントだけ書き直す
  { [ "$asked" = true ] ||
    { gh pr edit "$n" -R "$repo" --add-label needs-human &&
      gh workflow run notify-needs-human.yml -R "$repo" -f number="$n" -f label=needs-human; }; } &&
    gh pr comment "$n" -R "$repo" --body "<!-- ai-dev:agent --> <!-- ai-dev:needs-human --> <!-- $marker -->
$message" || return 1
  echo "#$n で人に頼んだ: $message"
}

ci_rerun "$@"
