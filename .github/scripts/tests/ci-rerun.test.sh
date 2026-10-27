#!/usr/bin/env bash
#
# ci-rerun.sh のテスト(TC-228-01〜・TC-365-01〜・TC-389-01〜・TC-410-02〜)。gh を、決まった値を返す偽物に差し替えて流す。GitHub には触らない。
#
# 使い方: .github/scripts/tests/ci-rerun.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../ci-rerun.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT
export FAKE

FAILED=0
PASSED=0

OLD=$(date -u -d '-2 hours' +%Y-%m-%dT%H:%M:%SZ)
NEW=$(date -u -d '-2 minutes' +%Y-%m-%dT%H:%M:%SZ)

main() {
	setup_fake_gh

	reset
	pr 7 develop false MERGEABLE
	runs
	run 7
	expect TC-228-01 '付け替わって pull_request の回が無い PR は、閉じて開き直す(workflow_dispatch はしない)' \
		"$(reopened | tr '\n' ' ')|$(dispatched)" 'closed pat open pat |'

	reset
	pr 7 develop false MERGEABLE
	runs null
	run 7
	expect TC-228-02 'いまのコミットで pull_request の回が走った(走っている)なら何もしない' "$(acted)" ''

	reset
	pr 7 issue-5-base false MERGEABLE
	runs
	run 7
	expect TC-228-03 '向き先が既定のブランチでない(積んでいる途中)なら何もしない' "$(acted)" ''

	reset
	pr 7 develop true MERGEABLE
	runs
	run 7
	expect TC-228-04 'フォークからの PR では何もしない' "$(acted)" ''

	reset
	pr 7 develop false MERGEABLE CLOSED
	runs
	run 7
	expect TC-228-05 '閉じた PR では何もしない' "$(acted)" ''

	reset
	pr 7 develop false MERGEABLE
	runs
	updated "$NEW"
	run 7
	expect TC-228-06 'push の直後(PR の更新から 10 分たっていない)は開き直さない' "$(acted)" ''

	reset
	pr 7 develop false CONFLICTING
	runs
	run 7
	expect TC-228-07 'コンフリクトしていたら何もせず、人にも頼まない(ai-conflict-resolve.yml が直す)' \
		"$(acted)$(commented)$(labeled)$(notified)" ''

	reset
	pr 7 develop false MERGEABLE
	runs
	touch "$FAKE/no-pat"
	run 7
	expect TC-228-08 '開き直すトークンが無ければ、そのことを頼み、Slack に知らせる' \
		"$(commented | grep -c 'CODERABBIT_REQUEST_TOKEN')|$(notified | grep -c needs-human)|$(reopened)" '1|1|'

	reset
	pr 7 develop false MERGEABLE
	runs
	touch "$FAKE/no-pat"
	echo '<!-- ai-dev:agent --> <!-- ai-dev:ci-rerun sha=sha7 -->' >"$FAKE/comments"
	run 7
	expect TC-228-09 '同じコミットでもう頼んでいたら、もう頼まない' "$(commented)$(labeled)$(notified)" ''

	reset
	pr 7 develop false MERGEABLE
	pr 8 develop false MERGEABLE
	runs
	echo "7 8" >"$FAKE/list"
	run
	expect TC-228-10 '番号なしなら、既定のブランチ向けの開いている PR をすべて見る' "$(grep -c '^open' "$FAKE/reopened")" 2

	# grep -q に直接つなぐと、目印を見つけた時点で gh が SIGPIPE で止まり、pipefail で見落とす
	reset
	pr 7 develop false MERGEABLE
	runs
	touch "$FAKE/no-pat"
	{ echo '<!-- ai-dev:ci-rerun sha=sha7 -->'; head -c 300000 /dev/zero | tr '\0' 'x'; echo; } >"$FAKE/comments"
	run 7
	expect TC-228-11 'コメントが大きくても、同じコミットで頼み直さない' "$(commented)" ''

	reset
	pr 7 develop false MERGEABLE
	runs
	echo 502 >"$FAKE/close-fails"
	run 7
	expect TC-228-15 'トークンのためでない失敗(一時的なエラー)で閉じられなければ、人に頼まず次の見回りに任せる' \
		"$(commented)$(labeled)$(notified)" ''

	# 前の回でラベルと通知までで止まり、コメントだけ書けなかったとき
	reset
	pr 7 develop false MERGEABLE OPEN needs-human
	runs
	touch "$FAKE/no-pat"
	run 7
	expect TC-228-16 'needs-human がもう付いていたら、Slack に知らせ直さず、頼むコメントだけ書く' \
		"$(commented | grep -c 'ai-dev:ci-rerun sha=sha7')|$(labeled)$(notified)" '1|'

	# 承認待ち(action_required)の回は、走ったものに数えない(#365)。承認して走らせる(#389)
	reset
	pr 7 develop false MERGEABLE
	runs action_required
	run 7
	expect TC-365-01 '承認待ちの回しか無ければ、その回を承認する(開き直さない・workflow_dispatch しない)' \
		"$(approved)|$(reopened)$(dispatched)" '101 gh-token|'

	reset
	pr 7 develop false MERGEABLE
	runs action_required
	updated "$NEW"
	run 7
	expect TC-365-02 '承認待ちの回は、push の直後(10 分たっていない)でも承認する' "$(approved)" '101 gh-token'

	reset
	pr 7 develop false MERGEABLE
	runs action_required null
	run 7
	expect TC-365-03 '承認待ちの回のほかに走っている回があっても、承認待ちの回は承認し、開き直さない' \
		"$(approved)|$(reopened)$(commented)" '101 gh-token|'

	reset
	pr 7 develop false MERGEABLE
	runs action_required success
	run 7
	expect TC-365-04 '承認待ちの回のほかに走り終わった回があっても、承認待ちの回は承認し、開き直さない' \
		"$(approved)|$(reopened)$(commented)" '101 gh-token|'

	reset
	pr 7 develop false MERGEABLE
	runs success
	run 7
	expect TC-365-05 '承認待ちでない回が走り終わっていれば、これまでどおり何もしない' "$(acted)$(commented)" ''

	reset
	pr 7 develop false CONFLICTING
	runs action_required
	run 7
	expect TC-365-06 '承認待ちでも、コンフリクトしていたら何もしない(ai-conflict-resolve.yml が直す)' \
		"$(acted)$(commented)$(labeled)$(notified)" ''

	reset
	pr 7 develop false MERGEABLE
	runs action_required
	touch "$FAKE/approve-fails"
	run 7
	expect TC-365-07 '承認できなければ、オーナーのトークンで閉じて開き直す' "$(reopened | tr '\n' ' ')" 'closed pat open pat '

	reset
	pr 7 develop false MERGEABLE
	runs
	touch "$FAKE/runs-fail"
	run 7
	expect TC-365-08 '回の一覧を読めなかったら、回が無いとみなして開き直さず、失敗で終わる' "$(acted)$(commented)|$RC" '|1'

	reset
	pr 7 develop true MERGEABLE
	runs action_required
	run 7
	expect TC-365-09 '承認待ちでも、フォークからの PR では承認しない' "$(acted)" ''

	# workflow_dispatch の回は、必須のチェックに数えられない(#389)
	reset
	pr 7 develop false MERGEABLE
	runs workflow_dispatch:success
	run 7
	expect TC-389-01 'workflow_dispatch の回しか無ければ、走っていないとみなして閉じて開き直す' \
		"$(reopened | tr '\n' ' ')" 'closed pat open pat '

	reset
	pr 7 develop false MERGEABLE
	runs workflow_dispatch:success action_required
	run 7
	expect TC-389-02 'workflow_dispatch の回が成功していても、pull_request の承認待ちの回は承認する' "$(approved)" '102 gh-token'

	reset
	pr 7 develop false MERGEABLE
	runs action_required other.yml/action_required
	run 7
	expect TC-389-03 'ほかのワークフローの承認待ちの回も、まとめて承認する' "$(approved | tr '\n' ' ')" '101 gh-token 102 gh-token '

	reset
	pr 7 develop false MERGEABLE
	runs success other.yml/action_required
	run 7
	expect TC-389-04 'CI は走り終わり、ほかのワークフローだけ承認待ちなら、それを承認する' "$(approved)|$(reopened)" '102 gh-token|'

	reset
	pr 7 develop false MERGEABLE
	runs success other.yml/action_required
	touch "$FAKE/approve-fails"
	run 7
	expect TC-389-05 'CI が走り終わっていれば、ほかの回を承認できなくても開き直さない(次の見回りでやり直す)' \
		"$(reopened)$(commented)$(labeled)" ''

	reset
	pr 7 develop false MERGEABLE
	runs
	echo 401 >"$FAKE/close-fails"
	run 7
	expect TC-389-06 'オーナーのトークンで閉じられなければ(期限切れ・権限不足)、人に頼み、Slack に知らせる' \
		"$(commented | grep -c '期限切れ・権限不足')|$(notified | grep -c needs-human)" '1|1'

	reset
	pr 7 develop false MERGEABLE
	runs
	touch "$FAKE/open-fails"
	run 7
	expect TC-389-07 '閉じた後に開き直せなければ、GITHUB_TOKEN で開き直し、人に頼む' \
		"$(reopened | tr '\n' ' ')|$(commented | grep -c '開き直せなかった')|$(notified | grep -c needs-human)" \
		'closed pat open gh-token |1|1'

	reset
	pr 7 develop false MERGEABLE
	runs action_required
	touch "$FAKE/approve-fails" "$FAKE/no-pat"
	run 7
	expect TC-389-08 '承認できず、開き直すトークンも無ければ、人に頼む' \
		"$(commented | grep -c 'CODERABBIT_REQUEST_TOKEN')|$(reopened)" '1|'

	reset
	pr 7 develop false MERGEABLE OPEN '' true
	runs
	run 7
	expect TC-410-02 'draft では、回が無くても何もしない(CI は ready にしてから流す)' "$(acted)$(commented)" ''

	reset
	pr 7 develop false MERGEABLE
	runs skipped
	run 7
	expect TC-410-03 'draft のときに飛ばした回しか無ければ、走っていないとみなして閉じて開き直す' 		"$(reopened | tr '
' ' ')" 'closed pat open pat '

	reset
	pr 7 develop false MERGEABLE
	runs skipped success
	run 7
	expect TC-410-04 '飛ばした回のほかに走り終わった回があれば、何もしない' "$(acted)$(commented)" ''

	echo
	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

setup_fake_gh() {
	mkdir -p "$FAKE/bin"
	cat >"$FAKE/bin/gh" <<'SH'
#!/usr/bin/env bash
echo "gh $*" >>"$FAKE/calls"
case "$*" in
	"api repos/o/r --jq .default_branch") echo develop ;;
	"pr list"*) tr ' ' '\n' <"$FAKE/list" ;;
	"pr view"*) jq -c --arg u "$(cat "$FAKE/updated")" '. + {updatedAt: $u}' "$FAKE/pr-$3.json" ;;
	"api repos/o/r/actions/runs?"*)
		if [ -f "$FAKE/runs-fail" ]; then echo "HTTP 502: runs failed" >&2; exit 1; fi
		# event=pull_request は GitHub の側で絞る。gh の --jq と同じく LF で出す(Windows の jq は CRLF で出す)
		jq '.workflow_runs |= map(select(.event == "pull_request"))' "$FAKE/runs" | jq -r "$4" | tr -d '\r' ;;
	"api -X POST repos/o/r/actions/runs/"*"/approve")
		if [ -f "$FAKE/approve-fails" ]; then echo "gh: Resource not accessible by integration (HTTP 403)" >&2; exit 1; fi
		id=${4#repos/o/r/actions/runs/}; echo "${id%/approve} $GH_TOKEN" >>"$FAKE/approved" ;;
	"api -X PATCH repos/o/r/pulls/"*" -f state=closed")
		if [ -f "$FAKE/close-fails" ]; then echo "gh: failed (HTTP $(cat "$FAKE/close-fails"))" >&2; exit 1; fi
		echo "closed $GH_TOKEN" >>"$FAKE/reopened" ;;
	"api -X PATCH repos/o/r/pulls/"*" -f state=open")
		if [ -f "$FAKE/open-fails" ] && [ "$GH_TOKEN" = pat ]; then echo "gh: failed (HTTP 502)" >&2; exit 1; fi
		echo "open $GH_TOKEN" >>"$FAKE/reopened" ;;
	"workflow run notify-needs-human.yml"*) shift 2; echo "$*" >>"$FAKE/notified" ;;
	"workflow run"*) shift 2; echo "$*" >>"$FAKE/dispatched" ;;
	"pr edit"*) shift 2; echo "$*" >>"$FAKE/labeled" ;;
	"api --paginate"*) cat "$FAKE/comments" ;;
	"pr comment"*) printf '%s\n' "$*" >>"$FAKE/commented" ;;
	*) echo "unexpected: gh $*" >&2; exit 2 ;;
