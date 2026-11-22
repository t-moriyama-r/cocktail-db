#!/usr/bin/env bash
#
# ai-review-loop.yml の Claude Code(resume・review のジョブ)に許すツールのテスト(TC-507-xx)。
# ワークフローの --allowedTools をそのまま取り出し、PR をマージできるもの(gh api・gh pr merge など)が無いこと、
# いまの対応(コメント・ラベルの付け外し・agent-write.sh でのスレッドへの返信・解決・本文の書き換え)に要るものが残っていることを確かめる。
# GitHub には触らない。
#
# 許すツールを足すときは、ここの ALLOWED_GH(gh のコマンド)か AGENT_WRITE を同じ PR で直す(黙って広げさせないため)。
#
# 使い方: .github/scripts/tests/review-loop-tools.test.sh

# ${{ … }} を文字のまま比べるので、シングルクォートの中で展開されないのは意図どおり
# shellcheck disable=SC2016

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
WORKFLOW="$HERE/../../workflows/ai-review-loop.yml"
AGENT_WRITE_SH="$HERE/../agent-write.sh"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

FAILED=0
PASSED=0

# Claude Code に許す gh のコマンド(この形のままだけを許す)
ALLOWED_GH='Bash(gh pr diff ${{ env.PR }})
Bash(gh pr diff ${{ env.PR }} --name-only)
Bash(gh pr checks ${{ env.PR }})
Bash(gh pr comment ${{ env.PR }} --body:*)
Bash(gh pr comment ${{ env.PR }} --body-file:*)
Bash(gh pr edit ${{ env.PR }} --add-label needs-human)
Bash(gh pr edit ${{ env.PR }} --add-label needs-local)
Bash(gh pr edit ${{ env.PR }} --add-label needs-manual)
Bash(gh pr edit ${{ env.PR }} --remove-label ai-in-actions)
Bash(gh pr edit ${{ env.PR }} --remove-label needs-approval)'
# Claude Code に許す git のコマンド(この形のままだけを許す。git config:*・git <alias>:* を許すと、alias の !… でどんなコマンドも動かせる)
ALLOWED_GIT='Bash(git add:*)
Bash(git checkout --ours:*)
Bash(git checkout --theirs:*)
Bash(git commit:*)
Bash(git diff -- *)
Bash(git diff --cached -- *)
Bash(git diff --cached --name-only)
Bash(git diff --cached --stat)
Bash(git diff --cached)
Bash(git diff --check)
Bash(git diff --name-only)
Bash(git diff --stat)
Bash(git diff HEAD -- *)
Bash(git diff HEAD)
Bash(git diff)
Bash(git log --oneline -30 -- *)
Bash(git log --oneline -30)
Bash(git log -p -5 -- *)
Bash(git ls-files:*)
Bash(git merge:*)
Bash(git mv:*)
Bash(git push origin HEAD)
Bash(git push origin HEAD:${{ steps.pr.outputs.head }})
Bash(git push)
Bash(git restore:*)
Bash(git rev-parse:*)
Bash(git rm:*)
Bash(git show --stat HEAD)
Bash(git show HEAD -- *)
Bash(git show HEAD)
Bash(git status:*)'
AGENT_WRITE='Bash(bash ${{ runner.temp }}/ai-dev/agent-write.sh:*)'
# Bash 以外に許すツール
ALLOWED_OTHER='Read
Edit(./**)
Write(./**)
Glob
Grep'

main() {
	expect TC-507-01 'Claude Code に --allowedTools を渡すのは resume・review の 2 か所だけで、ジョブごとに 1 つ' \
		"$(grep -c -- '--allowedTools "' "$WORKFLOW")|$(tools_count "$WORKFLOW" resume)|$(tools_count "$WORKFLOW" review)" '2|1|1'

	expect TC-507-02 'resume・review に gh api を許さない(マージの API(PUT pulls/<番号>/merge)を呼べない)' \
		"$(entries "$WORKFLOW" resume review | grep -c '^Bash(gh api')" '0'

	expect TC-507-03 'resume・review に PR をマージできるコマンド(gh pr merge・/merge)を許さない' \
		"$(entries "$WORKFLOW" resume review | grep -v '^Bash(git merge:\*)$' | grep -ci 'merge')" '0'

	expect TC-507-04 'resume・review に許す gh・bash のコマンドは決まった形だけで、gh:*・gh pr:*・curl などの広い形が無い' \
		"$(bad_entries "$WORKFLOW" resume)|$(bad_entries "$WORKFLOW" review)" '|'

	expect TC-507-05 'いまの対応に要るツール(コメント・ラベルの付け外し・agent-write.sh)は、resume・review の両方に残っている' \
		"$(missing_entries "$WORKFLOW" resume)|$(missing_entries "$WORKFLOW" review)" '|'

	expect TC-507-06 'agent-write.sh が呼ぶ gh api は、GraphQL(返信・解決・スレッドの PR の確かめ)と、対象の PR の読み取り・本文の PATCH だけ' \
		"$(gh_api_calls "$AGENT_WRITE_SH")" '-X PATCH repos/$REPO/pulls/$PR|-X PATCH repos/$REPO/pulls/$PR|graphql|graphql|graphql|repos/$REPO/pulls/$PR'

	expect TC-507-08 'agent-write.sh の GraphQL の操作は、スレッドへの返信と解決だけ(mergePullRequest などが無い)' \
		"$(graphql_ops "$AGENT_WRITE_SH")" 'addPullRequestReviewThreadReply|resolveReviewThread'

	# 確かめ方が空振りしていないこと(広げた形を書き足すと、上の確かめで見つかること)
	local widened="$WORK/widened.yml"
	sed -e 's#--allowedTools "Read,#--allowedTools "Read,Bash(gh api:*),Bash(gh pr merge ${{ env.PR }}:*),Bash(gh pr:*),Bash(curl:*),#' "$WORKFLOW" >"$widened"
	expect TC-507-07 'gh api・gh pr merge・gh pr:*・curl を足した形は、resume・review のどちらでも見つかる' \
		"$(entries "$widened" resume review | grep -c '^Bash(gh api')|$(entries "$widened" resume review | grep -v '^Bash(git merge:\*)$' | grep -ci 'merge')|$(bad_entries "$widened" resume | tr '\n' ' ')" \
		'2|2|Bash(gh api:*) Bash(gh pr merge ${{ env.PR }}:*) Bash(gh pr:*) Bash(curl:*) '

	local aliased="$WORK/aliased.yml" merging="$WORK/merging.sh"
	sed -e 's#--allowedTools "Read,#--allowedTools "Read,Bash(git config:*),Bash(git publish:*),#' "$WORKFLOW" >"$aliased"
	sed -e 's/resolveReviewThread(input/mergePullRequest(input/' "$AGENT_WRITE_SH" >"$merging"
	expect TC-507-09 'git config・git publish を足した形と、GraphQL を mergePullRequest に替えた形は見つかる' \
		"$(bad_entries "$aliased" resume | tr '\n' ' ')|$(graphql_ops "$merging")" \
		'Bash(git config:*) Bash(git publish:*) |addPullRequestReviewThreadReply|mergePullRequest'

	echo "passed: $PASSED, failed: $FAILED"
	[[ $FAILED -eq 0 ]]
}

# ジョブ(<file> の `  <job>:` から次のジョブまで)の行を出す
job_lines() {
	awk -v job="  $2:" '
		/^  [a-z][a-z0-9-]*:$/ { in_job = ($0 == job) }
		in_job { print }
	' "$1"
}

tools_count() {
	job_lines "$1" "$2" | grep -c -- '--allowedTools "'
}

# ジョブの --allowedTools の項目を 1 行に 1 つ出す(複数のジョブを渡すと、続けて出す)
entries() {
	local file=$1 job
	shift
	for job in "$@"; do
		job_lines "$file" "$job" | sed -n 's/.*--allowedTools "\([^"]*\)".*/\1/p' | tr ',' '\n'
	done
}

