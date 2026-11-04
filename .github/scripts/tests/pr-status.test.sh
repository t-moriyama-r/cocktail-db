#!/usr/bin/env bash
#
# pr-status.sh decide のテスト(TC-216-xx)。事実の JSON から、どの状態のラベルになるかを確かめる。
# GitHub には触らない。
#
# 使い方: .github/scripts/tests/pr-status.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../pr-status.sh"

FAILED=0
PASSED=0

# レビューが済み、指摘も CI の失敗も無く、GitHub がマージを許す PR。各ケースはここから変える
BASE='{"state":"OPEN","isDraft":false,"mergeable":"MERGEABLE","mergeStateStatus":"CLEAN","labels":[],"failedChecks":0,"pendingChecks":0,"unansweredThreads":0,"unresolvedThreads":0,"headReviewed":true}'

main() {
	check TC-216-01 '閉じた PR はラベルなし' '.state = "MERGED"' ''
	check TC-216-02 '下書き' '.isDraft = true' '状態:作業中'
	check TC-216-03 '下書きは、人の判断待ち・手元の作業待ち以外の条件より先' '.isDraft = true | .labels = ["needs-approval", "ai-in-actions"] | .mergeable = "CONFLICTING" | .failedChecks = 1 | .unansweredThreads = 1 | .headReviewed = false' '状態:作業中'
	check TC-216-04 'needs-human' '.labels = ["needs-human"]' '状態:人の判断待ち'
	check TC-216-05 'needs-decision' '.labels = ["task", "needs-decision"]' '状態:人の判断待ち'
	check TC-216-06 'needs-approval' '.labels = ["needs-approval"]' '状態:人のマージ待ち'
	check TC-216-07 '人の判断待ちは、人のマージ待ちより先' '.labels = ["needs-approval", "needs-human"]' '状態:人の判断待ち'
	check TC-216-08 'コンフリクト' '.mergeable = "CONFLICTING"' '状態:コンフリクト'
	check TC-216-09 'mergeable が UNKNOWN ならコンフリクトとみなさない' '.mergeable = "UNKNOWN"' '状態:マージ待ち'
	check TC-216-10 'CI の失敗' '.failedChecks = 2' '状態:CI失敗'
	check TC-216-11 'コンフリクトは CI の失敗より先' '.mergeable = "CONFLICTING" | .failedChecks = 1' '状態:コンフリクト'
	check TC-216-12 'Actions が対応している' '.labels = ["ai-in-actions"]' '状態:レビュー対応中'
	check TC-216-13 '返信待ちの指摘がある' '.unansweredThreads = 1' '状態:レビュー対応中'
	check TC-216-14 'レビュー対応中は、いまのコミットが未レビューでも先' '.unansweredThreads = 1 | .headReviewed = false' '状態:レビュー対応中'
	check TC-216-15 'いまのコミットがまだレビューされていない' '.headReviewed = false' '状態:レビュー待ち'
	check TC-216-16 'それ以外はマージ待ち' '.' '状態:マージ待ち'
	check TC-216-17 '関係の無いラベルは見ない' '.labels = ["task", "area:infra", "no-auto-merge", "状態:レビュー待ち"]' '状態:マージ待ち'

	# 昇格の PR(向き先が staging・release)。指摘が残ってもマージを止めず、Actions の Claude Code の対応も待たない
	check TC-332-01 'develop 向けで返信待ちの指摘がある(従来どおり)' '.baseRefName = "develop" | .unansweredThreads = 2' '状態:レビュー対応中'
	check TC-332-02 'staging 向けは、返信待ちの指摘があっても人のマージ待ち' '.baseRefName = "staging" | .unansweredThreads = 2' '状態:人のマージ待ち'
	check TC-332-03 'release 向けは、返信待ちの指摘があっても人のマージ待ち' '.baseRefName = "release" | .unansweredThreads = 1' '状態:人のマージ待ち'
	check TC-332-04 'staging 向けで ai-in-actions が残っていても、レビュー対応中にしない' '.baseRefName = "staging" | .labels = ["ai-in-actions"]' '状態:人のマージ待ち'
	check TC-332-05 'staging 向けで、いまのコミットが未レビューならレビュー待ち' '.baseRefName = "staging" | .headReviewed = false | .unansweredThreads = 1' '状態:レビュー待ち'
	check TC-332-06 'staging 向けでも CI の失敗が先' '.baseRefName = "staging" | .failedChecks = 1' '状態:CI失敗'
	check TC-332-07 'staging 向けでもコンフリクトが先' '.baseRefName = "staging" | .mergeable = "CONFLICTING"' '状態:コンフリクト'
	check TC-332-08 'staging 向けで needs-human は人の判断待ち' '.baseRefName = "staging" | .labels = ["needs-human"]' '状態:人の判断待ち'
	check TC-332-09 'staging 向けで needs-approval は人のマージ待ち' '.baseRefName = "staging" | .labels = ["needs-approval"] | .headReviewed = false' '状態:人のマージ待ち'
	check TC-332-10 'staging 向けの下書きは作業中' '.baseRefName = "staging" | .isDraft = true' '状態:作業中'
	check TC-332-11 'develop・main など、昇格でない向き先は従来どおり' '.baseRefName = "issue-1-base" | .unansweredThreads = 1' '状態:レビュー対応中'

	# マージ待ち・人のマージ待ちは、GitHub がマージを許すときだけ。止めている理由ごとにラベルを分ける
	check TC-385-01 '返信は済んだが、未解決のスレッドがある' '.unresolvedThreads = 3 | .mergeStateStatus = "BLOCKED"' '状態:指摘の解決待ち'
	check TC-385-02 '必須のチェックが 1 つも付いていない(BLOCKED で、ほかの理由が無い)' '.mergeStateStatus = "BLOCKED"' '状態:CI未実行'
	check TC-385-03 '必須のチェックが実行中' '.pendingChecks = 2 | .mergeStateStatus = "BLOCKED"' '状態:CI実行中'
	check TC-385-04 'CI 実行中は、指摘の解決待ちより先' '.pendingChecks = 1 | .unresolvedThreads = 1 | .mergeStateStatus = "BLOCKED"' '状態:CI実行中'
	check TC-385-05 'レビュー待ちは、CI 実行中より先' '.headReviewed = false | .pendingChecks = 1 | .mergeStateStatus = "BLOCKED"' '状態:レビュー待ち'
	check TC-385-06 'レビュー対応中は、指摘の解決待ちより先' '.unansweredThreads = 1 | .unresolvedThreads = 2 | .mergeStateStatus = "BLOCKED"' '状態:レビュー対応中'
	check TC-385-07 'GitHub が計算中(UNKNOWN)' '.mergeStateStatus = "UNKNOWN"' '状態:確認中'
	check TC-385-08 'ほかの理由でマージできない(BEHIND)' '.mergeStateStatus = "BEHIND"' '状態:マージ不可'
	check TC-385-09 '必須でないチェックの失敗(UNSTABLE)はマージできる' '.mergeStateStatus = "UNSTABLE"' '状態:マージ待ち'
	check TC-385-10 'HAS_HOOKS はマージできる' '.mergeStateStatus = "HAS_HOOKS"' '状態:マージ待ち'
	check TC-385-11 'needs-approval でも、未解決のスレッドがあれば指摘の解決待ち' '.labels = ["needs-approval"] | .unresolvedThreads = 1 | .mergeStateStatus = "BLOCKED"' '状態:指摘の解決待ち'
	check TC-385-12 'needs-approval でも、コンフリクトならコンフリクト' '.labels = ["needs-approval"] | .mergeable = "CONFLICTING" | .mergeStateStatus = "DIRTY"' '状態:コンフリクト'
	check TC-385-13 'needs-approval でも、必須のチェックが付いていなければ CI未実行' '.labels = ["needs-approval"] | .mergeStateStatus = "BLOCKED"' '状態:CI未実行'
	check TC-385-14 'needs-approval は、ai-in-actions が残っていてもレビュー対応中にしない' '.labels = ["needs-approval", "ai-in-actions"]' '状態:人のマージ待ち'
	check TC-385-15 'staging 向けで、指摘に返信が済み未解決のスレッドがある' '.baseRefName = "staging" | .unresolvedThreads = 2 | .mergeStateStatus = "BLOCKED"' '状態:指摘の解決待ち'
	check TC-385-16 'staging 向けで、返信待ちの指摘があり未解決' '.baseRefName = "staging" | .unansweredThreads = 1 | .unresolvedThreads = 1 | .mergeStateStatus = "BLOCKED"' '状態:指摘の解決待ち'
	check TC-385-17 'release 向けで、必須のチェックが付いていない' '.baseRefName = "release" | .mergeStateStatus = "BLOCKED"' '状態:CI未実行'
	check TC-456-01 'needs-local は見回り待ち(#496 で手元の作業待ちから分けた)' '.labels = ["needs-local"] | .failedChecks = 1' '状態:見回り待ち(証跡など)'
	check TC-456-02 '人の判断待ちは、見回り待ちより先' '.labels = ["needs-local", "needs-human"]' '状態:人の判断待ち'
	check TC-456-03 '見回り待ちは、下書きより先' '.isDraft = true | .labels = ["needs-local"]' '状態:見回り待ち(証跡など)'

	# 人が手を動かさないと進まない作業(needs-manual)は手元の作業待ち。見回りが片付ける needs-local と分ける(#496)
	check TC-496-01 'needs-manual は手元の作業待ち' '.labels = ["needs-manual"] | .failedChecks = 1' '状態:手元の作業待ち'
	check TC-496-02 'needs-manual と needs-local の両方なら、待っていても進まない手元の作業待ち' '.labels = ["needs-local", "needs-manual"]' '状態:手元の作業待ち'
	check TC-496-03 '人の判断待ちは、手元の作業待ちより先' '.labels = ["needs-manual", "needs-human"]' '状態:人の判断待ち'
	check TC-496-04 '下書きで needs-manual' '.isDraft = true | .labels = ["needs-manual"]' '状態:手元の作業待ち'
	check TC-496-05 '閉じた PR は、needs-manual があってもラベルなし' '.state = "CLOSED" | .labels = ["needs-manual"]' ''

	# 下書きでも、人の判断待ち・手元の作業待ち・見回り待ちのラベルがあれば、それを出す(#471)
	check TC-471-01 '下書きで needs-local' '.isDraft = true | .labels = ["needs-local"] | .failedChecks = 1' '状態:見回り待ち(証跡など)'
	check TC-471-02 '下書きで needs-human' '.isDraft = true | .labels = ["needs-human"] | .failedChecks = 1' '状態:人の判断待ち'
	check TC-471-03 '下書きで needs-decision' '.isDraft = true | .labels = ["task", "needs-decision"]' '状態:人の判断待ち'
	check TC-471-04 '下書きで needs-local と needs-human の両方' '.isDraft = true | .labels = ["needs-local", "needs-human"]' '状態:人の判断待ち'
	check TC-471-05 '下書きで needs-local と needs-decision の両方' '.isDraft = true | .labels = ["needs-decision", "needs-local"]' '状態:人の判断待ち'
	check TC-471-06 '下書きで待ちのラベルが無い' '.isDraft = true | .labels = ["task"]' '状態:作業中'
	check TC-471-07 '下書きで needs-approval だけ(待ちのラベルではない)' '.isDraft = true | .labels = ["needs-approval"]' '状態:作業中'
	check TC-471-08 '閉じた下書きは、needs-local があってもラベルなし' '.state = "CLOSED" | .isDraft = true | .labels = ["needs-local"]' ''

	# 積み上げの PR(向き先が develop・staging・release 以外)。ai-auto-merge.yml は見送るので、マージ待ちにしない
	check TC-460-01 '積み上げの PR で、GitHub がマージを許す' '.baseRefName = "issue-47-affiliate-api"' '状態:ベースブランチ待ち'
	check TC-460-02 'develop 向けは、これまでどおりマージ待ち' '.baseRefName = "develop"' '状態:マージ待ち'
	check TC-460-03 'staging 向けは、これまでどおり人のマージ待ち' '.baseRefName = "staging"' '状態:人のマージ待ち'
	check TC-460-04 'release 向けは、これまでどおり人のマージ待ち' '.baseRefName = "release"' '状態:人のマージ待ち'
	check TC-460-05 '積み上げの PR でも、コンフリクトはコンフリクト' '.baseRefName = "issue-47-affiliate-api" | .mergeable = "CONFLICTING" | .mergeStateStatus = "DIRTY"' '状態:コンフリクト'
	check TC-460-06 '積み上げの PR でも、GitHub がマージを許さなければ(BEHIND)マージ不可' '.baseRefName = "issue-47-affiliate-api" | .mergeStateStatus = "BEHIND"' '状態:マージ不可'
	check TC-460-07 '積み上げの PR でも、needs-approval は人のマージ待ち(人のマージ待ちの後に判定する)' '.baseRefName = "issue-47-affiliate-api" | .labels = ["needs-approval"]' '状態:人のマージ待ち'
	check TC-460-08 '積み上げの PR でも、UNSTABLE(必須でないチェックの失敗)はベースブランチ待ち' '.baseRefName = "issue-47-affiliate-api" | .mergeStateStatus = "UNSTABLE"' '状態:ベースブランチ待ち'
	check TC-460-09 '積み上げの PR でも、下書きは作業中' '.baseRefName = "issue-47-affiliate-api" | .isDraft = true' '状態:作業中'
	check TC-460-10 '積み上げの PR でも、いまのコミットが未レビューならレビュー待ち' '.baseRefName = "issue-47-affiliate-api" | .headReviewed = false' '状態:レビュー待ち'
	check TC-460-11 '事実に向き先が無いときは develop 向けとみなす(従来どおりマージ待ち)' 'del(.baseRefName)' '状態:マージ待ち'
	check TC-460-12 '向き先が空文字のときも develop 向けとみなす' '.baseRefName = ""' '状態:マージ待ち'

	# 承認のルール(develop のルールセット。オーナー以外の PR は承認が要る。管理者は bypass できる)で BLOCKED になっているだけの PR(#660)
	REVIEW_ONLY='.mergeStateStatus = "BLOCKED" | .reviewDecision = "REVIEW_REQUIRED" | .requiredChecksComplete = true'
	check TC-660-01 'オーナーの PR で、必須のチェックが揃い、承認のルールだけで BLOCKED ならマージ待ち' "$REVIEW_ONLY | .byOwner = true" '状態:マージ待ち'
	check TC-660-02 'オーナーの PR で needs-approval なら人のマージ待ち(#625 の形)' "$REVIEW_ONLY"' | .byOwner = true | .labels = ["needs-approval"]' '状態:人のマージ待ち'
	check TC-660-03 'オーナー以外の PR は承認のレビュー待ち' "$REVIEW_ONLY | .byOwner = false" '状態:承認のレビュー待ち'
	check TC-660-04 'オーナー以外の PR は、needs-approval でも承認のレビュー待ち' "$REVIEW_ONLY"' | .byOwner = false | .labels = ["needs-approval"]' '状態:承認のレビュー待ち'
	check TC-660-05 '作者が事実に無い(byOwner が無い)ときはオーナー以外とみなす' "$REVIEW_ONLY" '状態:承認のレビュー待ち'
	check TC-660-06 'オーナーの PR でも、必須のチェックが揃っていなければ CI未実行' "$REVIEW_ONLY | .byOwner = true | .requiredChecksComplete = false" '状態:CI未実行'
	check TC-660-07 'オーナー以外の PR でも、必須のチェックが揃っていなければ CI未実行' "$REVIEW_ONLY | .byOwner = false | .requiredChecksComplete = false" '状態:CI未実行'
	check TC-660-08 '必須のチェックが揃っていて、承認でない理由で BLOCKED ならマージ不可' '.mergeStateStatus = "BLOCKED" | .reviewDecision = "APPROVED" | .requiredChecksComplete = true | .byOwner = true' '状態:マージ不可'
	check TC-660-09 'オーナーの PR でも、未解決のスレッドがあれば指摘の解決待ち' "$REVIEW_ONLY | .byOwner = true | .unresolvedThreads = 1" '状態:指摘の解決待ち'
	check TC-660-10 'オーナーの PR でも、実行中の必須のチェックがあれば CI実行中' "$REVIEW_ONLY | .byOwner = true | .pendingChecks = 1" '状態:CI実行中'
	check TC-660-11 'オーナー以外の PR でも、GitHub がマージを許す(CLEAN)ならマージ待ち' '.reviewDecision = "REVIEW_REQUIRED" | .byOwner = false | .requiredChecksComplete = true' '状態:マージ待ち'
	check TC-660-19 'オーナーの積み上げの PR は、承認のルールだけで BLOCKED ならベースブランチ待ち' "$REVIEW_ONLY"' | .byOwner = true | .baseRefName = "issue-1-base"' '状態:ベースブランチ待ち'

	# facts: gh をスタブにして、必須のチェックの終了コードごとに数えられるかを確かめる
	check_checks TC-385-18 '必須のチェックが失敗(gh は終了コード 1 で、JSON を標準出力に出す)' 1 '[{"bucket":"fail"},{"bucket":"pass"}]' '' '1 0'
	check_checks TC-385-19 '必須のチェックが実行中(終了コード 8)' 8 '[{"bucket":"pending"},{"bucket":"pass"}]' '' '0 1'
	check_checks TC-385-20 '必須のチェックがすべて成功(終了コード 0)' 0 '[{"bucket":"pass"}]' '' '0 0'
	check_checks TC-385-21 '必須のチェックが 1 つも付いていない(終了コード 1、標準出力は空)' 1 '' 'no required checks reported on the branch' '0 0'
	check_checks TC-385-22 '必須のチェックを読めない(JSON でも未付与でもない)' 1 '' 'HTTP 500' 'error'

	# facts: BLOCKED のとき、いまのコミットの必須のチェックが成功で揃っているか・作者がオーナーかを事実に入れる(#660)。
	# スタブはブランチ・保護の API をすべて 403 で断る(Actions の GITHUB_TOKEN と同じく、保護を読めない)
	ALL5='[{"name":"backend","bucket":"pass"},{"name":"backend-lint","bucket":"pass"},{"name":"frontend","bucket":"pass"},{"name":"actionlint","bucket":"pass"},{"name":"e2e","bucket":"pass"}]'
	check_blocked TC-660-12 'オーナーの PR で、必須のチェックがすべて成功(保護を読めなくても決まる)' o "$ALL5" 'true true REVIEW_REQUIRED 状態:マージ待ち'
	check_blocked TC-660-13 'draft のときに飛ばした回のチェック(すべて skipping)しか無い' o "$(jq -c 'map(.bucket = "skipping")' <<<"$ALL5")" 'false true REVIEW_REQUIRED 状態:CI未実行'
	check_blocked TC-660-14 '必須のチェックが 1 つも付いていない' o '[]' 'false true REVIEW_REQUIRED 状態:CI未実行'
	check_blocked TC-660-15 '成功と skipping が混じる(release 向けで E2E だけ飛ばした回など)' o "$(jq -c '.[4].bucket = "skipping"' <<<"$ALL5")" 'true true REVIEW_REQUIRED 状態:マージ待ち'
	check_blocked TC-660-16 '必須のチェックが 1 つだけで成功(境界)' o '[{"name":"backend","bucket":"pass"}]' 'true true REVIEW_REQUIRED 状態:マージ待ち'
	check_blocked TC-660-17 '取り消された必須のチェックがある' o "$(jq -c '.[4].bucket = "cancel"' <<<"$ALL5")" 'false true REVIEW_REQUIRED 状態:CI未実行'
	check_blocked TC-660-18 'オーナー以外の PR で、必須のチェックがすべて成功' someone "$ALL5" 'true false REVIEW_REQUIRED 状態:承認のレビュー待ち'

	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

check_checks() {
	local id=$1 title=$2 rc=$3 out=$4 err=$5 expected=$6 actual bin
	bin=$(mktemp -d)
	cat >"$bin/gh" <<'STUB'
#!/usr/bin/env bash
case "$1 $2" in
"pr checks")
	printf '%s' "$STUB_OUT"
	printf '%s' "$STUB_ERR" >&2
	exit "$STUB_RC"
	;;
"pr view")
	echo '{"state":"OPEN","isDraft":false,"labels":[],"mergeable":"MERGEABLE","mergeStateStatus":"CLEAN","baseRefName":"develop","headRefOid":"abc","commits":[{"committedDate":"2026-01-01T00:00:00Z"}]}'
	;;