esac
SH
	chmod +x "$FAKE/bin/gh"
}

reset() {
	rm -f "$FAKE"/pr-*.json "$FAKE"/calls "$FAKE"/dispatched "$FAKE"/commented "$FAKE"/labeled "$FAKE"/notified \
		"$FAKE"/approved "$FAKE"/reopened "$FAKE"/runs-fail "$FAKE"/approve-fails "$FAKE"/close-fails "$FAKE"/open-fails "$FAKE"/no-pat
	: >"$FAKE/comments"
	: >"$FAKE/calls"
	echo 7 >"$FAKE/list"
	updated "$OLD"
}

# PR の番号・向き先・フォークか・mergeable・状態・付いているラベル・draft か
pr() {
	local labels=
	if [ -n "${6:-}" ]; then labels="{\"name\":\"$6\"}"; fi
	echo "{\"state\":\"${5:-OPEN}\",\"baseRefName\":\"$2\",\"headRefOid\":\"sha$1\",\"isCrossRepository\":$3,\"isDraft\":${7:-false},\"mergeable\":\"$4\",\"labels\":[$labels]}" >"$FAKE/pr-$1.json"
}

# いまのコミットの回。引数ごとに 1 回で、形は [<ワークフローのファイル>/][<イベント>:]<conclusion>。
# 既定は test.yml・pull_request。conclusion は、走っている回は null、承認待ちは action_required。ID は 101 から順に振る
runs() {
	local a wf ev c id=100 list=
	for a in "$@"; do
		wf=test.yml ev=pull_request c=$a
		if [[ $c == */* ]]; then wf=${c%%/*} c=${c#*/}; fi
		if [[ $c == *:* ]]; then ev=${c%%:*} c=${c#*:}; fi
		if [ "$c" = null ]; then c=null; else c="\"$c\""; fi
		id=$((id + 1))
		list="${list:+$list,}{\"id\":$id,\"event\":\"$ev\",\"path\":\".github/workflows/$wf\",\"conclusion\":$c}"
	done
	echo "{\"workflow_runs\":[$list]}" >"$FAKE/runs"
}
# PR の更新時刻(push の時刻の代わり)
updated() { echo "$1" >"$FAKE/updated"; }
dispatched() { cat "$FAKE/dispatched" 2>/dev/null; }
commented() { cat "$FAKE/commented" 2>/dev/null; }
labeled() { cat "$FAKE/labeled" 2>/dev/null; }
notified() { cat "$FAKE/notified" 2>/dev/null; }
# 承認した回の ID と、使ったトークン
approved() { cat "$FAKE/approved" 2>/dev/null; }
# 閉じた・開いたことと、使ったトークン
reopened() { cat "$FAKE/reopened" 2>/dev/null; }
# CI を走らせようとしたこと(承認・開き直し・workflow_dispatch)すべて
acted() { approved; reopened; dispatched; }

run() {
	RC=0
	local pat=pat
	if [ -f "$FAKE/no-pat" ]; then pat=; fi
	GH_TOKEN=gh-token PR_REOPEN_TOKEN=$pat PATH="$FAKE/bin:$PATH" bash "$SCRIPT" o/r "$@" >"$FAKE/out" 2>&1 || RC=$?
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
		sed 's/^/     | /' "$FAKE/out"
	fi
}

main
