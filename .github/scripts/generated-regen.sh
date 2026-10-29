#!/usr/bin/env bash
# 生成ファイル(openapi/openapi.json・frontend/src/api/schema.ts)を、PR のコードから決まった手順で作り直す手順をまとめたもの(#455・#495)。
# ai-conflict-resolve.yml(develop を取り込んで生成ファイルが衝突した)と ai-review-loop.yml(Claude Code が作り直しを頼んだ)が使う。
#
# 使い方:
#   generated-regen.sh request <印のファイル> <PR の head>   … Claude Code が作り直しを頼んだか。頼んでいれば、作り直す head を標準出力に出す
#   generated-regen.sh build <PR を checkout したディレクトリ>   … CLAUDE.md の「API を変えたら」の手順で作り直す(PR のコードを動かす)
#   generated-regen.sh collect <PR を checkout したディレクトリ> <書き出し先> <head>   … 作り直して変わった生成ファイルを書き出し先に集める
#   generated-regen.sh check <書き出し先> <いまの PR の head>   … push してよいかを 1 行で出す
#
# 流れ: Claude Code(書き込みの権限のジョブ)は PHP・npm を使えないので、作り直しが要ると判断したら印のファイルを書くだけにする。
# 読むだけの権限(contents: read)のジョブが PR の head を persist-credentials: false で checkout して build・collect し、
# 書き出し先をアーティファクトで渡す。書き込みの権限のジョブは PR のコードを動かさず、check で確かめてから push する(RC-04)。
#
# request(印のファイルのある作業ツリーで流す):
#   - 印のファイルが無い → 何も出さない(作り直さない)
#   - 印のファイルが通常のファイルでない(リンクなど)・git で追跡している(PR のコードに入っている) → 警告を出して何も出さない
#     (PR のコードに印を入れておけば、誰でも作り直しを起こせてしまうため。作り直すのは Claude Code がこの回に書いた印だけ)
#   - 作業ツリーの HEAD が PR の head と違う(Claude Code の push が拒否された・その間に誰かが push した) → 警告を出して何も出さない
#   - それ以外 → PR の head を出す
#   印のファイルの中身(作り直しが要る理由。1 行)は、ログに出すだけ
#
# check の出力(1 行。タブ区切り):
#   moved      … 作り直した後に head が進んだ。push しない(理由はログに出す)
#   failed<TAB><理由> … 作り直せなかった・書き出し先が正しい形でない。手元に頼む(needs-local)
#   unchanged  … 作り直しても生成ファイルは変わらなかった。push しない
#   push       … 書き出し先の changed に並んだファイルを push してよい
#   書き出し先は PR のコードを動かしたジョブが書くので、中身を PR のコードが自由に書ける。changed の各行が生成ファイルの一覧と完全に一致し、
#   files の中身が通常のファイル(リンクでない・リンクのディレクトリの下でない)のときだけ push にする
set -euo pipefail

# 決まった手順(CLAUDE.md の「API を変えたら」)で作り直せる生成ファイル。呼び出し側が環境変数で渡せる
GENERATED_FILES=${GENERATED_FILES:-openapi/openapi.json frontend/src/api/schema.ts}
USAGE="使い方: generated-regen.sh request <印のファイル> <PR の head> | build <dir> | collect <dir> <書き出し先> <head> | check <書き出し先> <いまの head>"

