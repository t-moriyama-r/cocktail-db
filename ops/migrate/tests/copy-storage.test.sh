#!/usr/bin/env bash
#
# copy-storage.sh のテスト(TC-34-xx・TC-328-xx)。DB は使わない。一時ディレクトリにダミーの画像を作って流す。
#
# 使い方:
#   ops/migrate/tests/copy-storage.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../copy-storage.sh"

FAILED=0
PASSED=0

main() {
	tc_01_copies_missing_files
	tc_02_skips_same_files
	tc_03_conflict_is_not_overwritten
	tc_04_keeps_dest_only_files_and_source
	tc_05_dotfiles_are_ignored
	tc_06_dry_run_writes_nothing
	tc_07_empty_source
	tc_08_argument_errors
	tc_09_refuses_overlap
	tc_10_copy_failure
	tc_328_01_list_failure
	tc_328_02_dest_created_before_placing
	tc_328_03_dangling_symlink_dest
	tc_328_04_cleans_up_list_file
	echo "passed: $PASSED, failed: $FAILED"
	[[ $FAILED -eq 0 ]]
}

# --- ケース -----------------------------------------------------------------

# TC-34-01
tc_01_copies_missing_files() {
	new_sandbox
	add_image "$SOURCE" "a.jpeg" 2048
	add_image "$SOURCE" "空白 と 日本語.png" 100
	add_image "$SOURCE" "sub/dir/deep.gif" 10
	chmod 640 "$SOURCE/a.jpeg"
	touch -d '2020-01-02 03:04:05' "$SOURCE/a.jpeg"
	run_script "$SOURCE" "$DEST"

	expect_status "TC-34-01: 正常に終わる" 0
	expect_output "TC-34-01: コピーした数を出す" "copied: 3"
	expect "TC-34-01: 空白・日本語・サブディレクトリの名前を含め、中身が旧と一致する" \
		cmp -s <(manifest "$SOURCE") <(manifest "$DEST")
	expect "TC-34-01: 更新日時を写す" test "$(stat -c %Y "$DEST/a.jpeg")" = "$(stat -c %Y "$SOURCE/a.jpeg")"
	expect "TC-34-01: 権限を写す" test "$(stat -c %a "$DEST/a.jpeg")" = 640
	expect "TC-34-01: 一時ファイルを残さない" test -z "$(find "$DEST" -name '.copy-storage.*')"
}

# TC-34-02
tc_02_skips_same_files() {
	new_sandbox
	add_image "$SOURCE" "a.jpeg" 100
	add_image "$SOURCE" "b.jpeg" 100
	run_script "$SOURCE" "$DEST"
	touch -d '2020-01-01' "$DEST/a.jpeg"
	local before
	before=$(stat -c %Y "$DEST/a.jpeg")

	add_image "$SOURCE" "c.jpeg" 100
	run_script "$SOURCE" "$DEST"
	expect_status "TC-34-02: 2 回目も正常に終わる" 0
	expect_output "TC-34-02: 新しいものだけをコピーする" "copied: 1"
	expect_output "TC-34-02: 同じ中身のものは飛ばす" "skipped: 2"
	expect "TC-34-02: 飛ばしたファイルに触らない" test "$(stat -c %Y "$DEST/a.jpeg")" = "$before"

	run_script "$SOURCE" "$DEST"
	expect_output "TC-34-02: 3 回目はすべて飛ばす" "copied: 0"
}

# TC-34-03
tc_03_conflict_is_not_overwritten() {
	new_sandbox
	add_image "$SOURCE" "same.jpeg" 100
	add_image "$SOURCE" "differ.jpeg" 100
	add_image "$SOURCE" "sub/differ2.jpeg" 100
	add_image "$SOURCE" "new.jpeg" 100
	cp -p "$SOURCE/same.jpeg" "$DEST/same.jpeg"
	add_image "$DEST" "differ.jpeg" 100
	add_image "$DEST" "sub/differ2.jpeg" 100
	manifest "$DEST" >"$SANDBOX/dest-before.sha256"
	run_script "$SOURCE" "$DEST"

	expect_status "TC-34-03: 中身の違う同じ名前のファイルがあれば 2" 2
	expect_output "TC-34-03: 食い違いの数を出す" "conflict: 2"
	expect_output "TC-34-03: 食い違いを相対パスで出す" "  differ.jpeg"
	expect_output "TC-34-03: サブディレクトリの食い違いも出す" "  sub/differ2.jpeg"
	expect "TC-34-03: 食い違ったファイルを上書きしない" \
		bash -c "! cmp -s '$SOURCE/differ.jpeg' '$DEST/differ.jpeg'"
	expect "TC-34-03: 食い違いがあっても、ほかのファイルはコピーする" cmp -s "$SOURCE/new.jpeg" "$DEST/new.jpeg"
	expect "TC-34-03: 既にあったファイルは変わらない" \
		bash -c "cd '$DEST' && sha256sum -c --quiet '$SANDBOX/dest-before.sha256'"
}

