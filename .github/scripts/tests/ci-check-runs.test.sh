#!/usr/bin/env bash
#
# ci-check-runs.sh のテスト(TC-367-01〜・TC-387-01〜・TC-776-01〜)。gh を、決まった JSON を返す偽物に差し替えて流す。GitHub には触らない。
# ai-auto-merge.yml と同じく、出た配列から「終わっていない」「失敗している」チェックの名前を出して確かめる。
#
# 使い方: .github/scripts/tests/ci-check-runs.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../ci-check-runs.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT

FAILED=0
PASSED=0

main() {
	setup_fake_gh
	# check suite の id: 1 = test.yml(pull_request)、2 = pr-status.yml(pull_request_target)、3 = notify-needs-human.yml(pull_request_target)、
	# 4 = ai-review-loop.yml(pull_request_review)、5 = ai-review-loop.yml(pull_request_review_comment)、6 = evidence-gate.yml(pull_request)、
	# 7 = ci-rerun.yml(pull_request_target)、8 = test.yml(workflow_dispatch)、9 = CodeRabbit(Actions の実行なし)、
	# 10 = CodeQL の既定の設定(dynamic)、11 = GitHub Actions 以外のアプリ(CodeQL の結果。Actions の実行なし)
	RUNS="[$(wf test.yml pull_request 1),$(wf pr-status.yml pull_request_target 2),$(wf notify-needs-human.yml pull_request_target 3),\
		$(wf ai-review-loop.yml pull_request_review 4),$(wf ai-review-loop.yml pull_request_review_comment 5),\
		$(wf evidence-gate.yml pull_request 6),$(wf ci-rerun.yml pull_request_target 7),$(wf test.yml workflow_dispatch 8),\
		$(jq -nc '{path: "dynamic/github-code-scanning/codeql", event: "dynamic", check_suite_id: 10}')]"

	check TC-367-01 'label だけが実行中なら、それを理由に見送らない(#353)' \
		"[$(run backend 1 completed success),$(run frontend 1 completed success),$(run label 2 in_progress null)]" \
		'pending=|failed=|names=backend,frontend'
	check TC-367-02 'notify・digest・mark-done・rerun・review・resume が実行中・失敗でも数えない' \
		"[$(run backend 1 completed success),$(run notify 3 queued null),$(run digest 3 in_progress null),$(run mark-done 3 completed failure),\
$(run rerun 7 in_progress null),$(run review 4 in_progress null),$(run resume 5 completed failure)]" \
		'pending=|failed=|names=backend'
	check TC-367-03 'CI のジョブが終わっていなければ、これまでどおり出す' \
		"[$(run backend 1 in_progress null),$(run e2e 1 queued null),$(run label 2 completed success)]" \
		'pending=backend,e2e|failed=backend,e2e|names=backend,e2e'
	check TC-367-04 'CI のジョブが失敗していれば、これまでどおり出す' \
		"[$(run backend 1 completed success),$(run backend-lint 1 completed failure),$(run label 2 in_progress null)]" \
		'pending=|failed=backend-lint|names=backend,backend-lint'
	check TC-367-05 'evidence-gate(pull_request)は CI として残す' \
		"[$(run evidence-gate 6 completed failure),$(run label 2 in_progress null)]" \
		'pending=|failed=evidence-gate|names=evidence-gate'
	check TC-367-06 'workflow_dispatch で流した CI は CI として残す' \
		"[$(run frontend 8 completed failure)]" \
		'pending=|failed=frontend|names=frontend'
	check TC-367-07 'Actions の実行に結び付かないチェック(CodeRabbit)は残す' \
		"[$(run CodeRabbit 9 in_progress null coderabbitai)]" \
		'pending=CodeRabbit|failed=CodeRabbit|names=CodeRabbit'
	check TC-367-08 '自分のジョブ(auto-merge)は、どのイベントの回でも除く' \
		"[$(run auto-merge 8 in_progress null),$(run backend 1 completed success)]" \
		'pending=|failed=|names=backend'
	check TC-367-09 '開発の仕組みのジョブしか無ければ、空の配列になる(「チェックが 1 つも無い」で見送る)' \
		"[$(run label 2 completed success),$(run notify 3 completed skipped)]" \
		'pending=|failed=|names='
	check_fails TC-367-10 'チェックを読めないときは失敗にする(「CI のチェックなし」と取り違えない)' check-runs
	check_fails TC-367-11 'ワークフローの実行を読めないときは失敗にする(開発の仕組みのジョブを CI と取り違えない)' runs

	# 終わっても ai-auto-merge.yml を呼び直すものが無いチェック(CodeQL の Analyze など)は、終わるまで待ってから出す(#387)。
	# 2 つめの引数の後の JSON は、2 回目以降に読んだときのチェック
	local ci_ok
	ci_ok="$(run backend 1 completed success),$(run evidence-gate 6 completed success)"
	check TC-387-01 '呼び直すものが無いチェックだけが実行中なら、終わるまで待ち、終わった結果を出す' \
		"[$ci_ok,$(run 'Analyze (actions)' 10 in_progress null)]" \
		'pending=|failed=|names=backend,evidence-gate,Analyze (actions)' \
		"[$ci_ok,$(run 'Analyze (actions)' 10 completed success)]"
	check TC-387-02 '待った後に失敗していれば、失敗として出す(マージを止める)' \
		"[$ci_ok,$(run 'Analyze (actions)' 10 queued null)]" \
		'pending=|failed=Analyze (actions)|names=backend,evidence-gate,Analyze (actions)' \
		"[$ci_ok,$(run 'Analyze (actions)' 10 completed failure)]"
	check TC-387-03 'Actions の実行に結び付かないアプリのチェック(CodeQL)も、実行中なら待つ' \
		"[$ci_ok,$(run CodeQL 11 in_progress null github-advanced-security)]" \
		'pending=|failed=|names=backend,evidence-gate,CodeQL' \
		"[$ci_ok,$(run CodeQL 11 completed neutral github-advanced-security)]"
	check TC-387-04 '上限まで待っても終わらなければ、実行中のまま出す(止める側に倒す)' \
		"[$ci_ok,$(run 'Analyze (actions)' 10 in_progress null)]" \
		'pending=Analyze (actions)|failed=Analyze (actions)|names=backend,evidence-gate,Analyze (actions)|warning'
	check TC-387-05 'CI(test.yml)が実行中なら待たない(終わると呼び直される)' \
		"[$(run backend 1 in_progress null),$(run 'Analyze (actions)' 10 in_progress null)]" \
		'pending=backend,Analyze (actions)|failed=backend,Analyze (actions)|names=backend,Analyze (actions)' \
		"[$(run backend 1 completed success),$(run 'Analyze (actions)' 10 completed success)]"
	check TC-387-06 'evidence-gate が実行中なら待たない(終わると呼び直される)' \
		"[$(run evidence-gate 6 queued null),$(run CodeQL 11 in_progress null github-advanced-security)]" \
		'pending=evidence-gate,CodeQL|failed=evidence-gate,CodeQL|names=evidence-gate,CodeQL' \
		"[$(run evidence-gate 6 completed success),$(run CodeQL 11 completed success github-advanced-security)]"
	check TC-387-07 'どれかが失敗していれば待たない(待っても見送りになる)' \
		"[$(run backend 1 completed failure),$(run 'Analyze (actions)' 10 in_progress null)]" \
		'pending=Analyze (actions)|failed=backend,Analyze (actions)|names=backend,Analyze (actions)' \
		"[$(run backend 1 completed failure),$(run 'Analyze (actions)' 10 completed success)]"
	check TC-387-08 'CodeRabbit が実行中なら待たない(レビューのイベントで呼び直される)' \
		"[$ci_ok,$(run CodeRabbit 9 in_progress null coderabbitai)]" \
		'pending=CodeRabbit|failed=CodeRabbit|names=backend,evidence-gate,CodeRabbit' \
		"[$ci_ok,$(run CodeRabbit 9 completed success coderabbitai)]"
	check TC-387-09 '開発の仕組みのジョブ(label・mark-done)が実行中でも待たない(数えない。#387 の見送りの理由)' \
		"[$ci_ok,$(run label 2 in_progress null),$(run mark-done 3 queued null)]" \
		'pending=|failed=|names=backend,evidence-gate|nowait'
	check_fails TC-387-10 '待っている間にチェックを読めなくなったら失敗にする(途中の結果で判定しない)' check-runs.2 \
		"[$(run backend 1 completed success),$(run 'Analyze (actions)' 10 in_progress null)]"

	# head のワークフローの実行は、コメント・ラベルのたびに増え、一覧が引数 1 つの上限(128 KiB)を超える(#776)
	check_large_runs TC-776-01 'ワークフローの実行の一覧が 128 KiB を超えても、チェックを出す' \
		"[$(run backend 1 completed success),$(run label 2 in_progress null)]" \
		'pending=|failed=|names=backend'

	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

# gh api(check-runs / actions/runs)に、$FAKE に置いた JSON を返す。--slurp ならページの配列にそろえる。
# $FAKE/fail-<check-runs|runs> があれば、その API を失敗させる(fail-<API>.<回数> なら、その回だけ)
setup_fake_gh() {
	cat >"$FAKE/gh" <<-'EOF'
		#!/usr/bin/env bash
		# 読んだ回数を API ごとに数え、<API>.<回数>.json があればそれを返す(無ければ <API>.json)
		slurp=false path=
		while [ $# -gt 0 ]; do
		  case "$1" in
		    --slurp) slurp=true ;;
		    api | --paginate) ;;
		    *) path=$1 ;;
		  esac
		  shift
		done
		case "$path" in
		  */check-runs*) file=check-runs ;;
		  */actions/runs*) file=runs ;;
		  *) echo "unexpected gh $path" >&2; exit 1 ;;
		esac
		n=$(($(cat "$FAKE_DIR/count-$file" 2>/dev/null || echo 0) + 1))
		echo "$n" >"$FAKE_DIR/count-$file"
		if [ -e "$FAKE_DIR/fail-$file" ] || [ -e "$FAKE_DIR/fail-$file.$n" ]; then echo "HTTP 502" >&2; exit 1; fi
		body="$FAKE_DIR/$file.json"
		if [ -e "$FAKE_DIR/$file.$n.json" ]; then body="$FAKE_DIR/$file.$n.json"; fi
		if [ "$slurp" = true ]; then echo "[$(cat "$body")]"; else cat "$body"; fi
	EOF
	chmod +x "$FAKE/gh"
}

