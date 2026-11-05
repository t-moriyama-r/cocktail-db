#!/usr/bin/env bash
# evidence-capture.yml が撮った証跡を PR 本文の「## 証跡」に貼り、needs-local を外す。撮れなかったときは needs-local を残してコメントする(#601。親は #487)
#
# 使い方:
#   evidence-report.sh section <request.json> <URL の一覧のファイル> <修正前の SHA> <修正後の SHA> <撮った回の URL>
#     … 本文に貼る塊(<!-- ai-dev:evidence-capture start --> 〜 end)を標準出力に出す。GitHub には触らない
#   evidence-report.sh merge <本文のファイル> <塊のファイル>
#     … 本文の「## 証跡」に塊を入れた本文を標準出力に出す。前に貼った塊があれば入れ替える。GitHub には触らない
#   evidence-report.sh attach <owner/repo> <PR の番号> <request.json> <URL の一覧のファイル> <修正前の SHA> <修正後の SHA> <撮った回の URL>
#     … 本文に貼り、needs-local を外し、<!-- ai-dev:local-done kind=evidence --> のコメントを書く
#   evidence-report.sh failed <owner/repo> <PR の番号> <理由のファイル> <撮った回の URL>
#     … needs-local を残し(無ければ付け)、何が撮れなかったかをコメントする(手元の見回りが拾う)
#
# request.json は evidence-request.sh が出した JSON(信頼する人の頼むコメントのブロック。evidence-pick.sh で選んだもの)。
# URL の一覧は evidence-publish.sh が出したもの(1 行に 1 つ)。publish の前の capture ジョブは PR のコードを動かすので、
# manifest.json は読まず、置いてよい名前(evidence-publish.sh の NAME_RE と同じ)のファイルの URL だけを使う。
# 貼る塊は evidence-gate.sh が数える形にする: 比較画像は evidence ブランチの URL の <img>、mp4 は Markdown のリンク、幅は <数字>px。
#
# 終わりのコード: 0 … できた(PR が閉じていて何もしなかったときも)/ 1 … 失敗 / 2 … 引数の誤り
set -euo pipefail

USAGE="使い方: evidence-report.sh section <request.json> <URL の一覧> <修正前の SHA> <修正後の SHA> <撮った回の URL>
       evidence-report.sh merge <本文のファイル> <塊のファイル>
       evidence-report.sh attach <owner/repo> <PR の番号> <request.json> <URL の一覧> <修正前の SHA> <修正後の SHA> <撮った回の URL>
       evidence-report.sh failed <owner/repo> <PR の番号> <理由のファイル> <撮った回の URL>"
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
START='<!-- ai-dev:evidence-capture start -->'
END='<!-- ai-dev:evidence-capture end -->'

usage() {
  echo "$USAGE" >&2
  return 2
}

