#!/usr/bin/env bash
# PR の本文の「## 証跡」に、修正前後の証跡がそろっているかを確かめる。足りなければ理由を出して 1 で終わる
#
# 使い方: evidence-gate.sh <owner/repo> <PR の番号>
#
# FE・BE のコード(文書・テスト・CI の設定・ロックファイル以外)を変えた PR は、何がどう変わったかを
# 静止画(比較画像)と修正前・修正後の動画で見せる決まり(CLAUDE.md の「ブランチ・コミット・PR」の 5)。
# 「撮れなかった」と書いて ready にした PR がレビューに回った(#368)ので、文章ではなく貼られたものを数える。
# 動画は GitHub が行だけの URL を再生できる形にするので、<img> に埋めていない行だけの user-attachments の URL を数える。
# Actions で撮った証跡は、検査する PR のリポジトリの evidence ブランチ(https://raw.githubusercontent.com/<owner>/<repo>/evidence/ の下)に置く(#487)。
# そこの画像(<img src>・![](…))は静止画、.mp4 への Markdown のリンクか行だけの URL は動画として数える(#598)。
# コードを変えていない PR は撮らなくてよいが、書き忘れと区別するため、撮らない理由が書いてあることは確かめる
# 画面のコード(frontend/src/ の .tsx・.css)を変えた PR は、幅の区切り(xs・sm・md・lg。docs/design/style.md の「幅の区切り」)の
# どれかで崩れていても気づけるよう、区切りごとに撮る決まり(#432)。節に、4 つの区切りそれぞれに入る幅(<数字>px)が書かれているかを見る。
# 画面に出ない FE の変更(純関数・型だけ)は、本文に <!-- ai-dev:no-viewport-evidence 理由 --> を書けばこの検査を飛ばす
# 撮る途中で気づいて直さなかったことを本文の「気づいたこと」に書くだけでは、誰も拾わないままマージされた(#512 の 375px の横スクロール)。
# 「気づいたこと」の見出しの下の項目は、issue の参照(#<番号> か issue の URL)が付くまで落とす(#518)
set -euo pipefail

repo=$1
number=$2

body=$(gh pr view "$number" -R "$repo" --json body --jq '.body // ""' | tr -d '\r')
section=$(awk '/^## /{in_section = /^## 証跡/; next} in_section' <<<"$body")
# gh pr view --json files は 100 件で切れるので、API をページ送りで読む
files=$(gh api --paginate "repos/$repo/pulls/$number/files" --jq '.[].filename')
code=$(grep -v '^$' <<<"$files" | grep -Ev \
	-e '\.(md|txt)$' \
	-e '^(docs|\.github|e2e|ops)/' \
	-e '(^|/)(tests?|__tests__)/' \
	-e '\.(test|spec)\.[cm]?[jt]sx?$' \
	-e 'Test\.php$' \
	-e '(^|/)(package-lock\.json|composer\.lock)$' \
	-e '(^|/)\.(coderabbit\.yaml|gitignore|editorconfig)$' || true)

# 幅の区切り(名前 下限 上限 撮る幅 範囲の書き方)。値は frontend/src/styles/breakpoints.ts・docs/design/style.md の「幅の区切り」と同じ
viewports=(
	'xs 1 599 375 〜599px'
	'sm 600 959 768 600〜959px'
	'md 960 1263 1024 960〜1263px'
	'lg 1264 99999 1440 1264px〜'
)

