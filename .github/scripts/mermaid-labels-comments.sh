#!/usr/bin/env bash
# この回にエージェントが PR・issue に書いたコメントと本文の Mermaid の図から、字数の上限を超えたラベルを ::warning:: で出す
#
# 使い方: mermaid-labels-comments.sh <owner/repo> <PR か issue の番号> <この時刻より後に書いた・直したものを見る(ISO 8601)>
#
# 字数の数え方と上限は mermaid-labels.sh。
# 見るのは、目印 <!-- ai-dev: の付いたコメントのうち、オーナーか、エージェントが使う bot(claude[bot]・github-actions[bot])が
# 書いたものだけ(公開リポジトリでは誰でもコメントを書けるので、外の人のコメントの図で警告を出さない。RC-05)。本文は、その時刻より
# 後に直されていれば見る(判断が要ることを本文に書く issue があるため)。
# ログに出すだけの手順なので、上限を超えたものがあっても成功で終わる。GitHub を読めなかったときは、無かったと取り違えないよう失敗で終わる
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)

mermaid_labels_comments() {
  local repo=${1:?owner/repo}
  local number=${2:?番号}
  local since=${3:?時刻}
  local owner=${repo%%/*} work comments issue count=0 n i url report line
  work=$(mktemp -d)
  trap 'rm -rf "$work"' RETURN

  # updated_at が since 以降のコメント(書いた・直したもの)。全ページを取る(RC-06)
  comments=$(gh api --paginate --slurp "repos/$repo/issues/$number/comments?since=$since&per_page=100")
  issue=$(gh api "repos/$repo/issues/$number")

  jq -c --arg owner "$owner" '[.[][]
      | select(.user.login == $owner or .user.login == "claude[bot]" or .user.login == "github-actions[bot]")
      | select(.body // "" | contains("<!-- ai-dev:"))
      | {url: .html_url, body}]' <<<"$comments" >"$work/targets.json"
  jq -c --arg since "$since" 'if .updated_at >= $since then [{url: .html_url, body: (.body // "")}] else [] end' <<<"$issue" >"$work/body.json"
  jq -s -c 'add | map(select(.body | test("```\\s*mermaid")))' "$work/targets.json" "$work/body.json" >"$work/all.json"

  n=$(jq 'length' "$work/all.json")
  for ((i = 0; i < n; i++)); do
    url=$(jq -r ".[$i].url" "$work/all.json")
    jq -r ".[$i].body" "$work/all.json" >"$work/body.md"
    report=$(bash "$HERE/mermaid-labels.sh" "$work/body.md" || true)
    while IFS= read -r line; do
      [ -n "$line" ] || continue
      if [[ "$line" == *上限* ]]; then
        count=$((count + 1))
        # ラベルはコメントから取った文字なので、% をワークフローのコマンドの決まりで置き換える(改行は mermaid-labels.sh が出さない)
        echo "::warning title=Mermaid のラベルが長い::$url ${line//%/%25}"
      else
        echo "$url $line"
      fi
    done <<<"$report"
  done
  echo "Mermaid の図を見たコメント・本文: $n 件、上限を超えたラベル: $count 件"
}

mermaid_labels_comments "$@"
