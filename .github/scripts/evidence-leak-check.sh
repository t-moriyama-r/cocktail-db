#!/usr/bin/env bash
# 撮った証跡に写る文字(画面の文字・API の応答・コマンドの結果・manifest.json)に、ランナーのパスやホスト名が出ていないかを見る(#600)
#
# 使い方: evidence-leak-check.sh <ディレクトリ>...
#   渡したディレクトリの下の文字のファイル(バイナリは見ない)をすべて見る。
#   決まった形(/home/<名前>・/Users/<名前>・/root/・/github/workspace)のほかに、環境変数 EVIDENCE_LEAK_WORDS に
#   1 行に 1 つ書いた文字列(ホスト名・$GITHUB_WORKSPACE・$RUNNER_TEMP など)も、大文字と小文字を区別せずに探す。
#
# 出力: 見つかれば「<ファイル>:<行>: <何が出たか>」を 1 件 1 行で標準エラーに出して 1 で終わる。出た値そのものは出さない
# (ジョブのログは公開なので、ホスト名をログに残さない)。無ければ何も出さずに 0。ディレクトリが無いなど引数の誤りは 2。
#
# 証跡は公開の evidence ブランチに置く(CLAUDE.md の「このリポジトリは public」)。画像そのものは文字として読めないので、
# 画像に写す元の文字(evidence-shoot.js が texts/ に書き出したもの)を見る。
set -euo pipefail

USAGE="使い方: evidence-leak-check.sh <ディレクトリ>..."
# 名前の入る絶対パス。/home/ の後に 1 文字以上あるものだけを見る(「/home/」とだけ書いた説明は拾わない)
PATTERNS=(
  '/home/[A-Za-z0-9._-]+'
  '/Users/[A-Za-z0-9._-]+'
  '/root/'
  '/github/workspace'
)

evidence_leak_check() {
  local dir found=0 file pattern word line
  if [ $# -lt 1 ]; then
    echo "$USAGE" >&2
    return 2
  fi
  for dir in "$@"; do
    if [ ! -d "$dir" ]; then
      echo "ディレクトリが無い: $dir" >&2
      return 2
    fi
  done
  local words=()
  while IFS= read -r word; do
    # 短すぎる語(1〜2 文字)は、ふつうの文に当たってしまうので数えない
    [ "${#word}" -ge 3 ] && words+=("$word")
  done < <(printf '%s\n' "${EVIDENCE_LEAK_WORDS:-}" | tr -d '\r')

  while IFS= read -r -d '' file; do
    # バイナリ(画像・動画)は文字として読めないので飛ばす
    grep -Iq . "$file" 2>/dev/null || continue
    for pattern in "${PATTERNS[@]}"; do
      while IFS= read -r line; do
        echo "$file:$line: ランナーのパス($pattern の形)" >&2
        found=1
      done < <(grep -nE "$pattern" "$file" | cut -d: -f1 || true)
    done
    for word in "${words[@]}"; do
      while IFS= read -r line; do
        echo "$file:$line: EVIDENCE_LEAK_WORDS の語(ホスト名・ランナーの置き場所)" >&2
        found=1
      done < <(grep -niF -- "$word" "$file" | cut -d: -f1 || true)
    done
  done < <(find "$@" -type f -print0)
  return "$found"
}

evidence_leak_check "$@"
