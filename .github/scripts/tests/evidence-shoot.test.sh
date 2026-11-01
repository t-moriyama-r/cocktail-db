#!/usr/bin/env bash
#
# evidence-shoot.js のテスト(TC-600-16〜21)。頼むコメントを evidence-request.sh で読んだ JSON を、plan(何も動かさずに
# 流す手順を出す)に渡して、撮る幅・動画・操作・リクエスト・出すファイルの名前を見る。撮影そのもの(Chrome・ffmpeg)は流さない。
#
# 使い方: .github/scripts/tests/evidence-shoot.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../evidence-shoot.js"
REQUEST="$HERE/../evidence-request.sh"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

FAILED=0
PASSED=0

main() {
	block 'version: 1' 'items:' '  - kind: screen' '    cases: [TC-1-01]' '    path: /search?q=zzz'
	plan
	expect TC-600-16 '画面は 4 幅の修正前後の静止画・幅ごとの比較画像と、375px・1440px の修正前後の mp4・GIF を出す' \
		"$RC|$(jq -r '.items[0].files | join(" ")' "$WORK/out")" \
		"0|$(printf '01-screen-%s-before.png 01-screen-%s-after.png 01-screen-%s-compare.png ' 375 375 375 768 768 768 1024 1024 1024 1440 1440 1440)$(printf '01-screen-%s-before.mp4 01-screen-%s-before.gif 01-screen-%s-after.mp4 01-screen-%s-after.gif ' 375 375 375 375 1440 1440 1440 1440 | sed 's/ $//')"

	expect TC-600-17 '静止画は帯を重ねない(video: false)全体の画面で、幅だけを変えて撮る。動画は video の幅だけ' \
		"$(jq -c '[.items[0].steps[] | select(.type == "capture") | [.config.viewport.width, .config.video, .config.pages[0].fullPage // false]] | unique' "$WORK/out")" \
		'[[375,false,true],[375,true,false],[768,false,true],[1024,false,true],[1440,false,true],[1440,true,false]]'

	block 'version: 1' 'items:' '  - kind: screen' '    cases: [TC-1-01]' '    path: /' '    widths: [375, 1440]' '    steps:' \
		"      - fill: { target: 'role=searchbox', value: ジン }" '      - press: Enter' "      - wait_for: 'role=table'" \
		"      - click: 'text=ジントニック'" "      - select: { target: 'select', value: 2 }" "      - hover: 'role=link'" \
		"      - scroll_to: 'footer'" '      - goto: /cocktails/1?x=1'
	plan
	expect TC-600-18 '操作を capture.js の actions に写す(待ち・移る操作は録画から詰める)' \
		"$RC|$(jq -c '.items[0].steps[] | select(.type == "capture") | .config.pages[0].actions' "$WORK/out" | sort -u)" \
		'0|[{"fill":["role=searchbox","ジン"]},{"press":"Enter"},{"waitFor":"role=table","cut":true},{"click":"text=ジントニック"},{"select":["select","2"]},{"hover":"role=link"},{"scrollTo":"footer"},{"eval":"location.assign(\"/cocktails/1?x=1\")","waitUrl":"/cocktails/1","wait":1500,"cut":true}]'

	# shellcheck disable=SC2016 # ${env:…} はそのまま渡す
	block 'version: 1' 'items:' '  - kind: screen' '    cases: [TC-1-01]' '    path: /' '    steps:' \
		"      - fill: { target: 'role=searchbox', value: '\${env:GITHUB_TOKEN}' }"
	plan
	expect TC-600-19 '入れる値に ${env:…} があれば断る(capture.js が環境変数の値に置き換えて画面に出すため)' \
		"$RC|$(grep -c 'env:' "$WORK/err")" '1|1'

	block 'version: 1' 'items:' '  - kind: api' '    cases: [TC-1-02]' '    request:' '      method: POST' '      path: /api/cocktails/search' \
		'      headers: { Accept: application/json }' '      body: { name: zzz }' '    data: { seed: FooSeeder }'
	plan
	expect TC-600-20 'API は修正前・修正後に同じリクエストを curl -si で送り、本文は JSON にして送る。入れるデータは項目ごと' \
		"$RC|$(jq -c '[.items[0].steps[] | select(.type == "curl") | [.side, (.args | join(" ")), .body]]' "$WORK/out")|$(jq -r '.items[0].seed + " " + (.items[0].files | join(" "))' "$WORK/out")" \
		'0|[["before","-si --max-time 60 -X POST -H Accept: application/json -H Content-Type: application/json --data-binary @<out>/work/01-api/request-body <before-url>/api/cocktails/search","{\"name\":\"zzz\"}"],["after","-si --max-time 60 -X POST -H Accept: application/json -H Content-Type: application/json --data-binary @<out>/work/01-api/request-body <after-url>/api/cocktails/search","{\"name\":\"zzz\"}"]]|FooSeeder 01-api-before.png 01-api-after.png 01-api-compare.png'

	block 'version: 1' 'items:' '  - kind: command' '    cases: [TC-1-03]' '    workdir: backend' '    run: php artisan db:show --table=cocktails' \
		'  - kind: screen' '    cases: [TC-1-04]' '    path: /' '    widths: [375, 1440, 1600]' '    video: [375, 1600]'
	plan
	expect TC-600-21 'コマンドは修正前・修正後の checkout の workdir で流す。項目ごとに連番を付け、video を書けばその幅だけ録る' \
		"$RC|$(jq -c '[.items[0].steps[] | select(.type == "command") | [.side, .workdir, .run]]' "$WORK/out")|$(jq -r '[.items[] | .id] | join(" ")' "$WORK/out")|$(jq -r '[.items[1].files[] | select(test("mp4$"))] | join(" ")' "$WORK/out")" \
		'0|[["before","backend","php artisan db:show --table=cocktails"],["after","backend","php artisan db:show --table=cocktails"]]|01-command 02-screen|02-screen-375-before.mp4 02-screen-375-after.mp4 02-screen-1600-before.mp4 02-screen-1600-after.mp4'

	echo
	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

# 行を並べて ```evidence のブロックにし、evidence-request.sh で既定の値を埋めた JSON にする
block() {
	{
		echo '```evidence'
		printf '%s\n' "$@"
		echo '```'
	} >"$WORK/in.md"
	bash "$REQUEST" "$WORK/in.md" >"$WORK/request.json" 2>"$WORK/err" || echo "evidence-request.sh が通らない: $(cat "$WORK/err")" >&2
}

plan() {
	RC=0
	node "$SCRIPT" plan "$WORK/request.json" >"$WORK/out" 2>"$WORK/err" || RC=$?
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
		sed 's/^/     stderr: /' "$WORK/err" 2>/dev/null | head -5
	fi
}

main
