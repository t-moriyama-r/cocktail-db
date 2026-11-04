#!/usr/bin/env bash
#
# evidence-publish.sh のテスト(TC-600-10〜15)。GitHub ではなく、一時ディレクトリの bare リポジトリ(EVIDENCE_REMOTE)に push する。
#
# 使い方: .github/scripts/tests/evidence-publish.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../evidence-publish.sh"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
export GIT_CONFIG_GLOBAL="$WORK/gitconfig" GIT_CONFIG_NOSYSTEM=1
git config --file "$GIT_CONFIG_GLOBAL" init.defaultBranch develop

FAILED=0
PASSED=0

main() {
	reset
	# 土台のリポジトリには develop の履歴がある。evidence ブランチはそれを持たないこと
	git -C "$WORK/seed" commit -q --allow-empty -m develop
	git -C "$WORK/seed" push -q "$WORK/remote.git" HEAD:refs/heads/develop
	files 01-screen-375-before.png 01-screen-375-after.png 01-screen-375-compare.png 01-screen-1440-after.mp4 01-screen-1440-after.gif
	run "$WORK/src" o/r 12 abc1234
	expect TC-600-10 'evidence ブランチが無ければ、孤立したブランチを作って pr-<番号>/<SHA>/ に置き、raw の URL を出す' \
		"$RC|$(tree_of)|$(git -C "$WORK/remote.git" rev-list --count evidence)|$(head -1 "$WORK/out")" \
		"0|pr-12/abc1234/01-screen-1440-after.gif pr-12/abc1234/01-screen-1440-after.mp4 pr-12/abc1234/01-screen-375-after.png pr-12/abc1234/01-screen-375-before.png pr-12/abc1234/01-screen-375-compare.png pr-12/abc1234/manifest.json|1|https://raw.githubusercontent.com/o/r/evidence/pr-12/abc1234/01-screen-1440-after.gif"

	files 01-api-before.png 01-api-after.png 01-api-compare.png
	run "$WORK/src" o/r 13 def5678
	expect TC-600-11 'evidence ブランチがあれば、ほかの PR のディレクトリを残して足す' \
		"$RC|$(tree_of | tr ' ' '\n' | cut -d/ -f1-2 | sort -u | paste -sd' ' -)" '0|pr-12/abc1234 pr-13/def5678'

	files 01-command-before.png
	run "$WORK/src" o/r 12 abc1234
	expect TC-600-12 '同じ SHA で撮り直したら、そのディレクトリの中身を入れ替える(前のファイルを残さない)' \
		"$RC|$(tree_of | tr ' ' '\n' | grep '^pr-12/' | paste -sd' ' -)" '0|pr-12/abc1234/01-command-before.png pr-12/abc1234/manifest.json'

	local before
	before=$(git -C "$WORK/remote.git" rev-parse evidence)
	files 01-screen-375-before.png
	printf 'x' >"$WORK/src/index.html"
	mkdir "$WORK/src/sub"
	ln -s /etc/passwd "$WORK/src/01-screen-375-after.png"
	run "$WORK/src" o/r 14 aaa1111
	expect TC-600-13 '決まった名前でないファイル・ディレクトリ・リンクがあれば、push せずに 1 で終わる' \
		"$RC|$(git -C "$WORK/remote.git" rev-parse evidence)|$(grep -c '置かない' "$WORK/err")" "1|$before|3"

	files 01-screen-375-before.png
	printf '{"note": "%s"}\n' '/home/runner/work/x' >"$WORK/src/manifest.json"
	run "$WORK/src" o/r 14 aaa1111
	expect TC-600-14 'manifest.json にランナーのパスが出ていれば、push せずに 1 で終わる' \
		"$RC|$(git -C "$WORK/remote.git" rev-parse evidence)" "1|$before"

	run "$WORK/src" o/r 14 'abc;rm'
	expect TC-600-15 'SHA の形でなければ 2 で終わる(パスに使うため)' "$RC" 2

	echo
	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

reset() {
	rm -rf "$WORK/remote.git" "$WORK/seed"
	git init -q --bare "$WORK/remote.git"
	git init -q "$WORK/seed"
	git -C "$WORK/seed" config user.name t
	git -C "$WORK/seed" config user.email t@example.test
}

# 証跡のディレクトリを作り直し、渡した名前のファイルと manifest.json を置く
files() {
	rm -rf "$WORK/src"
	mkdir -p "$WORK/src"
	local f
	for f in "$@"; do printf '%s' "$f" >"$WORK/src/$f"; done
	printf '{"version": 1}\n' >"$WORK/src/manifest.json"
}

tree_of() {
	git -C "$WORK/remote.git" ls-tree -r --name-only evidence | paste -sd' ' -
}

run() {
	RC=0
	EVIDENCE_REMOTE="$WORK/remote.git" bash "$SCRIPT" "$@" >"$WORK/out" 2>"$WORK/err" || RC=$?
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
