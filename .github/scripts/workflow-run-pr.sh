#!/usr/bin/env bash
# CI の終わり(workflow_run)の回で、終わったコミットを head にした開いている PR の番号を出す(ai-auto-merge.yml から呼ぶ)
#
# 使い方: workflow-run-pr.sh <owner/repo> <コミットの sha> [head のブランチ]
# 出力: PR の番号(見つからなければ何も出さず、成功で終える)
#
# workflow_run の回では PR の番号が来ないこと(github.event.workflow_run.pull_requests が空)があるので、
# まずコミットから探す(repos/<o>/<r>/commits/<sha>/pulls の開いているもの)。
# 昇格の PR(develop → staging など)の head は develop のマージコミットで、この API はそのコミットを入れた PR(閉じている)
# だけを返し、昇格の PR を返さない。手元のレビューを CI の終わる前に書くと、CI の終わりの回で PR が見つからず、
# needs-approval が付かなかった(#799)。コミットから見つからないときは、head のブランチを head にした開いている PR のうち、
# head の SHA がこのコミットのものを探す(同じリポジトリのブランチだけ。フォークの同じ名前のブランチは拾わない)
#
# PR の一覧を読めなかったときは、「PR が無い」と同じに扱わず失敗で終える(RC-01)
set -euo pipefail

repo=$1
sha=$2
branch=${3:-}
# sha は下の --jq の式に埋め込むので、コミットの SHA の形だけを通す
[[ $sha =~ ^[0-9a-f]{40}$ ]] || { echo "コミットの sha の形ではない: $sha" >&2; exit 1; }

number=$(gh api "repos/$repo/commits/$sha/pulls" --jq '[.[] | select(.state == "open")][0].number // empty')
if [ -z "$number" ] && [ -n "$branch" ]; then
  number=$(gh api -X GET "repos/$repo/pulls" -f state=open -f head="${repo%%/*}:$branch" -f per_page=100 \
    --jq "[.[] | select(.head.sha == \"$sha\")][0].number // empty")
fi
if [ -n "$number" ]; then echo "$number"; fi
