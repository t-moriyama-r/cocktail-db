#!/usr/bin/env bash
#
# draft-candidates.sh のテスト(TC-236-xx・TC-469-xx)。decide は、事実の JSON から、引き継がせるか・なぜ見送るかを確かめる。
# 事実を集めるところ(TC-469-xx)は、gh を決まった値を返す偽物に差し替えて流す。GitHub には触らない。
#
# 使い方: .github/scripts/tests/draft-candidates.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../draft-candidates.sh"

FAILED=0
PASSED=0

# 実装したエージェントが落ちて 4 時間たった、引き継ぐ draft。各ケースはここから 1 つだけ変える
BASE='{"state":"OPEN","isDraft":true,"isCrossRepository":false,"author":"owner","owner":"owner","baseRefName":"develop","defaultBranch":"develop","labels":[],"checksPending":false,"idleMinutes":240,"handledHead":false,"round":1}'

main() {
	check TC-236-01 '落ちた draft を引き継ぐ' '.' 'pick'
	check TC-236-02 'CI が赤でも引き継ぐ(状態のラベルは見ない)' '.labels = ["状態:CI失敗"]' 'pick'
	check TC-236-03 '閉じた PR' '.state = "MERGED"' 'skip: 開いていない'
	check TC-236-04 'ready の PR は、実装したエージェントが役目を果たしている' '.isDraft = false' 'skip: draft でない'
	check TC-236-05 'フォークから' '.isCrossRepository = true' 'skip: フォークから'
	check TC-236-06 'オーナー以外の PR' '.author = "someone"' 'skip: オーナーの PR でない'
	check TC-236-07 '積んだ PR(base が develop でない)' '.baseRefName = "issue-1-base"' 'skip: 向き先が develop でない(積んだ PR は、土台が入って付け替わってから)'
	check TC-236-08 'needs-human' '.labels = ["needs-human"]' 'skip: ラベル needs-human'
	check TC-236-09 'needs-decision' '.labels = ["needs-decision"]' 'skip: ラベル needs-decision'
	check TC-236-10 'ai-in-actions(Actions が対応している途中)' '.labels = ["ai-in-actions"]' 'skip: ラベル ai-in-actions'
	check TC-236-11 'no-auto-merge' '.labels = ["no-auto-merge"]' 'skip: ラベル no-auto-merge'
	check TC-236-12 'CI が動いている途中' '.checksPending = true' 'skip: CI が動いている途中'
	check TC-473-03 '境界: 最後の動きから 59 分は待つ(実装したエージェントがまだ動いているかもしれない)' '.idleMinutes = 59' 'skip: 最後の動きから 59 分(60 分たつまで待つ)'
	check TC-473-04 '境界: 最後の動きから 60 分で引き継ぐ(落ちたとみなす)' '.idleMinutes = 60' 'pick'
	check TC-236-15 '同じコミットで引き継ぎ済み' '.handledHead = true' 'skip: このコミットは引き継ぎ済み'
	check TC-236-16 '境界: 2 回目の引き継ぎはする' '.round = 2' 'pick'
	check TC-236-17 '境界: 3 回目の引き継ぎはしない' '.round = 3' 'skip: 引き継ぎが 2 回に達した'
	check TC-236-18 '人の判断待ちは、CI の途中より先に理由に出る' '.labels = ["needs-human"] | .checksPending = true' 'skip: ラベル needs-human'
	check TC-456-04 '見回り待ち(needs-local)は引き継がない' '.labels = ["needs-local"]' 'skip: ラベル needs-local'
	check TC-496-07 '手元の作業待ち(needs-manual)は引き継がない' '.labels = ["needs-manual"]' 'skip: ラベル needs-manual'

	collect TC-469-02 'コメントの合計が 200KB を超える PR でも落ちずに、事実を集めて選ぶ' \
		'[{"number":7,"head":"abc","branch":"issue-7-x","round":1}]|0'

	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

check() {
	local id=$1 title=$2 change=$3 expected=$4 actual
	actual=$(jq -c "$change" <<<"$BASE" | bash "$SCRIPT" decide)
	if [ "$actual" = "$expected" ]; then
		PASSED=$((PASSED + 1))
		echo "ok   $id $title"
	else
		FAILED=$((FAILED + 1))
		echo "FAIL $id $title: expected '$expected', got '$actual'"
	fi
}

# 偽の gh で draft-candidates.sh <owner/repo> を流し、選んだ PR の JSON と終了コードを比べる。
# #415 はコメントが多く、jq の引数で渡して Argument list too long で落ちた。1 引数の上限(Linux は 128KB)を超える 250KB の本文を返す
collect() {
	local id=$1 title=$2 expected=$3 fake actual rc=0
	fake=$(mktemp -d)
	mkdir -p "$fake/bin"
	cat >"$fake/bin/gh" <<'SH'
#!/usr/bin/env bash
case "$*" in
	"api repos/o/r --jq .default_branch") echo develop ;;
	"pr list -R o/r --state open --draft"*) echo 7 ;;
	"pr view 7 -R o/r --json"*)
		echo '{"state":"OPEN","isDraft":true,"isCrossRepository":false,"author":{"login":"o"},"baseRefName":"develop","headRefName":"issue-7-x","headRefOid":"abc","labels":[],"statusCheckRollup":[]}' ;;
	"api repos/o/r/commits/abc --jq .commit.committer.date") echo 2026-10-28T00:00:00Z ;;
	"api --paginate --slurp repos/o/r/issues/7/comments"*)
		head -c 250000 /dev/zero | tr '\0' x \
			| jq -R -s -c '[[{body: ., user: {login: "someone", type: "User"}, created_at: "2026-10-28T00:01:00Z"}]]' ;;
	*) echo "unexpected: gh $*" >&2; exit 2 ;;
esac
SH
	chmod +x "$fake/bin/gh"
	# 最後の動き(00:01)から 4 時間後
	actual=$(PATH="$fake/bin:$PATH" NOW=$(jq -n '"2026-10-28T04:01:00Z" | fromdate') bash "$SCRIPT" o/r 2>"$fake/err" | tr -d '\r') || rc=$?
	if [ "$actual|$rc" = "$expected" ]; then
		PASSED=$((PASSED + 1))
		echo "ok   $id $title"
	else
		FAILED=$((FAILED + 1))
		echo "FAIL $id $title: expected '$expected', got '$actual|$rc'"
		sed 's/^/     | /' "$fake/err"
	fi
	rm -rf "$fake"
}

main "$@"
