#!/usr/bin/env bash
#
# ops/backup/daily-backup.sh のテスト。DB は使わない。
#
#   bash ops/backup/tests/daily-backup.test.sh
#
# daily-backup.sh は同じディレクトリの dump-db.sh・verify-restore.sh・archive-storage.sh・offsite-upload.sh・
# prune-backups.sh を呼ぶ。一時ディレクトリに daily-backup.sh と本物の prune-backups.sh を写し、ほかの 4 つを、呼ばれた順と引数を
# 書き残して決めた終了コードを返す偽物に置き換えて流す。

set -uo pipefail

SRC=$(cd "$(dirname "$0")/.." && pwd)
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

FAILED=0
PASSED=0

run_tests() {
  setup_bin
  tc_success_prunes
  tc_dump_failure_stops
  tc_archive_failure_keeps_generations
  tc_verify_runs_right_after_dump
  tc_verify_failure_keeps_generations
  tc_archive_dir_mismatch
  tc_usage_errors
  tc_no_process_substitution
  tc_offsite_after_set
  tc_offsite_failure_still_prunes
  tc_offsite_skipped_when_set_incomplete
  tc_offsite_with_verify_failure
  tc_offsite_usage_error

  printf '\n%d passed, %d failed\n' "$PASSED" "$FAILED"
  [[ $FAILED -eq 0 ]]
}

tc_success_prunes() {
  local case_dir status
  case_dir=$(new_case)
  seed_old_sets "$case_dir"
  run_daily "$case_dir" --daily 2 --weekly 0
  status=$?
  local ok=1
  [[ $status -eq 0 ]] || ok=0
  [[ $(calls "$case_dir") == "dump archive" ]] || ok=0
  # 今日の組と、前の 1 日だけが残る
  [[ $(count_files "$case_dir/backups/db" sql.gz) -eq 2 ]] || ok=0
  [[ $(count_files "$case_dir/backups/storage" tar.gz) -eq 2 ]] || ok=0
  [[ -f $case_dir/backups/db/app-20261027-030000.sql.gz && -f $case_dir/backups/db/app-20261026-030000.sql.gz ]] || ok=0
  [[ -f $case_dir/backups/storage/20261027-030500.tar.gz ]] || ok=0
  check "TC-17-11: ダンプと退避が通ると、1 組ずつでき、世代を超えた古い組が消える(0 で終わる)" "$ok"
}

tc_dump_failure_stops() {
  local case_dir status
  case_dir=$(new_case)
  seed_old_sets "$case_dir"
  FAKE_DUMP_STATUS=1 run_daily "$case_dir" --daily 1 --weekly 0
  status=$?
  local ok=1
  [[ $status -eq 1 ]] || ok=0
  [[ $(calls "$case_dir") == "dump" ]] || ok=0
  [[ $(count_files "$case_dir/backups/db" sql.gz) -eq 3 ]] || ok=0
  grep -q 'ダンプに失敗した' "$case_dir/out.log" || ok=0
  check "TC-17-12: ダンプに失敗したら、退避も世代の削除もせずに止まる(古い組が残る)" "$ok"
}

tc_archive_failure_keeps_generations() {
  local case_dir status
  case_dir=$(new_case)
  seed_old_sets "$case_dir"
  FAKE_ARCHIVE_STATUS=2 run_daily "$case_dir" --daily 1 --weekly 0
  status=$?
  local ok=1
  [[ $status -eq 2 ]] || ok=0
  [[ $(calls "$case_dir") == "dump archive" ]] || ok=0
  # 今日のダンプ + 古い 3 組。どれも消えていない
  [[ $(count_files "$case_dir/backups/db" sql.gz) -eq 4 ]] || ok=0
  grep -q '古い世代は消さない' "$case_dir/out.log" || ok=0
  check "TC-17-13: 画像の退避が検証に通らない(2)と、古い世代を消さず、2 で終わる" "$ok"
}

