#!/usr/bin/env bash
# Markdown の ```mermaid のブロックから、ノードと矢印のラベルの字数を数え、上限を超えたものを出す
#
# 使い方: mermaid-labels.sh [ファイル]   (省略か - なら標準入力)
#
# 出力: 上限を超えたラベルを 1 行に 1 つ「図<n> ノード「<ラベル>」: 全角 13 字(上限 12 字)」の形で出し、1 つでもあれば終了コード 1。
# 超えたものが無ければ何も出さずに 0。数えない種類の図(sequenceDiagram など)は、そう出すだけで終了コードには数えない。
#
# GitHub の Mermaid は、ラベルの文字の幅を測ってから箱の大きさを決める。矢印のラベルは箱が文字の幅ちょうどで余白が無く、
# 端末のフォントが測ったときより少しでも広いと末尾が切れる(切れる幅はラベルの長さに比例する)。ノードは幅 120px で折り返し、
# 行が埋まっているほど 1 行増えて下が切れやすい。#478 で GitHub に描いて確かめ、矢印は全角 6 字、ノードは全角 12 字までにした。
# 字数は全角を 1、半角を 0.5 と数え、<br> で折ったラベルはいちばん長い行で数える(行ごとに幅が決まるため)。
# HTML のタグ・#quot; などの実体参照・ラベルを囲む引用符は数えない(実体参照は 1 文字として数える)。
set -euo pipefail

NODE_MAX=12
EDGE_MAX=6

mermaid_labels() {
  local file=${1:--} report over
  if [ "$file" = - ]; then
    report=$(jq -R -s -r --argjson node "$NODE_MAX" --argjson edge "$EDGE_MAX" "$(jq_program)")
  else
    report=$(jq -R -s -r --argjson node "$NODE_MAX" --argjson edge "$EDGE_MAX" "$(jq_program)" "$file")
  fi
  # Windows の jq は CRLF で出すことがあるので、CR を落としてから数える
  report=$(tr -d '\r' <<<"$report")
  [ -n "$report" ] && printf '%s\n' "$report"
  over=$(grep -c '上限' <<<"$report" || true)
  [ "$over" -eq 0 ]
}

# ブロックを取り出し、flowchart・graph の行からラベルを拾って幅を数える jq のプログラム
jq_program() {
  cat <<'JQ'
# 全角(東アジアの幅の広い文字と、日本語のフォントで全角に描かれる矢印・丸数字・図形)を 1、ほかを 0.5 と数える
def char_width:
  if (. >= 4352 and . <= 4447) or (. >= 8592 and . <= 8703) or (. >= 9312 and . <= 9983)
    or (. >= 11904 and . <= 42191) or (. >= 44032 and . <= 55203) or (. >= 63744 and . <= 64255)
    or (. >= 65072 and . <= 65103) or (. >= 65280 and . <= 65376) or (. >= 65504 and . <= 65510) or . >= 131072
  then 1 else 0.5 end;
def text_width: [explode[] | char_width] | add // 0;

# 描いたときに出る文字だけにする。<br> は改行にし、ほかのタグは消し、実体参照は 1 文字にする
def clean:
  gsub("<br\\s*/?>"; "\n"; "i") | gsub("<[^>]*>"; "") | gsub("#[A-Za-z0-9]+;|&[A-Za-z0-9#]+;"; "x") | gsub("`"; "")
  | split("\n") | map(gsub("^\\s+|\\s+$"; "")) | map(select(. != ""));

def to_label($kind): clean as $lines | select($lines | length > 0)
  | {kind: $kind, text: ($lines | join("<br>")), width: ($lines | map(text_width) | max)};

# 矢印のラベル: -->|x|・---|x|・-.->|x|・==>|x| と、-- x -->・-. x .->・== x ==>
def edge_re: "<?(?:-{2,}|-\\.+-|={2,})[>ox]?\\s*\\|([^|]*)\\||(?<![-.=<\\w])(?:--|-\\.|==)\\s+([^|>]*?)\\s+(?:-{2,}|\\.+-|={2,})[>ox]?";
def arrow_re: "<?(?:-{2,}|-\\.+-|={2,}|~{3,})[>ox]?";
# ノード: id の直後の形([ ]・( )・{ }・([ ])・[[ ]]・(( ))・{{ }}・[/ /]・>…] など)の中身。引用符で囲んだものは引用符の中
def node_re: "(?<![\\w-])[\\w-]+(?:\\(\\[|\\[\\[|\\[\\(|\\(\\(\\(|\\(\\(|\\{\\{|\\[/|\\[\\\\|\\[|\\(|\\{|>)\\s*(?:\"([^\"]*)\"|([^\"\\]\\)\\}]*?))\\s*(?:\\]\\)|\\]\\]|\\)\\]|\\)\\)\\)|\\)\\)|\\}\\}|/\\]|\\\\\\]|\\]|\\)|\\})";

def line_labels:
  [match(edge_re; "g") | (.captures[0].string // .captures[1].string // "") | to_label("edge")]
  + [gsub(edge_re; " ") | gsub(arrow_re; " ") | match(node_re; "g") | (.captures[0].string // .captures[1].string // "") | to_label("node")];

# 図の中身の行(先頭の --- で囲んだ設定と %% の行を除く)
def body_lines:
  map(select(test("^\\s*%%") | not))
  | if (.[0] // "" | test("^\\s*---\\s*$")) then (.[1:] | (map(test("^\\s*---\\s*$")) | index(true)) as $end | if $end then .[$end + 1:] else . end) else . end
  | map(select(test("^\\s*$") | not));

def blocks:
  split("\n") | map(sub("\r$"; ""))
  | reduce .[] as $l ({in: false, cur: [], out: []};
      if .in then
        if ($l | test("^\\s*```\\s*$")) then .out += [.cur] | .cur = [] | .in = false else .cur += [$l] end
      elif ($l | test("^\\s*```\\s*mermaid\\s*$")) then .in = true
      else . end)
  | if .in then .out + [.cur] else .out end;

def skip_re: "^\\s*(style|classDef|class|click|linkStyle|subgraph|end|direction|accTitle|accDescr)\\b";

blocks | to_entries[] | (.key + 1) as $n | (.value | body_lines) as $lines
| ($lines[0] // "" | gsub("^\\s+"; "") | split(" ")[0]) as $type
| if ($type | test("^(flowchart|graph)$")) then
    $lines[1:][] | select(test(skip_re) | not) | sub(";\\s*$"; "") | line_labels[]
    | select(.width > (if .kind == "edge" then $edge else $node end))
    | "図\($n) \(if .kind == "edge" then "矢印のラベル" else "ノード" end)「\(.text)」: 全角 \(.width) 字(上限 \(if .kind == "edge" then $edge else $node end) 字)"
  else
    "図\($n) は \($type) なので数えない(数えるのは flowchart・graph だけ)"
  end
JQ
}

mermaid_labels "$@"