# TC-34-04
tc_04_keeps_dest_only_files_and_source() {
	new_sandbox
	add_image "$SOURCE" "a.jpeg" 100
	add_image "$DEST" "uploaded-by-new-app.jpeg" 100
	local source_before
	source_before=$(manifest "$SOURCE")
	run_script "$SOURCE" "$DEST"

	expect_status "TC-34-04: 正常に終わる" 0
	expect "TC-34-04: 新の側にだけあるファイルを消さない" test -f "$DEST/uploaded-by-new-app.jpeg"
	expect "TC-34-04: 旧の側を変えない" cmp -s <(echo "$source_before") <(manifest "$SOURCE")
}

# TC-34-05
tc_05_dotfiles_are_ignored() {
	new_sandbox
	add_image "$SOURCE" "a.jpeg" 10
	printf '*\n!.gitignore\n' >"$SOURCE/.gitignore"
	printf 'other\n' >"$DEST/.gitignore"
	run_script "$SOURCE" "$DEST"

	expect_status "TC-34-05: 両方に中身の違う .gitignore があっても 0" 0
	expect_output "TC-34-05: ドットファイルは数えない" "copied: 1"
	expect "TC-34-05: 新の .gitignore を変えない" grep -qx other "$DEST/.gitignore"
}

# TC-34-06
tc_06_dry_run_writes_nothing() {
	new_sandbox
	add_image "$SOURCE" "new.jpeg" 10
	add_image "$SOURCE" "differ.jpeg" 10
	add_image "$DEST" "differ.jpeg" 10
	local dest_before
	dest_before=$(manifest "$DEST")
	run_script --dry-run "$SOURCE" "$DEST"

	expect_status "TC-34-06: --dry-run でも食い違いがあれば 2" 2
	expect_output "TC-34-06: コピーする予定の数を出す" "copied: 1"
	expect_output "TC-34-06: 何もコピーしていないと出す" "dry-run: 何もコピーしていない"
	expect "TC-34-06: 新の側に何も書かない" cmp -s <(echo "$dest_before") <(manifest "$DEST")
}

# TC-34-07
tc_07_empty_source() {
	new_sandbox
	run_script "$SOURCE" "$DEST"

	expect_status "TC-34-07: 旧が空でも 0" 0
	expect_output "TC-34-07: コピーした数は 0" "copied: 0"
}

# TC-34-08
tc_08_argument_errors() {
	new_sandbox
	run_script "$SANDBOX/none" "$DEST"
	expect_status "TC-34-08: 旧のディレクトリが無ければ 1" 1

	run_script "$SOURCE" "$SANDBOX/none"
	expect_status "TC-34-08: 新のディレクトリが無ければ 1(作らない)" 1
	expect "TC-34-08: 新のディレクトリを作らない" test ! -e "$SANDBOX/none"

	run_script "$SOURCE"
	expect_status "TC-34-08: 引数が足りなければ 1" 1

	run_script
	expect_status "TC-34-08: 引数が無ければ 1" 1
}

# TC-34-09
tc_09_refuses_overlap() {
	new_sandbox
	add_image "$SOURCE" "a.jpeg" 10
	run_script "$SOURCE" "$SOURCE/"
	expect_status "TC-34-09: 旧と新が同じなら 1" 1

	mkdir -p "$SOURCE/inner"
	run_script "$SOURCE" "$SOURCE/inner"
	expect_status "TC-34-09: 新が旧の中にあれば 1" 1
	expect "TC-34-09: 何もコピーしない" test -z "$(ls -A "$SOURCE/inner")"

	mkdir -p "$DEST/inner"
	run_script "$DEST/inner" "$DEST"
	expect_status "TC-34-09: 旧が新の中にあれば 1" 1
}

# TC-34-10
tc_10_copy_failure() {
	if [[ $EUID -eq 0 ]]; then
		echo "skip TC-34-10: 書けないディレクトリ(root では書けてしまうので、root 以外で流す CI で確かめる)"
		return
	fi
	new_sandbox
	add_image "$SOURCE" "sub/a.jpeg" 10
	mkdir -p "$DEST/sub"
	chmod 500 "$DEST/sub"
	run_script "$SOURCE" "$DEST"
	chmod 700 "$DEST/sub"

	expect_status "TC-34-10: 書けなければ 1" 1
	expect "TC-34-10: 途中のファイルを残さない" test -z "$(ls -A "$DEST/sub")"
}

# TC-328-01
tc_328_01_list_failure() {
	if [[ $EUID -eq 0 ]]; then
		echo "skip TC-328-01: 読めないディレクトリ(root では読めてしまうので、root 以外で流す CI で確かめる)"
		return
	fi
	new_sandbox
	add_image "$SOURCE" "a.jpeg" 10
	add_image "$SOURCE" "locked/b.jpeg" 10
	chmod 000 "$SOURCE/locked"
	run_script "$SOURCE" "$DEST"
	chmod 700 "$SOURCE/locked"

	expect_status "TC-328-01: 一覧を取れなければ 1" 1
	expect "TC-328-01: 一覧を取れなかったと出す" grep -qF "旧の画像の一覧を取れなかった" <<<"$OUTPUT"
	expect "TC-328-01: 「copied: 0」で成功したことにしない" test "$(grep -c '^copied:' <<<"$OUTPUT")" = 0
	expect "TC-328-01: 一覧が取れた分も、何もコピーしない" test -z "$(ls -A "$DEST")"
}

