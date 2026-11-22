#!/usr/bin/env bash
# PR に、いま誰の番かを表す状態のラベル(状態:…)を 1 つだけ付ける
#
# 使い方:
#   pr-status.sh <owner/repo> <PR の番号>             事実を集めて状態を決め、ラベルを付け替える
#   pr-status.sh <owner/repo> <PR の番号> --dry-run   付け替えずに、事実と状態を出す
#   pr-status.sh decide                               事実の JSON(標準入力)から状態のラベル名を出す。閉じた PR では空
#
# 付け外しを各ワークフローに分けて書くと、セッションやジョブが途中で止まったときに古いラベルが残る。
# そのため、どこから呼ばれても、毎回いまの事実(下書きか・制御用のラベル・コンフリクト・CI・レビュー・返信待ちと未解決の指摘・GitHub がマージを許すか)から
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
#
# 人の判断待ち(needs-human・needs-decision)・手元の作業待ち(needs-manual)・見回り待ち(needs-local)は、下書きより先に見る。
# クラウドのルーチンや Actions は、判断や証跡を待つ PR を下書きのまま止めるので、下書きを先に見ると、止まっている PR が一覧でみな作業中に見える(#471)。
# 下書きでこれらのラベルが無いものは作業中
#
# needs-local(証跡の撮影・workflow の push など)は、PC を開いたときに手元の見回り(pr-patrol・daily-start)が片付けるので、
# 待っていれば進む。needs-manual(Secret の設定・ログインなど)は人が手を動かさないと進まない。一覧で見分けられるよう分け、
# 両方付いていれば待っていても進まないほうを出す(#496)
#
# 向き先が staging・release の PR(昇格の PR)は、中身が develop に入る前にレビュー済みで、head が保護されたブランチ(develop・staging)なので
# Actions の Claude Code が指摘を直せない。指摘が残っていてもレビュー対応の番にはしない。needs-approval の PR は、ai-auto-merge.yml が
# レビューの済んだのを確かめてから付けたものなので、同じくレビューの番にはしない
#
# マージ待ち・人のマージ待ちは、GitHub が実際にマージを許す(mergeStateStatus がマージできる値の)ときだけにする。ブランチ保護は
# 必須のチェックの成功と会話の解決を求めるので、返信の済んだ指摘や、パスの絞り込みで CI が動かなかったコミットでも止まる。
# 止めている理由ごとにラベルを分け、どれにも当てはまらない受け皿をマージ待ちにしない。
# BLOCKED のうち実行中・未解決で説明できないもので、いまのコミットに成功した必須のチェックが付いていないもの
# (1 つも付いていない・draft のときに飛ばした回のものしか無い)は、必須のチェックが付いていないものとみなす。
#
# develop には、ブランチ保護のほかにルールセット「オーナー以外の PR は承認が要る」(#540。承認 1 件、管理者は bypass できる)があり、
# GitHub はオーナーの PR にも BLOCKED・REVIEW_REQUIRED を返す。オーナーは bypass してマージできるので、必須のチェックが揃っていて、
# 止めているのが承認だけ(REVIEW_REQUIRED)なら、マージできる PR として扱う。オーナー以外の PR は承認を待つ番にする(#660)
#
# 向き先が develop・staging・release 以外の PR(積み上げの PR。土台の PR のブランチに向けたもの)は、ai-auto-merge.yml が見送り、
# test.yml も流れない。向き先のブランチに保護が無いので GitHub は CLEAN を返すが、マージ待ちにはせずベースブランチ待ちにする(#460)。
# 土台がマージされると向き先が develop に付け替わり、ci-rerun.yml が CI を流してからはこれまでどおりに決まる。
# 事実に向き先が無い(空)ときは develop 向けとみなす
decide() {
  jq -r --arg p "$PREFIX" '
    (.labels // []) as $l
    | (.baseRefName // "") as $base
    | ($base | IN("staging", "release")) as $promotion
    | ($base != "" and ($base | IN("develop", "staging", "release") | not)) as $stacked
    | ($l | index("needs-approval") != null) as $approval
    | (.requiredChecksComplete == true) as $checksComplete
    | (.mergeStateStatus == "BLOCKED" and $checksComplete and .reviewDecision == "REVIEW_REQUIRED") as $reviewOnly
    | ($reviewOnly and .byOwner == true) as $ownerBypass
    | if .state != "OPEN" then ""
      elif ($l | index("needs-human") or index("needs-decision")) then $p + "人の判断待ち"
      elif ($l | index("needs-manual")) then $p + "手元の作業待ち"
      elif ($l | index("needs-local")) then $p + "見回り待ち(証跡など)"
      elif .isDraft then $p + "作業中"
      elif .mergeable == "CONFLICTING" then $p + "コンフリクト"
      elif (.failedChecks // 0) > 0 then $p + "CI失敗"
      elif ($promotion or $approval | not) and (($l | index("ai-in-actions")) or (.unansweredThreads // 0) > 0) then $p + "レビュー対応中"
      elif ($approval | not) and (.headReviewed | not) then $p + "レビュー待ち"
      elif (.pendingChecks // 0) > 0 then $p + "CI実行中"
      elif (.unresolvedThreads // 0) > 0 then $p + "指摘の解決待ち"
      elif .mergeStateStatus == "BLOCKED" and ($checksComplete | not) then $p + "CI未実行"
      elif $reviewOnly and ($ownerBypass | not) then $p + "承認のレビュー待ち"
      elif .mergeStateStatus == "UNKNOWN" then $p + "確認中"
      elif (((.mergeStateStatus | IN("CLEAN", "HAS_HOOKS", "UNSTABLE")) or $ownerBypass) | not) then $p + "マージ不可"
      elif $promotion or $approval then $p + "人のマージ待ち"
      elif $stacked then $p + "ベースブランチ待ち"
      else $p + "マージ待ち"
      end'
}

facts() {
  local repo=$1 number=$2 pr
  local fields=state,isDraft,labels,mergeable,mergeStateStatus,baseRefName,author,reviewDecision
  pr=$(gh pr view "$number" -R "$repo" --json "$fields")
  if [ "$(jq -r .state <<<"$pr")" != OPEN ]; then
    jq '{state, labels: [.labels[].name]}' <<<"$pr"
    return
  fi
  # GitHub はマージできるかを後から計算するので、UNKNOWN の間は少し待って取り直す
  local _
  for _ in 1 2 3; do
    [ "$(jq -r .mergeStateStatus <<<"$pr")" = UNKNOWN ] || break
    sleep 5
    pr=$(gh pr view "$number" -R "$repo" --json "$fields")
  done
  # CI はブランチ保護で必須にしたチェックだけを見る。AI のワークフローのジョブ(取り消し・見送りが多い)を CI の失敗と取り違えない。
  # gh pr checks は、終わっていないチェックがあると 8 で、失敗したチェックがあると 1 で終わる(どちらも JSON は標準出力に出る)。
  # 必須のチェックが 1 つも付いていないときも 1 で、標準出力は空になる
  local checks rc=0 err failed pending unanswered unresolved reviewed complete=false
  err=$(mktemp)
  checks=$(gh pr checks "$number" -R "$repo" --required --json bucket,name 2>"$err") || rc=$?
  if jq -e 'type == "array"' <<<"$checks" >/dev/null 2>&1; then
    :
  elif [ "$rc" = 1 ] && grep -q "no required checks reported" "$err"; then
    checks='[]'
  else
    cat "$err" >&2
    rm -f "$err"
    echo "::error::#$number の必須のチェックを読めなかった" >&2
    return 1
  fi
  rm -f "$err"
  failed=$(jq '[.[] | select(.bucket == "fail")] | length' <<<"$checks")
  pending=$(jq '[.[] | select(.bucket == "pending")] | length' <<<"$checks")
  complete=$(required_checks_complete "$checks")
  unanswered=$(bash "$HERE/unanswered-review-threads.sh" "$repo" "$number")
  unresolved=$(unresolved_threads "$repo" "$number")
  reviewed=$(bash "$HERE/head-reviewed.sh" "$repo" "$number")
  # オーナーかどうかは、ほかのスクリプト(conflict-candidates.sh など)と同じく、PR の作者とリポジトリの持ち主で決める
  jq --argjson failed "$failed" --argjson pending "$pending" --argjson unanswered "$unanswered" \
    --argjson unresolved "$unresolved" --argjson reviewed "$reviewed" --argjson complete "$complete" \
    --arg owner "${repo%%/*}" \
    '{state, isDraft, mergeable, mergeStateStatus, baseRefName, labels: [.labels[].name],
      failedChecks: $failed, pendingChecks: $pending, unansweredThreads: $unanswered, unresolvedThreads: $unresolved, headReviewed: $reviewed,
      requiredChecksComplete: $complete, reviewDecision: (.reviewDecision // ""), author: (.author.login // ""),
      byOwner: ((.author.login // "") == $owner)}' <<<"$pr"
}

# いまのコミットに付いた必須のチェック(gh pr checks --required の結果)が、成功で揃っているかを true・false で出す。
# 保護の設定(必須にしたチェックの一覧)は管理者の権限が無いと読めず、Actions の GITHUB_TOKEN では読めないので、付いたチェックだけで決める。
# draft のときに全ジョブを飛ばした回のチェック(skipping)は成功に数えられるが、CI を流したことにはならないので、
# 成功(pass)が 1 つも無ければ揃っていないとみなす。release 向けで E2E だけを飛ばした回のように、飛ばしたものが混じっていてもよい。
# 一部のチェックだけが付いていない(ワークフローの一部しか走らなかった)ことは、一覧を読めないので見分けられない
required_checks_complete() {
  jq 'any(.[]; .bucket == "pass") and all(.[]; .bucket == "pass" or .bucket == "skipping")' <<<"$1"
}

# ブランチ保護の会話の解決は、AI レビュアーのものに限らず、すべてのスレッドを数える
unresolved_threads() {
  local repo=$1 number=$2
  # --paginate はページごとに数を出すので、最後に足す
  gh api graphql --paginate -F owner="${repo%%/*}" -F name="${repo#*/}" -F number="$number" -f query='
    query($owner: String!, $name: String!, $number: Int!, $endCursor: String) {
      repository(owner: $owner, name: $name) {
        pullRequest(number: $number) {
          reviewThreads(first: 100, after: $endCursor) {
            pageInfo { hasNextPage endCursor }
            nodes { isResolved }
          }
        }
      }
    }' --jq '[.data.repository.pullRequest.reviewThreads.nodes[] | select(.isResolved | not)] | length' | jq -s add
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
    作業中) color=ededed description="下書き。まだレビューに出していない(人の判断待ち・手元の作業待ち・見回り待ちのラベルは無い)" ;;
    レビュー待ち) color=c5def5 description="AI レビュアーがいまのコミットを見るのを待っている" ;;
    レビュー対応中) color=0e8a16 description="レビューの指摘に、Actions の Claude Code が対応する番" ;;
    人の判断待ち) color=d93f0b description="人の判断を待っている(needs-human・needs-decision)" ;;
    手元の作業待ち) color=e99695 description="人が手を動かさないと進まない作業(Secret の設定・ログインなど)を待っている(needs-manual)。待っていても進まない" ;;
    "見回り待ち(証跡など)") color=c2e0c6 description="証跡の撮影など、PC を開いたときに手元の見回りが片付ける作業を待っている(needs-local)。人の判断も手も要らない" ;;
    人のマージ待ち) color=fbca04 description="マージできる。人の確認とマージを待っている(needs-approval・staging/release 向けの PR)" ;;
    コンフリクト) color=b60205 description="develop とコンフリクトしている。Actions の Claude Code が取り込んで直す" ;;
    CI失敗) color=b60205 description="必須のチェックが失敗している" ;;
    CI実行中) color=c5def5 description="必須のチェックが終わるのを待っている" ;;
    指摘の解決待ち) color=d93f0b description="指摘に返信は済んだが、スレッドが解決されていない。人が確かめて解決にする" ;;
    CI未実行) color=b60205 description="必須のチェックが、いまのコミットで成功していない(付いていない、など)" ;;
    承認のレビュー待ち) color=fbca04 description="オーナー以外の PR。必須のチェックは揃っているが、develop のルールセットで承認のレビューが 1 件要る" ;;
    確認中) color=ededed description="GitHub がマージできるかを計算している。次の出し直しで決まる" ;;
    マージ不可) color=b60205 description="GitHub がマージを止めている(上のどのラベルでもない理由)" ;;
    ベースブランチ待ち) color=bfdadc description="向き先が develop 以外(積み上げの PR)。ベースブランチの PR がマージされると develop に付け替わり、CI が流れる" ;;
    マージ待ち) color=5319e7 description="マージできる。ai-auto-merge.yml のマージを待っている" ;;
    *) color=ededed description="" ;;
  esac
  # 決め方を変えたときに、既にあるラベルの説明も揃うよう --force で上書きする
  gh label create "$name" -R "$repo" --color "$color" --description "$description" --force 2>/dev/null || true
}

main "$@"
