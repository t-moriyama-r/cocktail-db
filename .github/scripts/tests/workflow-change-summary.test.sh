#!/usr/bin/env bash
#
# workflow-change-summary.sh のテスト(TC-220-xx)。GitHub の pulls/<番号>/files と同じ形の JSON を作って流す。
#
# 使い方: .github/scripts/tests/workflow-change-summary.test.sh

# 入力と期待値には ${{ … }} やバッククォートを文字のまま書くので、シングルクォートの中で展開されないのは意図どおり
# shellcheck disable=SC2016

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../workflow-change-summary.sh"

FAILED=0
PASSED=0
OUTPUT=""

main() {
	tc_01_no_workflow_files
	tc_02_added_file
	tc_03_write_permission
	tc_04_secret
	tc_05_risky_trigger
	tc_06_third_party_action
	tc_07_external_send
	tc_08_credentials
	tc_09_moved_line
	tc_09_moved_line_unbalanced
	tc_10_permissions_block_removed
	tc_11_no_patch
	tc_12_removed_and_renamed
	tc_13_event_expression
	tc_14_escape
	tc_15_comment_lines
	tc_16_many_lines
	tc_17_no_relevant_change
	tc_05_list_trigger_and_secret_forms
	echo "passed: $PASSED, failed: $FAILED"
	[[ $FAILED -eq 0 ]]
}

# --- ケース -----------------------------------------------------------------

tc_01_no_workflow_files() {
	run "$(files "$(file_entry modified backend/app.php '+echo 1;')")"
	expect "TC-220-01: ワークフローを変えていなければ何も出さない" test -z "$OUTPUT"
}

tc_02_added_file() {
	run "$(files "$(file_entry added .github/workflows/new.yml "$(
		cat <<-'EOF'
			+name: new-check
			+
			+# 1 行目の説明で、
			+# 2 行目に続く
			+#
			+# 次の段落は出さない
			+on:
			+  pull_request:
			+    branches: [develop]
			+permissions:
			+  contents: read
			+jobs:
			+  test:
			+    steps:
			+      - uses: actions/checkout@v6
			+        with:
			+          persist-credentials: false
		EOF
	)")")"
	expect_line "TC-220-02: 要注意なしの結論が 1 行目に出る" 1 ':white_check_mark: *'
	expect_has "TC-220-02: 追加と行数が出る" '• `.github/workflows/new.yml`(追加。+17 -0)'
	expect_has "TC-220-02: 名前が出る" '    名前: new-check'
	expect_has "TC-220-02: 冒頭のコメントの最初の段落だけが説明に出る" '    説明(ファイルの冒頭のコメント): 1 行目の説明で、2 行目に続く'
	expect_not "TC-220-02: 次の段落は出ない" '次の段落'
	expect_has "TC-220-02: トリガーが出る(on: だけの行は出ない)" '    トリガー: `pull_request:`'
	expect_has "TC-220-02: 権限が出る" '    権限: `contents: read`'
	expect_has "TC-220-02: アクションが出る" '    アクション: `- uses: actions/checkout@v6`'
	expect_not "TC-220-02: 追加したファイルの行には + を付けない" '`+ '
	expect_not "TC-220-02: 要注意の印が無い" '⚠'
}

tc_03_write_permission() {
	run "$(files "$(file_entry modified .github/workflows/a.yml "$(
		printf '%s\n' '-  contents: read' '+  contents: write' '+  issues: read'
	)")")"
	expect_line "TC-220-03: 要注意ありの結論が 1 行目に出る" 1 ':rotating_light: *要注意の変更が 1 行ある*'
	expect_has "TC-220-03: write を足した行に印が付き、変更前の行も並ぶ" \
		'    権限: - `contents: read`  ⚠ + `contents: write`  + `issues: read`'
}

tc_04_secret() {
	run "$(files "$(file_entry modified .github/workflows/a.yml "$(
		printf '%s\n' '+          TOKEN: ${{ secrets.MY_TOKEN }}' "+          OTHER: \${{ secrets['X'] }}"
	)")")"
	expect_has "TC-220-04: secret を参照する行に印が付く" \
		"    secret: ⚠ + \`TOKEN: \${{ secrets.MY_TOKEN }}\`  ⚠ + \`OTHER: \${{ secrets['X'] }}\`"
}

tc_05_risky_trigger() {
	run "$(files "$(file_entry modified .github/workflows/a.yml "$(
		printf '%s\n' '+  pull_request_target:' '+  pull_request:' '+on: [push, workflow_run]' '+    - cron: "0 0 * * *"'
	)")")"
	expect_has "TC-220-05: secret 付きで動くトリガーにだけ印が付く" \
		'    トリガー: ⚠ + `pull_request_target:`  + `pull_request:`  ⚠ + `on: [push, workflow_run]`  + `- cron: "0 0 * * *"`'
}

