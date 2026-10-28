#!/usr/bin/env bash
# PR の本文の「## 証跡」に、修正前後の証跡がそろっているかを確かめる。足りなければ理由を出して 1 で終わる
#
# 使い方: evidence-gate.sh <owner/repo> <PR の番号>
#
# FE・BE のコード(文書・テスト・CI の設定・ロックファイル以外)を変えた PR は、何がどう変わったかを
# 静止画(比較画像)と修正前・修正後の動画で見せる決まり(CLAUDE.md の「ブランチ・コミット・PR」の 5)。
# 「撮れなかった」と書いて ready にした PR がレビューに回った(#368)ので、文章ではなく貼られたものを数える。
# 動画は GitHub が行だけの URL を再生できる形にするので、<img> に埋めていない行だけの user-attachments の URL を数える。
# コードを変えていない PR は撮らなくてよいが、書き忘れと区別するため、撮らない理由が書いてあることは確かめる
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

problems=()
if ! grep -q '^## 証跡' <<<"$body"; then
	problems+=('本文に「## 証跡」の節が無い')
elif [ -z "$code" ]; then
	[ -n "$(tr -d '[:space:]' <<<"$section")" ] || problems+=('コードを変えていない PR でも、「## 証跡」に撮らない理由(文書だけ・テストだけ など)を書く')
else
	images=$(grep -cE '<img [^>]*src="https://github\.com/user-attachments/|!\[[^]]*\]\(https://github\.com/user-attachments/' <<<"$section" || true)
	# 同じ URL を何行書いても 1 本と数える
	videos=$(grep -E '^[[:space:]]*https://github\.com/user-attachments/assets/[^[:space:]]+[[:space:]]*$' <<<"$section" | tr -d ' \t' | sort -u | wc -l || true)
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
fi

if [ ${#problems[@]} -gt 0 ]; then
	echo "PR #$number の証跡が足りない:"
	printf -- '- %s\n' "${problems[@]}"
	[ -z "$code" ] || echo "対象のコード: $(head -5 <<<"$code" | paste -sd, -)"
	exit 1
fi
echo "PR #$number の証跡はそろっている"
