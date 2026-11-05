#!/usr/bin/env bash
#
# evidence-report.sh のテスト(TC-601-10〜16)。gh を、決まった値を返す偽物に差し替えて流す。GitHub には触らない。
# 貼った本文が evidence-gate.sh を通るかも、同じ偽物の gh で確かめる。
#
# 使い方: .github/scripts/tests/evidence-report.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../evidence-report.sh"
REQUEST="$HERE/../evidence-request.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT
export FAKE

FAILED=0
PASSED=0

RAW=https://raw.githubusercontent.com/o/r/evidence/pr-7/abcdef1
RUN_URL=https://github.com/o/r/actions/runs/1
BEFORE=1234567890abcdef1234567890abcdef12345678
AFTER=abcdef1234567890abcdef1234567890abcdef12

main() {
	setup_fake_gh
	request
	urls

	run section "$FAKE/request.json" "$FAKE/urls" "$BEFORE" "$AFTER" "$RUN_URL"
	expect TC-601-10 '画面は幅ごとの比較画像の <img>・mp4 のリンク・撮った幅を、API は比較画像を並べる。決まった名前でない URL は使わない' \
		"$RC|$(grep -c "<img src=\"$RAW/01-screen-[0-9]*-compare.png\"" "$FAKE/out")|$(grep -c "\[修正前の動画\]($RAW/01-screen-[0-9]*-before.mp4) / \[修正後の動画\]($RAW/01-screen-[0-9]*-after.mp4)" "$FAKE/out")|$(grep -c '^撮った幅: 375px・768px・1024px・1440px$' "$FAKE/out")|$(grep -c "<img src=\"$RAW/02-api-compare.png\"" "$FAKE/out")|$(grep -c 'manifest.json\|evil' "$FAKE/out")|$(grep -c "^撮った回: $RUN_URL$" "$FAKE/out")" \
		'0|4|2|1|1|0|1'
	cp "$FAKE/out" "$FAKE/block.md"

	printf '%s\n' 'Closes #7' '' '## 証跡' '' '### 気づいたこと' '' 'なし' '' '## 確かめたこと' >"$FAKE/body.md"
	run merge "$FAKE/body.md" "$FAKE/block.md"
	cp "$FAKE/out" "$FAKE/merged.md"
	local inserted
	inserted=$(awk '/^## 証跡/{getline; getline; print; exit}' "$FAKE/merged.md")
	run merge "$FAKE/merged.md" "$FAKE/block.md"
	local again
	again=$(cmp -s "$FAKE/out" "$FAKE/merged.md" && echo same || echo differ)
	printf '%s\n' 'Closes #7' '' '## 確かめたこと' >"$FAKE/nosection.md"
	run merge "$FAKE/nosection.md" "$FAKE/block.md"
	expect TC-601-11 '「## 証跡」の見出しのすぐ下に入れ、2 回目は前の塊を入れ替える(増やさない)。見出しが無ければ末尾に足す' \
		"$inserted|$again|$(grep -c '^### 気づいたこと$' "$FAKE/merged.md")|$(grep -c '^## 証跡$' "$FAKE/out")|$(tail -1 "$FAKE/out")" \
		'<!-- ai-dev:evidence-capture start -->|same|1|1|<!-- ai-dev:evidence-capture end -->'

	pr open needs-local "$(cat "$FAKE/body.md")"
	printf '%s\n' 'frontend/src/pages/Search.css' 'frontend/src/pages/Search.tsx' >"$FAKE/files"
	run attach o/r 7 "$FAKE/request.json" "$FAKE/urls" "$BEFORE" "$AFTER" "$RUN_URL"
	local gate_rc=0
	PATH="$FAKE/bin:$PATH" bash "$HERE/../evidence-gate.sh" o/r 7 >"$FAKE/gate" 2>&1 || gate_rc=$?
	expect TC-601-12 '貼った本文は、.css を変えた画面の PR として evidence-gate.sh を通る' "$RC|$gate_rc|$(cat "$FAKE/gate")" \
		'0|0|PR #7 の証跡はそろっている'

	expect TC-601-13 '本文に貼り、needs-local を外し、local-done kind=evidence のコメントに evidence-gate.sh の結果を書く' \
		"$(writes)|$(head -1 "$FAKE/comment")|$(grep -c '^- `evidence-gate.sh`: 通った$' "$FAKE/comment")|$(grep -c "^- 撮った回: $RUN_URL$" "$FAKE/comment")" \
		'patch delete-needs-local comment|<!-- ai-dev:agent --> <!-- ai-dev:local-done kind=evidence -->|1|1'

	pr closed needs-local ''
	run attach o/r 7 "$FAKE/request.json" "$FAKE/urls" "$BEFORE" "$AFTER" "$RUN_URL"
	local closed_attach
	closed_attach="$RC|$(writes)"
	printf '%s\n' '撮るものの指定が無い' >"$FAKE/reason"
	run failed o/r 7 "$FAKE/reason" "$RUN_URL"
	expect TC-601-14 '撮っているあいだに閉じられた PR には、貼らず・コメントもしない' "$closed_attach|$RC|$(writes)" '0||0|'

	pr open needs-local ''
	printf '%s\n' '撮るものの指定(```evidence のブロック)の形が誤っている' '' 'items[0].path が無い' >"$FAKE/reason"
	run failed o/r 7 "$FAKE/reason" "$RUN_URL"
	local with_label
	with_label="$(writes)|$(head -1 "$FAKE/comment")|$(grep -c 'items\[0\].path が無い' "$FAKE/comment")|$(grep -c '^```' "$FAKE/comment")"
	pr open '' ''
	run failed o/r 7 "$FAKE/reason" "$RUN_URL"
	expect TC-601-15 '撮れなかったら needs-local を残して理由をコメントし(理由の ``` で区切りを崩さない)、外されていれば付け直す' \
		"$with_label|$(writes)" \
		'comment|<!-- ai-dev:agent --> <!-- ai-dev:needs-local kind=evidence -->|1|2|comment add-needs-local'

	run section "$FAKE/request.json"
	local few=$RC
	run attach o/r abc "$FAKE/request.json" "$FAKE/urls" "$BEFORE" "$AFTER" "$RUN_URL"
	local bad_number=$RC
	run nope
	expect TC-601-16 '引数の誤りは 2 で終わる' "$few|$bad_number|$RC" '2|2|2'

	echo
	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