tc_05_list_trigger_and_secret_forms() {
	run "$(files "$(file_entry modified .github/workflows/a.yml "$(
		printf '%s\n' '+    - pull_request_target' '+    secrets: inherit' '+          ALL: ${{ toJSON(secrets) }}'
	)")")"
	expect_has "TC-220-18: リスト形式の危険なトリガーに印が付く" '    トリガー: ⚠ + `- pull_request_target`'
	expect_has "TC-220-18: secrets: inherit と toJSON(secrets) に印が付く" \
		'    secret: ⚠ + `secrets: inherit`  ⚠ + `ALL: ${{ toJSON(secrets) }}`'
}

tc_06_third_party_action() {
	run "$(files "$(file_entry modified .github/workflows/a.yml "$(
		printf '%s\n' '+      - uses: actions/setup-node@v4' '+      - uses: anthropics/claude-code-action@v1' \
			'+      - uses: someone/tool@main' '+      - uses: ./.github/actions/local'
	)")")"
	expect_has "TC-220-06: actions・anthropics・github・ローカル以外のアクションにだけ印が付く" \
		'    アクション: + `- uses: actions/setup-node@v4`  + `- uses: anthropics/claude-code-action@v1`  ⚠ + `- uses: someone/tool@main`  + `- uses: ./.github/actions/local`'
}

tc_07_external_send() {
	run "$(files "$(file_entry modified .github/workflows/a.yml "$(
		printf '%s\n' '+          curl -sS https://example.com/x' '+          echo https://github.com/foo/bar' \
			'+          wget -q x' '+          url: https://api.example.net/hook'
	)")")"
	expect_has "TC-220-07: curl・wget・GitHub 以外の URL に印が付き、GitHub の URL は出さない" \
		'    外への送信: ⚠ + `curl -sS https://example.com/x`  ⚠ + `wget -q x`  ⚠ + `url: https://api.example.net/hook`'
}

tc_08_credentials() {
	run "$(files "$(file_entry modified .github/workflows/a.yml "$(
		printf '%s\n' '-          persist-credentials: false'
	)")" "$(file_entry modified .github/workflows/b.yml "$(
		printf '%s\n' '+          persist-credentials: true'
	)")" "$(file_entry modified .github/workflows/c.yml "$(
		printf '%s\n' '+          persist-credentials: false'
	)")")"
	expect_has "TC-220-08: persist-credentials: false を消した行に印が付く" '    認証情報の保持: ⚠ - `persist-credentials: false`'
	expect_has "TC-220-08: persist-credentials: true を足した行に印が付く" '    認証情報の保持: ⚠ + `persist-credentials: true`'
	expect_has "TC-220-08: persist-credentials: false を足した行には付かない" '    認証情報の保持: + `persist-credentials: false`'
}

tc_09_moved_line() {
	run "$(files "$(file_entry modified .github/workflows/a.yml "$(
		printf '%s\n' '-  actions: write   # 古いコメント' '+  actions: write   # 新しいコメント' \
			'-      TOKEN: ${{ secrets.A }}' '+      TOKEN: ${{ secrets.A }}'
	)")")"
	expect_line "TC-220-09: 行末のコメントを直した・動かしただけなら要注意にしない" 1 ':white_check_mark: *'
	expect_not "TC-220-09: 印が付かない" '⚠'
	expect_has "TC-220-09: 行は中身として並ぶ" '    権限: - `actions: write   # 古いコメント`  + `actions: write   # 新しいコメント`'
}

tc_09_moved_line_unbalanced() {
	run "$(files "$(file_entry modified .github/workflows/a.yml "$(
		printf '%s\n' '-      contents: write' '+      contents: write' '+      contents: write'
	)")")"
	expect_line "TC-220-19: 足した数が多い分は要注意のまま残す" 1 ':rotating_light: *要注意の変更が 1 行ある*'
}

tc_10_permissions_block_removed() {
	run "$(files "$(file_entry modified .github/workflows/a.yml "$(
		printf '%s\n' '-permissions:' '-  contents: read'
	)")")"
	expect_has "TC-220-10: permissions のブロックを消すと印が付く" '    権限: ⚠ - `permissions:`  - `contents: read`'
}

tc_11_no_patch() {
	run "$(files "$(file_entry modified .github/workflows/big.yml '' | jq 'del(.patch) | .additions = 4000')")"
	expect_line "TC-220-11: 確かめられなかったことが 1 行目に出る" 1 ':warning: 差分が大きく、1 件のファイルは機械的に確かめられなかった。差分を見てください'
	expect_has "TC-220-11: ファイルの行にも出る" '    差分が大きく、GitHub が差分を返さなかったので確かめられなかった'
}