tc_verify_runs_right_after_dump() {
  local case_dir status
  case_dir=$(new_case)
  run_daily "$case_dir" --verify-restore app_verify --storage-dir "$case_dir/images"
  status=$?
  local ok=1
  [[ $status -eq 0 ]] || ok=0
  [[ $(calls "$case_dir") == "dump verify archive" ]] || ok=0
  grep -qxF "verify $case_dir/app.env $case_dir/backups/db/app-20261027-030000.sql.gz app_verify" "$case_dir/calls.log" || ok=0
  grep -qxF "archive $case_dir/app.env $case_dir/images" "$case_dir/calls.log" || ok=0
  check "TC-17-14: --verify-restore を付けると、ダンプの直後に、そのダンプと復元先の DB 名で verify-restore.sh を呼ぶ" "$ok"
}

tc_verify_failure_keeps_generations() {
  local case_dir status
  case_dir=$(new_case)
  seed_old_sets "$case_dir"
  FAKE_VERIFY_STATUS=2 run_daily "$case_dir" --verify-restore app_verify --daily 1 --weekly 0
  status=$?
  local ok=1
  [[ $status -eq 2 ]] || ok=0
  # 復元の確かめが通らなくても、その日の画像の退避は取る
  [[ $(calls "$case_dir") == "dump verify archive" ]] || ok=0
  [[ $(count_files "$case_dir/backups/db" sql.gz) -eq 4 ]] || ok=0
  [[ -f $case_dir/backups/storage/20261027-030500.tar.gz ]] || ok=0
  check "TC-17-15: 復元の確かめが通らない(2)と、退避は取るが古い世代は消さず、2 で終わる" "$ok"
}

tc_archive_dir_mismatch() {
  local case_dir status
  case_dir=$(new_case)
  seed_old_sets "$case_dir"
  # archive-storage.sh が ~/ を展開せずに別の場所へ書いた、という状況
  FAKE_ARCHIVE_ROOT="$case_dir/elsewhere" run_daily "$case_dir" --daily 1 --weekly 0
  status=$?
  local ok=1
  [[ $status -eq 1 ]] || ok=0
  [[ $(count_files "$case_dir/backups/db" sql.gz) -eq 4 ]] || ok=0
  grep -q 'ダンプの置き場所の storage/' "$case_dir/out.log" || ok=0
  check "TC-17-16: 画像の退避先がダンプと同じ BACKUP_DIR の storage/ でなければ、古い世代を消さずに 1 で終わる" "$ok"
}

tc_usage_errors() {
  local case_dir ok=1
  case_dir=$(new_case)
  run_daily "$case_dir" --verify-restore && ok=0
  run_daily "$case_dir" --daily 0 && ok=0
  run_daily "$case_dir" --weekly x && ok=0
  bash "$BIN/daily-backup.sh" >/dev/null 2>&1 && ok=0
  # どれもダンプを始める前に止まる
  [[ ! -s $case_dir/calls.log ]] || ok=0
  check "TC-17-17: 引数の誤り(値の無い --verify-restore・世代数の誤り・.env 無し)では、ダンプを始める前に 1 で止まる" "$ok"
}

