#!/usr/bin/env bash
# PR に、いま誰の番かを表す状態のラベル(状態:…)を 1 つだけ付ける
#
# 使い方:
#   pr-status.sh <owner/repo> <PR の番号>             事実を集めて状態を決め、ラベルを付け替える
#   pr-status.sh <owner/repo> <PR の番号> --dry-run   付け替えずに、事実と状態を出す
#   pr-status.sh decide                               事実の JSON(標準入力)から状態のラベル名を出す。閉じた PR では空
#
# 付け外しを各ワークフローに分けて書くと、セッションやジョブが途中で止まったときに古いラベルが残る。
# そのため、どこから呼ばれても、毎回いまの事実(下書きか・制御用のラベル・コンフリクト・CI・レビュー・返信待ちの指摘)から
# 出し直して付け替える。何度呼んでも同じ結果になるので、見回り(ai-review-sweep.yml)から呼べば食い違いが直る。
# 状態のラベルは表示のためのもので、ワークフローの条件には使わない(条件は needs-human などの制御用のラベルで決める)
set -euo pipefail

PREFIX="状態:"
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)

main() {
  if [ "${1:-}" = decide ]; then
    decide
    return
  fi
  local repo=$1 number=$2 json target
  json=$(facts "$repo" "$number")
  target=$(decide <<<"$json")
  if [ "${3:-}" = --dry-run ]; then
    echo "#$number $(jq -c . <<<"$json") => ${target:-(状態のラベルなし)}"
    return
  fi
  apply "$repo" "$number" "$target"
}

# 上から見て最初に当てはまったものにする。誰の番かが変わるところで分け、レビューの流れが止まるもの(コンフリクト・CI の失敗)は
# 人が気づけるよう別にする。mergeable が UNKNOWN(push の直後に GitHub が計算している間)はコンフリクトとみなさない
decide() {
  jq -r --arg p "$PREFIX" '
    (.labels // []) as $l
    | if .state != "OPEN" then ""
      elif .isDraft then $p + "作業中"
      elif ($l | index("needs-human") or index("needs-decision")) then $p + "人の判断待ち"
      elif ($l | index("needs-approval")) then $p + "人のマージ待ち"
      elif .mergeable == "CONFLICTING" then $p + "コンフリクト"
      elif (.failedChecks // 0) > 0 then $p + "CI失敗"
      elif ($l | index("ai-in-actions")) or (.unansweredThreads // 0) > 0 then $p + "レビュー対応中"
      elif .headReviewed | not then $p + "レビュー待ち"
      else $p + "マージ待ち"
      end'
}

facts() {
  local repo=$1 number=$2 pr
  pr=$(gh pr view "$number" -R "$repo" --json state,isDraft,labels,mergeable)
  if [ "$(jq -r .state <<<"$pr")" != OPEN ]; then
    jq '{state, labels: [.labels[].name]}' <<<"$pr"
    return
  fi
  # CI はブランチ保護で必須にしたチェックだけを見る。AI のワークフローのジョブ(取り消し・見送りが多い)を CI の失敗と取り違えない
  # gh pr checks は、終わっていないチェックがあると 0 以外で終わる
  local checks failed unanswered reviewed
  checks=$(gh pr checks "$number" -R "$repo" --required --json bucket 2>/dev/null || true)
  if ! failed=$(jq -e '[.[] | select(.bucket == "fail")] | length' <<<"$checks" 2>/dev/null); then
    echo "::warning::#$number の必須のチェックを読めなかった。失敗は無いものとして扱う" >&2
    failed=0
  fi
  unanswered=$(bash "$HERE/unanswered-review-threads.sh" "$repo" "$number")
  reviewed=$(bash "$HERE/head-reviewed.sh" "$repo" "$number")
  jq --argjson failed "$failed" --argjson unanswered "$unanswered" --argjson reviewed "$reviewed" \
    '{state, isDraft, mergeable, labels: [.labels[].name], failedChecks: $failed, unansweredThreads: $unanswered, headReviewed: $reviewed}' <<<"$pr"
}

apply() {
  local repo=$1 number=$2 target=$3 current label
  current=$(gh api "repos/$repo/issues/$number/labels" --jq ".[].name | select(startswith(\"$PREFIX\"))")
  # 先に付けてから古いものを外す。途中で止まっても、ラベルが 1 つも無い PR を残さない(2 つ残っても次の回で直る)
  if [ -n "$target" ] && ! grep -qxF "$target" <<<"$current"; then
    ensure_label "$repo" "$target"
    gh api -X POST "repos/$repo/issues/$number/labels" -f "labels[]=$target" >/dev/null
  fi
  while IFS= read -r label; do
    [ -n "$label" ] && [ "$label" != "$target" ] || continue
    gh api -X DELETE "repos/$repo/issues/$number/labels/$(jq -rn --arg s "$label" '$s | @uri')" >/dev/null
  done <<<"$current"
  echo "#$number: ${target:-(状態のラベルなし)}"
}

ensure_label() {
  local repo=$1 name=$2 color description
  case "${name#"$PREFIX"}" in
    作業中) color=ededed description="下書き。まだレビューに出していない" ;;
    レビュー待ち) color=c5def5 description="AI レビュアーがいまのコミットを見るのを待っている" ;;
    レビュー対応中) color=0e8a16 description="レビューの指摘に、Actions の Claude Code が対応する番" ;;
    人の判断待ち) color=d93f0b description="人の判断を待っている(needs-human・needs-decision)" ;;
    人のマージ待ち) color=fbca04 description="人の確認とマージを待っている(needs-approval)" ;;
    コンフリクト) color=b60205 description="develop とコンフリクトしている。Actions の Claude Code が取り込んで直す" ;;
    CI失敗) color=b60205 description="必須のチェックが失敗している" ;;
    マージ待ち) color=5319e7 description="レビューの対応が済み、自動マージを待っている" ;;
    *) color=ededed description="" ;;
  esac
  gh label create "$name" -R "$repo" --color "$color" --description "$description" 2>/dev/null || true
}

main "$@"