tc_12_removed_and_renamed() {
	run "$(files "$(file_entry removed .github/workflows/old.yml '-  pull_request_target:')" \
		"$(file_entry renamed .github/workflows/new.yml '' | jq '.previous_filename = ".github/workflows/prev.yml" | del(.patch)')")"
	expect_has "TC-220-12: 削除が出る" '• `.github/workflows/old.yml`(削除。+0 -1)'
	expect_not "TC-220-12: 削除したファイルの行は並べず、要注意にもしない" '⚠'
	expect_line "TC-220-12: 削除と、差分の無いリネームは確かめられたものとする" 1 ':white_check_mark: *'
	expect_has "TC-220-12: リネームは元の名前が出る" '• `.github/workflows/new.yml`(名前を変えた。元: `.github/workflows/prev.yml`。+0 -0)'
}

tc_13_event_expression() {
	run "$(files "$(file_entry modified .github/workflows/a.yml "$(
		printf '%s\n' '+          echo "${{ github.event.pull_request.title }}"' '+          echo "${{ github.event.pull_request.number }}"'
	)")")"
	expect_has "TC-220-13: PR のタイトルなどを埋め込む行に印が付き、番号は出さない" \
		'    入力の埋め込み: ⚠ + `echo "${{ github.event.pull_request.title }}"`'
}

tc_14_escape() {
	run "$(files "$(file_entry modified '.github/workflows/<a>&b.yml' "$(
		printf '%s\n' '+          curl "x" -d "<b>&`y`"'
	)")")"
	expect_has "TC-220-14: ファイル名の < > & をエスケープする" '• `.github/workflows/&lt;a&gt;&amp;b.yml`'
	expect_has "TC-220-14: 行の < > & をエスケープし、バッククォートを置き換える" "\`curl \"x\" -d \"&lt;b&gt;&amp;'y'\"\`"
}

tc_15_comment_lines() {
	run "$(files "$(file_entry modified .github/workflows/a.yml "$(
		printf '%s\n' '+      # secrets.X を curl で送る話' '+' '+      run: echo ok'
	)")")"
	expect_line "TC-220-15: コメントと空行は見ない" 1 ':white_check_mark: *'
	expect_has "TC-220-15: 触れる行が無いと出る" '    権限・secret・トリガー・アクション・外への送信に触れる行の変更は無い'
}

tc_16_many_lines() {
	run "$(files "$(file_entry modified .github/workflows/a.yml "$(
		for i in 1 2 3 4 5 6 7; do echo "+      - uses: actions/x$i@v1"; done
	)")")"
	expect_has "TC-220-16: 6 行目からはまとめる" '`- uses: actions/x5@v1`  ほか 2 行'
	expect_not "TC-220-16: 6 行目は出さない" 'x6@'
}

tc_17_no_relevant_change() {
	run "$(files "$(file_entry modified .github/workflows/a.yml '+          echo hello')" \
		"$(file_entry modified backend/app.php '+curl')")"
	expect_has "TC-220-17: 触れる行が無いワークフローにはその旨が出る" '    権限・secret・トリガー・アクション・外への送信に触れる行の変更は無い'
	expect_not "TC-220-17: ワークフロー以外のファイルは出さない" 'backend/app.php'
}

# --- 道具 -------------------------------------------------------------------

# pulls/<番号>/files の 1 件分。additions・deletions は patch から数える
file_entry() {
	local status=$1 filename=$2 patch=$3
	jq -n --arg status "$status" --arg filename "$filename" --arg patch "$patch" '{
		filename: $filename, status: $status,
		additions: ($patch | split("\n") | map(select(startswith("+"))) | length),
		deletions: ($patch | split("\n") | map(select(startswith("-"))) | length),
		patch: ("@@ -1,1 +1,1 @@\n" + $patch)
	}'
}

files() {
	printf '%s\n' "$@" | jq -s .
}

run() {
	OUTPUT=$(bash "$SCRIPT" <<<"$1" 2>&1)
}

expect() {
	local name=$1
	shift
	if "$@"; then pass "$name"; else fail "$name"; fi
}

expect_has() {
	if grep -qF -- "$2" <<<"$OUTPUT"; then pass "$1"; else fail "$1"; fi
}

expect_not() {
	if grep -qF -- "$2" <<<"$OUTPUT"; then fail "$1"; else pass "$1"; fi
}

# n 行目が文字列で始まるか(* は末尾の何でも)
expect_line() {
	local line
	line=$(sed -n "${2}p" <<<"$OUTPUT")
	# shellcheck disable=SC2053
	if [[ $line == $3 ]]; then pass "$1"; else fail "$1"; fi
}

pass() {
	PASSED=$((PASSED + 1))
	echo "ok   $1"
}

fail() {
	FAILED=$((FAILED + 1))
	echo "FAIL $1"
	local line
	while IFS= read -r line; do
		echo "     | $line"
	done <<<"$OUTPUT"
}

main