check_viewports() {
	local marker widths missing=() entry name low high shot range w found
	marker=$(grep -oE '<!--[[:space:]]*ai-dev:no-viewport-evidence([[:space:]][^>]*)?-->' <<<"$body" | head -1 || true)
	if [ -n "$marker" ]; then
		# 理由の無い印では飛ばさない。何のために飛ばしたかを、レビューで確かめられるようにする
		if [ -n "$(sed -E 's/^<!--[[:space:]]*ai-dev:no-viewport-evidence//; s/-->$//' <<<"$marker" | tr -d '[:space:]')" ]; then
			return 0
		fi
		problems+=('<!-- ai-dev:no-viewport-evidence --> に、幅ごとに撮らない理由が書かれていない(<!-- ai-dev:no-viewport-evidence 理由 --> の形で書く)')
		return 0
	fi
	# PR のひな形の HTML コメント(撮る幅の例を書いている)を残したまま通らないよう、コメントの中の幅は数えない
	widths=$(perl -0pe 's/<!--.*?-->//gs' <<<"$section" | grep -oE '(^|[^0-9])[0-9]+px' | grep -oE '[0-9]+' | sort -un || true)
	for entry in "${viewports[@]}"; do
		read -r name low high shot range <<<"$entry"
		found=
		for w in $widths; do
			if [ "$w" -ge "$low" ] && [ "$w" -le "$high" ]; then
				found=1
				break
			fi
		done
		[ -n "$found" ] || missing+=("$name(${range}。撮る幅は ${shot}px)")
	done
	if [ ${#missing[@]} -gt 0 ]; then
		problems+=("画面のコードを変えた PR なのに、幅の区切りごとの証跡が足りない。足りない区切り: $(printf '%s / ' "${missing[@]}" | sed 's| / $||')")
		problems+=('撮った幅を「## 証跡」に <数字>px で書く(xs 375px・sm 768px・md 1024px・lg 1440px。画面に出ない変更なら <!-- ai-dev:no-viewport-evidence 理由 -->)')
	fi
}

# 本文の「気づいたこと」の見出し(## 〜 ######)の下にある箇条書きの項目のうち、issue の参照が無いものを 1 項目 1 行で出す。
# 項目がまだ無いときは字下げした箇条書きも項目の始まりとし、そのあとの字下げした行は続きとして項目に含める。
# `#337ab7` のような色コードは参照と数えない(`#` の前後に英数字が続くものを除く)。PR のひな形の HTML コメントと、「- なし」で始まる項目(「- なし(画面の変更が無いため)」のように理由を添えたものも)は数えない
findings_without_issue() {
	perl -0pe 's/<!--.*?-->//gs' <<<"$body" | awk '
		/^#+[[:space:]]/ {
			if (item != "") print item
			item = ""
			in_findings = ($0 ~ /^##+[[:space:]]*(\*\*)?気づいたこと/)
			next
		}
		!in_findings { next }
		(item == "" && /^[[:space:]]*([-*]|[0-9]+\.)[[:space:]]/) || /^([-*]|[0-9]+\.)[[:space:]]/ {
			if (item != "") print item
			item = $0
			next
		}
		item != "" && /^[[:space:]]+[^[:space:]]/ { item = item " " $0 }
		END { if (item != "") print item }
	' | grep -vE '(^|[^A-Za-z0-9_&])#[0-9]+([^A-Za-z0-9_]|$)|/issues/[0-9]+|^[[:space:]]*([-*]|[0-9]+\.)[[:space:]]*(なし|無し)([.]|。|、|\(|（|:|：|[[:space:]]|$)' || true
}

problems=()
if ! grep -q '^## 証跡' <<<"$body"; then
	problems+=('本文に「## 証跡」の節が無い')
elif [ -z "$code" ]; then
	[ -n "$(tr -d '[:space:]' <<<"$section")" ] || problems+=('コードを変えていない PR でも、「## 証跡」に撮らない理由(文書だけ・テストだけ など)を書く')
else
	images=$(grep -cE '<img [^>]*src="https://github\.com/user-attachments/|!\[[^]]*\]\(https://github\.com/user-attachments/' <<<"$section" || true)
	# Actions で撮って evidence ブランチに置いた証跡(#487)も数える。検査する PR のリポジトリの evidence ブランチの下だけで、
	# ほかのリポジトリ・ほかのブランチの raw.githubusercontent.com は数えない(#598)。リポジトリ名の . は正規表現で 1 文字に当たるので逃がす
	evidence="https://raw\.githubusercontent\.com/${repo//./\\.}/evidence/"
	evidence_images=$(grep -cE "<img [^>]*src=\"${evidence}|!\\[[^]]*\\]\\(${evidence}" <<<"$section" || true)
	images=$((images + evidence_images))
	# 同じ URL を何行書いても 1 本と数える。evidence ブランチの .mp4 は、行だけの URL か Markdown のリンク([…](….mp4)。![…](…) は除く)を数える
	videos=$({
		grep -E '^[[:space:]]*https://github\.com/user-attachments/assets/[^[:space:]]+[[:space:]]*$' <<<"$section" || true
		grep -E "^[[:space:]]*${evidence}[^[:space:]]+\\.mp4[[:space:]]*\$" <<<"$section" || true
		grep -oE "(^|[^!])\\[[^]]*\\]\\(${evidence}[^)[:space:]]+\\.mp4\\)" <<<"$section" | sed -E 's/^.*\]\(//; s/\)$//' || true
	} | tr -d ' \t' | sort -u | wc -l)
	# 説明の文中の「撮れず」では落とさない。行の頭(箇条書き・太字の記号の後)で宣言している行だけ見る
	declared=$(grep -E '^[[:space:]]*([-*>]|[0-9]+\.)?[[:space:]]*(\*\*)?(未撮影|撮れなかった|撮れず)' <<<"$section" || true)
	if [ -n "$declared" ]; then
		problems+=('「未撮影」「撮れなかった」のまま。撮れない環境なら ready にせず、draft のまま needs-local で撮影を頼む' "該当の行: $(head -3 <<<"$declared" | paste -sd'/' -)")
	fi
	[ "$images" -ge 1 ] || problems+=('静止画(修正前後の比較画像)が貼られていない')
	[ "$videos" -ge 2 ] || problems+=("動画が修正前・修正後の 2 本以上そろっていない(${videos} 本)")
	if ! grep -q '修正前' <<<"$section" || ! grep -q '修正後' <<<"$section"; then
		problems+=('修正前と修正後のどちらを見せているかが節に書かれていない')
	fi
	if grep -Eq '^frontend/src/.*\.(tsx|css)$' <<<"$code"; then
		check_viewports
	fi
fi
unlinked=$(findings_without_issue)
if [ -n "$unlinked" ]; then
	problems+=('「気づいたこと」に、issue の参照(#<番号> か issue の URL)が無い項目がある。この PR で直さないものは issue にして参照を書く(この PR の中で判断が要るものは needs-human で聞く)')
	problems+=("該当の項目: $(head -3 <<<"$unlinked" | paste -sd'/' -)")
fi

if [ ${#problems[@]} -gt 0 ]; then
	echo "PR #$number の証跡が足りない:"
	printf -- '- %s\n' "${problems[@]}"
	[ -z "$code" ] || echo "対象のコード: $(head -5 <<<"$code" | paste -sd, -)"
	exit 1
fi
echo "PR #$number の証跡はそろっている"
