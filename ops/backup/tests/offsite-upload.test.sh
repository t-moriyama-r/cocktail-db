#!/usr/bin/env bash
#
# ops/backup/offsite-upload.sh のテスト。DB は使わない。gpg・perl・sha1sum・sha256sum が要る。
#
#   bash ops/backup/tests/offsite-upload.test.sh
#
# B2 は外部の境界なので、curl を偽物(OFFSITE_CURL)に差し替える。偽物は B2 の native API の 3 つの呼び出しに
# 決めた応答を返し、送られたファイルを一時ディレクトリに B2 での名前で置く。暗号化は本物の gpg で、テストの中で
# 作った使い捨ての鍵の組を使い、置かれたファイルを秘密鍵で復号して確かめる。

set -uo pipefail

SRC=$(cd "$(dirname "$0")/.." && pwd)
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

FAILED=0
PASSED=0

run_tests() {
  setup_keys
  setup_fake_curl
  tc_sends_six_files
  tc_decrypts_and_hashes_match
  tc_hashes_sent_last
  tc_secrets_not_in_argv
  tc_key_can_delete
  tc_key_without_write
  tc_private_key_rejected
  tc_config_missing
  tc_auth_failure
  tc_retry_then_success
  tc_upload_keeps_failing
  tc_sha1_mismatch
  tc_set_file_missing

  printf '\n%d passed, %d failed\n' "$PASSED" "$FAILED"
  [[ $FAILED -eq 0 ]]
}

tc_sends_six_files() {
  local case_dir status ok=1
  case_dir=$(new_case)
  run_upload "$case_dir"
  status=$?
  [[ $status -eq 0 ]] || ok=0
  [[ $(stored "$case_dir") == "$(printf '%s\n' \
    cocktail/db/app-20261027-030000.counts.tsv.gpg \
    cocktail/db/app-20261027-030000.sha256.gpg \
    cocktail/db/app-20261027-030000.sql.gz.gpg \
    cocktail/storage/20261027-030500.check.tsv.gpg \
    cocktail/storage/20261027-030500.tar.gz.gpg \
    cocktail/storage/20261027-030500.tar.gz.sha256.gpg)" ]] || ok=0
  # 作業用のディレクトリ(暗号化したファイル)は残さない
  ! compgen -G "$case_dir/backups/.offsite.*" >/dev/null || ok=0
  grep -q '1 組送り終えた' "$case_dir/out.log" || ok=0
  check "TC-373-01: 1 組の 6 つのファイルを、<B2_PREFIX><db か storage>/<名前>.gpg として B2 に送り、0 で終わる(作業用のディレクトリは消す)" "$ok"
}

tc_decrypts_and_hashes_match() {
  local case_dir ok=1 restore f
  case_dir=$(new_case)
  run_upload "$case_dir" || ok=0
  # docs/backup.md の「B2 から戻す」と同じ手順: 復号して .gpg を外し、db/・storage/ の中で sha256sum -c
  restore=$case_dir/restore
  mkdir -p "$restore"
  (cd "$case_dir/store/cocktail" && find . -name '*.gpg') >"$case_dir/list"
  while IFS= read -r f; do
    mkdir -p "$restore/$(dirname "$f")"
    gpg --batch --quiet --homedir "$KEYS" --output "$restore/${f%.gpg}" --decrypt "$case_dir/store/cocktail/$f" || ok=0
  done <"$case_dir/list"
  (cd "$restore/db" && sha256sum -c --quiet app-20261027-030000.sha256) || ok=0
  (cd "$restore/storage" && sha256sum -c --quiet 20261027-030500.tar.gz.sha256) || ok=0
  cmp -s "$restore/db/app-20261027-030000.sql.gz" "$case_dir/backups/db/app-20261027-030000.sql.gz" || ok=0
  # B2 に置かれたものは暗号化されていて、元の中身がそのまま入っていない
  ! grep -q 'CREATE TABLE secret_rows' "$case_dir/store/cocktail/db/app-20261027-030000.sql.gz.gpg" || ok=0
  ! cmp -s "$case_dir/store/cocktail/db/app-20261027-030000.sql.gz.gpg" "$case_dir/backups/db/app-20261027-030000.sql.gz" || ok=0
  check "TC-373-02: B2 に置いたものは平文でなく、秘密鍵で復号すると元と同じになり、sha256sum -c が通る" "$ok"
}