"api graphql") echo 0 ;;
*) echo '[]' ;;
esac
STUB
	chmod +x "$bin/gh"
	actual=$(PATH="$bin:$PATH" STUB_RC=$rc STUB_OUT=$out STUB_ERR=$err bash "$SCRIPT" o/r 1 --dry-run 2>/dev/null |
		sed 's/^#1 //; s/ => .*//' | jq -r '"\(.failedChecks) \(.pendingChecks)"' 2>/dev/null) || actual=
	[ -n "$actual" ] || actual=error
	rm -rf "$bin"
	if [ "$actual" = "$expected" ]; then
		PASSED=$((PASSED + 1))
		echo "ok   $id $title"
	else
		FAILED=$((FAILED + 1))
		echo "FAIL $id $title: expected '$expected', got '$actual'"
	fi
}

# BLOCKED の PR の事実を gh のスタブから集め、「requiredChecksComplete byOwner reviewDecision 状態」を比べる。
# author は PR の作者(リポジトリ o/r の持ち主は o)、checks は必須のチェック
check_blocked() {
	local id=$1 title=$2 author=$3 checks=$4 expected=$5 actual out bin
	bin=$(mktemp -d)
	cat >"$bin/gh" <<'STUB'
#!/usr/bin/env bash
# いまのコミット(abc)は CodeRabbit がレビュー済み(head-reviewed.sh が読む)
case "$*" in
*"pulls/1/reviews"*)
	echo '[[{"commit_id":"abc","user":{"login":"coderabbitai[bot]"},"body":"レビュー"}]]'
	exit 0
	;;
