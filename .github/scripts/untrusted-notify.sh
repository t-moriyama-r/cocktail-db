#!/usr/bin/env bash
# この回に untrusted-hold.sh が止めて人に頼んだ PR・issue を、Slack に知らせる
#
# 使い方: untrusted-notify.sh <owner/repo> <PR か issue の番号> <この時刻より後に書いたものを見る(ISO 8601)>
#
# untrusted-hold.sh は GITHUB_TOKEN で needs-human を付けるので、ラベルのイベントでは notify-needs-human.yml が起きない。
# hold は Claude Code のジョブの中で起きるが、そのジョブにはワークフローを起動できる権限(actions: write)を渡さないので、
# 権限を持つ通知のジョブから、これで知らせる。
# needs-human は hold のたびに付け直され、付いたままオーナーが返信するたびに hold が動くこともあるので、ラベルではなく
# 「この回に頼むコメント(目印 ai-dev:untrusted)を新しく書いたか」で決める。頼むコメントは信頼しないものが増えたときだけ書くので、同じものでは 1 回だけ知らせる。
# 読めなかったときは、知らせずに失敗で終わる(呼ぶ側は警告にして、後ろの手順まで進む。毎朝のまとめには出る)
set -euo pipefail

USAGE="使い方: untrusted-notify.sh <owner/repo> <PR か issue の番号> <時刻(ISO 8601)>"
MARK='<!-- ai-dev:untrusted -->'

untrusted_notify() {
  local repo=${1:-} n=${2:-} since=${3:-} labels asked
  if [ -z "$repo" ] || [ -z "$n" ] || [ -z "$since" ]; then
    echo "$USAGE" >&2
    return 2
  fi
  labels=$(gh api "repos/$repo/issues/$n" --jq '[.labels[].name] | join(",")')
  # 頼んだ後に人がもう外していれば、知らせることは無い
  [[ ",$labels," == *",needs-human,"* ]] || return 0
  # 頼むコメントは github-actions[bot] が書く。外の人が目印をまねたコメントでは知らせない
  asked=$(gh api --paginate "repos/$repo/issues/$n/comments?since=$since&per_page=100" \
    --jq ".[] | select(.created_at >= \"$since\" and .user.login == \"github-actions[bot]\" and (.body | contains(\"$MARK\"))) | .id")
  [ -n "$asked" ] || return 0
  echo "#$n: 信頼しない人が書いたものがあって止めたので、Slack に知らせる"
  gh workflow run notify-needs-human.yml -R "$repo" -f number="$n" -f label=needs-human
}

untrusted_notify "$@"