setup_fake_gh() {
	mkdir -p "$FAKE/bin"
	cat >"$FAKE/bin/gh" <<'SH'
#!/usr/bin/env bash
# --jq の式を外して、残りの引数で返すものを決める。返したものに --jq を当てる
args=() expr=
while [ $# -gt 0 ]; do
	if [ "$1" = --jq ]; then expr=$2; shift 2; else args+=("$1"); shift; fi
done
set -- "${args[@]}"
out=
case "$*" in
	"api repos/o/r/pulls/7" | "api repos/o/r/issues/7") out=$(cat "$FAKE/pr") ;;
	"api -X PATCH repos/o/r/pulls/7 -F body=@"*)
		jq --rawfile b "${6#body=@}" '.body = $b' "$FAKE/pr" >"$FAKE/pr.new" && mv "$FAKE/pr.new" "$FAKE/pr"
		echo patch >>"$FAKE/writes"
		out='{}'
		;;
	"api -X DELETE repos/o/r/issues/7/labels/needs-local") echo delete-needs-local >>"$FAKE/writes"; out='[]' ;;
	"api -X POST repos/o/r/issues/7/labels -f labels[]=needs-local") echo add-needs-local >>"$FAKE/writes"; out='[]' ;;
	"api repos/o/r/issues/7/comments -F body=@"*) cp "${4#body=@}" "$FAKE/comment"; echo comment >>"$FAKE/writes"; out='{}' ;;
	"pr view 7 -R o/r --json body") out=$(cat "$FAKE/pr") ;;
	"api --paginate repos/o/r/pulls/7/files") out=$(jq -R -s -c 'split("\n") | map(select(. != "") | {status: "modified", filename: .})' "$FAKE/files") ;;
	*) echo "unexpected: gh $*" >&2; exit 2 ;;
esac
if [ -n "$expr" ]; then jq -r "$expr" <<<"$out"; else printf '%s\n' "$out"; fi
SH
	chmod +x "$FAKE/bin/gh"
}

# 頼むコメントのブロックを evidence-request.sh で読んだ JSON(画面 1 つと API 1 つ)
request() {
	printf '%s\n' '```evidence' 'version: 1' 'items:' '  - kind: screen' '    cases: [TC-1-01]' '    path: /search?q=zzz' \
		'  - kind: api' '    cases: [TC-1-02]' '    request: { method: GET, path: /api/x }' '```' >"$FAKE/in.md"
	bash "$REQUEST" "$FAKE/in.md" >"$FAKE/request.json" || echo "evidence-request.sh が通らない" >&2
}

# evidence-publish.sh が出す URL の一覧。決まった名前でないもの・ほかの場所のものも混ぜる
urls() {
	{
		local w s
		for w in 375 768 1024 1440; do for s in before after compare; do echo "$RAW/01-screen-$w-$s.png"; done; done
		for w in 375 1440; do for s in before after; do echo "$RAW/01-screen-$w-$s.mp4" "$RAW/01-screen-$w-$s.gif"; done; done | tr ' ' '\n'
		for s in before after compare; do echo "$RAW/02-api-$s.png"; done
		echo "$RAW/manifest.json"
		echo "$RAW/evil.png"
	} >"$FAKE/urls"
}

# PR の状態を決める: pr <state> <ラベル(空なら無し)> <本文>
pr() {
	jq -n -c --arg state "$1" --arg label "$2" --arg body "$3" \
		'{state: $state, body: $body, labels: (if $label == "" then [] else [{name: $label}] end)}' >"$FAKE/pr"
	rm -f "$FAKE/writes" "$FAKE/comment"
}

# 書き込んだもの(本文の書き換え・ラベル・コメント)を順に並べる
writes() {
	paste -sd' ' "$FAKE/writes" 2>/dev/null || true
}

run() {
	RC=0
	PATH="$FAKE/bin:$PATH" bash "$SCRIPT" "$@" >"$FAKE/out" 2>"$FAKE/err" || RC=$?
}

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
		sed 's/^/     stderr: /' "$FAKE/err" 2>/dev/null | head -5
	fi
}

main