regen_request() {
  local marker=$1 pr_head=$2 local_head reason
  if [ ! -e "$marker" ] && [ ! -L "$marker" ]; then
    echo "生成ファイルの作り直しは頼まれていない" >&2
    return 0
  fi
  if [ -L "$marker" ] || [ ! -f "$marker" ]; then
    echo "::warning::作り直しの印 $marker が通常のファイルでないので、作り直さない" >&2
    return 0
  fi
  if git ls-files --error-unmatch -- "$marker" >/dev/null 2>&1; then
    echo "::warning::作り直しの印 $marker が PR のコードに入っている(この回に Claude Code が書いたものではない)ので、作り直さない" >&2
    return 0
  fi
  reason=$(head -n 1 "$marker" | tr -d '\r' | cut -c 1-200)
  local_head=$(git rev-parse HEAD)
  if [ -z "$pr_head" ] || [ "$local_head" != "$pr_head" ]; then
    echo "::warning::作り直しを頼まれたが、作業ツリーの HEAD(${local_head:0:7})が PR の head(${pr_head:0:7})と違う(push されていない・その間に push された)ので、作り直さない" >&2
    return 0
  fi
  echo "生成ファイルの作り直しを頼まれた(${pr_head:0:7}): ${reason:-理由の記載なし}" >&2
  echo "$pr_head"
}

# Scramble がモデルの型をテーブルから読むので、先にマイグレーションを流す(DB_* は呼び出し側が環境変数で渡す)
regen_build() {
  local dir=$1
  (
    cd "$dir/backend"
    composer install --no-interaction --prefer-dist
    cp .env.example .env
    php artisan key:generate
    php artisan migrate --force
    php artisan scramble:export --path=../openapi/openapi.json
  )
  (
    cd "$dir/frontend"
    npm ci
    npm run gen:api
  )
}

regen_collect() {
  local dir=$1 out=$2 head=$3 f
  mkdir -p "$out"
  echo "$head" > "$out/head"
  : > "$out/changed"
  for f in $GENERATED_FILES; do
    if [ -n "$(git -C "$dir" status --porcelain -- "$f")" ]; then
      mkdir -p "$out/files/$(dirname "$f")"
      cp "$dir/$f" "$out/files/$f"
      echo "$f" >> "$out/changed"
    fi
  done
  if [ -s "$out/changed" ]; then
    echo "作り直して変わったファイル: $(paste -sd' ' "$out/changed")" >&2
  else
    rm -f "$out/changed"
    echo "作り直して変わったファイル: なし" >&2
  fi
}

regen_check() {
  local out=$1 now=$2 regen_head f g ok src
  regen_head=$(cat "$out/head" 2>/dev/null || true)
  if [ -z "$regen_head" ]; then
    printf 'failed\t%s\n' "作り直した結果が残っていなかった"
    return 0
  fi
  if [ "$now" != "$regen_head" ]; then
    echo "作り直した後に ${now:0:7} が push されたので、push しない" >&2
    echo moved
    return 0
  fi
  if [ -s "$out/failed" ]; then
    printf 'failed\t%s\n' "作り直すコマンドが失敗した"
    return 0
  fi
  if [ ! -s "$out/changed" ]; then
    echo "作り直しても生成ファイルは変わらなかった" >&2
    echo unchanged
    return 0
  fi
  while IFS= read -r f; do
    ok=
    for g in $GENERATED_FILES; do [ "$f" = "$g" ] && ok=1; done
    src="$out/files/$f"
    if [ -z "$ok" ] || [ ! -f "$src" ] || [ -L "$src" ] || [ "$(realpath -- "$src")" != "$(realpath -- "$out")/files/$f" ]; then
      echo "::warning::作り直したファイルの一覧に、生成ファイルではないか通常のファイルでないものがあるので、push しない" >&2
      printf 'failed\t%s\n' "作り直したファイルの一覧が正しい形でなかった"
      return 0
    fi
  done < "$out/changed"
  echo push
}

main() {
  local mode=${1:-}
  case "$mode" in
    request) [ $# -eq 3 ] || { echo "$USAGE" >&2; return 2; }; regen_request "$2" "$3" ;;
    build) [ $# -eq 2 ] || { echo "$USAGE" >&2; return 2; }; regen_build "$2" ;;
    collect) [ $# -eq 4 ] || { echo "$USAGE" >&2; return 2; }; regen_collect "$2" "$3" "$4" ;;
    check) [ $# -eq 3 ] || { echo "$USAGE" >&2; return 2; }; regen_check "$2" "$3" ;;
    *) echo "$USAGE" >&2; return 2 ;;
  esac
}

main "$@"