# 決まった形でない項目を出す
bad_entries() {
	local e
	while IFS= read -r e; do
		case "$e" in
		'Bash(git'*) grep -qxF -- "$e" <<<"$ALLOWED_GIT" || echo "$e" ;; # git:*・git config:* などは alias.x='!…' でどんなコマンドも動かせる
		'Bash('*)
			grep -qxF -- "$e" <<<"$ALLOWED_GH" || [[ $e == "$AGENT_WRITE" ]] || echo "$e"
			;;
		*) grep -qxF -- "$e" <<<"$ALLOWED_OTHER" || echo "$e" ;;
		esac
	done < <(entries "$1" "$2")
}

# いまの対応に要るのに無い項目を出す
missing_entries() {
	local list e
	list=$(entries "$1" "$2")
	while IFS= read -r e; do
		case "$e" in
		*'gh pr comment'* | *'--add-label'* | *'--remove-label'*) grep -qxF -- "$e" <<<"$list" || echo "$e" ;;
		esac
	done <<<"$ALLOWED_GH"
	grep -qxF -- "$AGENT_WRITE" <<<"$list" || echo "$AGENT_WRITE"
}

# スクリプトの gh api の呼び出しを、メソッドとパス(graphql)だけにし、並べ替えて | でつなぐ(書く順を変えただけでは落ちないように)
gh_api_calls() {
	grep -o 'gh api \(-X [A-Z]* \)\?[^ ]*' "$1" | sed -e 's/^gh api //' -e 's/"//g' | LC_ALL=C sort | paste -sd'|' -
}

# スクリプトの GraphQL の操作名(input を取るもの)を並べ替えて | でつなぐ
graphql_ops() {
	grep -o '[A-Za-z]*(input:' "$1" | sed -e 's/(input://' | LC_ALL=C sort -u | paste -sd'|' -
}

expect() {
	local id=$1 name=$2 actual=$3 expected=$4
	if [[ "$actual" == "$expected" ]]; then
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
