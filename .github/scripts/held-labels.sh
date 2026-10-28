#!/usr/bin/env bash
# PR に付いたラベルのうち、Actions の見回り・自動マージが手を出さずに待つ理由になるものを、, でつないで出す。無ければ空
#
# 使い方: held-labels.sh [ほかに待つ理由にするラベル...] < PR の JSON
#   JSON の .labels は、gh pr view の [{name}] でも、ラベル名の配列でもよい
#
# 人の判断(needs-human・needs-decision)と、手元の環境でしかできない作業(needs-local。証跡の撮影・workflow の push など)は、
# Actions がその先を進めても片付かず、人か手元のエージェントの番になっている。どの見回りもこれらの PR を見送るので、ここにまとめる。
# needs-approval・ai-in-actions・no-auto-merge のように、見送るかどうかが見回りごとに違うラベルは、呼ぶ側が引数で足す
set -euo pipefail

WAITING=(needs-human needs-decision needs-local)

jq -r --args '[.labels[]? | if type == "object" then .name else . end | select(IN($ARGS.positional[]))] | join(",")' \
  "${WAITING[@]}" "$@"
