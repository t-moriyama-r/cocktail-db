#!/usr/bin/env bash
# release 向けの PR(昇格の PR)のタイトルから、本番に出す版の番号(SemVer)を取り出して確かめる
# (branch-flow.yml・release-tag.yml から呼ぶ。決まりは CLAUDE.md の手順 9)
#
# 使い方:
#   release-version.sh parse "<PR のタイトル>"
#     タイトルが「【運用】release v<MAJOR>.<MINOR>.<PATCH>」の形なら、版(v1.2.0 など)を出す。形が違えば失敗で終える
#   release-version.sh check "<PR のタイトル>" < <いまあるタグ(1 行に 1 つ)>
#     parse に加え、版がいまあるタグのうちいちばん大きい版より大きいかを確かめ、通れば版を出す。
#     vX.Y.Z の形でないタグ(v2・legacy-… など)は比べる相手にしない。タグが 1 つも無ければ、形が合っていれば通す
#
# 数字は先頭に 0 を付けない(SemVer のとおり。v1.02.0 は落とす)。比べるのは数としてで、v1.10.0 は v1.9.0 より大きい
set -euo pipefail

TITLE_RE='^【運用】release (v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*))$'
TAG_RE='^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$'

parse() {
  local title=$1
  if [[ ! $title =~ $TITLE_RE ]]; then
    echo "::error::release 向けの PR のタイトルは「【運用】release v<MAJOR>.<MINOR>.<PATCH>」(例: 【運用】release v1.2.0)にする。いまのタイトル: $title" >&2
    return 1
  fi
  echo "${BASH_REMATCH[1]}"
}

# 2 つの版を比べ、1 つめが大きければ 0 で終える
greater() {
  local a=$1 b=$2 i
  local -a x y
  [[ $a =~ $TAG_RE ]] && x=("${BASH_REMATCH[1]}" "${BASH_REMATCH[2]}" "${BASH_REMATCH[3]}")
  [[ $b =~ $TAG_RE ]] && y=("${BASH_REMATCH[1]}" "${BASH_REMATCH[2]}" "${BASH_REMATCH[3]}")
  for i in 0 1 2; do
    if ((10#${x[i]} > 10#${y[i]})); then return 0; fi
    if ((10#${x[i]} < 10#${y[i]})); then return 1; fi
  done
  return 1
}

check() {
  local title=$1 version latest="" tag
  version=$(parse "$title")
  while IFS= read -r tag || [ -n "$tag" ]; do
    [[ $tag =~ $TAG_RE ]] || continue
    if [ -z "$latest" ] || greater "$tag" "$latest"; then latest=$tag; fi
  done
  if [ -n "$latest" ] && ! greater "$version" "$latest"; then
    echo "::error::版 $version は、前のタグ $latest より大きくする(上げ方は CLAUDE.md の手順 9)" >&2
    return 1
  fi
  echo "$version"
}

case "${1:-}" in
  parse) parse "${2:?タイトルを渡す}" ;;
  check) check "${2:?タイトルを渡す}" ;;
  *)
    echo "使い方: $0 parse|check <PR のタイトル>" >&2
    exit 2
    ;;
esac
