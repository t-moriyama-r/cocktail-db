#!/usr/bin/env bash
#
# 標準入力の Slack のメッセージ(chat.postMessage の JSON。text・unfurl_links・metadata など)を送る。
#
# Slack App の bot(secret SLACK_BOT_TOKEN)があれば、リポジトリ変数 SLACK_NOTIFY_USER の人と bot の DM に送る。
# bot で送ったメッセージは、後から ✅ を付けたり本文を書き換えたりできる(slack-mark-done.sh)。
# bot が無いリポジトリでは Incoming Webhook(secret SLACK_WEBHOOK_URL)で送る。Webhook はメタデータを受け付けないので外す。
# どちらも無ければ、送らずに警告だけ出す(通知が無くても、ワークフローの本来の仕事は止めない)
#
# 環境変数 SKIP_SAME_ASK が true で、頼む通知(メタデータ ai_dev_ask)に頼む中身にしたコメントの URL(event_payload.ask)があるときは、
# bot で送る前に DM の履歴を見る。
# 同じリポジトリ・番号・ラベル・頼むコメントの通知が、まだ「済」になっていない(ai_dev_ask のまま)なら、同じ頼みの通知を
# もう送っているので送らない。回のあいだにほかのワークフローが付けた needs-human を、ai-review-loop.yml などが
# 「この回で付いた」とみなしてもう一度呼んでも、同じ頼みが 2 通届かないようにするため(#577)。
# notify-needs-human.yml は、ワークフローから呼ばれた(workflow_dispatch)ときだけ true にする。人がラベルを付け直したときは、
# 同じコメントのままでも、人が知らせ直したいものとして送る
# 履歴を読むのに、bot の権限 im:write・im:history が要る(slack-mark-done.sh と同じ)。
# 履歴を読めなかったときは、知らせ漏れより 2 通の方がましなので、警告を出して送る。Webhook は履歴を読めないので見ない
#
# 使い方: jq -n '{text: "…"}' | .github/scripts/slack-post.sh

set -euo pipefail

# 同じ頼みの通知を探す範囲(slack-mark-done.sh と同じ。それより古い通知は「済」を付ける側も見ない)
LOOKBACK_DAYS=${LOOKBACK_DAYS:-90}

slack_post() {
	local payload
	payload=$(cat)

	if [ -n "${SLACK_BOT_TOKEN:-}" ] && [ -n "${SLACK_NOTIFY_USER:-}" ]; then
		local res sent
		if sent=$(already_asked "$payload") && [ -n "$sent" ]; then
			echo "同じ頼み($sent)の通知を送って、まだ「済」になっていないので、Slack に送らなかった"
			return 0
		fi
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

# 頼む通知のうち、同じ頼みの通知がまだ「済」になっていなければ、その頼み(<番号> の <ラベル>: <コメントの URL>)を出す。
# 送ってよいときは何も出さない。履歴を読めないときも、警告を出して何も出さない(送る)
already_asked() {
	local payload=$1 key opened channel oldest cursor="" page found
	[ "${SKIP_SAME_ASK:-}" = true ] || return 0
	key=$(jq -c '.metadata // {} | select(.event_type == "ai_dev_ask" and ((.event_payload.ask // "") != ""))
		| .event_payload | {repo, number: (.number | tostring), label, ask}' <<<"$payload") || return 0
	[ -n "$key" ] || return 0

	if ! opened=$(slack_form conversations.open --data-urlencode "users=$SLACK_NOTIFY_USER"); then
		echo "::warning::Slack の DM を開けなかったので、同じ頼みを送ったかを確かめずに送る" >&2
		return 0
	fi
	channel=$(jq -r .channel.id <<<"$opened")
	oldest=$(($(date -u +%s) - LOOKBACK_DAYS * 86400))
	while :; do
		if ! page=$(slack_form conversations.history --data-urlencode "channel=$channel" --data-urlencode "oldest=$oldest" \
			--data-urlencode "limit=200" --data-urlencode "include_all_metadata=true" --data-urlencode "cursor=$cursor"); then
			echo "::warning::Slack の DM の履歴を読めなかったので、同じ頼みを送ったかを確かめずに送る" >&2
			return 0
		fi
		# 1 ページの応答は 1 引数の上限を超えうるので、標準入力で渡す(slack-mark-done.sh と同じ)
		if ! found=$(jq -r --argjson key "$key" '
			[(.messages // [])[] | select(.metadata.event_type == "ai_dev_ask") | .metadata.event_payload
				| select(.repo == $key.repo and (.number | tostring) == $key.number and .label == $key.label and .ask == $key.ask)]
			| if length > 0 then "#\($key.number) の \($key.label): \($key.ask)" else "" end' <<<"$page"); then
			echo "::warning::Slack の DM の履歴を読めなかったので、同じ頼みを送ったかを確かめずに送る" >&2
			return 0
		fi
		if [ -n "$found" ]; then
			printf '%s\n' "$found"
			return 0
		fi
		cursor=$(jq -r '.response_metadata.next_cursor // ""' <<<"$page")
		[ -n "$cursor" ] || return 0
	done
}

slack_form() {
	local method=$1
	shift
	local res
	res=$(curl -sS -X POST "https://slack.com/api/$method" -H "Authorization: Bearer $SLACK_BOT_TOKEN" "$@")
	if [ "$(jq -r .ok <<<"$res")" != true ]; then
		echo "::warning::Slack の $method が失敗した: $(jq -r .error <<<"$res")" >&2
		return 1
	fi
	printf '%s\n' "$res"
}

slack_post
