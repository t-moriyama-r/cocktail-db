#!/usr/bin/env bash
#
# evidence-shoot.js のテスト(TC-600-16〜21・TC-601-17・TC-768-03〜08・TC-802-01〜03)。頼むコメントを evidence-request.sh で読んだ JSON を、plan(何も動かさずに
# 流す手順を出す)に渡して、撮る幅・動画・操作・リクエスト・出すファイルの名前を見る。撮影そのもの(Chrome・ffmpeg)は流さない。
# 管理画面にログインして撮る(login: admin。#768)ところは、php・ffmpeg・curl・capture.js・compose.js を偽物にして run を流し、
# ログインの URL を撮る回ごとに出すこと・設定ファイルや texts に残さないこと・エラーの文で伏せることを見る。
# steps のある画面で、撮る回ごとにその側の DB を入れ直すこと(#802)も、plan の手順と、偽物で流した run の順で見る。
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

	expect TC-601-17 '画面は、データを入れた後・撮る前に、修正後の画面の HTTP の状態を確かめる(5xx なら撮らない。#601)。コマンドには確かめない' \
		"$(jq -c '[.items[] | [.kind, (.steps[0:2] | map(.type)), ([.steps[] | select(.type == "probe") | [.side, .path]])]]' "$WORK/out")" \
		'[["command",["seed","command"],[]],["screen",["seed","probe"],[["after","/"]]]]'

	# --- 管理画面にログインして撮る(#768) ---

	block 'version: 1' 'items:' '  - kind: screen' '    cases: [TC-1-01]' '    path: /admin' '    login: admin' '    widths: [375, 1440]' \
		'  - kind: screen' '    cases: [TC-1-02]' '    path: /' '    widths: [375, 1440]'
	plan
	# shellcheck disable=SC2016 # ${env:…} はそのまま比べる
	expect TC-768-03 'login: admin の画面は、撮る回(幅ごと・動画・修正前後)のすべてで、URL の値を持たない手順でログインする。書かない画面はログインしない' \
		"$RC|$(jq -c '[.items[0].steps[] | select(.type == "capture") | [.login, .config.login]] | unique' "$WORK/out")|$(jq '[.items[0].steps[] | select(.type == "capture")] | length' "$WORK/out")|$(jq -c '[.items[1].steps[] | select(.type == "capture") | (.login // null), (.config.login // null)] | unique' "$WORK/out")" \
		'0|[["admin",{"url":"file://<out>/work/login.html","actions":[{"fill":["#u","${env:EVIDENCE_LOGIN_URL}"]},{"click":"#go"}],"waitUrlNot":"file:"}]]|8|[null]'

	block 'version: 1' 'items:' '  - kind: screen' '    cases: [TC-1-01]' '    path: /admin' '    login: admin' '    widths: [375, 1440]'
	fake_run
	expect TC-768-04 '撮る回ごとに、その側の checkout で APP_URL をその側のアプリにして、E2E の管理者のログインの URL を 1 つずつ出し、capture.js に環境変数で渡す' \
		"$RC|$(sort "$FAKE/calls" | uniq -c | awk '{print $1, $2, $3, $4}' | paste -sd,)|$(grep -c '^got-login ' "$FAKE/capture.log")|$(grep -c '^no-login ' "$FAKE/capture.log")" \
		"0|4 $FAKE/after/backend http://127.0.0.1:8002 e2e-admin@example.com,4 $FAKE/before/backend http://127.0.0.1:8001 e2e-admin@example.com|8|0"
	expect TC-768-05 'ログインの URL(トークン)は、設定ファイル・texts・撮った物のどこにも残らない' \
		"$(grep -rlF -e 'tok' -e 'login-link' "$WORK/run" | wc -l)|$(ls "$WORK/run/texts" | wc -l)" '0|8'

	FAKE_MODE=leak fake_run
	expect TC-768-06 '撮った結果の文字にログインの URL が出ていたら、texts に書かずに落ち、エラーの文にも URL を出さない' \
		"$RC|$(grep -cF 'ログインの URL が出ていた' "$WORK/err")|$(grep -cF -e 'tok' "$WORK/err")|$(find "$WORK/run/texts" -type f | wc -l)" '1|1|0|0'

	FAKE_MODE=fail fake_run
	expect TC-768-07 'capture.js が落ちてエラーの文に URL が入っていても、URL とトークンを伏せて出す' \
		"$RC|$(grep -cF 'goto <ログインの URL> failed' "$WORK/err")|$(grep -cF -e 'tok' "$WORK/err")" '1|1|0'

	FAKE_LINK='not a url' fake_run
	expect TC-768-08 'admin:login-link がその側のアプリのログインの URL を出さなければ、出した文字を書かずに落ちる' \
		"$RC|$(grep -cF 'before の admin:login-link が、http://127.0.0.1:8001 のログインの URL を出さなかった' "$WORK/err")|$(grep -cF 'not a url' "$WORK/err")" '1|1|0'

	# --- steps のある画面は、撮る回ごとにその側の DB を入れ直す(#802) ---

	block 'version: 1' 'items:' '  - kind: screen' '    cases: [TC-1-01]' '    path: /admin/skip-words' '    login: admin' '    widths: [375, 1440]' '    steps:' \
		"      - fill: { target: 'role=textbox[name=\"語\"]', value: テスト }" "      - click: 'role=button[name=\"保存\"]'" "      - wait_for: 'text=保存しました'"
	plan
	expect TC-802-01 'steps のある画面は、項目の先頭で両側に入れた後、その側の 2 回目からの撮る回(幅ごと・動画)の前に、その側だけを入れ直す' \
		"$RC|$(jq -c '[.items[0].steps[] | select(.type == "seed" or .type == "capture") | [.type, (.side // "both"), (.seed // .config.viewport.width), .config.video]]' "$WORK/out")" \
		'0|[["seed","both","E2eSeeder",null],["capture","before",375,false],["capture","after",375,false],["seed","before","E2eSeeder",null],["capture","before",1440,false],["seed","after","E2eSeeder",null],["capture","after",1440,false],["seed","before","E2eSeeder",null],["capture","before",375,true],["seed","after","E2eSeeder",null],["capture","after",375,true],["seed","before","E2eSeeder",null],["capture","before",1440,true],["seed","after","E2eSeeder",null],["capture","after",1440,true]]'

	fake_run
	expect TC-802-02 '流すと、撮る回の直前にその側の checkout で migrate:fresh を流す(各側 1 回目は先頭の分)。ログインの URL は入れ直した後に出す' \
		"$RC|$(grep -v '^login ' "$FAKE/capture.log" | paste -sd,)|$(grep -c '^seed before E2eSeeder$' "$FAKE/capture.log")|$(grep -c '^seed after E2eSeeder$' "$FAKE/capture.log")" \
		"0|seed before E2eSeeder,seed after E2eSeeder,got-login before,got-login after,seed before E2eSeeder,got-login before,seed after E2eSeeder,got-login after,seed before E2eSeeder,got-login before,seed after E2eSeeder,got-login after,seed before E2eSeeder,got-login before,seed after E2eSeeder,got-login after|4|4"

	block 'version: 1' 'items:' '  - kind: screen' '    cases: [TC-1-01]' '    path: /' '    widths: [375, 1440]' \
		'  - kind: api' '    cases: [TC-1-02]' '    request:' '      method: POST' '      path: /api/x' '      body: { a: 1 }' \
		'  - kind: command' '    cases: [TC-1-03]' '    workdir: backend' '    run: echo hi'
	plan
	expect TC-802-03 'steps の無い画面・API・コマンドは、今までどおり項目の先頭で 1 回だけ入れる' \
		"$RC|$(jq -c '[.items[] | [.kind, ([.steps[] | select(.type == "seed") | (.side // "both")])]]' "$WORK/out")" \
		'0|[["screen",["both"]],["api",["both"]],["command",["both"]]]'

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