tc_hashes_sent_last() {
  local case_dir ok=1
  case_dir=$(new_case)
  run_upload "$case_dir" || ok=0
  [[ $(tail -n 2 "$case_dir/uploads.log" | paste -sd ' ' -) == "cocktail/storage/20261027-030500.tar.gz.sha256.gpg cocktail/db/app-20261027-030000.sha256.gpg" ]] || ok=0
  check "TC-373-03: .sha256 を最後に送る(B2 に .sha256 のある組は、ほかも送り終えている)" "$ok"
}

tc_secrets_not_in_argv() {
  local case_dir ok=1
  case_dir=$(new_case)
  run_upload "$case_dir" || ok=0
  [[ -s $case_dir/argv.log ]] || ok=0
  ! grep -qF -e "$APP_KEY" -e fake-account-token -e fake-upload-token "$case_dir/argv.log" || ok=0
  ! grep -qF -e "$APP_KEY" -e fake-account-token -e fake-upload-token "$case_dir/out.log" || ok=0
  # 認証は curl の設定(標準入力)で渡っている
  grep -qF "user = \"keyid-1:$APP_KEY\"" "$case_dir/stdin.log" || ok=0
  grep -qF 'header = "Authorization: fake-upload-token"' "$case_dir/stdin.log" || ok=0
  check "TC-373-04: キーの値とトークンを curl のコマンドラインにもログにも出さず、標準入力の設定で渡す" "$ok"
}

tc_key_can_delete() {
  local case_dir ok=1 cap
  for cap in deleteFiles bypassGovernance writeFileRetentions writeBucketRetentions; do
    case_dir=$(new_case)
    FAKE_CAPS="listFiles writeFiles $cap" run_upload "$case_dir"
    [[ $? -eq 1 ]] || ok=0
    [[ -z $(stored "$case_dir") ]] || ok=0
    grep -q "$cap の権限がある" "$case_dir/out.log" || ok=0
  done
  check "TC-373-05: キーに消す権限・保持期間を変える権限(deleteFiles・bypassGovernance・writeFileRetentions・writeBucketRetentions)があれば、送らずに 1 で止まる" "$ok"
}

tc_key_without_write() {
  local case_dir ok=1
  case_dir=$(new_case)
  FAKE_CAPS="listFiles readFiles" run_upload "$case_dir"
  [[ $? -eq 1 ]] || ok=0
  [[ -z $(stored "$case_dir") ]] || ok=0
  grep -q 'writeFiles の権限が無い' "$case_dir/out.log" || ok=0
  check "TC-373-06: キーに writeFiles が無ければ、送らずに 1 で止まる" "$ok"
}

tc_private_key_rejected() {
  local case_dir ok=1
  case_dir=$(new_case)
  sed -i "s|^OFFSITE_PUBLIC_KEY=.*|OFFSITE_PUBLIC_KEY=$WORK/secret.asc|" "$case_dir/offsite.conf"
  run_upload "$case_dir"
  [[ $? -eq 1 ]] || ok=0
  [[ ! -s $case_dir/argv.log ]] || ok=0
  grep -q '秘密鍵が入っている' "$case_dir/out.log" || ok=0
  check "TC-373-07: 公開鍵のファイルに秘密鍵が入っていたら、B2 に繋がずに 1 で止まる" "$ok"
}

tc_config_missing() {
  local case_dir ok=1 key
  for key in B2_KEY_ID B2_APPLICATION_KEY B2_BUCKET_ID OFFSITE_PUBLIC_KEY; do
    case_dir=$(new_case)
    sed -i "/^$key=/d" "$case_dir/offsite.conf"
    run_upload "$case_dir"
    [[ $? -eq 1 ]] || ok=0
    [[ ! -s $case_dir/argv.log ]] || ok=0
    grep -q "$key が空" "$case_dir/out.log" || ok=0
  done
  case_dir=$(new_case)
  bash "$SRC/offsite-upload.sh" "$case_dir/offsite.conf" >/dev/null 2>&1 && ok=0
  check "TC-373-08: 設定ファイルに必須のキーが無い・引数が足りないときは、B2 に繋がずに 1 で止まる" "$ok"
}

