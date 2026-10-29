#!/usr/bin/env bash
# PR のいまのコミットを、AI レビュアー(CodeRabbit・代わりの Claude)がもう見たか(昇格の PR では、手元の Claude がレビューしたか)を true / false で出す
#
# 使い方: head-reviewed.sh <owner/repo> <PR の番号>
# 環境変数: OWNER(手元の Claude がレビューを書くアカウント。既定は owner/repo の owner)
#
# 昇格の PR(向き先が staging・release)は、必ず手元のエージェントが作り、そのエージェントが差分を読んでレビューする(#479)。
# Actions は CodeRabbit にも代わりの Claude にも頼まないので、オーナーが目印 <!-- ai-dev:local-review --> を付けたレビューだけを数える。
# 昇格の PR の head は develop(staging)そのもので、常に先へ進む。増えた分は develop に入る前に PR ごとにレビュー済みなので、
# いまのコミットへのレビューでなくても、目印のレビューが 1 つあればレビュー済みとみなす(#506)
#
# CodeRabbit は指摘があればそのコミットへのレビューを付け、無ければ「Review finished」「No actionable comments」のコメントだけを付ける
# (どちらか片方のこともあり、後から別のコメントが続くこともある)。上限で動かなかったときは、ai-claude-review.yml が
# 目印 <!-- ai-dev:claude-review --> 付きのレビューをそのコミットに付ける。
# CodeRabbit はスレッドへの返信のたびに本文の無いレビューをいまのコミットに付けるので、それはレビューとして数えない
set -euo pipefail

repo=$1
number=$2
owner=${OWNER:-${repo%%/*}}

pr=$(gh pr view "$number" -R "$repo" --json headRefOid,baseRefName)
head=$(jq -r .headRefOid <<<"$pr")
reviews=$(gh api --paginate --slurp "repos/$repo/pulls/$number/reviews?per_page=100" | jq 'add // []')

case "$(jq -r .baseRefName <<<"$pr")" in
  staging | release)
    jq --arg owner "$owner" \
      'any(.[]; .user.login == $owner and (.body // "" | contains("<!-- ai-dev:local-review -->")))' <<<"$reviews"
    exit 0 ;;
esac

# --json commits は先頭 100 件までなので、101 件目からの PR では最後の要素がいまのコミットにならない(RC-06)。いまのコミットの日時を直接取る
pushed=$(gh api "repos/$repo/commits/$head" --jq .commit.committer.date)

reviewed=$(jq --arg head "$head" 'any(.[]; .commit_id == $head and ((.user.login == "coderabbitai[bot]" and (.body // "" | length) > 0)
      or (.user.login == "github-actions[bot]" and (.body // "" | contains("<!-- ai-dev:claude-review -->")))))' <<<"$reviews")
finished=$(gh api --paginate --slurp "repos/$repo/issues/$number/comments?per_page=100" \
  | jq --arg since "$pushed" 'add // [] | map(select(.user.login == "coderabbitai[bot]" and .created_at > $since))
      | any(.body // "" | test("Review finished|No actionable comments|LGTM"))')

if [ "$reviewed" = true ] || [ "$finished" = true ]; then echo true; else echo false; fi
