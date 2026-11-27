#!/usr/bin/env bash
#
# 切り替えの手順 8(docs/cutover.md のスモーク)の curl の確かめを、順に流す。読むだけなので、何度流しても同じ。
#
#   ops/cutover/smoke.sh <アプリの URL> [--legacy-category <旧のカテゴリの ID>] [--expect-count <件数>]
#
#   <アプリの URL>       末尾の / を付けない(例: https://example.com)
#   --legacy-category   /detail/category/<ID> が 301 で /category/<新の ID> へ移ることも見る(#78)
#   --expect-count      /api/cocktails/count の件数が、旧サイトの「現在の登録数」と同じかを見る(省くと件数を出すだけ)
#
# 確かめること(この順。1 つ目の失敗で止め、通ったもの・止まったものを出す):
#   up          /up が 200
#   hello       /api/hello が 200(deploy.yml の Smoke test と同じ)
#   category    /detail/category/<旧の ID> が 301 で /category/<数字> へ(--legacy-category のときだけ)
#   admin       /Cocktail/admin が 301 で /admin へ(#78)
#   count       /api/cocktails/count が 200 で件数を返す(--expect-count のときは件数も比べる)
#
# 画面・管理画面のログイン・laravel.log は、docs/cutover.md の 8 のとおり人が見る。
#
# 終了コード:
#   0  すべて通った
#   1  引数のエラー、または確かめが通らなかった

set -uo pipefail

CHECKS=()
PASSED=()

smoke() {
  local app='' legacy_category='' expect_count=''
  while [[ $# -gt 0 ]]; do
    case $1 in
      --legacy-category) require_value "$@"; legacy_category=$2; shift 2 ;;
      --expect-count) require_value "$@"; expect_count=$2; shift 2 ;;
      -*) usage ;;
      *)
        [[ -z $app ]] || usage
        app=$1
        shift
        ;;
    esac
  done
  [[ -n $app ]] || usage
  [[ $app =~ ^https?://[^/]+$ ]] || die "アプリの URL は https://<ホスト> の形で、末尾の / を付けない: $app"
  [[ -z $legacy_category || $legacy_category =~ ^[0-9]+$ ]] || die "--legacy-category は数字: $legacy_category"
  [[ -z $expect_count || $expect_count =~ ^[0-9]+$ ]] || die "--expect-count は数字: $expect_count"
  APP=$app

  CHECKS=(up hello)
  [[ -z $legacy_category ]] || CHECKS+=(category)
  CHECKS+=(admin count)

  WORK=$(mktemp -d)
  trap 'rm -rf -- "$WORK"' EXIT

  run up expect_status /up 200
  run hello expect_status /api/hello 200
  [[ -z $legacy_category ]] || run category expect_redirect "/detail/category/$legacy_category" '/category/[0-9]+'
  run admin expect_redirect /Cocktail/admin /admin
  run count expect_count "$expect_count"
  log "すべて通った(${PASSED[*]})"
}

# --- 確かめ ---

expect_status() {
  local path=$1 want=$2 status
  status=$(fetch "$path") || return 1
  [[ $status == "$want" ]] || { log "$path が $status を返した($want を期待)"; return 1; }
}

expect_redirect() {
  local path=$1 pattern=$2 status location
  status=$(fetch "$path") || return 1
  location=$(header_location)
  [[ $status == 301 ]] || { log "$path が $status を返した(301 を期待)"; return 1; }
  # Location は絶対 URL(アプリの URL から始まる)でも、パスだけでもよい
  local target=${location#"$APP"}
  [[ $target =~ ^${pattern}$ ]] || { log "$path の転送先が $location(${pattern} を期待)"; return 1; }
  log "$path → $location"
}

expect_count() {
  local want=$1 status count
  status=$(fetch /api/cocktails/count) || return 1
  [[ $status == 200 ]] || { log "/api/cocktails/count が $status を返した(200 を期待)"; return 1; }
  count=$(sed -n 's/.*"count":[[:space:]]*\([0-9][0-9]*\).*/\1/p' "$WORK/body" | head -n 1)
  [[ -n $count ]] || { log "/api/cocktails/count の応答に件数が無い"; return 1; }
  if [[ -n $want && $count != "$want" ]]; then
    log "登録数が $count 件($want 件を期待)"
    return 1
  fi
  log "登録数: $count 件"
}

# --- 下回り ---

usage() {
  sed -n '5p' "$0" >&2
  exit 1
}

die() {
  log "$*"
  exit 1
}

log() {
  printf '%s smoke: %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

require_value() {
  [[ $# -ge 2 && -n $2 && $2 != -* ]] || die "$1 に値を渡す"
}

# 転送は追わない。本文を $WORK/body、ヘッダーを $WORK/headers に書き、状態コードを出す
fetch() {
  local status
  status=$(curl -sS --max-time 30 -o "$WORK/body" -D "$WORK/headers" -w '%{http_code}' -- "$APP$1") || {
    # 呼ぶ側は $(…) で状態コードを受けるので、知らせは標準エラーに出す
    log "$1 に繋がらなかった" >&2
    return 1
  }
  printf '%s\n' "$status"
}

header_location() {
  sed -n 's/^[Ll]ocation:[[:space:]]*//p' "$WORK/headers" | tr -d '\r' | tail -n 1
}

run() {
  local name=$1
  shift
  if "$@"; then
    PASSED+=("$name")
    return 0
  fi
  local rest=() c seen=0
  for c in "${CHECKS[@]}"; do
    ((seen)) && rest+=("$c")
    [[ $c != "$name" ]] || seen=1
  done
  log "[$name] で止まった"
  log "通ったもの: ${PASSED[*]:-(無し)}"
  log "流していないもの: ${rest[*]:-(無し)}"
  exit 1
}

smoke "$@"
