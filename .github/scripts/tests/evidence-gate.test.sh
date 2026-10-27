#!/usr/bin/env bash
#
# evidence-gate.sh のテスト(TC-380-01〜15)。gh を、決まった JSON を返す偽物に差し替えて流す。GitHub には触らない。
#
# 使い方: .github/scripts/tests/evidence-gate.test.sh

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SCRIPT="$HERE/../evidence-gate.sh"
FAKE=$(mktemp -d)
trap 'rm -rf "$FAKE"' EXIT

FAILED=0
PASSED=0

IMG='<img width="922" height="543" alt="compare" src="https://github.com/user-attachments/assets/11111111-aaaa" />'
MD_IMG='![compare](https://github.com/user-attachments/assets/22222222-bbbb)'
VIDEO_BEFORE='https://github.com/user-attachments/assets/33333333-cccc'
VIDEO_AFTER='https://github.com/user-attachments/assets/44444444-dddd'
CODE='["backend/app/Http/Controllers/HelloController.php"]'
DOCS='["README.md","docs/design/style.md",".github/workflows/test.yml","backend/tests/Feature/HelloTest.php","frontend/src/App.test.tsx","e2e/tests/layout.spec.ts","frontend/package-lock.json"]'

main() {
	setup_fake_gh

	check TC-380-01 'コードの PR に、静止画・修正前後の動画・修正前後の記載がそろっている' "$CODE" \
		"$(body "## 証跡" "修正前と修正後の比較" "$IMG" "" "修正前" "" "$VIDEO_BEFORE" "" "修正後" "" "$VIDEO_AFTER")" 0
	check TC-380-02 'コードの PR に「## 証跡」の節が無い' "$CODE" \
		"$(body "## 何をしたか" "直した")" 1 '「## 証跡」の節が無い'
	check TC-380-03 '「未撮影」が残っている(画像・動画はある)' "$CODE" \
		"$(body "## 証跡" "未撮影のものがある。修正前・修正後" "$IMG" "" "$VIDEO_BEFORE" "" "$VIDEO_AFTER")" 1 '未撮影'
	check TC-380-04 '動画が 1 本だけ' "$CODE" \
		"$(body "## 証跡" "修正前・修正後" "$IMG" "" "$VIDEO_AFTER")" 1 '(1 本)'
	check TC-380-05 '動画は 2 本あるが静止画が無い' "$CODE" \
		"$(body "## 証跡" "修正前・修正後" "" "$VIDEO_BEFORE" "" "$VIDEO_AFTER")" 1 '静止画'
	check TC-380-06 '修正前・修正後の記載が無い' "$CODE" \
		"$(body "## 証跡" "$IMG" "" "$VIDEO_BEFORE" "" "$VIDEO_AFTER")" 1 'どちらを見せているか'
	check TC-380-07 '文書・テスト・CI の設定・ロックファイルだけで、撮らない理由がある' "$DOCS" \
		"$(body "## 証跡" "文書とテストだけの変更なので撮らない" "## 確かめたこと" "CI")" 0
	check TC-380-08 '文書だけで、節が空' '["README.md"]' \
		"$(body "## 証跡" "" "## 確かめたこと" "CI")" 1 '撮らない理由'
	check TC-380-09 'テストのファイルと一緒に backend/app を変えたらコードとして扱う' \
		'["backend/tests/Feature/HelloTest.php","backend/app/Models/Item.php"]' \
		"$(body "## 証跡" "テストだけなので撮らない")" 1 'backend/app/Models/Item.php'
	check TC-380-10 '静止画が ![](…) の形でも数える' "$CODE" \
		"$(body "## 証跡" "修正前・修正後" "$MD_IMG" "" "$VIDEO_BEFORE" "" "$VIDEO_AFTER")" 0
	check TC-380-11 'ほかの節にある画像・動画は数えない' "$CODE" \
		"$(body "## 何をしたか" "修正前・修正後" "$IMG" "" "$VIDEO_BEFORE" "" "$VIDEO_AFTER" "## 証跡" "修正前・修正後は上" "## 確かめたこと" "CI")" 1 '静止画'
	check TC-380-12 '<img> に埋めた URL は動画として数えない' "$CODE" \
		"$(body "## 証跡" "修正前・修正後" "$IMG" "$IMG" "$IMG")" 1 '(0 本)'
	check TC-380-13 'ops だけを変えた PR は、撮らない理由があれば通る' '["ops/backup/backup.sh","ops/migrate/run.sh"]' \
		"$(body "## 証跡" "ops のシェルだけで画面も API も無いので撮らない")" 0
	check TC-380-14 '同じ動画の URL を 2 行書いても 1 本と数える' "$CODE" \
		"$(body "## 証跡" "修正前・修正後" "$IMG" "" "$VIDEO_BEFORE" "" "$VIDEO_BEFORE")" 1 '(1 本)'
	check TC-380-15 '文の途中の「撮れず」は宣言として扱わない' "$CODE" \
		"$(body "## 証跡" "修正前・修正後。旧は動画が撮れず静止画で比べた" "$IMG" "" "$VIDEO_BEFORE" "" "$VIDEO_AFTER")" 0

	echo "passed: $PASSED, failed: $FAILED"
	[ "$FAILED" -eq 0 ]
}

# gh pr view に、$FAKE に置いた JSON を返す
setup_fake_gh() {
	cat >"$FAKE/gh" <<-'EOF'
		#!/usr/bin/env bash
		case "$*" in
		  "pr view"*) jq -r '.body' "$FAKE_DIR/pr.json" ;;
		  "api --paginate"*) jq -r '.files[].path' "$FAKE_DIR/pr.json" ;;
		  *) echo "unexpected gh $*" >&2; exit 1 ;;
		esac
	EOF
	chmod +x "$FAKE/gh"
}

# 引数を 1 行ずつつないだ本文。本物の PR の本文に合わせて CRLF にする
body() { printf '%s\r\n' "$@"; }

check() {
	local id=$1 title=$2 files=$3 text=$4 expected=$5 message=${6:-} actual output
	jq -n --argjson files "$files" --arg body "$text" '{body: $body, files: ($files | map({path: .}))}' >"$FAKE/pr.json"
	output=$(PATH="$FAKE:$PATH" FAKE_DIR="$FAKE" bash "$SCRIPT" o/r 1 2>&1)
	actual=$?
	if [ "$actual" -eq "$expected" ] && { [ -z "$message" ] || grep -qF -- "$message" <<<"$output"; }; then
		PASSED=$((PASSED + 1))
	else
		FAILED=$((FAILED + 1))
		echo "NG $id: $title (期待 $expected${message:+ と「$message」}、実際 $actual)"
		echo "$output" | sed 's/^/    /'
	fi
}

main
