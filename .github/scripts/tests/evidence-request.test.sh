#!/usr/bin/env bash
#
# evidence-request.sh のテスト(TC-599-01〜)。頼むコメントの Markdown を渡して、出す JSON・エラーと終了コードを見る。
# docs/evidence-request.md の例も取り出して流し、文書とスクリプトがずれていないかを見る。
#
# 使い方: .github/scripts/tests/evidence-request.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../evidence-request.sh"
DOC="$HERE/../../../docs/evidence-request.md"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

FAILED=0
PASSED=0

main() {
	# --- 文書の例 ---

	# 文書の最初の例(````markdown で囲んだ頼むコメント)を取り出して流し、文書に載せた JSON と比べる
	awk '/^````markdown$/ {f=1; next} /^````$/ {f=0} f' "$DOC" >"$WORK/in.md"
	awk '/^```json$/ {f=1; next} /^```$/ {f=0} f' "$DOC" >"$WORK/doc.json"
	run
	expect TC-599-01 '文書の頼むコメントの例は通り、文書に載せた JSON と同じ中身を出す' \
		"$(jq -S -c . "$WORK/out" 2>/dev/null)|$RC" "$(jq -S -c . "$WORK/doc.json")|0"

	local n=0 ok=0 i
	n=$(grep -c '^```evidence$' "$DOC")
	for ((i = 1; i <= n; i++)); do
		awk -v want="$i" '/^```evidence$/ {k++; if (k == want) {f=1; print; next}} f {print} f && /^```$/ {f=0}' "$DOC" >"$WORK/in.md"
		run
		[ "$RC" -eq 0 ] && ok=$((ok + 1))
	done
	expect TC-599-02 '文書の ```evidence の例(3 つ)は、どれも通る' "$ok/$n" "3/3"

	# --- 既定の値 ---

	block 'version: 1' 'items:' '  - kind: screen' '    cases: [TC-1-01]' '    path: /'
	run
	expect TC-599-03 '画面の幅・動画の幅・入れるデータ・操作を省くと、既定の値を埋める' \
		"$(jq -c '.items[0] | [.widths, .video, .data.seed, .steps]' "$WORK/out")|$RC" \
		'[[375,768,1024,1440],[375,1440],"E2eSeeder",[]]|0'

	block 'version: 1' 'items:' '  - kind: command' '    cases: [TC-1-01]' '    run: php artisan test --filter=Foo'
	run
	expect TC-599-04 'コマンドの workdir を省くと、リポジトリの直下(.)にする' "$(jq -r '.items[0].workdir' "$WORK/out")|$RC" '.|0'

	# --- ブロックの取り出し ---

	printf '%s\n' '頼むコメントの本文だけで、ブロックが無い' >"$WORK/in.md"
	run
	expect TC-599-05 'ブロックが無ければ、無いと出して 1 で終わる' "$(out)|$RC" 'evidence のブロックが無い|1'

	block 'version: 1' 'items:' '  - kind: screen' '    cases: [TC-1-01]' '    path: /'
	cat "$WORK/in.md" "$WORK/in.md" >"$WORK/two.md" && mv "$WORK/two.md" "$WORK/in.md"
	run
	expect TC-599-06 'ブロックが 2 つあれば、1 つにするよう出して 1 で終わる' "$(out)|$RC" \
		'evidence のブロックが 2 個ある(1 つのコメントに 1 つだけ書く。撮るものは items に並べる)|1'

	printf '%s\n' '```evidence' 'version: 1' >"$WORK/in.md"
	run
	expect TC-599-07 'ブロックが閉じていなければ、そう出して 1 で終わる' "$(out)|$RC" 'evidence のブロックが ``` で閉じていない|1'

	printf '%s\n' '```mermaid' 'flowchart TD' '```' '```evidence' 'version: 1' 'items: [' '```' >"$WORK/in.md"
	run
	expect TC-599-08 'YAML として読めなければ、読めないと出して 1 で終わる(ほかの言語のブロックは見ない)' \
		"$(out | grep -c '^YAML として読めない')|$RC" '1|1'

	printf '%s\r\n' '```evidence' 'version: 1' 'items:' '  - kind: screen' '    cases: [TC-1-01]' '    path: /' '```' >"$WORK/in.md"
	run
	expect TC-599-09 'CRLF の本文でも読む' "$(jq -r '.items[0].path' "$WORK/out")|$RC" '/|0'

	block 'version: 1' 'items:' '  - kind: screen' '    cases: [TC-1-01]' '    path: /'
	RC=0
	bash "$SCRIPT" <"$WORK/in.md" >"$WORK/out" 2>&1 || RC=$?
	expect TC-599-10 'ファイルを渡さなければ標準入力を読む' "$(jq -r '.items[0].kind' "$WORK/out")|$RC" 'screen|0'

	# --- 先頭の項目 ---

	block 'items:' '  - kind: screen' '    cases: [TC-1-01]' '    path: /'
	run
	expect TC-599-11 'version が無ければ落とす' "$(out)|$RC" 'version: 1 にする|1'

	block 'version: 1' 'items: []'
	run
	expect TC-599-12 'items が空なら落とす' "$(out)|$RC" 'items: 撮るものを 1 つ以上並べる|1'

	block 'version: 1' 'before: main' 'after: 0123abc' 'items:' '  - kind: screen' '    cases: [TC-1-01]' '    path: /'
	run
	expect TC-599-13 'before・after は SHA だけを通す(ブランチ名は落とす)' "$(out)|$RC" 'before: コミットの SHA(16 進 7〜40 桁)にする|1'

	block 'version: 1' 'items:' '  - &a' '    kind: screen' '    cases: [TC-1-01]' '    path: /' '  - *a'
	run
	expect TC-599-14 'YAML の別名(& と *)は読まない' "$(out | grep -c '^YAML として読めない')|$RC" '1|1'

	# --- 項目の共通 ---

	block 'version: 1' 'items:' '  - cases: [TC-1-01]' '    path: /' '  - kind: video' '    cases: [TC-1-01]'
	run
	expect TC-599-15 'kind が無い・知らない種類なら、何番目かを付けて落とす' "$(out)|$RC" \
		"$(printf '%s\n%s' 'items[1].kind: screen・api・command のどれかにする' 'items[2].kind: screen・api・command のどれかにする: video')|1"

	block 'version: 1' 'items:' '  - kind: screen' '    path: /' '  - kind: screen' '    cases: [TC-599-1, 599-01]' '    path: /'
	run
	expect TC-599-16 'cases が無い・ID の形でないものは落とす' "$(out)|$RC" \
		"$(printf '%s\n%s\n%s' 'items[1](screen).cases: 確かめるテストケースの ID を配列で書く(例: [TC-599-01])' 'items[2](screen).cases: TC-<issue 番号>-<連番 2 桁> の形にする: TC-599-1' 'items[2](screen).cases: TC-<issue 番号>-<連番 2 桁> の形にする: 599-01')|1"

	block 'version: 1' 'items:' '  - kind: screen' '    cases: [TC-1-01]' '    path: /' '    widht: [375]'
	run
	expect TC-599-17 '知らない項目(綴りの誤り)は落とす' "$(out)|$RC" \
		'items[1](screen): 知らない項目 widht(使えるのは kind・cases・note・path・widths・steps・video・data)|1'

	block 'version: 1' 'items:' '  - kind: screen' '    cases: [TC-1-01]' '    path: /' '    data: { seed: "Database; rm -rf" }'
	run
	expect TC-599-18 '入れるデータは、シーダーのクラス名だけを通す' "$(out)|$RC" \
		'items[1](screen).data.seed: シーダーのクラス名(〜Seeder)を書く: Database; rm -rf|1'

	# --- 画面 ---

	block 'version: 1' 'items:' '  - kind: screen' '    cases: [TC-1-01]' '  - kind: screen' '    cases: [TC-1-01]' '    path: https://example.com/search'
	run
	expect TC-599-19 '画面のパスが無い・ホストを書いたものは落とす' "$(out)|$RC" \
		"$(printf '%s\n%s' 'items[1](screen).path: 文字列で書く' 'items[2](screen).path: / で始まるアプリの中のパスにする(ホストは書かない): https://example.com/search')|1"

	block 'version: 1' 'items:' '  - kind: screen' '    cases: [TC-1-01]' '    path: /' '    widths: [375, 1440]'
	run
	expect TC-599-20 '幅を書くときに、入っていない区切りがあれば名前を出して落とす' "$(out)|$RC" \
		'items[1](screen).widths: 区切りごとに 1 つ以上の幅を入れる(足りない区切り: sm・md。既定は 375・768・1024・1440)|1'

	block 'version: 1' 'items:' '  - kind: screen' '    cases: [TC-1-01]' '    path: /' '    widths: [599, 600, 959, 960, 1264]'
	run
	expect TC-599-21 '区切りの境目の幅(599・600・959・960・1264)は、それぞれの区切りに数える' "$(out)|$RC" \
		"$(jq -c . <<<'{"version":1,"items":[{"kind":"screen","cases":["TC-1-01"],"path":"/","widths":[599,600,959,960,1264],"video":[599,1264],"steps":[],"data":{"seed":"E2eSeeder"}}]}')|0"

	block 'version: 1' 'items:' '  - kind: screen' '    cases: [TC-1-01]' '    path: /' '    video: [375, 800]'
	run
	expect TC-599-22 '動画の幅が widths に無い・lg の幅が無いものは落とす' "$(out)|$RC" \
		"$(printf '%s\n%s' 'items[1](screen).video: widths に無い幅がある: 800' 'items[1](screen).video: lg の幅を 1 つ以上入れる(動画は少なくとも lg と xs で撮る)')|1"

	block 'version: 1' 'items:' '  - kind: screen' '    cases: [TC-1-01]' '    path: /' '    steps:' \
		'      - tap: "text=ジン"' '      - fill: { target: "role=searchbox" }' '      - goto: //evil.example' '      - click: "text=ジン"'
	run
	expect TC-599-23 '知らない操作・fill の value が無い・goto のホストは落とし、正しい操作は通す' "$(out)|$RC" \
		"$(printf '%s\n%s\n%s' 'items[1](screen).steps[1]: 知らない操作 tap(使えるのは goto・click・fill・select・press・hover・wait_for・scroll_to)' 'items[1](screen).steps[2]: value(入れる値)を書く' 'items[1](screen).steps[3]: / で始まるアプリの中のパスにする(ホストは書かない): //evil.example')|1"

	# --- API ---

	block 'version: 1' 'items:' '  - kind: api' '    cases: [TC-1-01]' '    request: { method: FETCH, path: /cocktails }'
	run
	expect TC-599-24 'API のメソッドが知らないもの・パスが /api/ で始まらないものは落とす' "$(out)|$RC" \
		"$(printf '%s\n%s' 'items[1](api).request.method: GET・POST・PUT・PATCH・DELETE のどれかにする' 'items[1](api).request.path: /api/ で始まるアプリの中のパスにする(ホストは書かない): /cocktails')|1"

	block 'version: 1' 'items:' '  - kind: api' '    cases: [TC-1-01]' '    request:' '      method: GET' '      path: /api/cocktails' \
		'      headers: { authorization: Bearer x, Accept: application/json }' '      body: { a: 1 }'
	run
	expect TC-599-25 '秘密の入るヘッダーと、GET の body は落とす' "$(out)|$RC" \
		"$(printf '%s\n%s' 'items[1](api).request.headers: authorization は書かない(秘密を頼むコメントに書かないため)' 'items[1](api).request.body: GET には body を書かない')|1"

	block 'version: 1' 'items:' '  - kind: api' '    cases: [TC-1-01]'
	run
	expect TC-599-26 'request が無ければ落とす' "$(out)|$RC" 'items[1](api).request: method と path を書く|1'

	# --- コマンド ---

	block 'version: 1' 'items:' '  - kind: command' '    cases: [TC-1-01]' '    workdir: ../other' '  - kind: command' '    cases: [TC-1-01]' '    run: ls' '    workdir: /srv/app'
	run
	expect TC-599-27 'run が無い・workdir がリポジトリの外を指すものは落とす' "$(out)|$RC" \
		"$(printf '%s\n%s\n%s' 'items[1](command).run: 流すコマンドを文字列で書く' 'items[1](command).workdir: リポジトリの中の相対パスにする(/ で始めない・.. を使わない): ../other' 'items[2](command).workdir: リポジトリの中の相対パスにする(/ で始めない・.. を使わない): /srv/app')|1"

	# --- 公開リポジトリ ---

	block 'version: 1' 'items:' '  - kind: command' '    cases: [TC-1-01]' '    run: cat /home/<user>/app/.env'
	run
	expect TC-599-28 'どこかに /home/ などの絶対パスがあれば落とす' "$(out)|$RC" \
		'絶対パスを書かない(公開リポジトリのため): cat /home/<user>/app/.env|1'

	# --- 引数 ---

	RC=0
	bash "$SCRIPT" "$WORK/in.md" "$WORK/in.md" >"$WORK/out" 2>&1 || RC=$?
	expect TC-599-29 '引数が 2 つ以上なら使い方を出して 2 で終わる' "$(out)|$RC" '使い方: evidence-request.sh [ファイル]|2'

	RC=0
	bash "$SCRIPT" "$WORK/missing.md" >"$WORK/out" 2>&1 || RC=$?
	expect TC-599-30 '無いファイルなら 2 で終わる' "$RC" 2

	echo
	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

# 引数の行を、```evidence のブロック 1 つにした頼むコメントを書く
block() {
	{
		echo '<!-- ai-dev:agent --> <!-- ai-dev:needs-local kind=evidence -->'
		echo '次を撮ってほしい。'
		echo '```evidence'
		printf '%s\n' "$@"
		echo '```'
	} >"$WORK/in.md"
}

# 出す JSON は標準出力、エラーは標準エラー。どちらも out に入れる(通ったときは JSON だけになる)
run() {
	RC=0
	bash "$SCRIPT" "$WORK/in.md" >"$WORK/out" 2>&1 || RC=$?
}

# Windows の ruby・bash は CRLF を混ぜることがあるので、手元でも CI と同じに比べられるよう CR を落とす
out() { tr -d '\r' <"$WORK/out"; }

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
	fi
}

main