*"/branches"* | *"/protection"* | *"/rulesets"*)
	echo 'HTTP 403: Resource not accessible by integration' >&2
	exit 1
	;;
esac
case "$1 $2" in
"pr checks")
	if [ "$STUB_CHECKS" = '[]' ]; then
		echo 'no required checks reported on the branch' >&2
		exit 1
	fi
	printf '%s' "$STUB_CHECKS"
	;;
"pr view")
	jq -nc --arg a "$STUB_AUTHOR" '{state: "OPEN", isDraft: false, labels: [], mergeable: "MERGEABLE", mergeStateStatus: "BLOCKED",
		baseRefName: "develop", author: {login: $a}, reviewDecision: "REVIEW_REQUIRED", headRefOid: "abc",
		commits: [{committedDate: "2026-01-01T00:00:00Z"}]}'
	;;
"api graphql") echo 0 ;;
*) echo '[]' ;;
esac
STUB
	chmod +x "$bin/gh"
	out=$(PATH="$bin:$PATH" STUB_AUTHOR=$author STUB_CHECKS=$checks \
		bash "$SCRIPT" o/r 1 --dry-run 2>/dev/null) || out=
	if [ -n "$out" ]; then
		actual="$(sed 's/^#1 //; s/ => .*//' <<<"$out" | jq -r '"\(.requiredChecksComplete) \(.byOwner) \(.reviewDecision)"') ${out##* => }"
	else
		actual=error
	fi
	rm -rf "$bin"
	if [ "$actual" = "$expected" ]; then
		PASSED=$((PASSED + 1))
		echo "ok   $id $title"
	else
		FAILED=$((FAILED + 1))
		echo "FAIL $id $title: expected '$expected', got '$actual'"
	fi
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

main