tc_auth_failure() {
  local case_dir ok=1
  case_dir=$(new_case)
  FAKE_AUTH_STATUS=401 run_upload "$case_dir"
  [[ $? -eq 3 ]] || ok=0
  [[ -z $(stored "$case_dir") ]] || ok=0
  grep -q 'B2 の認証に失敗した(HTTP 401。bad_auth_token: fake message)' "$case_dir/out.log" || ok=0
  check "TC-373-09: B2 の認証が通らない(401)と、3 で終わり、ログに HTTP のステータスと B2 のエラーが出る" "$ok"
}

tc_retry_then_success() {
  local case_dir ok=1
  case_dir=$(new_case)
  FAKE_UPLOAD_FAILS=1 run_upload "$case_dir" || ok=0
  [[ $(stored "$case_dir" | wc -l) -eq 6 ]] || ok=0
  grep -q '送り直す(1 回目が失敗' "$case_dir/out.log" || ok=0
  # 送り直すときは、送り先の URL を取り直している(6 ファイル + 送り直しの 1 回)
  [[ $(grep -c b2_get_upload_url "$case_dir/argv.log") -eq 7 ]] || ok=0
  check "TC-373-10: 送るのに 1 回失敗(503)しても、送り先の URL を取り直して送り直し、0 で終わる" "$ok"
}

tc_upload_keeps_failing() {
  local case_dir ok=1
  case_dir=$(new_case)
  FAKE_UPLOAD_FAILS=99 run_upload "$case_dir"
  [[ $? -eq 3 ]] || ok=0
  [[ -z $(stored "$case_dir") ]] || ok=0
  [[ $(grep -c '送れなかった: cocktail/db/app-20261027-030000.sql.gz.gpg(HTTP 503' "$case_dir/out.log") -eq 3 ]] || ok=0
  ! compgen -G "$case_dir/backups/.offsite.*" >/dev/null || ok=0
  check "TC-373-11: 3 回とも送れなければ、残りを送らずに 3 で終わり、作業用のディレクトリを消す" "$ok"
}

tc_sha1_mismatch() {
  local case_dir ok=1
  case_dir=$(new_case)
  FAKE_BAD_SHA1=1 run_upload "$case_dir"
  [[ $? -eq 3 ]] || ok=0
  grep -q 'B2 の受け取った SHA-1' "$case_dir/out.log" || ok=0
  check "TC-373-12: B2 が返した SHA-1 が送ったものと違えば、送れなかったとして 3 で終わる" "$ok"
}

tc_set_file_missing() {
  local case_dir ok=1
  case_dir=$(new_case)
  rm "$case_dir/backups/storage/20261027-030500.tar.gz.sha256"
  run_upload "$case_dir"
  [[ $? -eq 1 ]] || ok=0
  [[ ! -s $case_dir/argv.log ]] || ok=0
  grep -q '組のファイルが無い' "$case_dir/out.log" || ok=0
  check "TC-373-13: 組のファイルが 1 つでも無ければ、B2 に繋がずに 1 で止まる" "$ok"
}

# ---- 補助 ----

KEYS=$WORK/keys
APP_KEY=K005appsecretvalue

# 使い捨ての鍵の組を作り、公開鍵と秘密鍵をファイルに書き出す
setup_keys() {
  mkdir -p "$KEYS"
  chmod 700 "$KEYS"
  gpg --batch --quiet --homedir "$KEYS" --passphrase '' --quick-gen-key 'cocktail-db backup test' future-default default never
  gpg --batch --quiet --homedir "$KEYS" --armor --export >"$WORK/public.asc"
  gpg --batch --quiet --homedir "$KEYS" --pinentry-mode loopback --passphrase '' --armor --export-secret-keys >"$WORK/secret.asc"
}

# 偽物の curl。offsite-upload.sh が呼ぶ形(--config - -o <出力> -w '%{http_code}' … <URL>)だけを受ける
setup_fake_curl() {
  cat >"$WORK/curl" <<'EOF'
#!/usr/bin/env bash
out='' upload='' name='' url=''
printf '%s\n' "$*" >>"$FAKE_DIR/argv.log"
while [[ $# -gt 0 ]]; do
  case $1 in
    -o) out=$2; shift 2 ;;
    -T) upload=$2; shift 2 ;;
    -H) [[ $2 == X-Bz-File-Name:* ]] && name=${2#X-Bz-File-Name: }; shift 2 ;;
    -w | -X | --data | --config | --connect-timeout | --max-time) shift 2 ;;
    -*) shift ;;
    *) url=$1; shift ;;
  esac