# 偽の php・ffmpeg・curl・capture.js・compose.js で run を流す(#768)。アプリは起こさない。FAKE_MODE=leak は撮った結果に、fail はエラーの文に
# 受け取ったログインの URL を出す。FAKE_LINK を渡すと admin:login-link がそれを出す
fake_run() {
	export FAKE="$WORK/fake"
	rm -rf "$FAKE" "$WORK/run"
	mkdir -p "$FAKE/bin" "$FAKE/tools" "$FAKE/before/backend" "$FAKE/after/backend"
	# shellcheck disable=SC2016 # 偽物の中身なので、ここでは展開しない
	printf '%s\n' '#!/usr/bin/env bash' \
		'case "$2" in' \
		'  migrate:fresh) echo "seed $(basename "$(dirname "$PWD")") ${5#--seeder=}" >>"$FAKE/capture.log" ;;' \
		'  admin:login-link) echo "$PWD $APP_URL $3" >>"$FAKE/calls"; n=$(wc -l <"$FAKE/calls");' \
		'    echo "${FAKE_LINK:-$APP_URL/admin/login-link/1?token=tok${n}secret0000&signature=sig${n}}" ;;' \
		'  *) exit 1 ;;' \
		'esac' >"$FAKE/bin/php"
	printf '%s\n' '#!/usr/bin/env bash' 'for a; do last=$a; done' ': >"$last"' >"$FAKE/bin/ffmpeg"
	# 撮る前の修正後の画面の確かめ(probe)は、アプリを起こさないので 200 を返す
	printf '%s\n' '#!/usr/bin/env bash' 'printf 200' >"$FAKE/bin/curl"
	chmod +x "$FAKE/bin/php" "$FAKE/bin/ffmpeg" "$FAKE/bin/curl"
	cat >"$FAKE/tools/capture.js" <<'JS'
const fs = require("fs");
const path = require("path");
const c = JSON.parse(fs.readFileSync(process.argv[2], "utf8"));
const link = process.env.EVIDENCE_LOGIN_URL;
fs.appendFileSync(path.join(process.env.FAKE, "capture.log"), `${link ? "got-login" : "no-login"} ${c.label}\n`);
if (process.env.FAKE_MODE === "fail") {
  console.error(`page.goto: net::ERR_FAILED\n  - goto ${link} failed`);
  process.exit(1);
}
const name = c.pages[0].name;
fs.writeFileSync(path.join(c.outDir, `${c.label}_${name}.png`), "");
if (c.video) fs.writeFileSync(path.join(c.outDir, `${c.label}.webm`), "");
const body = process.env.FAKE_MODE === "leak" ? `管理画面 ${link}` : "管理画面";
console.log(JSON.stringify({ label: c.label, results: [{ name, url: `${c.baseUrl}/admin`, read: { body } }] }));
JS
	cat >"$FAKE/tools/compose.js" <<'JS'
const fs = require("fs");
const path = require("path");
const c = JSON.parse(fs.readFileSync(process.argv[2], "utf8"));
fs.writeFileSync(path.join(c.outDir, `${c.name}_compare_${c.compare[0].name}.png`), "");
JS
	RC=0
	PATH="$FAKE/bin:$PATH" node "$SCRIPT" run "$WORK/request.json" --out "$WORK/run" --tools "$FAKE/tools" --pw "$FAKE" \
		--before-dir "$FAKE/before" --before-url http://127.0.0.1:8001 --after-dir "$FAKE/after" --after-url http://127.0.0.1:8002 \
		>/dev/null 2>"$WORK/err" || RC=$?
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
