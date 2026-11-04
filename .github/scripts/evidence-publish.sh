#!/usr/bin/env bash
# 撮った証跡を、evidence ブランチ(証跡だけの孤立したブランチ)の pr-<番号>/<head の短い SHA>/ に置いて push する(#600)
#
# 使い方: evidence-publish.sh <証跡のディレクトリ> <owner/repo> <PR の番号> <head の短い SHA>
#   push には環境変数 GH_TOKEN を使う(evidence-capture.yml の publish ジョブの GITHUB_TOKEN。contents: write はこのジョブだけ)。
#   EVIDENCE_REMOTE を渡すと、GitHub ではなくその git のリモートに push する(テスト用。GH_TOKEN は使わない)。
#
# 出力: 置いたファイルの raw.githubusercontent.com の URL を 1 行に 1 つ標準出力に出して 0 で終わる。
# 置けないもの(決まった名前でないファイル・ディレクトリ・リンク・大きすぎるファイル)があるとき、ランナーのパス・ホスト名が
# 出ていたとき(evidence-leak-check.sh)は、push せずに理由を標準エラーに出して 1 で終わる。引数の誤りは 2。
#
# 証跡のディレクトリは、PR のコードを動かした capture ジョブが作ったもの(アーティファクト)なので、書き込みの権限のある
# このジョブでは中身を信用せず、置いてよい名前と大きさのファイルだけを置く。PR のコードはここでは動かさない。
# 同じ SHA で撮り直したときは、そのディレクトリを入れ替える。ほかの PR・ほかの SHA のディレクトリには触らない。
set -euo pipefail

USAGE="使い方: evidence-publish.sh <証跡のディレクトリ> <owner/repo> <PR の番号> <head の短い SHA>"
BRANCH=evidence
# GitHub は 100MB を超えるファイルの push を断る。証跡の 1 本としても大きすぎるので、手前で止める
MAX_BYTES=$((50 * 1024 * 1024))
NAME_RE='^([0-9]{2}-(screen|api|command)(-[0-9]{3,4})?-(before|after|compare)\.(png|mp4|gif)|manifest\.json)$'

# 1 回分: evidence ブランチを取り、dest を入れ替えてコミットし、push する。push が断られたら 3、ほかの失敗は 1
# (呼ぶ側の || で set -e が効かないので、1 つずつ確かめる)
publish_once() {
  local dir=$1 src=$2 remote=$3 dest=$4 message=$5
  shift 5
  local auth=("$@")
  git init -q "$dir" || return 1
  cd "$dir" || return 1
  git config user.name "github-actions[bot]" || return 1
  git config user.email "41898282+github-actions[bot]@users.noreply.github.com" || return 1
  local heads
  heads=$(git "${auth[@]}" ls-remote --heads "$remote" "$BRANCH") || return 1
  if [ -n "$heads" ]; then
    git "${auth[@]}" fetch -q --depth 1 "$remote" "refs/heads/$BRANCH" || return 1
    git checkout -q -B "$BRANCH" FETCH_HEAD || return 1
  else
    # 初めて置くときは、develop などの履歴を持たない孤立したブランチにする
    git symbolic-ref HEAD "refs/heads/$BRANCH" || return 1
  fi
  rm -rf "$dest" && mkdir -p "$dest" && cp "$src"/* "$dest"/ || return 1
  git add -A "$dest" || return 1
  if git diff --cached --quiet; then
    echo "evidence ブランチの $dest は、もう同じ中身になっている" >&2
    return 0
  fi
  git commit -q -m "evidence: $message" || return 1
  git "${auth[@]}" push -q "$remote" "HEAD:refs/heads/$BRANCH" || return 3
}

evidence_publish() {
  local src=${1:-} repo=${2:-} number=${3:-} short=${4:-}
  if [ $# -ne 4 ] || [ ! -d "$src" ] || [[ ! "$repo" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] \
    || [[ ! "$number" =~ ^[1-9][0-9]*$ ]] || [[ ! "$short" =~ ^[0-9a-f]{7,40}$ ]]; then
    echo "$USAGE" >&2
    return 2
  fi
  local here
  here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
  src=$(cd "$src" && pwd)

  local bad=() f name size count=0
  while IFS= read -r -d '' f; do
    name=${f#"$src"/}
    if [ -L "$f" ] || [ ! -f "$f" ]; then
      bad+=("$name: ファイルでない(ディレクトリ・リンクは置かない)")
    elif [[ ! "$name" =~ $NAME_RE ]]; then
      bad+=("$name: 置いてよい名前でない")
    else
      size=$(stat -c %s "$f")
      [ "$size" -le "$MAX_BYTES" ] || bad+=("$name: 大きすぎる(${size} バイト。上限 ${MAX_BYTES})")
      count=$((count + 1))
    fi
  done < <(find "$src" -mindepth 1 -print0)
  [ "$count" -gt 0 ] || bad+=("置くファイルが 1 つも無い")
  [ -f "$src/manifest.json" ] || bad+=("manifest.json が無い")
  if [ "${#bad[@]}" -gt 0 ]; then
    printf '置かない: %s\n' "${bad[@]}" >&2
    return 1
  fi
  if ! bash "$here/evidence-leak-check.sh" "$src"; then
    echo "置かない: ランナーのパス・ホスト名が出ている" >&2
    return 1
  fi

  local remote auth=()
  if [ -n "${EVIDENCE_REMOTE:-}" ]; then
    remote=$EVIDENCE_REMOTE
  else
    : "${GH_TOKEN:?GH_TOKEN が無い}"
    remote="https://github.com/$repo.git"
    # トークンを .git/config・URL に残さないよう、その回のコマンドの設定で渡す
    auth=(-c "http.https://github.com/.extraheader=AUTHORIZATION: basic $(printf 'x-access-token:%s' "$GH_TOKEN" | base64 -w0)")
  fi

  local rc attempt dest="pr-$number/$short"
  WORK=$(mktemp -d)
  trap 'rm -rf "$WORK"' EXIT
  for attempt in 1 2 3; do
    rc=0
    publish_once "$WORK/repo-$attempt" "$src" "$remote" "$dest" "PR #$number の $short の証跡" "${auth[@]}" || rc=$?
    [ "$rc" -eq 0 ] && break
    # 3 はほかの実行が先に push した(fast-forward でない)とき。取り直して置き直す。ほかの失敗はそこで止める
    if [ "$rc" -ne 3 ] || [ "$attempt" -eq 3 ]; then
      echo "evidence ブランチに push できなかった" >&2
      return 1
    fi
    sleep $((attempt * 5))
  done

  for f in "$src"/*; do
    echo "https://raw.githubusercontent.com/$repo/$BRANCH/$dest/$(basename "$f")"
  done
}

evidence_publish "$@"
