#!/usr/bin/env bash
# PR のいまのコミットのレビューを、まず CodeRabbit に頼み、CodeRabbit が動けないときだけ代わりの Claude(ai-claude-review.yml)を呼ぶ
# 昇格の PR(向き先が staging・release)は、どちらにも頼まない(作った手元のエージェントがレビューする。#479)
#
# 使い方: request-review.sh <owner/repo> <PR の番号>
# 環境変数:
#   GH_TOKEN                  PR を読み、ワークフローを呼ぶトークン(actions: write が要る)
#   CODERABBIT_REQUEST_TOKEN  オーナーの fine-grained PAT(このリポジトリの Pull requests: write)。「@coderabbitai review」を書く
#   OWNER・WAIT_MINUTES       review-request-state.sh に渡す
#
# Claude のトークンを使うのは、CodeRabbit が上限を返した・依頼に返事が無い・依頼を書けなかったときだけ。
# CodeRabbit はボットの依頼に応じないので、依頼はオーナーの PAT で書く。GITHUB_TOKEN や Claude の App のトークンで書いた依頼では、
# CodeRabbit が上限の返事すら付けず、代わりの Claude も呼ばれないまま PR が止まる(#227)
set -euo pipefail

repo=$1
number=$2
here=$(cd "$(dirname "$0")" && pwd)

main() {
  local pr label state
  pr=$(gh pr view "$number" -R "$repo" --json state,isDraft,labels,baseRefName)
  if [ "$(jq -r .state <<<"$pr")" != OPEN ]; then echo "#$number 見送り: 開いていない"; return; fi
  if [ "$(jq -r .isDraft <<<"$pr")" = true ]; then echo "#$number 見送り: draft"; return; fi
  # 昇格の PR は、作った手元のエージェントがレビューする(#479)
  case "$(jq -r .baseRefName <<<"$pr")" in
    staging | release) echo "#$number 見送り: 昇格の PR(手元の Claude がレビューする)"; return ;;
  esac
  label=$(jq -r '[.labels[].name | select(IN("needs-human","needs-local","needs-approval"))] | join(",")' <<<"$pr")
  if [ -n "$label" ]; then echo "#$number 見送り: ラベル $label(人か手元の番)"; return; fi

  state=$(bash "$here/review-request-state.sh" "$repo" "$number")
  case "$state" in
    reviewed) echo "#$number いまのコミットはレビュー済み" ;;
    waiting) echo "#$number CodeRabbit の返事を待っている" ;;
    request) request_coderabbit ;;
    limited) claude_review limited "CodeRabbit が上限を返した" ;;
    unanswered) claude_review no-response "CodeRabbit への依頼に ${WAIT_MINUTES:-30} 分たっても返事もレビューも無い" ;;
    *) echo "::error::review-request-state.sh が知らない値を返した: $state"; return 1 ;;
  esac
}

request_coderabbit() {
  if [ -z "${CODERABBIT_REQUEST_TOKEN:-}" ]; then
    echo "::warning::secret CODERABBIT_REQUEST_TOKEN が無いので CodeRabbit に頼めない"
    claude_review request-failed "CodeRabbit への依頼を書けなかった(secret CODERABBIT_REQUEST_TOKEN が無い)"
    return
  fi
  if GH_TOKEN=$CODERABBIT_REQUEST_TOKEN gh pr comment "$number" -R "$repo" --body '@coderabbitai review' >/dev/null; then
    echo "#$number CodeRabbit にレビューを頼んだ(上限が返れば、その返事で代わりの Claude が動く)"
    return
  fi
  # 期限切れ・権限不足などで書けなかったときは、止めずに代わりの Claude に回す
  echo "::warning::CODERABBIT_REQUEST_TOKEN で依頼を書けなかった。PAT の期限と権限(Pull requests: write)を確かめる"
  claude_review request-failed "CodeRabbit への依頼を書けなかった(CODERABBIT_REQUEST_TOKEN の期限切れ・権限不足)"
}

claude_review() {
  echo "#$number $2。代わりの Claude を呼ぶ"
  gh workflow run ai-claude-review.yml -R "$repo" -f number="$number" -f reason="$1"
}

main
