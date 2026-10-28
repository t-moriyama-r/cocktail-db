# 毎朝のまとめ(notify-needs-human.yml の digest ジョブ)で Slack に送る本文を作る。送るものが無ければ何も出さない
#
# 使い方: jq -n -f notify-digest.jq --arg repo <owner/repo> --arg labels "<ラベル> ..." --slurpfile items <items.jsonl>
#   items は 1 行に 1 件({number, title, url, kind, label, ask: {text, url}, days})。days はそのラベルが付いてからの日数
#
# needs-local(手元の作業待ち)は人の判断を待っていないので、付いたときには知らせない。手元のエージェントが拾えば数時間で片付くので、
# LOCAL_WAIT_DAYS 日たっても残っているもの(手元のエージェントがしばらく動いていない)だけを載せる
def LOCAL_WAIT_DAYS: 2;
def esc: gsub("&"; "&amp;") | gsub("<"; "&lt;") | gsub(">"; "&gt;");
def link($url; $label): "<\($url)|\($label | esc)>";
def waited: if .days == null then "" elif .days == 0 then "(1 日たっていない)" else "(\(.days) 日前から)" end;

{
  "needs-human":    {head: ":raising_hand: *人の判断が必要*(needs-human)", act: "頼むコメントに返信する"},
  "needs-decision": {head: ":thinking_face: *着手する前に決めてほしい*(needs-decision)", act: "issue にコメントで答えると、続きが進む"},
  "needs-approval": {head: ":white_check_mark: *承認を待っている*(needs-approval)", act: "問題が無ければマージすると承認になる。直してほしいことは PR にコメントする"},
  "needs-local":    {head: ":hammer_and_wrench: *手元の作業待ちが \(LOCAL_WAIT_DAYS) 日以上残っている*(needs-local)", act: "手元で Claude Code を開くと、daily-start・pr-patrol が拾って片付ける"}
} as $t
| ($items | map(select(.label != "needs-local" or (.days // 0) >= LOCAL_WAIT_DAYS))) as $items
| ($items | map(.url) | unique | length) as $count
| select($count > 0)
| {
    unfurl_links: false,
    text: ([
      (if $labels == "needs-decision"
        then ":thinking_face: *着手する前に決めてほしいことが \($count) 件あります*(\($repo))"
        else ":bell: *人の返事か手元の作業を待っているものが \($count) 件あります*(\($repo))" end),
      ($labels | split(" ")[] as $label
        | ($items | map(select(.label == $label)) | sort_by(-(.days // 0), .number)) as $list
        | select($list | length > 0)
        | "", $t[$label].head, $t[$label].act,
          # 見出しのリンクも、頼むコメントがあればそこへ向ける(無ければ .ask.url は PR・issue)
          ($list[] | "• \(link(.ask.url; "\(.kind) #\(.number) \(.title)"))\(waited)"
            + (if .ask.text != "" then "\n      \(link(.ask.url; .ask.text))" else "" end)))
    ] | join("\n"))
  }
