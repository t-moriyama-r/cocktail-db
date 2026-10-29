#!/usr/bin/env bash
#
# mermaid-labels.sh のテスト(TC-478-01〜)。Markdown のファイルを渡して、上限を超えたラベルの出力と終了コードを見る。
#
# 使い方: .github/scripts/tests/mermaid-labels.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../mermaid-labels.sh"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

FAILED=0
PASSED=0

main() {
	# --- 上限の境界(ノードは全角 12 字、矢印のラベルは全角 6 字まで) ---

	diagram 'A[一二三四五六七八九十一二] --> B[一二三四五六七八九十一二三]'
	run
	expect TC-478-01 'ノードは全角 12 字までは通し、13 字は上限を超えたと出して 1 で終わる' "$(out)|$RC" \
		'図1 ノード「一二三四五六七八九十一二三」: 全角 13 字(上限 12 字)|1'

	diagram 'A -->|一二三四五六| B' 'A -->|一二三四五六七| C'
	run
	expect TC-478-02 '矢印のラベルは全角 6 字までは通し、7 字は上限を超えたと出す' "$(out)|$RC" \
		'図1 矢印のラベル「一二三四五六七」: 全角 7 字(上限 6 字)|1'

	diagram 'A[開始] -->|はい| B{分かれる}' 'B -->|いいえ| C(終わり)'
	run
	expect TC-478-03 '上限に収まる図なら何も出さず 0 で終わる' "$(out)|$RC" '|0'

	# --- 数え方 ---

	diagram 'A -->|ok 扱い| B' 'A -->|needs-human| C'
	run
	expect TC-478-04 '半角は全角 0.5 字と数える(ok 扱い は 4 字、needs-human は 5.5 字)' "$(out)|$RC" '|0'

	diagram 'A -->|指摘1: sha が空になりうる| B'
	run
	expect TC-478-05 '半角を混ぜたラベルの字数を、小数で出す(#452 の矢印のラベル)' "$(out)" \
		'図1 矢印のラベル「指摘1: sha が空になりうる」: 全角 12.5 字(上限 6 字)'

	diagram 'A[一二三四五六七八九十<br>一二三四五六七八九十] -->|一二三<br/>四五六| B'
	run
	expect TC-478-06 '<br> で折ったラベルは、合計ではなくいちばん長い行で数える' "$(out)|$RC" '|0'

	diagram 'A["一二三四五六七八九十一二"] --> B["<b>一二三四五六七八九十一二</b>"]' 'B --> C[一二三#quot;四五六七八九十一]'
	run
	expect TC-478-07 '囲む引用符と HTML のタグは数えず、実体参照は 1 字に数える' "$(out)|$RC" '|0'

	diagram 'A[一二三四五六七八九十一二三]'
	run
	expect TC-478-08 '矢印の無い行のノードも数える' "$(out)" '図1 ノード「一二三四五六七八九十一二三」: 全角 13 字(上限 12 字)'

	# --- ノードの形と矢印の書き方 ---

	diagram 'A([一二三四五六七八九十一二三]) --> B[[一二三四五六七八九十一二三]]' \
		'B --> C((一二三四五六七八九十一二三)) --> D{{一二三四五六七八九十一二三}}' \
		'D --> E[/一二三四五六七八九十一二三/] --> F>一二三四五六七八九十一二三]' \
		'F --> G{一二三四五六七八九十一二三} --> H(一二三四五六七八九十一二三)'
	run
	expect TC-478-09 'ノードの形(([ ])・[[ ]]・(( ))・{{ }}・[/ /]・>…]・{ }・( ))をどれも拾う' "$(out | grep -c 'ノード')" 8

	diagram 'A -- 一二三四五六七 --> B' 'A -. 一二三四五六七 .-> C' 'A == 一二三四五六七 ==> D' 'A -.->|一二三四五六七| E' 'A ==>|一二三四五六七| F'
	run
	expect TC-478-10 '矢印のラベルの書き方(-- x -->・-. x .->・== x ==>・-.->|x|・==>|x|)をどれも拾う' "$(out | grep -c '矢印のラベル')" 5

	diagram 'A -->|一二三| B -->|一二三四五六七| C --> D[一二三四五六七八九十一二三]'
	run
	expect TC-478-11 '1 行につないだ矢印は、それぞれのラベルとノードを拾う' "$(out)" \
		"$(printf '%s\n%s' '図1 矢印のラベル「一二三四五六七」: 全角 7 字(上限 6 字)' '図1 ノード「一二三四五六七八九十一二三」: 全角 13 字(上限 12 字)')"

	# --- 誤って拾わないもの ---

	diagram 'A --> B' 'B --- C' 'C & D --> E;' 'A1-->B1'
	run
	expect TC-478-12 'ラベルの無い参照・& ・; ・空白の無い矢印では何も出さない' "$(out)|$RC" '|0'

	diagram '%% 一二三四五六七八九十一二三四五六七八九十のコメント' 'style A fill:#f9f,stroke:#333' \
		'classDef warn fill:#fdd' 'class A warn' 'click A "https://example.test/一二三四五六七八九十一二三"' 'linkStyle 0 stroke:#f00'
	run
	expect TC-478-13 '%% のコメント・style・classDef・class・click・linkStyle の行は数えない' "$(out)|$RC" '|0'

	printf '%s\n' '```mermaid' 'sequenceDiagram' '  A->>B: 一二三四五六七八九十一二三四五六七八九十' '```' >"$WORK/in.md"
	run
	expect TC-478-14 'flowchart・graph でない図は数えず、そう出して 0 で終わる' "$(out)|$RC" \
		'図1 は sequenceDiagram なので数えない(数えるのは flowchart・graph だけ)|0'

	printf '%s\n' '```' 'A[一二三四五六七八九十一二三]' '```' '```js' 'x = "A -->|一二三四五六七| B"' '```' >"$WORK/in.md"
	run
	expect TC-478-15 'mermaid でないコードブロックは見ない' "$(out)|$RC" '|0'

	# --- 図の置き方 ---

	printf '%s\n' '前置き' '```mermaid' 'graph LR' '  A[一二三]' '```' '' '```mermaid' 'flowchart TD' '  B[一二三四五六七八九十一二三]' '```' >"$WORK/in.md"
	run
	expect TC-478-16 '図が複数あれば、何番目の図かを出す(graph も数える)' "$(out)" '図2 ノード「一二三四五六七八九十一二三」: 全角 13 字(上限 12 字)'

	printf '%s\r\n' '```mermaid' '---' 'title: 一二三四五六七八九十一二三四五' '---' 'flowchart TD' '  A[一二三四五六七八九十一二三]' '```' >"$WORK/in.md"
	run
	expect TC-478-17 '先頭の --- で囲んだ設定は飛ばし、CRLF のファイルでも数える' "$(out)" '図1 ノード「一二三四五六七八九十一二三」: 全角 13 字(上限 12 字)'

	diagram 'A[一二三四五六七八九十一二三]'
	RC=0
	bash "$SCRIPT" <"$WORK/in.md" >"$WORK/out" 2>&1 || RC=$?
	expect TC-478-18 'ファイルを渡さなければ標準入力を読む' "$(out)|$RC" '図1 ノード「一二三四五六七八九十一二三」: 全角 13 字(上限 12 字)|1'

	: >"$WORK/in.md"
	run
	expect TC-478-19 '図の無い文書なら何も出さず 0 で終わる' "$(out)|$RC" '|0'

	echo
	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

# 引数の行を、flowchart の図 1 つにした Markdown を書く
diagram() {
	{
		echo '頼むコメントの本文'
		echo '```mermaid'
		echo 'flowchart TD'
		printf '  %s\n' "$@"
		echo '```'
	} >"$WORK/in.md"
}

run() {
	RC=0
	bash "$SCRIPT" "$WORK/in.md" >"$WORK/out" 2>&1 || RC=$?
}

# Windows の jq・bash は CRLF を混ぜることがあるので、手元でも CI と同じに比べられるよう CR を落とす
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
