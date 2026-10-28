#!/usr/bin/env bash
#
# 人に頼んだ Slack の通知(needs-human・needs-local・needs-decision・needs-approval)のうち、片付いたものに ✅ を付け、本文の先頭に「【済】」を足す。
#
# 頼んだ通知は、notify-needs-human.yml が bot で送るときに、どの PR・issue のどのラベルかをメッセージのメタデータ
# (event_type ai_dev_ask)に持たせている。GitHub 側に記録を残さないので、bot と人の DM の履歴を読んで頼んだ通知を探す。
# 片付いたかは GitHub の今の状態で決める。ラベルを外すのは Actions の中(GITHUB_TOKEN)のことが多く、そのときは unlabeled の
# イベントが起きないため、「外れた」という出来事ではなく、今の状態と見比べる。だから、どこから何度呼んでもよい。
# 「済」を付けた通知は、メタデータを ai_dev_done に書き換えて、次からは見ない。
#
# 片付いたとみなすのは、次のどれか
# - PR・issue が閉じた
# - 頼んだラベルが外れた
# - 同じ PR・issue の同じラベルで、後から頼み直した(前の頼みには返事があり、エージェントがそれを読んで頼み直している)
#
# 要るもの: secret SLACK_BOT_TOKEN(chat:write・reactions:write・im:write・im:history)、変数 SLACK_NOTIFY_USER、
# 環境変数 REPO(owner/name)と GH_TOKEN。bot が無いリポジトリ(Webhook で送っている)では何もしない
#
# 使い方: .github/scripts/slack-mark-done.sh

set -euo pipefail

# 頼んでから返事を待つ間に、これより古くなった通知は見ない(履歴を読む量を抑える)
LOOKBACK_DAYS=${LOOKBACK_DAYS:-90}

slack_mark_done() {
	if [ -z "${SLACK_BOT_TOKEN:-}" ] || [ -z "${SLACK_NOTIFY_USER:-}" ]; then
		echo "secret SLACK_BOT_TOKEN か変数 SLACK_NOTIFY_USER が無いので、「済」を付けなかった"
		return 0
	fi

	# 「済」は見た目の印なので、Slack 側の失敗(権限の不足など)でジョブを失敗にしない。
	# pull_request_target で動くので、失敗すると PR のチェックが赤くなり、自動マージが止まる
	local opened channel asks
	if ! opened=$(slack_form conversations.open --data-urlencode "users=$SLACK_NOTIFY_USER"); then
		echo "::warning::Slack の DM を開けなかったので、「済」を付けなかった"
		return 0
	fi
	channel=$(jq -r .channel.id <<<"$opened")
	if ! asks=$(read_asks "$channel"); then
		echo "::warning::Slack の DM の履歴を読めなかったので、「済」を付けなかった"
		return 0
	fi

	local pending
	pending=$(jq -c '.[] | select(.done | not)' <<<"$asks")
	if [ -z "$pending" ]; then
		echo "「済」を付けていない通知は無かった"
		return 0
	fi

	local ask number label why
	while read -r ask; do
		number=$(jq -r .number <<<"$ask")
		label=$(jq -r .label <<<"$ask")
		why=$(settled_reason "$ask" "$number" "$label")
		if [ -n "$why" ] && mark_done "$channel" "$ask"; then
			echo "#$number の $label の通知に「済」を付けた($why)"
		fi
	done <<<"$pending"
}

# DM の履歴から、このリポジトリについて頼んだ通知を [{ts, text, number, label, done, superseded}] で返す。
# superseded は、同じ PR・issue の同じラベルで、後から頼み直した通知があるもの
read_asks() {
	local channel=$1 oldest cursor="" page messages="[]"
	oldest=$(($(date -u +%s) - LOOKBACK_DAYS * 86400))
	while :; do
		page=$(slack_form conversations.history --data-urlencode "channel=$channel" --data-urlencode "oldest=$oldest" \
			--data-urlencode "limit=200" --data-urlencode "include_all_metadata=true" --data-urlencode "cursor=$cursor") || return 1
		# 1 ページの応答は 1 引数の上限(Linux で 128KB)を超えうるので、引数ではなく標準入力で渡す。
		# この関数は if ! の中で呼ばれて set -e が効かないので、失敗は自分で返す
		messages=$(printf '%s\n%s\n' "$messages" "$page" | jq -cs '.[0] + (.[1].messages // [])') || return 1
		cursor=$(jq -r '.response_metadata.next_cursor // ""' <<<"$page")
		[ -n "$cursor" ] || break
	done

	jq -c --arg repo "$REPO" '
		map(select(.metadata.event_type == "ai_dev_ask" or .metadata.event_type == "ai_dev_done")
			| select(.metadata.event_payload.repo == $repo)
			| {ts, text, number: (.metadata.event_payload.number | tostring), label: .metadata.event_payload.label,
				done: (.metadata.event_type == "ai_dev_done")})
		| . as $all
		| map(. as $a | . + {superseded: any($all[]; .number == $a.number and .label == $a.label
			and (.ts | tonumber) > ($a.ts | tonumber))})' <<<"$messages"
}

# 片付いていれば、その理由を返す。片付いていなければ空
settled_reason() {
	local ask=$1 number=$2 label=$3
	if [ "$(jq -r .superseded <<<"$ask")" = true ]; then
		echo "後から頼み直した"
		return 0
	fi
	# issue の API は PR も返す
	local target
	target=$(gh api "repos/$REPO/issues/$number" --jq '{state, labels: [.labels[].name]}') || {
		echo "::warning::#$number の状態を読めなかった" >&2
		return 0
	}
	if [ "$(jq -r .state <<<"$target")" = closed ]; then
		echo "閉じた"
	elif ! jq -e --arg label "$label" '.labels | index($label)' <<<"$target" >/dev/null; then
		echo "$label が外れた"
	fi
}

mark_done() {
	local channel=$1 ask=$2 res
	res=$(jq -n --arg channel "$channel" --argjson ask "$ask" \
		'{channel: $channel, timestamp: $ask.ts, name: "white_check_mark"}' | slack_json reactions.add)
	# 前の回でリアクションまで付けて、本文の書き換えで止まったときは already_reacted になる
	if [ "$(jq -r .ok <<<"$res")" != true ] && [ "$(jq -r .error <<<"$res")" != already_reacted ]; then
		echo "::warning::リアクションを付けられなかった: $(jq -r .error <<<"$res")"
		return 1
	fi

	res=$(jq -n --arg channel "$channel" --argjson ask "$ask" --arg repo "$REPO" '{
		channel: $channel, ts: $ask.ts,
		text: ("【済】" + $ask.text),
		metadata: {event_type: "ai_dev_done", event_payload: {repo: $repo, number: $ask.number, label: $ask.label}}
	}' | slack_json chat.update)
	if [ "$(jq -r .ok <<<"$res")" != true ]; then
		echo "::warning::本文を書き換えられなかった: $(jq -r .error <<<"$res")"
		return 1
	fi
}

# 読む API(conversations.*)はフォームで、書く API は JSON で呼ぶ
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

slack_json() {
	curl -sS -X POST "https://slack.com/api/$1" -H "Authorization: Bearer $SLACK_BOT_TOKEN" \
		-H 'Content-Type: application/json; charset=utf-8' --data @-
}

slack_mark_done