# run <名前> <check suite の id> <status> <conclusion> [アプリの slug(省略すると github-actions)]
run() {
	jq -nc --arg n "$1" --argjson id "$2" --arg s "$3" --argjson c "$(conclusion "$4")" --arg app "${5:-github-actions}" \
		'{name: $n, status: $s, conclusion: $c, check_suite: {id: $id}, app: {slug: $app}}'
}
wf() { jq -nc --arg p ".github/workflows/$1" --arg e "$2" --argjson id "$3" '{path: $p, event: $e, check_suite_id: $id}'; }
conclusion() { if [ "$1" = null ]; then echo null; else jq -nc --arg c "$1" '$c'; fi; }

# prepare <1 回目のチェック> [2 回目以降のチェック]
prepare() {
	rm -f "$FAKE"/fail-* "$FAKE"/count-* "$FAKE"/check-runs.*.json
	jq -n --argjson r "$1" '{check_runs: $r}' >"$FAKE/check-runs.json"
	if [ -n "${2:-}" ]; then jq -n --argjson r "$2" '{check_runs: $r}' >"$FAKE/check-runs.2.json"; fi
	jq -n --argjson r "$RUNS" '{workflow_runs: $r}' >"$FAKE/runs.json"
}

# ai-auto-merge.yml の判定と同じ jq で、終わっていない・失敗しているチェックと、残ったチェックの名前を出す。
# 待つ間隔は 0 秒・上限は 2 回にして流す。期待値の末尾の |warning は待ちきれなかった警告が出ること、|nowait は待たなかったこと
check() {
	local id=$1 title=$2 checks=$3 expected=$4 later=${5:-}
	prepare "$checks" "$later"
	judge "$id" "$title" "$expected"
}

