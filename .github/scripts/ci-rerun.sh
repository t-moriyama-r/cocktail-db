#!/usr/bin/env bash
# CI(test.yml)がいまのコミットで一度も動いていない(承認待ちの回しか無い)PR に、CI を流し直させる
#
# 使い方:
#   ci-rerun.sh <owner/repo>              既定のブランチ向けの開いている PR すべてを見る
#   ci-rerun.sh <owner/repo> <PR の番号>  その PR だけを見る
#
# 開いている PR の上に積んだ PR は、土台がマージされてブランチが消えると、GitHub が base を既定のブランチに付け替える。
# 付け替えはプッシュではないので test.yml が動かず、必須のチェックがそろわないまま止まる。ほかにも GITHUB_TOKEN で push した
# コミットは test.yml を起こさない。そのため、何が起きたかではなく「いまのコミットで test.yml が動いたか」で決める。
# 何度呼んでも、動いていない PR に 1 回流させるだけ。
# 承認待ち(conclusion が action_required)の回は、動いたものに数えない。Actions の Claude(claude[bot])が push したコミットでは、
# pull_request の回が「Approve and run」を待ったまま走らず、必須のチェックが付かない(#353・#365)。GITHUB_TOKEN の workflow_dispatch は
# 承認が要らないので、承認待ちの回しか無ければ流し直す。承認待ちの回があるなら pull_request の回はもう作られているので、push 直後の待ちはしない。
# 流し直した回(承認待ちでない回)ができると、次からは「動いた」とみなして重ねて流さない。
# 流し直しは、test.yml を PR のブランチで workflow_dispatch する(GITHUB_TOKEN で push しても CI は起きないので、既定のブランチを
# 取り込んで push する形にはしない)。そのため、試すのは PR のブランチの先頭で、既定のブランチとのマージではない
# (必須のチェックは「最新に追いついていること」を求めていないので、これでマージできる)。
# コンフリクトしているものは、ai-conflict-resolve.yml が develop を取り込んで直す(その push で CI が動く)。ここで needs-human を
# 付けると、needs-human の付いた PR を見送る ai-conflict-resolve.yml が動かなくなるので、何もせずに見送る。
# 流せないもの(ブランチの test.yml に workflow_dispatch が無い)は、人に頼む。人が動くのは Slack に
# 知らせが来たときだけなので、頼むコメント(目印 ai-dev:needs-human)を書いて needs-human を付け、notify-needs-human.yml を呼ぶ。
# オーナーが返信すると ai-review-loop.yml の Claude Code が引き継ぐ。頼むのはコミットごとに 1 回だけ
set -euo pipefail

WORKFLOW=${CI_WORKFLOW:-test.yml}
# push の直後は、pull_request の回が作られる前のことがある。その間に重ねて流さない
MIN_AGE_MINUTES=${MIN_AGE_MINUTES:-10}

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
  local repo=$1 default=$2 n=$3 pr sha branch
  skip() { echo "#$n 見送り: $*"; }
  pr=$(gh pr view "$n" -R "$repo" --json state,baseRefName,headRefName,headRefOid,isCrossRepository,mergeable,updatedAt,labels) || return 1  # || で呼ばれると set -e が効かないので、読めなかったら先へ進まない
  sha=$(jq -r .headRefOid <<<"$pr")
  branch=$(jq -r .headRefName <<<"$pr")

  if [ "$(jq -r .state <<<"$pr")" != OPEN ]; then skip "閉じている"; return; fi
  # フォークのブランチでは、こちらから test.yml を動かせない(動かすのも危ない)
  if [ "$(jq -r .isCrossRepository <<<"$pr")" = true ]; then skip "フォークから"; return; fi
  if [ "$(jq -r .baseRefName <<<"$pr")" != "$default" ]; then skip "向き先が $default ではない(積んでいる途中)"; return; fi
  # 回の数と、そのうち承認待ちの数。1 つのコミットの回は多くても数個なので、100 件で足りる
  local runs total waiting
  runs=$(gh api "repos/$repo/actions/workflows/$WORKFLOW/runs?head_sha=$sha&per_page=100" \
    --jq '"\(.total_count) \([.workflow_runs[] | select(.conclusion == "action_required")] | length)"') || return 1  # 読めなかったのを「回が無い」と取り違えて流さない
  read -r total waiting <<<"$runs"
  if [ "$total" != "$waiting" ]; then
    skip "$WORKFLOW はいまのコミットで動いた・動いている"
    return
  fi
  if [ "$total" = 0 ]; then
    # コミットの日付は push の時刻ではない(古い日付のコミットを push できる)ので、push に近い PR の更新時刻で測る
    local updated age
    updated=$(jq -r .updatedAt <<<"$pr")
    age=$((($(date -u +%s) - $(date -u -d "$updated" +%s)) / 60))
    if [ "$age" -lt "$MIN_AGE_MINUTES" ]; then skip "PR の更新から $age 分しかたっていない"; return; fi
  fi
  if [ "$(jq -r .mergeable <<<"$pr")" = CONFLICTING ]; then
    skip "コンフリクトは ai-conflict-resolve.yml が直す"
    return
  fi

  local out
  if out=$(gh workflow run "$WORKFLOW" -R "$repo" --ref "$branch" 2>&1); then
    if [ "$total" = 0 ]; then
      echo "#$n で $WORKFLOW を流し直した($branch の ${sha:0:7})"
    else
      echo "#$n で $WORKFLOW を流し直した($branch の ${sha:0:7}。承認待ちの回しか無かった)"
    fi
  elif ! grep -q 'HTTP 422' <<<"$out"; then
    # API の一時的なエラー・レート制限など。人に頼まず、次の見回りに任せる
    skip "$WORKFLOW を動かせなかったが、workflow_dispatch が無いためではなさそう(次の見回りでやり直す): $out"
  else
    tell "$repo" "$n" "$sha" "$(jq -r 'any(.labels[]?; .name == "needs-human")' <<<"$pr")" "いまのコミット(${sha:0:7})で CI が動いていないが、このブランチの $WORKFLOW を手で動かせなかった(workflow_dispatch が無い古いブランチなど)。$default を取り込んで push してほしい(push すれば CI が動く)。このコメントに「取り込んで」と返信すれば、Actions の Claude Code が取り込んで push する"
  fi
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