# 本文に貼る塊。項目ごとに、比較画像・修正前後の静止画・動画(mp4 のリンクと GIF)を並べる
section() {
  local request=$1 urls=$2 before=$3 after=$4 run_url=$5
  [ -f "$request" ] && [ -f "$urls" ] || usage
  echo "$START"
  echo '### Actions で撮った証跡'
  echo
  echo "\`evidence-capture.yml\` が、修正前(\`${before:0:7}\`)と修正後(\`${after:0:7}\`)を同じデータで起こして撮った。"
  echo "撮った回: $run_url"
  echo
  # 置いてよい名前の URL だけを、連番・種類・幅・修正前後・拡張子に分けて読む
  jq -r -n --rawfile urls "$urls" --slurpfile req "$request" '
    def code: tostring | gsub("`"; "'"'"'") | gsub("\n"; " ");
    ($urls | split("\n") | map(select(test("^https://raw\\.githubusercontent\\.com/[^[:space:]]+/[0-9]{2}-(screen|api|command)(-[0-9]{3,4})?-(before|after|compare)\\.(png|mp4|gif)$")))
      | map({url: .} + (split("/") | last | capture("^(?<n>[0-9]{2})-(?<kind>[a-z]+)(-(?<w>[0-9]+))?-(?<side>before|after|compare)\\.(?<ext>[a-z0-9]+)$")))) as $files
    | def file($n; $w; $side; $ext): [$files[] | select(.n == $n and (.w // "") == $w and .side == $side and .ext == $ext) | .url] | first // null;
    $req[0].items | to_entries[]
    | .value as $item | ((.key + 1) | tostring | if length < 2 then "0" + . else . end) as $n
    | ([$files[] | select(.n == $n)]) as $mine
    | if ($mine | length) == 0 then empty else
      (if $item.kind == "screen" then "`\($item.path | code)`"
       elif $item.kind == "api" then "`\($item.request.method) \($item.request.path | code)`"
       else "`\($item.run | code)`(workdir: `\($item.workdir // "." | code)`)" end) as $what
      | ([$mine[] | select(.ext == "png" and .side == "compare") | .w // empty] | unique_by(tonumber) | sort_by(tonumber)) as $widths
      | ([$mine[] | select(.ext == "mp4") | .w // empty] | unique_by(tonumber) | sort_by(tonumber)) as $vwidths
      | "#### \($n)-\($item.kind)(\($item.cases | join("・"))): \($what)", "",
        (if $item.note then "見るところ: \($item.note | code)", "" else empty end),
        (if $item.kind == "screen" then
          "撮った幅: \($widths | map(. + "px") | join("・"))", "",
          "| 幅 | 比較画像(修正前・修正後) | 修正前・修正後の静止画 |",
          "|---|---|---|",
          ($widths[] as $w
            | "| \($w)px | <img src=\"\(file($n; $w; "compare"; "png"))\" width=\"640\" alt=\"\($w)px の修正前・修正後\"> | [修正前](\(file($n; $w; "before"; "png"))) / [修正後](\(file($n; $w; "after"; "png"))) |"),
          "",
          (if ($vwidths | length) > 0 then
            "動画(修正前・修正後の mp4):", "",
            ($vwidths[] as $w | "- \($w)px: [修正前の動画](\(file($n; $w; "before"; "mp4"))) / [修正後の動画](\(file($n; $w; "after"; "mp4")))"),
            "",
            "<details><summary>動画の GIF(修正前・修正後。先頭の 15 秒)</summary>", "",
            ($vwidths[] as $w | "\($w)px: <img src=\"\(file($n; $w; "before"; "gif"))\" width=\"320\" alt=\"\($w)px の修正前\"> <img src=\"\(file($n; $w; "after"; "gif"))\" width=\"320\" alt=\"\($w)px の修正後\">", ""),
            "</details>", ""
          else empty end)
        else
          "<img src=\"\(file($n; ""; "compare"; "png"))\" width=\"720\" alt=\"修正前・修正後\">", "",
          "修正前・修正後の画像: [修正前](\(file($n; ""; "before"; "png"))) / [修正後](\(file($n; ""; "after"; "png")))", ""
        end)
      end' | sed 's|(null)|(無い)|g; s|src="null"|src=""|g'
  echo "$END"
}

# 本文の「## 証跡」に塊を入れる。前に貼った塊があれば入れ替え、無ければ見出しのすぐ下に入れる。見出しが無ければ末尾に足す
merge() {
  local body=$1 block=$2
  [ -f "$body" ] && [ -f "$block" ] || usage
  BLOCK_FILE=$block START=$START END=$END perl -0777 -ne '
    my $body = $_;
    $body =~ s/\r//g;
    open(my $fh, "<", $ENV{BLOCK_FILE}) or die "塊のファイルを読めない\n";
    local $/;
    my $block = <$fh>;
    $block =~ s/\n*\z/\n/;
    my ($s, $e) = (quotemeta $ENV{START}, quotemeta $ENV{END});
    if ($body =~ /$s.*?$e\n?/s) {
      $body =~ s/$s.*?$e\n?/$block/s;
    } elsif ($body =~ /^## 証跡[^\n]*\n/m) {
      $body =~ s/^(## 証跡[^\n]*\n)/$1\n$block/m;
    } else {
      $body =~ s/\n*\z//;
      $body .= "\n\n## 証跡\n\n$block";
    }
    print $body;
  ' "$body"
}

# PR が開いているか。閉じた PR には貼らない・コメントしない(撮っているあいだに閉じられた。RC-07)
still_open() {
  local repo=$1 number=$2 state
  state=$(gh api "repos/$repo/pulls/$number" --jq .state)
  if [ "$state" != open ]; then
    echo "PR #$number は閉じられたので、貼らない"
    return 1
  fi
}

has_label() {
  local repo=$1 number=$2 label=$3 labels
  labels=$(gh api "repos/$repo/issues/$number" --jq '[.labels[].name] | join(",")')
  [[ ",$labels," == *",$label,"* ]]
}

attach() {
  local repo=$1 number=$2 request=$3 urls=$4 before=$5 after=$6 run_url=$7 work gate rc=0
  still_open "$repo" "$number" || return 0
  work=$(mktemp -d)
  WORK=$work
  trap 'rm -rf "$WORK"' EXIT
  section "$request" "$urls" "$before" "$after" "$run_url" >"$work/block.md"
  gh api "repos/$repo/pulls/$number" --jq '.body // ""' >"$work/body.md"
  merge "$work/body.md" "$work/block.md" >"$work/new.md"
  gh api -X PATCH "repos/$repo/pulls/$number" -F "body=@$work/new.md" >/dev/null
  if has_label "$repo" "$number" needs-local; then
    gh api -X DELETE "repos/$repo/issues/$number/labels/needs-local" >/dev/null
  fi
  # 貼った本文が evidence-gate.sh を通るかを、コメントに書く(落ちても撮った物は貼れているので、ここでは止めない)
  gate=$(bash "$HERE/evidence-gate.sh" "$repo" "$number" 2>&1) || rc=$?
  {
    echo '<!-- ai-dev:agent --> <!-- ai-dev:local-done kind=evidence -->'
    echo "Actions(\`evidence-capture.yml\`)で修正前後の証跡を撮り、本文の「## 証跡」に貼って \`needs-local\` を外した。"
    echo
    echo "- 修正前: \`${before:0:7}\` / 修正後: \`${after:0:7}\`"
    echo "- 置き場所: \`evidence\` ブランチの \`pr-$number/${after:0:7}/\`"
    echo "- 撮った回: $run_url"
    if [ "$rc" -eq 0 ]; then
      echo "- \`evidence-gate.sh\`: 通った"
    else
      echo "- \`evidence-gate.sh\`: 足りないものがある。本文を直してから ready にする"
      echo
      echo '```text'
      sed 's/```/'"'''"'/g' <<<"$gate"
      echo '```'
    fi
  } >"$work/comment.md"
  gh api "repos/$repo/issues/$number/comments" -F "body=@$work/comment.md" >/dev/null
  echo "PR #$number の「## 証跡」に貼り、needs-local を外した"
}

failed() {
  local repo=$1 number=$2 reason=$3 run_url=$4 work
  [ -f "$reason" ] || usage
  still_open "$repo" "$number" || return 0
  work=$(mktemp -d)
  WORK=$work
  trap 'rm -rf "$WORK"' EXIT
  {
    echo '<!-- ai-dev:agent --> <!-- ai-dev:needs-local kind=evidence -->'
    echo "Actions(\`evidence-capture.yml\`)では証跡を撮れなかったので、手元で撮って「## 証跡」に貼ってほしい(\`needs-local\` は残す)。"
    echo
    echo "- 撮った回: $run_url"
    echo
    echo '```text'
    # 理由の中のコードブロックの区切りで、このコメントの区切りが崩れないようにする
    sed 's/```/'"'''"'/g' "$reason" | head -40
    echo '```'
  } >"$work/comment.md"
  gh api "repos/$repo/issues/$number/comments" -F "body=@$work/comment.md" >/dev/null
  if ! has_label "$repo" "$number" needs-local; then
    gh api -X POST "repos/$repo/issues/$number/labels" -f "labels[]=needs-local" >/dev/null
  fi
  echo "PR #$number は撮れなかったので、needs-local を残してコメントした"
}

evidence_report() {
  local cmd=${1:-}
  shift || true
  case "$cmd" in
    section) [ $# -eq 5 ] || usage; section "$@" ;;
    merge) [ $# -eq 2 ] || usage; merge "$@" ;;
    attach)
      [ $# -eq 7 ] && [[ "$1" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] && [[ "$2" =~ ^[1-9][0-9]*$ ]] || usage
      attach "$@"
      ;;
    failed)
      [ $# -eq 4 ] && [[ "$1" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] && [[ "$2" =~ ^[1-9][0-9]*$ ]] || usage
      failed "$@"
      ;;
    *) usage ;;
  esac
}

evidence_report "$@"
