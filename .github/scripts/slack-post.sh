#!/usr/bin/env bash
#
# 標準入力の Slack のメッセージ(chat.postMessage の JSON。text・unfurl_links・metadata など)を送る。
#
# Slack App の bot(secret SLACK_BOT_TOKEN)があれば、リポジトリ変数 SLACK_NOTIFY_USER の人と bot の DM に送る。
# bot で送ったメッセージは、後から ✅ を付けたり本文を書き換えたりできる(slack-mark-done.sh)。
# bot が無いリポジトリでは Incoming Webhook(secret SLACK_WEBHOOK_URL)で送る。Webhook はメタデータを受け付けないので外す。
# どちらも無ければ、送らずに警告だけ出す(通知が無くても、ワークフローの本来の仕事は止めない)
#
# 使い方: jq -n '{text: "…"}' | .github/scripts/slack-post.sh

set -euo pipefail

slack_post() {
	local payload
	payload=$(cat)

	if [ -n "${SLACK_BOT_TOKEN:-}" ] && [ -n "${SLACK_NOTIFY_USER:-}" ]; then
		local res
		res=$(jq --arg channel "$SLACK_NOTIFY_USER" '. + {channel: $channel}' <<<"$payload" \
			| curl -sS -X POST https://slack.com/api/chat.postMessage \
				-H "Authorization: Bearer $SLACK_BOT_TOKEN" \
				-H 'Content-Type: application/json; charset=utf-8' --data @-)
		# Slack の API は失敗しても HTTP 200 で ok: false を返す
		if [ "$(jq -r .ok <<<"$res")" != true ]; then
			echo "::error::Slack に送れなかった: $(jq -r .error <<<"$res")"
			return 1
		fi
		return 0
	fi

	if [ -n "${SLACK_WEBHOOK_URL:-}" ]; then
		jq 'del(.metadata)' <<<"$payload" \
			| curl -sSf -X POST -H 'Content-Type: application/json' --data @- "$SLACK_WEBHOOK_URL"
		return 0
	fi

	echo "::warning::secret SLACK_BOT_TOKEN(と変数 SLACK_NOTIFY_USER)も SLACK_WEBHOOK_URL も無いので Slack に送らなかった"
}

slack_post