# check_large_runs <ID> <名前> <チェック> <期待値>: ワークフローの実行の一覧に、1 件 5 KB ほどの実行を 40 件足し、128 KiB を超えさせて流す。
# 足した実行は push のイベントで、どのチェックの check suite とも結び付かないので、結果は足す前と変わらない
check_large_runs() {
	local id=$1 title=$2 checks=$3 expected=$4 pad
	prepare "$checks"
	pad=$(printf '%05000d' 0)
	jq -c --arg pad "$pad" '.workflow_runs += [range(0; 40) | {path: ".github/workflows/x.yml", event: "push", check_suite_id: (1000 + .), pad: $pad}]' \
		"$FAKE/runs.json" >"$FAKE/runs.large.json"
	mv "$FAKE/runs.large.json" "$FAKE/runs.json"
	judge "$id" "$title" "$expected"
}

# 用意した偽物の gh でスクリプトを流し、ai-auto-merge.yml の判定と同じ形にした結果を期待値と比べる
judge() {
	local id=$1 title=$2 expected=$3 out actual
	if out=$(PATH="$FAKE:$PATH" FAKE_DIR="$FAKE" CI_CHECK_WAIT_INTERVAL=0 CI_CHECK_WAIT_TRIES=2 bash "$SCRIPT" o/r abc1234 2>"$FAKE/stderr"); then
		actual="pending=$(jq -r '[.[] | select(.status != "completed") | .name] | join(",")' <<<"$out")"
		actual+="|failed=$(jq -r '[.[] | select(.conclusion | IN("success","skipped","neutral") | not) | .name] | join(",")' <<<"$out")"
		actual+="|names=$(jq -r 'map(.name) | join(",")' <<<"$out")"
		if grep -q '::warning::' "$FAKE/stderr"; then actual+="|warning"; fi
		if [[ $expected == *"|nowait" ]] && ! grep -q '待つ' "$FAKE/stderr"; then actual+="|nowait"; fi
	else
		actual="exit $?"
	fi
	result "$id" "$title" "$expected" "$actual"
}

# check_fails <ID> <名前> <失敗させる API(fail- の後ろ)> [チェック]
check_fails() {
	local id=$1 title=$2 api=$3 checks=${4:-"[$(run backend 1 completed success)]"} actual
	prepare "$checks"
	touch "$FAKE/fail-$api"
	if PATH="$FAKE:$PATH" FAKE_DIR="$FAKE" CI_CHECK_WAIT_INTERVAL=0 CI_CHECK_WAIT_TRIES=2 bash "$SCRIPT" o/r abc1234 >/dev/null 2>&1; then actual=ok; else actual=failed; fi
	result "$id" "$title" failed "$actual"
}

result() {
	if [ "$4" = "$3" ]; then
		PASSED=$((PASSED + 1))
		echo "ok   $1 $2"
	else
		FAILED=$((FAILED + 1))
		echo "FAIL $1 $2: expected '$3', got '$4'"
	fi
}

main
