#!/usr/bin/env bash
# PR で変わったワークフロー(.github/workflows/)の、どういう変更かのサマリーを Slack の mrkdwn で出す
#
# 使い方: gh api --paginate --slurp "repos/<owner/repo>/pulls/<番号>/files?per_page=100" | jq 'add // []' \
#           | workflow-change-summary.sh
#
# 1 行目は結論、2 行目からはファイルごとの中身。ワークフローを変えていなければ何も出さない。
#
# 通知の役目は、Actions の Claude Code が書き換えたワークフローを人が必ず見ることなので、サマリーは PR の本文や
# LLM の要約に頼らず、差分(patch)の行から機械的に作る。YAML を解釈するのではなく行を見るだけなので、
# 「要注意なし」は安全の保証にはならない。そう読める文言にしている。
# 要注意にするのは、足すと権限・secret・外部への送信が広がる変更。読み取りの権限や actions/ のアクションのように、
# どのワークフローにもあるものまで要注意にすると、毎回鳴って結局読まれなくなるので、中身として並べるだけにする
set -euo pipefail

jq -r '
  def esc: gsub("&"; "&amp;") | gsub("<"; "&lt;") | gsub(">"; "&gt;");
  # インラインのコードに入れる。中にバッククォートがあると Slack の表示が崩れるので置き換え、長い行は切る
  def code: (if length > 100 then .[0:100] + "…" else . end) | gsub("`"; "'"'"'") | esc | "`\(.)`";

  # トリガーのうち、フォークの PR やコメントからでも secret 付きで動くもの
  def risky_events: ["pull_request_target", "workflow_run", "issue_comment", "pull_request_review", "pull_request_review_comment", "discussion_comment"];
  def events: risky_events + ["pull_request", "push", "schedule", "workflow_dispatch", "workflow_call", "repository_dispatch",
    "issues", "merge_group", "release", "discussion", "check_run", "check_suite", "create", "delete", "deployment",
    "deployment_status", "fork", "label", "milestone", "page_build", "public", "registry_package", "status", "watch",
    "branch_protection_rule", "gollum"];
  def scopes: "actions|attestations|checks|contents|deployments|discussions|id-token|issues|models|packages|pages|pull-requests|repository-projects|security-events|statuses";
  # 外への送信として数えない送り先(GitHub 自身)
  def github_host: test("^https?://([a-z0-9-]+\\.)*github(usercontent)?\\.com(/|$)");

  # 行の分類。c はマーカー(+ / -)を除いた中身、added は足した行か
  def category:
    (.c | ltrimstr("- ")) as $c
    # issues・deployments などはトリガーと権限の両方の名前なので、値(read / write / none)の付く権限を先に見る
    | if ($c | test("^permissions:")) or ($c | test("^(\(scopes)):\\s*(read|write|none)\\b")) then "permissions"
      elif ($c | test("^on:")) or ($c | test("^cron:"))
        or ($c | test("^(\(events | join("|")))\\s*:")) then "trigger"
      elif ($c | test("secrets[.\\[]")) then "secrets"
      elif ($c | test("^uses:")) then "uses"
      elif ($c | test("\\b(curl|wget)\\b")) or ([$c | scan("https?://[^\\s\"'"'"')]+") | select(github_host | not)] | length > 0) then "send"
      elif ($c | test("persist-credentials")) then "credentials"
      # run の中に埋め込むと、PR のタイトル・本文などを書いた人がシェルのコードを差し込める
      elif ($c | test("\\$\\{\\{\\s*github\\.(head_ref|event\\.[^}]*\\.(title|body|head_ref|ref|label|name|message|email))")) then "expression"
      else empty end;

  def warn:
    (.c | ltrimstr("- ")) as $c
    | if .cat == "permissions" then
        (.added and ($c | test("\\bwrite(-all)?\\b")))
        # permissions のブロックを消すと、リポジトリの既定の権限(書き込みのこともある)に広がる
        or ((.added | not) and ($c | test("^permissions:")))
      elif .cat == "trigger" then .added and ([risky_events[] as $e | $c | test("^\($e)\\s*:|[\\[ ,]\($e)[\\], ]|^on:\\s*\($e)\\s*$")] | any)
      elif .cat == "uses" then .added and ($c | test("^uses:\\s*[\"'"'"']?(actions|anthropics|github)/") | not) and ($c | test("^uses:\\s*[\"'"'"']?\\./") | not)
      elif .cat == "credentials" then (.added and ($c | test("true"))) or ((.added | not) and ($c | test("false")))
      else .added end;

  def cat_label: {
    trigger: "トリガー", permissions: "権限", secrets: "secret", uses: "アクション",
    send: "外への送信", credentials: "認証情報の保持", expression: "入力の埋め込み"
  }[.];
  def order: ["trigger", "permissions", "secrets", "uses", "send", "credentials", "expression"];

  def lines:
    .patch | split("\n") | map(select(test("^[+-]")))
    | map({added: startswith("+"), c: (.[1:] | gsub("^\\s+|\\s+$"; ""))})
    | map(select(.c != "" and (.c | startswith("#") | not)));

  # 足したファイルの、自分自身についての説明(name と冒頭のコメント)
  def description:
    (.patch | split("\n") | map(select(startswith("+")) | .[1:])) as $l
    | ([$l[] | select(test("^name:")) | sub("^name:\\s*"; "") | gsub("^[\"'"'"']|[\"'"'"']$"; "")] | first // null) as $name
    # 最初のコメントの段落(空のコメント行の手前まで)。ジョブの中のコメントまで拾わないよう、ファイルの頭の 15 行から探す
    | ([$l[0:15] | to_entries[] | select(.value | test("^\\s*#")) | .key] | first // null) as $from
    | (if $from == null then [] else $l[$from:]
        | .[:((map(test("^\\s*#\\s*\\S") | not) | index(true)) // length)]
        | map(sub("^\\s*#\\s?"; "")) end) as $comment
    | ($comment | join("") | if length > 200 then .[0:200] + "…" else . end) as $text
    | [if $name then "名前: \($name | esc)" else empty end, if $text != "" then "説明(ファイルの冒頭のコメント): \($text | esc)" else empty end];

  def status_text:
    if .status == "added" then "追加"
    elif .status == "removed" then "削除"
    elif .status == "renamed" then "名前を変えた。元: \(.previous_filename | code)"
    else "変更" end;

  [.[] | select(.filename | startswith(".github/workflows/"))] as $files
  | if ($files | length) == 0 then empty else
    [$files[] | . + {
      # 中身を変えていないリネームにも patch は無い
      checked: (.patch != null or .status == "removed" or .additions + .deletions == 0),
      items: (if .patch == null or .status == "removed" then []
        else [lines[] | . + {cat: category} | . + {warn: warn, key: (.c | sub("\\s+#.*$"; ""))}]
          # 同じ行を消して足しただけ(行末のコメントを直した・場所を動かした)なら、広がっていないので要注意にしない
          | . as $all | map(.key as $k | .added as $a | .warn = (.warn and ([$all[] | select(.key == $k and .added != $a)] | length == 0)))
          # on: や permissions: だけの行は、中身の行が並ぶので、要注意のときだけ出す
          | map(select(.warn or (.key | IN("on:", "permissions:") | not)))
        end)
    }] as $files
    | ([$files[].items[] | select(.warn)] | length) as $warns
    | ([$files[] | select(.checked | not)] | length) as $unchecked
    | (if $warns > 0 then
        ":rotating_light: *要注意の変更が \($warns) 行ある*(⚠ の行)。書き込みの権限・secret・外部への送信などが広がる変更なので、差分を確かめてください"
      elif $unchecked > 0 then
        ":warning: 差分が大きく、\($unchecked) 件のファイルは機械的に確かめられなかった。差分を見てください"
      else
        ":white_check_mark: 機械的に見た範囲では、書き込みの権限・secret・外部への送信などを広げる変更は無かった(行を見ただけで、安全の保証ではない。中身は下のとおり)"
      end),
    ($files[] |
      "• \(.filename | code)(\(status_text)。+\(.additions) -\(.deletions))",
      (if .status == "added" and .patch != null then description[] | "    \(.)" else empty end),
      (if .checked | not then "    差分が大きく、GitHub が差分を返さなかったので確かめられなかった"
       elif .status == "removed" then empty
       elif (.items | length) == 0 then "    権限・secret・トリガー・アクション・外への送信に触れる行の変更は無い"
       else
         (.status == "added") as $new
         | .items as $items
         | order[] as $cat
         | [$items[] | select(.cat == $cat)] as $hits
         | select($hits | length > 0)
         | ($hits[0:5] | map((if .warn then "⚠ " else "" end) + (if $new then "" elif .added then "+ " else "- " end) + (.c | code)))
           as $shown
         | "    \($cat | cat_label): \($shown | join("  "))\(if ($hits | length) > 5 then "  ほか \(($hits | length) - 5) 行" else "" end)"
       end))
  end
'
