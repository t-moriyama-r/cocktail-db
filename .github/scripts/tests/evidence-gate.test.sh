#!/usr/bin/env bash
#
# evidence-gate.sh のテスト(TC-380-01〜15・TC-432-01〜11・TC-518-01〜12・TC-598-01〜13)。gh を、決まった JSON を返す偽物に差し替えて流す。GitHub には触らない。
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
# Actions で撮って evidence ブランチに置いた証跡(#487・#598)。検査するリポジトリは o/r
EV_ROOT='https://raw.githubusercontent.com/o/r/evidence'
EV_IMG="<img width=\"922\" alt=\"compare\" src=\"$EV_ROOT/598/compare.png\" />"
EV_MD_IMG="![compare]($EV_ROOT/598/compare.png)"
EV_MP4_BEFORE="$EV_ROOT/598/before.mp4"
EV_MP4_AFTER="$EV_ROOT/598/after.mp4"
CODE='["backend/app/Http/Controllers/HelloController.php"]'
FE='["frontend/src/components/DataTable.tsx","frontend/src/components/DataTable.module.css"]'
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


	# 画面のコード(frontend/src/ の .tsx・.css)を変えた PR は、幅の区切り(xs・sm・md・lg)ごとの幅が節に要る(#432)
	check TC-432-01 '画面のコードの PR に、4 つの区切りの幅がそろっている' "$FE" \
		"$(fe_body "xs 375px・sm 768px・md 1024px・lg 1440px")" 0
	check TC-432-02 'sm の幅が欠けている' "$FE" \
		"$(fe_body "xs 375px・md 1024px・lg 1440px")" 1 '足りない区切り: sm(600〜959px。撮る幅は 768px)'
	check TC-432-03 'FE の画面の差分が無い(BE のコードだけ)なら、幅を見ない' "$CODE" \
		"$(fe_body "PC 幅で撮った")" 0
	check TC-432-04 '飛ばす印に理由があれば、幅を見ない' "$FE" \
		"$(fe_body "PC 幅で撮った" "<!-- ai-dev:no-viewport-evidence 型の名前を変えただけで画面に出ない -->")" 0
	check TC-432-05 '飛ばす印に理由が無ければ飛ばさず落とす' "$FE" \
		"$(fe_body "xs 375px・sm 768px・md 1024px・lg 1440px" "<!-- ai-dev:no-viewport-evidence -->")" 1 '撮らない理由が書かれていない'
	check TC-432-06 '.css だけの差分でも幅を見る。幅が 1 つも無ければ 4 つとも出す' '["frontend/src/styles/base.css"]' \
		"$(fe_body "PC 幅とスマホ幅で撮った")" 1 'xs(〜599px。撮る幅は 375px) / sm(600〜959px。撮る幅は 768px) / md(960〜1263px。撮る幅は 1024px) / lg(1264px〜。撮る幅は 1440px)'
	check TC-432-07 'frontend/src/ の .ts(純関数)だけなら、幅を見ない' '["frontend/src/lib/format.ts"]' \
		"$(fe_body "画面に出ない関数なので比較はテストの結果")" 0
	check TC-432-08 'ほかの節に書いた幅は数えない' "$FE" \
		"$(body "## 何をしたか" "xs 375px・sm 768px・md 1024px・lg 1440px で見た" "$(fe_body "PC 幅で撮った")")" 1 'xs(〜599px'
	check TC-432-09 '区切りの境目の幅(599・600・1263・1264px)は、それぞれの区切りに数える' "$FE" \
		"$(fe_body "599px・600px・1263px・1264px")" 0
	check TC-432-10 '<img> の width="922" は幅として数えない' "$FE" \
		"$(fe_body "xs 375px・sm 768px・lg 1440px")" 1 '足りない区切り: md(960〜1263px'
	check TC-432-11 'HTML コメントの中の幅(PR のひな形の例)は数えない' "$FE" \
		"$(fe_body "<!--" "xs 375px・sm 768px・md 1024px・lg 1440px の 4 つの幅で撮る" "-->" "PC 幅で撮った")" 1 '足りない区切り: xs('

	# 本文の「気づいたこと」の項目は、issue の参照が付くまで落とす(#518)
	check TC-518-01 '「気づいたこと」の項目に #<番号> がある' "$CODE" \
		"$(fe_body "### 気づいたこと(直していない)" "" "- 375px でページが横にスクロールする → #520")" 0
	check TC-518-02 '「気づいたこと」の項目に issue の参照が無い' "$CODE" \
		"$(fe_body "### 気づいたこと(直していない)" "" "- 375px でページが横にスクロールする")" 1 '該当の項目: - 375px でページが横にスクロールする'
	check TC-518-03 'issue の URL でも参照として数える' "$CODE" \
		"$(fe_body "### 気づいたこと" "" "- 横スクロールが出る: https://github.com/o/r/issues/520")" 0
	check TC-518-04 '2 項目のうち 1 つだけ参照が無ければ、その項目だけを出して落とす' "$CODE" \
		"$(fe_body "### 気づいたこと" "" "- 一つ目 #520" "- 二つ目は参照なし")" 1 '該当の項目: - 二つ目は参照なし'
	check TC-518-05 '字下げした続きの行に書いた参照は、その項目の参照として数える' "$CODE" \
		"$(fe_body "### 気づいたこと" "" "- 375px でページが横にスクロールする" "  issue にした: #520")" 0
	check TC-518-06 '見出しの下に項目が無い(「なし」だけ)なら通る' "$CODE" \
		"$(fe_body "### 気づいたこと" "" "なし")" 0
	check TC-518-07 'PR の URL だけでは issue の参照として数えない' "$CODE" \
		"$(fe_body "### 気づいたこと" "" "- 横スクロールが出る https://github.com/o/r/pull/512")" 1 '「気づいたこと」に、issue の参照'
	check TC-518-08 '文書だけの PR でも、「気づいたこと」は見る' '["README.md"]' \
		"$(body "## 証跡" "文書だけなので撮らない" "## 気づいたこと" "- 手順の古いところがある")" 1 '「気づいたこと」に、issue の参照'
	check TC-518-09 '次の見出しの後の箇条書きと、HTML コメントの中の項目は数えない' "$CODE" \
		"$(body "$(fe_body "### 気づいたこと" "<!--" "- ひな形の例" "-->")" "## 確かめたこと" "- テストを流した")" 0
	check TC-518-10 '「- なし」だけの箇条書きは、参照が無くても通る' "$CODE" \
		"$(fe_body "### 気づいたこと" "" "- なし")" 0
	check TC-518-11 '字下げした箇条書きの項目も、参照が無ければ落とす' "$CODE" \
		"$(fe_body "### 気づいたこと" "" "  - 横スクロールが出る")" 1 '「気づいたこと」に、issue の参照'
	check TC-518-12 '色コード(#337ab7)は issue の参照として数えない' "$CODE" \
		"$(fe_body "### 気づいたこと" "" "- #337ab7 の文字が見づらい")" 1 '「気づいたこと」に、issue の参照'

	# Actions で撮って evidence ブランチに置いた証跡も数える。検査する PR のリポジトリの evidence ブランチの下だけ(#598)
	check TC-598-01 'evidence ブランチの画像を <img src> で貼ったら静止画と数える' "$CODE" \
		"$(body "## 証跡" "修正前・修正後" "$EV_IMG" "" "$VIDEO_BEFORE" "" "$VIDEO_AFTER")" 0
	check TC-598-02 'evidence ブランチの画像を ![](…) で貼ったら静止画と数える' "$CODE" \
		"$(body "## 証跡" "修正前・修正後" "$EV_MD_IMG" "" "$VIDEO_BEFORE" "" "$VIDEO_AFTER")" 0
	check TC-598-03 'evidence ブランチの .mp4 の行だけの URL を、1 本ずつ動画と数える' "$CODE" \
		"$(body "## 証跡" "修正前・修正後" "$EV_IMG" "" "$EV_MP4_BEFORE" "" "$EV_MP4_AFTER")" 0
	check TC-598-04 'evidence ブランチの .mp4 への Markdown のリンクを、1 本ずつ動画と数える' "$CODE" \
		"$(body "## 証跡" "修正前・修正後" "$EV_IMG" "" "- [修正前の動画]($EV_MP4_BEFORE)" "- [修正後の動画]($EV_MP4_AFTER)")" 0
	check TC-598-05 '同じ .mp4 の URL を行だけの URL とリンクで 3 回書いても 1 本と数える' "$CODE" \
		"$(body "## 証跡" "修正前・修正後" "$EV_IMG" "" "$EV_MP4_BEFORE" "" "  $EV_MP4_BEFORE  " "[修正前]($EV_MP4_BEFORE)")" 1 '(1 本)'
	check TC-598-06 'ほかのリポジトリの evidence ブランチの画像は数えない' "$CODE" \
		"$(body "## 証跡" "修正前・修正後" '<img alt="a" src="https://raw.githubusercontent.com/x/r/evidence/598/compare.png" />' '![b](https://raw.githubusercontent.com/o/r2/evidence/598/compare.png)' "" "$VIDEO_BEFORE" "" "$VIDEO_AFTER")" 1 '静止画'
	check TC-598-07 'ほかのリポジトリの evidence ブランチの .mp4 は数えない' "$CODE" \
		"$(body "## 証跡" "修正前・修正後" "$IMG" "" "https://raw.githubusercontent.com/x/r/evidence/598/before.mp4" "" "[修正後](https://raw.githubusercontent.com/o/r2/evidence/598/after.mp4)")" 1 '(0 本)'
	check TC-598-08 'ほかのブランチ(main・evidence-old)の画像は数えない' "$CODE" \
		"$(body "## 証跡" "修正前・修正後" '<img alt="a" src="https://raw.githubusercontent.com/o/r/main/598/compare.png" />' '![b](https://raw.githubusercontent.com/o/r/evidence-old/598/compare.png)' "" "$VIDEO_BEFORE" "" "$VIDEO_AFTER")" 1 '静止画'
	check TC-598-09 'ほかのブランチ(main・evidence-old)の .mp4 は数えない' "$CODE" \
		"$(body "## 証跡" "修正前・修正後" "$IMG" "" "https://raw.githubusercontent.com/o/r/main/598/before.mp4" "" "[修正後](https://raw.githubusercontent.com/o/r/evidence-old/598/after.mp4)")" 1 '(0 本)'
	check TC-598-10 'リポジトリ名の . は 1 文字に当たらない(o/r.db の検査で o/rXdb の画像を数えない)' "$CODE" \
		"$(body "## 証跡" "修正前・修正後" '<img alt="a" src="https://raw.githubusercontent.com/o/rXdb/evidence/598/compare.png" />' "" "$VIDEO_BEFORE" "" "$VIDEO_AFTER")" 1 '静止画' o/r.db
	check TC-598-11 '. を含むリポジトリ名でも、そのリポジトリの evidence ブランチなら数える' "$CODE" \
		"$(body "## 証跡" "修正前・修正後" '<img alt="a" src="https://raw.githubusercontent.com/o/r.db/evidence/598/compare.png" />' "" "https://raw.githubusercontent.com/o/r.db/evidence/598/before.mp4" "" "https://raw.githubusercontent.com/o/r.db/evidence/598/after.mp4")" 0 '' o/r.db
	check TC-598-12 '![](….mp4) と、.mp4 でない行だけの URL は動画と数えない' "$CODE" \
		"$(body "## 証跡" "修正前・修正後" "$IMG" "" "![修正前]($EV_MP4_BEFORE)" "" "$EV_ROOT/598/after.png")" 1 '(0 本)'
	check TC-598-13 'user-attachments の動画と evidence ブランチの .mp4 を 1 本ずつ合わせて 2 本と数える' "$CODE" \
		"$(body "## 証跡" "修正前・修正後" "$IMG" "" "$VIDEO_BEFORE" "" "$EV_MP4_AFTER")" 0

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

# 画面のコードの PR の、静止画・修正前後の動画がそろった「## 証跡」。引数(撮った幅など)を節の頭に足す
fe_body() {
	body "## 証跡" "$@" "修正前と修正後の比較" "$IMG" "" "修正前" "" "$VIDEO_BEFORE" "" "修正後" "" "$VIDEO_AFTER"
}

# 引数を 1 行ずつつないだ本文。本物の PR の本文に合わせて CRLF にする
body() { printf '%s\r\n' "$@"; }

check() {
	local id=$1 title=$2 files=$3 text=$4 expected=$5 message=${6:-} repo=${7:-o/r} actual output
	jq -n --argjson files "$files" --arg body "$text" '{body: $body, files: ($files | map({path: .}))}' >"$FAKE/pr.json"
	output=$(PATH="$FAKE:$PATH" FAKE_DIR="$FAKE" bash "$SCRIPT" "$repo" 1 2>&1)
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
