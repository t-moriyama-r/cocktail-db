#!/usr/bin/env bash
# マージした PR の head のブランチを、GitHub のブランチの自動削除と同じように片付ける(#463)
#
# 使い方: branch-cleanup.sh <owner/repo> <マージした PR の番号>
# 出力: 向き先を付け替えた PR の番号を、1 行に 1 つずつ標準出力に出す(呼んだ側が CI を流し直す)。ログは標準エラーに出す
# 終了コード: 0 は片付けを決められた(消した・消さないと決めた)とき。PR・ブランチ・開いた PR を読めない、消せないなどは 1
#
# ai-auto-merge.yml が GITHUB_TOKEN でマージした後に呼ぶ(GITHUB_TOKEN のマージでは、GitHub のブランチの自動削除が効かない)。
# GitHub の自動削除は、マージした PR の head のブランチを --base にしている開いた PR(積み上げの PR)の向き先を、マージした PR の
# 向き先に付け替えてから、ブランチを消す。付け替えずにブランチを消すと、積み上げの PR は閉じられてしまう。
# 付け替えずに残すと、積み上げの PR は向き先が土台のブランチのまま、いつまでもマージされない(#438)。そのため次のように決める
# - 保護されたブランチ・保護を読めないブランチは消さない(付け替えもしない)
# - ほかの開いた PR が --head に使っているブランチは消さない(付け替えもしない)
# - --base に使っている開いた PR は、マージした PR の向き先に付け替えてから消す。1 つでも付け替えられなければ消さない
#   (消すと付け替えられなかった PR が閉じられる)。付け替えは GITHUB_TOKEN なので CI が動かない。呼んだ側が ci-rerun.sh で流し直す
set -euo pipefail

repo=$1
number=$2
log() { echo "$*" >&2; }

refs=$(gh api "repos/$repo/pulls/$number" --jq '.head.ref + " " + .base.ref') || {
  log "#$number を読めない"
  exit 1
}
read -r branch base <<<"$refs"
if [ -z "$branch" ] || [ -z "$base" ]; then
  log "#$number の head・base を読めない"
  exit 1
fi

# 保護を読めない(権限・通信の失敗)ときは、保護されているものとして消さない
protected=$(gh api "repos/$repo/branches/$branch" --jq .protected 2>/dev/null) || protected=unknown
if [ "$protected" != false ]; then
  log "ブランチ $branch は消さない(保護: $protected)"
  exit 0
fi

# 読めなかったのを「使っている PR が無い」と取り違えて消さない
heads=$(gh api --paginate "repos/$repo/pulls?state=open&head=${repo%%/*}:$branch&per_page=100" --jq '.[].number') || {
  log "ブランチ $branch を --head に使っている開いた PR を読めないので、消さない"
  exit 1
}
if [ -n "$heads" ]; then
  log "ブランチ $branch は消さない(開いた PR #$(paste -sd, - <<<"$heads") が --head に使っている)"
  exit 0
fi
stacked=$(gh api --paginate "repos/$repo/pulls?state=open&base=$branch&per_page=100" --jq '.[].number') || {
  log "ブランチ $branch を --base に使っている開いた PR を読めないので、消さない"
  exit 1
}

failed=
for n in $stacked; do
  if gh api -X PATCH "repos/$repo/pulls/$n" -f base="$base" >/dev/null; then
    log "#$n の向き先を $branch から $base に付け替えた"
    echo "$n"
  else
    log "::warning::#$n の向き先を $base に付け替えられなかった"
    failed=1
  fi
done
if [ -n "$failed" ]; then
  log "付け替えられなかった PR があるので、ブランチ $branch は消さない(消すと、その PR が閉じられる)"
  exit 1
fi

if gh api -X DELETE "repos/$repo/git/refs/heads/$branch" >/dev/null; then
  log "ブランチ $branch を消した"
else
  log "::warning::ブランチ $branch を消せなかった"
  exit 1
fi
