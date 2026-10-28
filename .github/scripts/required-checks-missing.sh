#!/usr/bin/env bash
# 向き先のブランチの保護で必須にしたチェックのうち、コミットに成功(skipped・neutral を含む)で付いていないものを出す
#
# 使い方: required-checks-missing.sh <owner/repo> <向き先のブランチ> <コミット>
#
# 足りないものが無ければ何も出さない。あれば「backend,frontend」のようにカンマでつないで 1 行で出し、
# そのコミットに承認待ち(action_required)のワークフローの実行があれば「(承認待ちの実行: test)」を続ける。
#
# 走らなかったワークフローのチェックはコミットに付かないので、付いたチェックに失敗が無いかを見るだけでは、
# 必須のチェックが無いままでも緑に見える。bot が push したコミットの CI が、人の承認を待って走らないときに起きる(#348)
set -euo pipefail

repo=$1
base=$2
head=$3

branch=$(gh api "repos/$repo/branches/$base")
# 保護されているのに中身が読めないときは、必須のチェックが無いと取り違えないよう失敗にする
if jq -e '.protected == true and .protection == null' <<<"$branch" >/dev/null; then
  echo "$base の保護の中身を読めない" >&2
  exit 1
fi
required=$(jq '[.protection.required_status_checks.contexts // [] | .[]]' <<<"$branch")
passed=$(gh api --paginate --slurp "repos/$repo/commits/$head/check-runs?per_page=100" \
  | jq '[.[].check_runs[] | select(.status == "completed" and (.conclusion | IN("success","skipped","neutral"))) | .name]')
missing=$(jq -rn --argjson required "$required" --argjson passed "$passed" '$required - $passed | join(",")')
[ -n "$missing" ] || exit 0

waiting=$(gh api "repos/$repo/actions/runs?head_sha=$head&per_page=100" \
  --jq '[.workflow_runs[] | select(.conclusion == "action_required") | .name] | unique | join(",")')
echo "$missing${waiting:+(承認待ちの実行: $waiting)}"
