#!/usr/bin/env bash
#
# ops/cutover/smoke.sh のテスト。手元に小さな HTTP サーバー(python3)を起こし、本物の curl で流す。
#
#   bash ops/cutover/tests/smoke.test.sh
#
# サーバーは、ルートの表(パス・状態コード・Location・本文のタブ区切り)のとおりに返す。表はテストごとに書き換える。

set -uo pipefail

SRC=$(cd "$(dirname "$0")/.." && pwd)
WORK=$(mktemp -d)
SERVER_PID=''
trap 'cleanup' EXIT

FAILED=0
PASSED=0

cleanup() {
  [[ -z $SERVER_PID ]] || kill "$SERVER_PID" 2>/dev/null
  rm -rf "$WORK"
}

run_tests() {
  start_server
  tc_all_pass
  tc_rerun_same
  tc_up_fails
  tc_admin_not_redirected
  tc_category_redirect_wrong
  tc_count_mismatch
  tc_without_options
  tc_unreachable
  tc_usage_errors

  printf '\n%d passed, %d failed\n' "$PASSED" "$FAILED"
  [[ $FAILED -eq 0 ]]
}

tc_all_pass() {
  local status ok=1
  good_routes
  run_smoke "$APP" --legacy-category 12 --expect-count 1234
  status=$?
  [[ $status -eq 0 ]] || ok=0
  grep -q 'すべて通った(up hello category admin count)' "$WORK/out.log" || ok=0
  grep -q "/detail/category/12 → $APP/category/5" "$WORK/out.log" || ok=0
  grep -q '/Cocktail/admin → /admin' "$WORK/out.log" || ok=0
  grep -q '登録数: 1234 件' "$WORK/out.log" || ok=0
  check "TC-139-21: すべて通ると 0 で終わる(旧のカテゴリの 301・旧の管理画面の 301・登録数を見る)" "$ok"
}

tc_rerun_same() {
  local first second ok=1
  good_routes
  run_smoke "$APP" --legacy-category 12 --expect-count 1234 || ok=0
  first=$(strip_time)
  run_smoke "$APP" --legacy-category 12 --expect-count 1234 || ok=0
  second=$(strip_time)
  [[ -n $first && $first == "$second" ]] || ok=0
  check "TC-139-22: 何度流しても同じ結果になる(読むだけ)" "$ok"
}

tc_up_fails() {
  local status ok=1
  good_routes
  sed -i 's#^/up\t200#/up\t500#' "$WORK/routes.tsv"
  run_smoke "$APP" --legacy-category 12
  status=$?
  [[ $status -eq 1 ]] || ok=0
  grep -q '/up が 500 を返した(200 を期待)' "$WORK/out.log" || ok=0
  grep -q '\[up\] で止まった' "$WORK/out.log" || ok=0
  grep -q '通ったもの: (無し)' "$WORK/out.log" || ok=0
  grep -q '流していないもの: hello category admin count' "$WORK/out.log" || ok=0
  ! grep -q 'すべて通った' "$WORK/out.log" || ok=0
  check "TC-139-23: 1 つ目で落ちると止まり、通ったもの(無し)と流していないものを出す" "$ok"
}

tc_admin_not_redirected() {
  local status ok=1
  good_routes
  sed -i 's#^/Cocktail/admin\t301\t/admin#/Cocktail/admin\t200\t-#' "$WORK/routes.tsv"
  run_smoke "$APP"
  status=$?
  [[ $status -eq 1 ]] || ok=0
  grep -q '/Cocktail/admin が 200 を返した(301 を期待)' "$WORK/out.log" || ok=0
  grep -q '通ったもの: up hello$' "$WORK/out.log" || ok=0
  grep -q '流していないもの: count$' "$WORK/out.log" || ok=0
  check "TC-139-24: 途中で落ちると止まり、それまでに通ったものと流していないものを出す" "$ok"
}

tc_category_redirect_wrong() {
  local status ok=1
  good_routes
  printf '/detail/category/99\t301\t/\t-\n' >>"$WORK/routes.tsv"
  run_smoke "$APP" --legacy-category 99
  status=$?
  [[ $status -eq 1 ]] || ok=0
  grep -q '/detail/category/99 の転送先が /' "$WORK/out.log" || ok=0
  check "TC-139-25: 旧のカテゴリの URL が /category/<数字> 以外へ移ると、落とす" "$ok"
}