# TC-328-02: 存在の確認とコピーの間に、新アプリが同じ名前でファイルを作ったことを、cp の差し替えで起こす
tc_328_02_dest_created_before_placing() {
	new_sandbox
	local real_cp shim="$SANDBOX/shim"
	real_cp=$(command -v cp)
	mkdir -p "$shim"
	cat >"$shim/cp" <<SHIM
#!/usr/bin/env bash
$real_cp "\$@" || exit \$?
[[ -z "\${RACE_DEST:-}" ]] || printf '%s' "\${RACE_CONTENT:-}" >"\$RACE_DEST"
SHIM
	chmod +x "$shim/cp"

	add_image "$SOURCE" "race.jpeg" 100
	RACE_DEST="$DEST/race.jpeg" RACE_CONTENT="written by new app" PATH="$shim:$PATH" run_script "$SOURCE" "$DEST"
	expect_status "TC-328-02: 置く直前に中身の違うファイルが作られたら 2" 2
	expect_output "TC-328-02: 食い違いに出す" "  race.jpeg"
	expect "TC-328-02: 新アプリが作ったファイルを上書きしない" grep -qx "written by new app" "$DEST/race.jpeg"
	expect "TC-328-02: 一時ファイルを残さない" test -z "$(find "$DEST" -name '.copy-storage.*')"

	rm -f "$DEST/race.jpeg"
	printf '%s' "same bytes" >"$SOURCE/race.jpeg"
	RACE_DEST="$DEST/race.jpeg" RACE_CONTENT="same bytes" PATH="$shim:$PATH" run_script "$SOURCE" "$DEST"
	expect_status "TC-328-02: 置く直前に同じ中身のファイルが作られたら 0" 0
	expect_output "TC-328-02: 同じ中身は skipped にする" "skipped: 1"
}

# TC-328-03
tc_328_03_dangling_symlink_dest() {
	new_sandbox
	add_image "$SOURCE" "link.jpeg" 100
	ln -s "$SANDBOX/nowhere" "$DEST/link.jpeg"
	run_script "$SOURCE" "$DEST"

	expect_status "TC-328-03: 置き先が壊れたシンボリックリンクなら 2" 2
	expect_output "TC-328-03: 食い違いに出す" "  link.jpeg"
	expect "TC-328-03: シンボリックリンクを置き換えない" test -L "$DEST/link.jpeg"
	expect "TC-328-03: リンクの先に何も作らない" test ! -e "$SANDBOX/nowhere"
}

# TC-328-04
tc_328_04_cleans_up_list_file() {
	new_sandbox
	mkdir -p "$SANDBOX/tmp"
	add_image "$SOURCE" "a.jpeg" 10
	TMPDIR="$SANDBOX/tmp" run_script "$SOURCE" "$DEST"
	expect_status "TC-328-04: 正常に終わる(一覧の一時ファイルの後始末で失敗しない)" 0
	expect "TC-328-04: 正常に終わったとき、一時ファイルを残さない" test -z "$(ls -A "$SANDBOX/tmp")"

	add_image "$SOURCE" "differ.jpeg" 10
	add_image "$DEST" "differ.jpeg" 10
	TMPDIR="$SANDBOX/tmp" run_script "$SOURCE" "$DEST"
	expect_status "TC-328-04: 食い違いで 2 のとき" 2
	expect "TC-328-04: 食い違いで終わったときも、一時ファイルを残さない" test -z "$(ls -A "$SANDBOX/tmp")"
}

# --- 道具 -------------------------------------------------------------------

new_sandbox() {
	SANDBOX=$(mktemp -d)
	SOURCE="$SANDBOX/old/storage/app/public"
	DEST="$SANDBOX/new/storage/app/public"
	mkdir -p "$SOURCE" "$DEST"
}

add_image() {
	local path="$1/$2"
	mkdir -p "$(dirname "$path")"
	head -c "$3" /dev/urandom >"$path"
}

run_script() {
	OUTPUT=$("$SCRIPT" "$@" 2>&1)
	STATUS=$?
}

manifest() {
	(cd "$1" && find . -type f -print0 | LC_ALL=C sort -z | xargs -0 -r sha256sum)
}

expect() {
	local name=$1
	shift
	if "$@"; then pass "$name"; else fail "$name"; fi
}

expect_status() {
	if [[ $STATUS -eq $2 ]]; then pass "$1"; else fail "$1 (終了コード $STATUS)"; fi
}

expect_output() {
	if grep -qxF -- "$2" <<<"$OUTPUT"; then pass "$1"; else fail "$1 (「$2」の行が無い)"; fi
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
