#!/usr/bin/env bash
# PR のいまのコミットを、AI レビュアー(CodeRabbit・代わりの Claude)がもう見たかを true / false で出す
#
# 使い方: head-reviewed.sh <owner/repo> <PR の番号>
#
# CodeRabbit は指摘があればそのコミットへのレビューを付け、無ければ「Review finished」「No actionable comments」のコメントだけを付ける
# (どちらか片方のこともあり、後から別のコメントが続くこともある)。上限で動かなかったときは、ai-claude-review.yml が
# 目印 <!-- ai-dev:claude-review --> 付きのレビューをそのコミットに付ける。
# CodeRabbit はスレッドへの返信のたびに本文の無いレビューをいまのコミットに付けるので、それはレビューとして数えない
set -euo pipefail

repo=$1
number=$2

pr=$(gh pr view "$number" -R "$repo" --json headRefOid,commits)
head=$(jq -r .headRefOid <<<"$pr")
pushed=$(jq -r '.commits[-1].committedDate' <<<"$pr")

reviewed=$(gh api --paginate --slurp "repos/$repo/pulls/$number/reviews?per_page=100" \
  | jq --arg head "$head" 'add // [] | any(.commit_id == $head and ((.user.login == "coderabbitai[bot]" and (.body // "" | length) > 0)
      or (.user.login == "github-actions[bot]" and (.body // "" | contains("<!-- ai-dev:claude-review -->")))))')
finished=$(gh api --paginate --slurp "repos/$repo/issues/$number/comments?per_page=100" \
  | jq --arg since "$pushed" 'add // [] | map(select(.user.login == "coderabbitai[bot]" and .created_at > $since))
      | any(.body // "" | test("Review finished|No actionable comments|LGTM"))')

if [ "$reviewed" = true ] || [ "$finished" = true ]; then echo true; else echo false; fi
