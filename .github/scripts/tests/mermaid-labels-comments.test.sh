#!/usr/bin/env bash
#
# mermaid-labels-comments.sh のテスト(TC-478-20〜)。gh を、決まった値を返す偽物に差し替えて流す。GitHub には触らない。
#
# 使い方: .github/scripts/tests/mermaid-labels-comments.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../mermaid-labels-comments.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT
export FAKE

FAILED=0
PASSED=0

SINCE=2026-10-29T00:00:00Z
LONG=$'<!-- ai-dev:agent --> <!-- ai-dev:needs-human --> どちらにするか決めてほしい\n\n```mermaid\nflowchart TD\n  A -->|一二三四五六七| B\n```'
SHORT=$'<!-- ai-dev:agent --> <!-- ai-dev:needs-human --> どちらにするか決めてほしい\n\n```mermaid\nflowchart TD\n  A -->|はい| B\n```'
NO_MARK=$'図を描いた\n\n```mermaid\nflowchart TD\n  A -->|一二三四五六七| B\n```'

main() {
	setup_fake_gh

	reset
	comments "o|$LONG"
	run
	expect TC-478-20 'オーナーが書いた目印付きのコメントの図で上限を超えたラベルを、コメントの URL を添えて ::warning:: で出し、成功で終わる' \
		"$(warnings)|$RC" '::warning title=Mermaid のラベルが長い::https://example.test/c1 図1 矢印のラベル「一二三四五六七」: 全角 7 字(上限 6 字)|0'

	reset
	comments "claude[bot]|$LONG" "github-actions[bot]|$LONG"
	run
	expect TC-478-21 'Actions のエージェント(claude[bot]・github-actions[bot])が書いたコメントも見る' "$(warnings | grep -c '::warning')" 2

	reset
	comments "outsider|$LONG"
	run
	expect TC-478-22 'オーナーでも決まった bot でもない人のコメントは見ない(外の人の図で警告を出さない)' "$(warnings)|$RC" '|0'

	reset
	comments "o|$NO_MARK"
	run
	expect TC-478-23 '目印 <!-- ai-dev: の無いコメント(人が書いたもの)は見ない' "$(warnings)|$RC" '|0'

	reset
	comments "o|$SHORT"
	run
	expect TC-478-24 '上限に収まる図なら警告を出さず、見た件数を出す' "$(warnings)|$(tail -n 1 "$FAKE/out" | tr -d '\r')" \
		'|Mermaid の図を見たコメント・本文: 1 件、上限を超えたラベル: 0 件'

	reset
	issue_body "$LONG" 2026-10-29T00:05:00Z
	run
	expect TC-478-25 'この回に直された本文の図も見る(判断が要ることを本文に書く issue)' \
		"$(warnings)" '::warning title=Mermaid のラベルが長い::https://example.test/issues/7 図1 矢印のラベル「一二三四五六七」: 全角 7 字(上限 6 字)'

	reset
	issue_body "$LONG" 2026-10-28T23:59:59Z
	run
	expect TC-478-26 'この回より前に直された本文は見ない' "$(warnings)|$RC" '|0'

	reset
	comments "o|$(sed 's/一二三四五六七/50%を超える文/' <<<"$LONG")"
	run
	expect TC-478-27 'ラベルの % はワークフローのコマンドの決まりで置き換える' \
		"$(warnings)" '::warning title=Mermaid のラベルが長い::https://example.test/c1 図1 矢印のラベル「50%25を超える文」: 全角 6.5 字(上限 6 字)'

	reset
	comments "o|$LONG"
	touch "$FAKE/count-fails"
	run
	expect TC-478-33 '字数を数えられなかった(mermaid-labels.sh が 0・1 以外で終わった)ら、超えたもの無しと取り違えずに警告する' \
		"$(warnings)|$RC" '::warning title=Mermaid のラベルを数えられなかった::https://example.test/c1 (mermaid-labels.sh の終了コード 5)|0'

	reset
	touch "$FAKE/api-fails"
	run
	expect TC-478-28 'GitHub を読めなかったら、無かったと取り違えずに失敗で終わる' "$(warnings)|$([ "$RC" -ne 0 ] && echo failed)" '|failed'

	reset
	RC=0
	PATH="$FAKE/bin:$PATH" bash "$SCRIPT" o/r 7 >"$FAKE/out" 2>&1 || RC=$?
	expect TC-478-29 '引数が足りなければ失敗で終わる' "$([ "$RC" -ne 0 ] && echo failed)" failed

	echo
	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

setup_fake_gh() {
	mkdir -p "$FAKE/bin"
	cat >"$FAKE/bin/gh" <<'SH'
#!/usr/bin/env bash
echo "gh $*" >>"$FAKE/calls"
if [ -f "$FAKE/api-fails" ]; then echo "HTTP 502: api failed" >&2; exit 1; fi
case "$*" in
	"api --paginate --slurp repos/o/r/issues/7/comments?since=2026-10-29T00:00:00Z&per_page=100") cat "$FAKE/comments" ;;
	"api repos/o/r/issues/7") cat "$FAKE/issue" ;;
	*) echo "unexpected: gh $*" >&2; exit 2 ;;
esac
SH
	chmod +x "$FAKE/bin/gh"
	# mermaid-labels.sh だけが使う jq -R -s を、count-fails があるときに落とす(ほかの jq はそのまま本物に渡す)
	REAL_JQ=$(command -v jq)
	cat >"$FAKE/bin/jq" <<SH
#!/usr/bin/env bash
if [ -f "\$FAKE/count-fails" ] && [ "\${1:-}" = -R ]; then echo "jq: error" >&2; exit 5; fi
exec "$REAL_JQ" "\$@"
SH
	chmod +x "$FAKE/bin/jq"
}

reset() {
	rm -f "$FAKE"/calls "$FAKE"/api-fails "$FAKE"/count-fails "$FAKE"/out
	comments
	issue_body '' 2026-10-28T00:00:00Z
}

# 「書いた人|本文」を並べる。--slurp なので、ページの配列を 1 つの配列に入れて返す
comments() {
	local i=0 c json='[]'
	for c in "$@"; do
		i=$((i + 1))
		json=$(jq -c --arg login "${c%%|*}" --arg body "${c#*|}" --arg url "https://example.test/c$i" \
			'. + [{user: {login: $login}, body: $body, html_url: $url}]' <<<"$json")
	done
	jq -c '[.]' <<<"$json" >"$FAKE/comments"
}

issue_body() {
	jq -n -c --arg body "$1" --arg at "$2" '{body: $body, updated_at: $at, html_url: "https://example.test/issues/7"}' >"$FAKE/issue"
}

run() {
	RC=0
	PATH="$FAKE/bin:$PATH" bash "$SCRIPT" o/r 7 "$SINCE" >"$FAKE/out" 2>&1 || RC=$?
}

# Windows の jq・bash は CRLF を混ぜることがあるので、手元でも CI と同じに比べられるよう CR を落とす
warnings() { grep '^::warning' "$FAKE/out" | tr -d '\r'; }

expect() {
	local id=$1 name=$2 actual=$3 expected=$4
	if [ "$actual" = "$expected" ]; then
		PASSED=$((PASSED + 1))
		echo "ok   $id: $name"
	else
		FAILED=$((FAILED + 1))
		echo "FAIL $id: $name"
		echo "     expected: $expected"
		echo "     actual:   $actual"
		sed 's/^/     | /' "$FAKE/out"
	fi
}

main
