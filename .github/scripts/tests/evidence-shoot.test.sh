#!/usr/bin/env bash
#
# evidence-shoot.js のテスト(TC-600-16〜21・TC-601-17・TC-768-03〜08・TC-781-01〜06)。頼むコメントを evidence-request.sh で読んだ JSON を、plan(何も動かさずに
# 流す手順を出す)に渡して、撮る幅・動画・操作・リクエスト・出すファイルの名前を見る。撮影そのもの(Chrome・ffmpeg)は流さない。
# 管理画面にログインして撮る(login: admin。#768)ところは、php・ffmpeg・curl・capture.js・compose.js を偽物にして run を流し、
# ログインの URL を撮る回ごとに出すこと・設定ファイルや texts に残さないこと・エラーの文で伏せることを見る。
# コマンドの結果(kind: command。#781)も同じ偽物で run を流し、出力の中の checkout の絶対パスを <repo> に置き換えることを見る。
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

	# --- コマンドの出力に出る checkout の絶対パスを <repo> に置き換える(#781) ---

	# shellcheck disable=SC2016 # コマンドの中身なので、ここでは展開しない
	block 'version: 1' 'items:' '  - kind: command' '    cases: [TC-1-05]' '    workdir: frontend' \
		'    run: echo " RUN  v3.2.4 $(pwd -P)"; echo "spec $(pwd -P)/src/a.test.ts"'
	fake_run
	expect TC-781-01 'Vitest の RUN の行などに出た、そのコマンドを流した checkout(修正前・修正後)の絶対パスを <repo> に置き換えて texts に書き、evidence-leak-check.sh を通る' \
		"$RC|$(head -2 "$WORK/run/texts/01-command-before.txt" | paste -sd'#')|$(head -2 "$WORK/run/texts/01-command-after.txt" | paste -sd'#')|$(leak_check)" \
		'0| RUN  v3.2.4 <repo>/frontend#spec <repo>/frontend/src/a.test.ts| RUN  v3.2.4 <repo>/frontend#spec <repo>/frontend/src/a.test.ts|0'
	expect TC-781-02 '置き換えたときは、撮る画面(text2html)に置き換えたことを 1 行で書き、画面にも checkout の絶対パスを残さない' \
		"$(grep -cF '<p>出力の中の checkout の絶対パスは &lt;repo&gt; に置き換えている</p>' "$WORK/run/work/01-command/before.html" "$WORK/run/work/01-command/after.html" | cut -d: -f2 | paste -sd,)|$(grep -lF -e "$FAKE/before" -e "$FAKE/after" "$WORK/run/work/01-command/before.html" "$WORK/run/work/01-command/after.html" | wc -l)" \
		'1,1|0'

	ln -s "$FAKE/after" "$WORK/after-link"
	FAKE_AFTER_DIR="$WORK/after-link" fake_run
	expect TC-781-03 '--after-dir がシンボリックリンクでも、コマンドが出す実のパスを <repo> に置き換える' \
		"$RC|$(head -1 "$WORK/run/texts/01-command-after.txt")" '0| RUN  v3.2.4 <repo>/frontend'
	rm -f "$WORK/after-link"

	# shellcheck disable=SC2016 # コマンドの中身なので、ここでは展開しない
	block 'version: 1' 'items:' '  - kind: command' '    cases: [TC-1-05]' '    workdir: frontend' \
		'    run: echo "old $(cd .. && pwd -P)-old/x"'
	fake_run
	expect TC-781-04 'checkout の名前の後ろに文字が続く別のディレクトリ(<checkout>-old)は置き換えない' \
		"$RC|$(head -1 "$WORK/run/texts/01-command-before.txt")" "0|old $FAKE/before-old/x"

	# shellcheck disable=SC2016 # コマンドの中身なので、ここでは展開しない
	block 'version: 1' 'items:' '  - kind: command' '    cases: [TC-1-04]' '    workdir: frontend' \
		'    run: p=$(cd .. && pwd -P); echo "$p+old/x"; echo "$p日本語/x"; echo "$p/../out/x"; echo "$p/a/../b/x"'
	fake_run
	expect TC-781-07 '"+"・非ASCII が続く別のディレクトリと、".." で checkout の外へ出るパスは置き換えない。checkout の下で戻るだけなら置き換える' \
		"$RC|$(sed -n '1,4p' "$WORK/run/texts/01-command-before.txt" | paste -sd'#' | sed "s#$FAKE#F#g")" \
		'0|F/before+old/x#F/before日本語/x#F/before/../out/x#<repo>/a/../b/x'

	# shellcheck disable=SC2016 # コマンドの中身なので、ここでは展開しない
	block 'version: 1' 'items:' '  - kind: command' '    cases: [TC-1-06]' '    workdir: frontend' \
		'    run: echo " RUN  v3.2.4 $(pwd -P)"; printf "cache /%s/%s/.npm\n" home runner'
	fake_run
	expect TC-781-05 'checkout の外の絶対パス(ホームなど)は置き換えず、evidence-leak-check.sh が今までどおり落とす' \
		"$RC|$(grep -c '^cache /[h]ome/[a-z]*/\.npm$' "$WORK/run/texts/01-command-after.txt")|$(head -1 "$WORK/run/texts/01-command-after.txt")|$(leak_check)" \
		'0|1| RUN  v3.2.4 <repo>/frontend|1'

	block 'version: 1' 'items:' '  - kind: command' '    cases: [TC-1-07]' '    run: echo hello'
	fake_run
	expect TC-781-06 '出力に checkout の絶対パスが無ければ、出力はそのままで、置き換えたことの 1 行も書かない' \
		"$RC|$(paste -sd'#' "$WORK/run/texts/01-command-before.txt")|$(grep -cF '置き換えている' "$WORK/run/work/01-command/before.html")" \
		'0|hello##(終了コード 0)|0'

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

# 偽の php・ffmpeg・curl・capture.js・compose.js で run を流す(#768。コマンドの出力の置き換えも見る。#781)。アプリは起こさない。FAKE_MODE=leak は撮った結果に、fail はエラーの文に
# 受け取ったログインの URL を出す。FAKE_LINK を渡すと admin:login-link がそれを出す。FAKE_AFTER_DIR を渡すと、修正後の checkout をそこにする
fake_run() {
	export FAKE="$WORK/fake"
	rm -rf "$FAKE" "$WORK/run"
	mkdir -p "$FAKE/bin" "$FAKE/tools" "$FAKE/before/backend" "$FAKE/after/backend" "$FAKE/before/frontend" "$FAKE/after/frontend"
	# shellcheck disable=SC2016 # 偽物の中身なので、ここでは展開しない
	printf '%s\n' '#!/usr/bin/env bash' \
		'case "$2" in' \
		'  migrate:fresh) exit 0 ;;' \
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
		--before-dir "$FAKE/before" --before-url http://127.0.0.1:8001 --after-dir "${FAKE_AFTER_DIR:-$FAKE/after}" --after-url http://127.0.0.1:8002 \
		>/dev/null 2>"$WORK/err" || RC=$?
}

# 撮った文字に、ランナーのパス・置き場所(ここでは一時ディレクトリ)が出ていないかを evidence-leak-check.sh で見て、終了コードを出す(#781)
leak_check() {
	local rc=0
	EVIDENCE_LEAK_WORDS=$WORK bash "$HERE/../evidence-leak-check.sh" "$WORK/run/texts" "$WORK/run/publish" 2>/dev/null || rc=$?
	echo "$rc"
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
