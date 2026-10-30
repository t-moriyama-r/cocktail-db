#!/usr/bin/env bash
# 証跡を頼むコメントから ```evidence のブロックを取り出し、YAML として読んで、必須の項目がそろっているかを確かめる
#
# 使い方: evidence-request.sh [ファイル]   (省略か - なら標準入力。コメントの本文の Markdown を渡す)
#
# 形は docs/evidence-request.md(#599)。
# 出力: そろっていれば、既定の値(幅・動画の幅・入れるデータ)を埋めた中身を JSON で標準出力に出して 0 で終わる。
# そろっていなければ、足りないところを 1 行に 1 つ標準エラーに出して 1 で終わる。ブロックが無い・2 つ以上ある・YAML として読めないときも 1。
# 引数の誤りは 2。
#
# 書いた人は見ない。読む側(撮るワークフロー)は、信頼する人・bot のコメントだけを渡す(untrusted-comments.sh・RC-05)。
# 手元の LANG によらず、本文・出力を UTF-8 で扱う(-E と、プログラムの先頭の encoding)。
# YAML は Ruby の標準の Psych で読む(GitHub のランナーに入っている。Node・Python の YAML は標準に無い)。
# safe_load なので、別名(&・*)・日付・任意のクラスは読まない。
set -euo pipefail

USAGE="使い方: evidence-request.sh [ファイル]"