tc_count_mismatch() {
  local status ok=1
  good_routes
  run_smoke "$APP" --expect-count 1000
  status=$?
  [[ $status -eq 1 ]] || ok=0
  grep -q '登録数が 1234 件(1000 件を期待)' "$WORK/out.log" || ok=0
  check "TC-139-26: 登録数が旧サイトと違うと、落とす" "$ok"
}

tc_without_options() {
  local status ok=1
  good_routes
  run_smoke "$APP"
  status=$?
  [[ $status -eq 0 ]] || ok=0
  grep -q 'すべて通った(up hello admin count)' "$WORK/out.log" || ok=0
  grep -q '登録数: 1234 件' "$WORK/out.log" || ok=0
  check "TC-139-27: --legacy-category・--expect-count を省くと、カテゴリの転送は見ず、登録数は出すだけ" "$ok"
}

tc_unreachable() {
  local status ok=1
  run_smoke "http://127.0.0.1:1"
  status=$?
  [[ $status -eq 1 ]] || ok=0
  grep -q '/up に繋がらなかった' "$WORK/out.log" || ok=0
  check "TC-139-28: 繋がらなければ、1 で止まる" "$ok"
}

tc_usage_errors() {
  local ok=1
  run_smoke && ok=0
  run_smoke "$APP/" && ok=0
  grep -q '末尾の / を付けない' "$WORK/out.log" || ok=0
  run_smoke "$APP" --legacy-category abc && ok=0
  run_smoke "$APP" --expect-count && ok=0
  check "TC-139-29: URL が無い・末尾が /・数字でない ID・値の無いオプションは、1 で終わる" "$ok"
}

# --- 下回り ---

good_routes() {
  printf '%s\n' \
    $'/up\t200\t-\t<html></html>' \
    $'/api/hello\t200\t-\t{"message":"hello"}' \
    "/detail/category/12"$'\t301\t'"$APP/category/5"$'\t-' \
    $'/Cocktail/admin\t301\t/admin\t-' \
    $'/api/cocktails/count\t200\t-\t{"count":1234}' >"$WORK/routes.tsv"
}

start_server() {
  cat >"$WORK/server.py" <<'EOF'
import http.server, sys

routes_file = sys.argv[1]

class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        routes = {}
        with open(routes_file, encoding="utf-8") as f:
            for line in f:
                path, status, location, body = line.rstrip("\n").split("\t")
                routes[path] = (int(status), location, body)
        status, location, body = routes.get(self.path, (404, "-", "-"))
        data = b"" if body == "-" else body.encode()
        self.send_response(status)
        if location != "-":
            self.send_header("Location", location)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def log_message(self, *args):
        pass

server = http.server.HTTPServer(("127.0.0.1", 0), Handler)
print(server.server_address[1], flush=True)
server.serve_forever()
EOF
  : >"$WORK/routes.tsv"
  python3 -I "$WORK/server.py" "$WORK/routes.tsv" >"$WORK/port" &
  SERVER_PID=$!
  local i
  for ((i = 0; i < 50; i++)); do
    [[ -s $WORK/port ]] && break
    sleep 0.1
  done
  [[ -s $WORK/port ]] || { echo 'サーバーが起きなかった' >&2; exit 1; }
  APP="http://127.0.0.1:$(cat "$WORK/port")"
}

run_smoke() {
  # 手元に proxy の設定があっても、起こしたサーバーへ直に繋ぐ
  NO_PROXY=127.0.0.1 no_proxy=127.0.0.1 bash "$SRC/smoke.sh" "$@" >"$WORK/out.log" 2>&1
}

# 出力から時刻を除く(流すたびに変わるのは時刻だけ)
strip_time() {
  sed 's/^[0-9-]* [0-9:]* //' "$WORK/out.log"
}

check() {
  if [[ $2 -eq 1 ]]; then
    PASSED=$((PASSED + 1))
    printf 'PASS %s\n' "$1"
  else
    FAILED=$((FAILED + 1))
    printf 'FAIL %s\n' "$1"
  fi
}

run_tests