done
cat >>"$FAKE_DIR/stdin.log"
respond() { printf '%s' "$2" >"$out"; printf '%s' "$1"; }
case $url in
  */b2_authorize_account)
    [[ ${FAKE_AUTH_STATUS:-200} == 200 ]] || { respond "$FAKE_AUTH_STATUS" '{"status":401,"code":"bad_auth_token","message":"fake message"}'; exit 0; }
    caps=$(printf '"%s",' ${FAKE_CAPS:-listFiles writeFiles}); caps=${caps%,}
    respond 200 "{\"accountId\":\"a\",\"authorizationToken\":\"fake-account-token\",\"apiInfo\":{\"storageApi\":{\"apiUrl\":\"https://api.fake\",\"allowed\":{\"buckets\":[{\"id\":\"bucket-1\",\"name\":\"b\"}],\"capabilities\":[$caps]}}}}"
    ;;
  https://api.fake/b2api/v3/b2_get_upload_url)
    respond 200 '{"bucketId":"bucket-1","uploadUrl":"https://upload.fake/b2api/v3/b2_upload_file/bucket-1/x","authorizationToken":"fake-upload-token"}'
    ;;
  https://upload.fake/*)
    n=$(($(cat "$FAKE_DIR/upload-count" 2>/dev/null || echo 0) + 1))
    echo "$n" >"$FAKE_DIR/upload-count"
    if ((n <= ${FAKE_UPLOAD_FAILS:-0})); then
      respond 503 '{"status":503,"code":"service_unavailable","message":"busy"}'
      exit 0
    fi
    mkdir -p "$FAKE_DIR/store/$(dirname "$name")"
    cp "$upload" "$FAKE_DIR/store/$name"
    echo "$name" >>"$FAKE_DIR/uploads.log"
    sha=$(sha1sum <"$upload"); sha=${sha%% *}
    [[ -z ${FAKE_BAD_SHA1:-} ]] || sha=0000000000000000000000000000000000000000
    respond 200 "{\"fileId\":\"f\",\"fileName\":\"$name\",\"contentSha1\":\"$sha\"}"
    ;;
  *) echo "fake curl: unexpected url $url" >&2; exit 6 ;;
esac
EOF
  chmod +x "$WORK/curl"
}

# BACKUP_DIR の形に 1 組を置き、設定ファイルを作る
new_case() {
  local dir
  dir=$(mktemp -d "$WORK/case.XXXXXX")
  mkdir -p "$dir/backups/db" "$dir/backups/storage" "$dir/store"
  printf 'CREATE TABLE secret_rows (id int);\n' | gzip >"$dir/backups/db/app-20261027-030000.sql.gz"
  printf 'secret_rows\t1\n' >"$dir/backups/db/app-20261027-030000.counts.tsv"
  (cd "$dir/backups/db" && sha256sum app-20261027-030000.sql.gz >app-20261027-030000.sha256)
  printf 'image-bytes\n' | gzip >"$dir/backups/storage/20261027-030500.tar.gz"
  printf 'missing\tcocktails\t1\ta.jpg\n' >"$dir/backups/storage/20261027-030500.check.tsv"
  (cd "$dir/backups/storage" && sha256sum 20261027-030500.tar.gz >20261027-030500.tar.gz.sha256)
  cat >"$dir/offsite.conf" <<CONF
B2_KEY_ID=keyid-1
B2_APPLICATION_KEY=$APP_KEY
B2_BUCKET_ID=bucket-1
OFFSITE_PUBLIC_KEY=$WORK/public.asc
B2_PREFIX=cocktail/
CONF
  : >"$dir/argv.log"
  printf '%s' "$dir"
}

run_upload() {
  local dir=$1
  FAKE_DIR=$dir OFFSITE_CURL=$WORK/curl OFFSITE_RETRY_WAIT=0 \
    bash "$SRC/offsite-upload.sh" "$dir/offsite.conf" \
    "$dir/backups/db/app-20261027-030000.sql.gz" "$dir/backups/storage/20261027-030500.tar.gz" >"$dir/out.log" 2>&1
}

# B2 に置かれたファイルの名前を、並べて出す
stored() {
  (cd "$1/store" && find . -type f | sed 's|^\./||' | LC_ALL=C sort)
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

export FAKE_CAPS FAKE_AUTH_STATUS FAKE_UPLOAD_FAILS FAKE_BAD_SHA1
run_tests