tc_no_process_substitution() {
  # 本番のサーバー(CageFS)には /dev/fd が無く、プロセス置換が失敗する。コメントの行は見ない
  local ok=1
  grep -nE '^[^#]*<\(' "$SRC"/*.sh >&2 && ok=0
  check "TC-17-27: サーバーで動くスクリプト(ops/backup/*.sh)は、プロセス置換(<(…))を使わない" "$ok"
}

tc_offsite_after_set() {
  local case_dir status ok=1
  case_dir=$(new_case)
  seed_old_sets "$case_dir"
  run_daily "$case_dir" --offsite "$case_dir/offsite.conf" --daily 2 --weekly 0
  status=$?
  [[ $status -eq 0 ]] || ok=0
  [[ $(calls "$case_dir") == "dump archive offsite" ]] || ok=0
  grep -qxF "offsite $case_dir/offsite.conf $case_dir/backups/db/app-20261027-030000.sql.gz $case_dir/backups/storage/20261027-030500.tar.gz" "$case_dir/calls.log" || ok=0
  [[ $(count_files "$case_dir/backups/db" sql.gz) -eq 2 ]] || ok=0
  grep -q 'B2 に送った' "$case_dir/out.log" || ok=0
  check "TC-373-14: --offsite を付けると、その日の組を作った後に、設定ファイル・その日のダンプ・アーカイブで offsite-upload.sh を呼び、世代を消して 0 で終わる" "$ok"
}

tc_offsite_failure_still_prunes() {
  local case_dir status ok=1
  case_dir=$(new_case)
  seed_old_sets "$case_dir"
  FAKE_OFFSITE_STATUS=3 run_daily "$case_dir" --offsite "$case_dir/offsite.conf" --daily 2 --weekly 0
  status=$?
  [[ $status -eq 3 ]] || ok=0
  # 送れなくても、サーバーの古い世代は消す
  [[ $(count_files "$case_dir/backups/db" sql.gz) -eq 2 ]] || ok=0
  [[ $(count_files "$case_dir/backups/storage" tar.gz) -eq 2 ]] || ok=0
  grep -q 'B2 に送れなかった(offsite-upload.sh の終了コード 3)' "$case_dir/out.log" || ok=0
  grep -q '古い世代は消したが、B2 に送れなかった(終了コード 3)' "$case_dir/out.log" || ok=0
  check "TC-373-15: B2 に送れないと、サーバーの古い世代は消したうえで、ログに残して 3 で終わる" "$ok"
}

tc_offsite_skipped_when_set_incomplete() {
  local case_dir ok=1
  case_dir=$(new_case)
  FAKE_ARCHIVE_STATUS=2 run_daily "$case_dir" --offsite "$case_dir/offsite.conf"
  [[ $? -eq 2 ]] || ok=0
  [[ $(calls "$case_dir") == "dump archive" ]] || ok=0
  case_dir=$(new_case)
  FAKE_ARCHIVE_ROOT="$case_dir/elsewhere" run_daily "$case_dir" --offsite "$case_dir/offsite.conf"
  [[ $? -eq 1 ]] || ok=0
  [[ $(calls "$case_dir") == "dump archive" ]] || ok=0
  case_dir=$(new_case)
  FAKE_DUMP_STATUS=1 run_daily "$case_dir" --offsite "$case_dir/offsite.conf"
  [[ $? -eq 1 ]] || ok=0
  [[ $(calls "$case_dir") == "dump" ]] || ok=0
  check "TC-373-16: ダンプ・退避が取れなかった日(失敗・退避先の食い違い)は、B2 に送らない" "$ok"
}

tc_offsite_with_verify_failure() {
  local case_dir ok=1
  case_dir=$(new_case)
  seed_old_sets "$case_dir"
  FAKE_VERIFY_STATUS=2 FAKE_OFFSITE_STATUS=3 run_daily "$case_dir" --verify-restore app_verify --offsite "$case_dir/offsite.conf" --daily 1 --weekly 0
  [[ $? -eq 2 ]] || ok=0
  [[ $(calls "$case_dir") == "dump verify archive offsite" ]] || ok=0
  [[ $(count_files "$case_dir/backups/db" sql.gz) -eq 4 ]] || ok=0
  check "TC-373-17: 復元の確かめが通らない日も組はそろっているので B2 に送り、古い世代は消さず、終了コードは復元の確かめの 2 を優先する" "$ok"
}

tc_offsite_usage_error() {
  local case_dir ok=1
  case_dir=$(new_case)
  run_daily "$case_dir" --offsite && ok=0
  run_daily "$case_dir" --offsite --daily 1 && ok=0
  [[ ! -s $case_dir/calls.log ]] || ok=0
  check "TC-373-18: 値の無い --offsite では、ダンプを始める前に 1 で止まる" "$ok"
}

# ---- 補助 ----

BIN=$WORK/bin

setup_bin() {
  mkdir -p "$BIN"
  cp "$SRC/daily-backup.sh" "$SRC/prune-backups.sh" "$BIN/"

  # 偽物の dump-db.sh: .env の BACKUP_DIR の db/ に 1 組作り、.sql.gz のパスを出す
  cat >"$BIN/dump-db.sh" <<'EOF'
#!/usr/bin/env bash
printf 'dump %s\n' "$*" >>"$FAKE_CALLS"
[[ ${FAKE_DUMP_STATUS:-0} -eq 0 ]] || exit "$FAKE_DUMP_STATUS"
dir=$(sed -n 's/^BACKUP_DIR=//p' "$1")/db
mkdir -p "$dir"
for ext in sql.gz sha256 counts.tsv; do printf 'x\n' >"$dir/app-20261027-030000.$ext"; done
printf '%s\n' "$dir/app-20261027-030000.sql.gz"
EOF

  cat >"$BIN/verify-restore.sh" <<'EOF'
#!/usr/bin/env bash
printf 'verify %s\n' "$*" >>"$FAKE_CALLS"
exit "${FAKE_VERIFY_STATUS:-0}"
EOF

  # 偽物の archive-storage.sh: storage/ に 1 組作り、archive-storage.sh と同じ形で「archive: <パス>」を出す
  cat >"$BIN/archive-storage.sh" <<'EOF'
#!/usr/bin/env bash
printf 'archive %s\n' "$*" >>"$FAKE_CALLS"
root=${FAKE_ARCHIVE_ROOT:-$(sed -n 's/^BACKUP_DIR=//p' "$1")}
mkdir -p "$root/storage"
for ext in tar.gz tar.gz.sha256 check.tsv; do printf 'x\n' >"$root/storage/20261027-030500.$ext"; done
echo "archive: $root/storage/20261027-030500.tar.gz"
echo "missing: 0"
exit "${FAKE_ARCHIVE_STATUS:-0}"
EOF

  cat >"$BIN/offsite-upload.sh" <<'EOF'
#!/usr/bin/env bash
printf 'offsite %s\n' "$*" >>"$FAKE_CALLS"
exit "${FAKE_OFFSITE_STATUS:-0}"
EOF
}

new_case() {
  local dir
  dir=$(mktemp -d "$WORK/case.XXXXXX")
  mkdir -p "$dir/backups" "$dir/images"
  printf 'DB_DATABASE=app\nBACKUP_DIR=%s\n' "$dir/backups" >"$dir/app.env"
  : >"$dir/calls.log"
  printf '%s' "$dir"
}

# 前の 3 日分(10-24〜10-26)の組を置く
seed_old_sets() {
  local dir=$1 day ext
  mkdir -p "$dir/backups/db" "$dir/backups/storage"
  for day in 20261024 20261025 20261026; do
    for ext in sql.gz sha256 counts.tsv; do printf 'x\n' >"$dir/backups/db/app-$day-030000.$ext"; done
    for ext in tar.gz tar.gz.sha256 check.tsv; do printf 'x\n' >"$dir/backups/storage/$day-030500.$ext"; done
  done
}

run_daily() {
  local dir=$1
  shift
  FAKE_CALLS=$dir/calls.log bash "$BIN/daily-backup.sh" "$dir/app.env" "$@" >"$dir/out.log" 2>&1
}

# ディレクトリにある、拡張子が <ext> のファイルの数
count_files() {
  local f n=0
  for f in "$1"/*."$2"; do
    [[ -f $f ]] && n=$((n + 1))
  done
  printf '%d' "$n"
}

# 呼ばれたスクリプトを、呼ばれた順に空白区切りで出す
calls() {
  cut -d ' ' -f 1 "$1/calls.log" | paste -sd ' ' -
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

export FAKE_DUMP_STATUS FAKE_VERIFY_STATUS FAKE_ARCHIVE_STATUS FAKE_ARCHIVE_ROOT FAKE_OFFSITE_STATUS
run_tests