evidence_request() {
  if [ $# -gt 1 ]; then
    echo "$USAGE" >&2
    return 2
  fi
  local file=${1:--}
  if [ "$file" != - ] && [ ! -f "$file" ]; then
    echo "ファイルが無い: $file" >&2
    return 2
  fi
  if ! command -v ruby >/dev/null 2>&1; then
    echo "ruby が無い(YAML を読むのに使う)" >&2
    return 2
  fi
  if [ "$file" = - ]; then
    ruby -E UTF-8:UTF-8 -e "$(ruby_program)"
  else
    ruby -E UTF-8:UTF-8 -e "$(ruby_program)" <"$file"
  fi
}

ruby_program() {
  cat <<'RUBY'
# encoding: utf-8
require "yaml"
require "json"

# 幅の区切り(docs/design/style.md の「幅の区切り」)と、既定で撮る幅
BREAKPOINTS = [["xs", 0, 599], ["sm", 600, 959], ["md", 960, 1263], ["lg", 1264, Float::INFINITY]].freeze
DEFAULT_WIDTHS = [375, 768, 1024, 1440].freeze
DEFAULT_SEED = "E2eSeeder"
KINDS = %w[screen api command].freeze
METHODS = %w[GET POST PUT PATCH DELETE].freeze
STEP_ACTIONS = %w[goto click fill select press hover wait_for scroll_to].freeze
# 秘密を書かないよう、頼むコメントに書かせないヘッダー
SECRET_HEADERS = %w[authorization cookie proxy-authorization x-xsrf-token x-csrf-token].freeze

$errors = []
def err(msg)
  $errors << msg
end

def breakpoint(w)
  BREAKPOINTS.find { |_, lo, hi| w >= lo && w <= hi }&.first
end

def check_keys(where, h, allowed)
  (h.keys - allowed).each { |k| err("#{where}: 知らない項目 #{k}(使えるのは #{allowed.join('・')})") }
end

# 画面の URL・API・goto のパス。アプリの中のパスだけを書く(ホスト・スキームは書かない)
def check_path(where, v, prefix: "/")
  if !v.is_a?(String) || v.empty?
    err("#{where}: 文字列で書く")
  elsif !v.start_with?(prefix) || v.start_with?("//") || v.include?("://")
    err("#{where}: #{prefix} で始まるアプリの中のパスにする(ホストは書かない): #{v}")
  end
end

def check_steps(where, steps)
  return [] if steps.nil?
  unless steps.is_a?(Array)
    err("#{where}: 配列で書く")
    return []
  end
  steps.each_with_index do |s, j|
    w = "#{where}[#{j + 1}]"
    unless s.is_a?(Hash) && s.size == 1
      err("#{w}: 「操作: 値」を 1 つだけ書く(使えるのは #{STEP_ACTIONS.join('・')})")
      next
    end
    action, value = s.first
    case action
    when "goto" then check_path(w, value)
    when "fill", "select"
      if value.is_a?(Hash)
        check_keys(w, value, %w[target value])
        err("#{w}: target(何に)を書く") unless value["target"].is_a?(String) && !value["target"].empty?
        err("#{w}: value(入れる値)を書く") unless value["value"].is_a?(String) || value["value"].is_a?(Numeric)
      else
        err("#{w}: #{action} は target と value を書く")
      end
    when "click", "press", "hover", "wait_for", "scroll_to"
      err("#{w}: #{action} は文字列で書く") unless value.is_a?(String) && !value.empty?
    else
      err("#{w}: 知らない操作 #{action}(使えるのは #{STEP_ACTIONS.join('・')})")
    end
  end
  steps
end

def check_widths(where, v)
  return DEFAULT_WIDTHS.dup if v.nil?
  unless v.is_a?(Array) && !v.empty? && v.all? { |w| w.is_a?(Integer) && w >= 320 && w <= 3840 }
    err("#{where}: 320〜3840 の整数の配列で書く")
    return []
  end
  missing = BREAKPOINTS.map(&:first) - v.map { |w| breakpoint(w) }
  unless missing.empty?
    err("#{where}: 区切りごとに 1 つ以上の幅を入れる(足りない区切り: #{missing.join('・')}。既定は #{DEFAULT_WIDTHS.join('・')})")
  end
  v.uniq.sort
end

def check_video(where, v, widths)
  if v.nil?
    return [widths.min, widths.max].uniq if widths.any?
    return []
  end
  unless v.is_a?(Array) && v.all? { |w| w.is_a?(Integer) }
    err("#{where}: 幅(整数)の配列で書く")
    return []
  end
  extra = v - widths
  err("#{where}: widths に無い幅がある: #{extra.join('・')}") unless extra.empty?
  %w[xs lg].each do |bp|
    err("#{where}: #{bp} の幅を 1 つ以上入れる(動画は少なくとも lg と xs で撮る)") unless v.any? { |w| breakpoint(w) == bp }
  end
  v.uniq.sort
end

def check_data(where, v)
  return { "seed" => DEFAULT_SEED } if v.nil?
  unless v.is_a?(Hash)
    err("#{where}: seed を書いた対応表にする")
    return {}
  end
  check_keys(where, v, %w[seed])
  seed = v.fetch("seed", DEFAULT_SEED)
  err("#{where}.seed: シーダーのクラス名(〜Seeder)を書く: #{seed}") unless seed.is_a?(String) && seed.match?(/\A[A-Z][A-Za-z0-9]*Seeder\z/)
  { "seed" => seed }
end

def check_request(where, r)
  unless r.is_a?(Hash)
    err("#{where}: method と path を書く")
    return r
  end
  check_keys(where, r, %w[method path headers body])
  method = r["method"]
  err("#{where}.method: #{METHODS.join('・')} のどれかにする") unless METHODS.include?(method)
  check_path("#{where}.path", r["path"], prefix: "/api/")
  headers = r["headers"]
  if !headers.nil?
    if headers.is_a?(Hash) && headers.all? { |k, v| k.is_a?(String) && v.is_a?(String) }
      headers.each_key do |k|
        err("#{where}.headers: #{k} は書かない(秘密を頼むコメントに書かないため)") if SECRET_HEADERS.include?(k.downcase)
      end
    else
      err("#{where}.headers: 「名前: 値」の文字列で書く")
    end
  end
  if !r["body"].nil? && %w[GET DELETE].include?(method)
    err("#{where}.body: #{method} には body を書かない")
  end
  r
end

def check_cases(where, v)
  unless v.is_a?(Array) && !v.empty?
    err("#{where}: 確かめるテストケースの ID を配列で書く(例: [TC-599-01])")
    return
  end
  v.each { |c| err("#{where}: TC-<issue 番号>-<連番 2 桁> の形にする: #{c}") unless c.is_a?(String) && c.match?(/\ATC-\d+-\d{2,}\z/) }
end

def check_item(i, item)
  where = "items[#{i + 1}]"
  unless item.is_a?(Hash)
    err("#{where}: kind・cases などを書いた対応表にする")
    return item
  end
  kind = item["kind"]
  unless KINDS.include?(kind)
    err("#{where}.kind: #{KINDS.join('・')} のどれかにする#{kind.nil? ? '' : ": #{kind}"}")
    return item
  end
  where = "#{where}(#{kind})"
  check_cases("#{where}.cases", item["cases"])
  out = item.dup
  case kind
  when "screen"
    check_keys(where, item, %w[kind cases note path widths steps video data])
    check_path("#{where}.path", item["path"])
    out["widths"] = check_widths("#{where}.widths", item["widths"])
    out["video"] = check_video("#{where}.video", item["video"], out["widths"])
    out["steps"] = check_steps("#{where}.steps", item["steps"])
    out["data"] = check_data("#{where}.data", item["data"])
  when "api"
    check_keys(where, item, %w[kind cases note request data])
    out["request"] = check_request("#{where}.request", item["request"])
    out["data"] = check_data("#{where}.data", item["data"])
  when "command"
    check_keys(where, item, %w[kind cases note run workdir data])
    err("#{where}.run: 流すコマンドを文字列で書く") unless item["run"].is_a?(String) && !item["run"].strip.empty?
    workdir = item.fetch("workdir", ".")
    unless workdir.is_a?(String) && !workdir.start_with?("/") && !workdir.split("/").include?("..")
      err("#{where}.workdir: リポジトリの中の相対パスにする(/ で始めない・.. を使わない): #{workdir}")
    end
    out["workdir"] = workdir
    out["data"] = check_data("#{where}.data", item["data"])
  end
  out
end

# 公開リポジトリなので、絶対パスを頼むコメントに書かせない(CLAUDE.md の「このリポジトリは public」)
def each_string(v, &blk)
  case v
  when String then blk.call(v)
  when Array then v.each { |x| each_string(x, &blk) }
  when Hash then v.each { |k, x| each_string(k, &blk); each_string(x, &blk) }
  end
end

# ```evidence から ``` までを取り出す(CRLF でも読む)
blocks = []
cur = nil
$stdin.read.split("\n").each do |line|
  line = line.chomp("\r")
  if cur
    if line.match?(/\A\s*```\s*\z/)
      blocks << cur.join("\n")
      cur = nil
    else
      cur << line
    end
  elsif line.match?(/\A\s*```\s*evidence\s*\z/)
    cur = []
  end
end

if !cur.nil?
  warn "evidence のブロックが ``` で閉じていない"
  exit 1
end
if blocks.empty?
  warn "evidence のブロックが無い"
  exit 1
end
if blocks.size > 1
  warn "evidence のブロックが #{blocks.size} 個ある(1 つのコメントに 1 つだけ書く。撮るものは items に並べる)"
  exit 1
end

begin
  doc = YAML.safe_load(blocks[0], permitted_classes: [], aliases: false)
rescue Psych::Exception => e
  warn "YAML として読めない: #{e.message.lines.first.strip}"
  exit 1
end

unless doc.is_a?(Hash)
  warn "version・items を書いた対応表にする"
  exit 1
end

check_keys("先頭", doc, %w[version before after items])
err("version: 1 にする") unless doc["version"] == 1
%w[before after].each do |k|
  next if doc[k].nil?
  err("#{k}: コミットの SHA(16 進 7〜40 桁)にする") unless doc[k].is_a?(String) && doc[k].match?(/\A[0-9a-f]{7,40}\z/)
end
items = doc["items"]
if items.is_a?(Array) && !items.empty?
  doc["items"] = items.each_with_index.map { |item, i| check_item(i, item) }
else
  err("items: 撮るものを 1 つ以上並べる")
end

each_string(doc) do |s|
  err("絶対パスを書かない(公開リポジトリのため): #{s}") if s.match?(%r{(\A|[\s"'=:])(/home/|/Users/|/root/)})
end

if $errors.any?
  $errors.each { |e| warn e }
  exit 1
end
puts JSON.generate(doc)
RUBY
}

evidence_request "$@"
