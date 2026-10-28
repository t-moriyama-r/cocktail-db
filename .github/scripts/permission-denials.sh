#!/usr/bin/env bash
# Claude Code が使おうとして許可されなかったツールを、Actions のログに ::warning:: で出す
#
# 使い方: permission-denials.sh <claude-code-action の execution_file>
#
# claude-code-action のログには、拒否された回数(permission_denials_count)しか出ない。何が拒否されたかが分からないと、
# --allowedTools とプロンプトのどちらを直せばよいか決められない(#426 の resume は 7 回拒否されて何もせずに終わったが、
# 中身が残っておらず直しようがなかった。#455)。execution_file の結果(type が result のもの)の permission_denials から、
# ツールの名前と、Bash ならコマンドの先頭を出す。
#
# コマンドは PR のコメント(外の人も書ける)から作られることがあるので、秘密や長い文を出し過ぎないよう先頭 80 文字に切り、
# 改行などをワークフローのコマンドとして読まれないよう ::warning:: の決まりで置き換える。
# ログに出すだけの手順なので、ファイルが無い・読めないときも警告を出して成功で終わる(後ろの手順を止めない)
set -euo pipefail

MAX=80

permission_denials() {
  local file=${1:-} denials count
  if [ -z "$file" ] || [ ! -s "$file" ]; then
    echo "Claude Code の実行の記録(execution_file)が無い。拒否されたツールは調べない"
    return
  fi
  # 記録は、メッセージの配列のこともあれば、1 行に 1 つのこともある。どちらでも result を拾えるよう、全部を 1 つの配列にしてから見る
  if ! denials=$(jq -c -s '[flatten[] | objects | select(.type == "result") | .permission_denials[]?]' "$file" 2>/dev/null); then
    echo "::warning::Claude Code の実行の記録を JSON として読めなかったので、拒否されたツールを出せない"
    return
  fi
  if jq -e 'length == 0' <<<"$denials" >/dev/null; then
    echo "Claude Code が拒否されたツールは無い"
    return
  fi
  count=0
  while IFS= read -r line; do
    count=$((count + 1))
    line=${line%$'\r'}
    echo "::warning title=Claude Code が拒否されたツール::$(escape "$line")"
  done < <(jq -r --argjson max "$MAX" '.[]
    | (.tool_name // "(不明)") as $name
    | (if $name == "Bash" then (.tool_input.command // "" | gsub("[\r\n\t]+"; " ") | .[0:$max]) else "" end) as $cmd
    | "\($name)\(if $cmd == "" then "" else ": \($cmd)" end)"' <<<"$denials")
  echo "Claude Code が拒否されたツール: $count 件"
}

# ::warning:: の本文で特別な意味を持つ文字を置き換える(GitHub Actions のワークフローのコマンドの決まり)
escape() {
  local s=${1//%/%25}
  s=${s//$'\r'/%0D}
  s=${s//$'\n'/%0A}
  printf '%s' "$s"
}

permission_denials "$@"
