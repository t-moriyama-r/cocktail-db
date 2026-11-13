#!/usr/bin/env bash
# ai-decision-loop.yml の Claude Code が、人の判断を issue に反映したか(何かしたか)を見る
#
# 使い方: decision-applied.sh <owner/repo> <issue の番号> <きっかけのコメントの created_at>
#
# 標準出力に次のどれかを 1 行で出す。
#   label-removed    … needs-decision が外れている(判断を本文に反映して、ラベルを外した)
#   agent-commented  … ラベルは残っているが、きっかけのコメントより後に、エージェントのコメント(目印 ai-dev:agent)がある
#                      (判断になっていないので改めて頼んだ、など)
#   not-applied      … ラベルが残っていて、きっかけのコメントより後にエージェントのコメントも無い(何もせずに終わった)
#
# Claude Code が本文を書くファイルを拒否されて、コメントもラベル外しもせずに「成功」で終わることがあった(#686・#675。#742)。
# ワークフローは not-applied のとき、反映しなかったことを issue にコメントしてジョブを失敗にする。
#
# - エージェントのコメントは、オーナーか bot(GitHub App・Actions)が書いた、目印 ai-dev:agent の付いたもの
#   (public のリポジトリでは誰でもコメントでき、目印を書き込めるため。owner-answered.sh と同じ見分け方)
# - 反映しなかったことを知らせるコメント(目印 ai-dev:decision-not-applied)は、エージェントが何かしたことに数えない
#   (Re-run したときに、前の回の知らせで「反映した」と取り違えないため)
# - ラベル・コメントを読めなかったら、何も出さずに失敗で終わる(「反映しなかった」と取り違えない)
set -euo pipefail

USAGE="使い方: decision-applied.sh <owner/repo> <issue の番号> <きっかけのコメントの created_at>"

decision_applied() {
  local repo=${1:-} n=${2:-} since=${3:-} labels comments
  if [ -z "$repo" ] || [ -z "$n" ] || [ -z "$since" ]; then
    echo "$USAGE" >&2
    return 2
  fi
  labels=$(gh api "repos/$repo/issues/$n" --jq '[.labels[].name] | join(",")')
  if [[ ",$labels," != *",needs-decision,"* ]]; then
    echo "label-removed"
    return
  fi
  # since は更新の時刻で絞るので、作った時刻で絞り直す。ページごとの配列を 1 つにまとめてから見る
  comments=$(gh api --paginate --slurp "repos/$repo/issues/$n/comments?since=$since&per_page=100")
  printf '%s\n' "$comments" | jq -r --arg owner "${repo%%/*}" --arg since "$since" '
    add // []
    | map(select(.created_at > $since
        and (.user.login == $owner or .user.type == "Bot")
        and (.body // "" | contains("ai-dev:agent"))
        and (.body // "" | contains("ai-dev:decision-not-applied") | not)))
    | if length > 0 then "agent-commented" else "not-applied" end'
}

decision_applied "$@"
