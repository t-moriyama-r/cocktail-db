#!/usr/bin/env bash
#
# ops/cutover/migrate-data.sh のテスト。DB は使わない。
#
#   bash ops/cutover/tests/migrate-data.test.sh
#
# migrate-data.sh は ../backup/daily-backup.sh・../backup/dump-db.sh・../migrate/copy-storage.sh と php artisan を呼ぶ
# (legacy:count-anomalies・migrate・legacy:import・legacy:verify・legacy:verify-images)。
# 一時ディレクトリに ops/ の形で migrate-data.sh と本物の copy-storage.sh を写し、daily-backup.sh・dump-db.sh と
# PHP(環境変数 PHP で差し替える)を、呼ばれた順と引数を書き残して決めた終了コードを返す偽物にして流す。

set -uo pipefail

SRC=$(cd "$(dirname "$0")/../.." && pwd)
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
OPS=$WORK/ops

FAILED=0
PASSED=0

ALL_CALLS="backup recount dump-new migrate import verify verify-images"

run_tests() {
  setup_ops
  tc_all_steps
  tc_rerun_skips_done
  tc_failure_stops_and_resumes
  tc_backup_failure_stops
  tc_backup_offsite_failure_continues
  tc_copy_conflict_stops
  tc_verify_failure
  tc_verify_images_missing
  tc_args_changed
  tc_restart
  tc_from
  tc_dump_new_failure
  tc_recount_different_stops
  tc_recount_accepted
  tc_recount_failure
  tc_usage_errors
  tc_no_process_substitution

  printf '\n%d passed, %d failed\n' "$PASSED" "$FAILED"
  [[ $FAILED -eq 0 ]]
}

tc_all_steps() {
  local c status ok=1
  c=$(new_case)
  run_migrate "$c"
  status=$?
  [[ $status -eq 0 ]] || ok=0
  [[ $(calls "$c") == "$ALL_CALLS" ]] || ok=0
  # 画像は本物の copy-storage.sh で写る
  [[ -f $c/new/storage/app/public/a.jpg ]] || ok=0
  grep -q 'すべての手順が済んだ' "$c/out.log" || ok=0
  local s
  for s in backup recount dump-new migrate import copy-storage verify; do
    [[ -f $c/state/$s.done ]] || ok=0
    grep -q '^seconds=[0-9]' "$c/state/$s.done" || ok=0
  done
  grep -qx "dump=$c/backups/db/legacy-20261123-030000.sql.gz" "$c/state/backup.done" || ok=0
  grep -qx 'missing=1' "$c/state/backup.done" || ok=0
  grep -qx "dump=$c/backups/db/app-20261123-030000.sql.gz" "$c/state/dump-new.done" || ok=0
  # migrate は --force を付け、artisan は新アプリのディレクトリで流す
  grep -qx "migrate --force --no-interaction @$c/new" "$c/php.log" || ok=0
  # 数え直しは、新アプリの artisan で旧 DB(LEGACY_DB_*)に流す
  grep -qx "legacy:count-anomalies --no-interaction @$c/new" "$c/php.log" || ok=0
  check "TC-139-01: 手順 2〜6 を順に流し、すべて済むと 0 で終わる(済みの記録とかかった秒数が残る)" "$ok"
}

tc_rerun_skips_done() {
  local c status ok=1 before
  c=$(new_case)
  run_migrate "$c"
  before=$(cat "$c/state/dump-new.done")
  : >"$c/calls.log"
  run_migrate "$c"
  status=$?
  [[ $status -eq 0 ]] || ok=0
  [[ -z $(calls "$c") ]] || ok=0
  [[ $(grep -c '済み(.*)。飛ばす' "$c/out.log") -eq 7 ]] || ok=0
  [[ $(cat "$c/state/dump-new.done") == "$before" ]] || ok=0
  check "TC-139-02: すべて済んだ後に流し直すと、どの手順も流さずに 0 で終わる(新 DB のダンプを取り直さない)" "$ok"
}

tc_failure_stops_and_resumes() {
  local c status ok=1
  c=$(new_case)
  FAKE_IMPORT_STATUS=1 run_migrate "$c"
  status=$?
  [[ $status -eq 1 ]] || ok=0
  [[ $(calls "$c") == "backup recount dump-new migrate import" ]] || ok=0
  [[ ! -e $c/new/storage/app/public/a.jpg ]] || ok=0
  grep -q '\[import\] で止まった(終了コード 1)' "$c/out.log" || ok=0
  grep -q '済みの手順: backup recount dump-new migrate$' "$c/out.log" || ok=0
  grep -q '止まった手順: import' "$c/out.log" || ok=0
  grep -q 'import から続ける' "$c/out.log" || ok=0
  [[ ! -e $c/state/import.done && ! -e $c/state/import.done.tmp ]] || ok=0
  check "TC-139-03: 途中の手順が失敗したら止まり、済みの手順・止まった手順・続け方を出す(後ろの手順は流さない)" "$ok"

  ok=1
  : >"$c/calls.log"
  run_migrate "$c"
  status=$?
  [[ $status -eq 0 ]] || ok=0
  [[ $(calls "$c") == "import verify verify-images" ]] || ok=0
  [[ -f $c/new/storage/app/public/a.jpg ]] || ok=0
  check "TC-139-04: 直して流し直すと、止まった手順から続ける(済みの backup・recount・dump-new・migrate は流さない)" "$ok"
}

tc_backup_failure_stops() {
  local c status ok=1
  c=$(new_case)
  FAKE_BACKUP_STATUS=2 run_migrate "$c"
  status=$?
  [[ $status -eq 2 ]] || ok=0
  [[ $(calls "$c") == "backup" ]] || ok=0
  grep -q '済みの手順: (無し)' "$c/out.log" || ok=0
  [[ ! -e $c/state/backup.done ]] || ok=0
  check "TC-139-05: バックアップか復元の確かめが通らなければ(daily-backup.sh が 2)、2 で止まり、新 DB に触らない" "$ok"
}

tc_backup_offsite_failure_continues() {
  local c status ok=1
  c=$(new_case)
  printf 'x\n' >"$c/offsite.conf"
  FAKE_BACKUP_STATUS=3 run_migrate "$c" --offsite "$c/offsite.conf"
  status=$?
  [[ $status -eq 0 ]] || ok=0
  [[ $(calls "$c") == "$ALL_CALLS" ]] || ok=0
  grep -q -- "--offsite $c/offsite.conf" "$c/backup-args.log" || ok=0
  grep -q 'B2 には送れなかったが' "$c/out.log" || ok=0
  check "TC-139-06: B2 に送れなかっただけ(daily-backup.sh が 3)なら、先へ進む" "$ok"
}

tc_copy_conflict_stops() {
  local c status ok=1
  c=$(new_case)
  printf 'other\n' >"$c/new/storage/app/public/a.jpg"
  run_migrate "$c"
  status=$?
  [[ $status -eq 2 ]] || ok=0
  [[ $(calls "$c") == "backup recount dump-new migrate import" ]] || ok=0
  [[ $(cat "$c/new/storage/app/public/a.jpg") == other ]] || ok=0
  grep -q '\[copy-storage\] で止まった(終了コード 2)' "$c/out.log" || ok=0
  grep -q 'どちらを残すかを決めてから' "$c/out.log" || ok=0
  check "TC-139-07: 中身の違う同じ名前の画像があると、上書きせずに 2 で止まる" "$ok"

  ok=1
  cp "$c/old-images/a.jpg" "$c/new/storage/app/public/a.jpg"
  : >"$c/calls.log"
  run_migrate "$c"
  status=$?
  [[ $status -eq 0 ]] || ok=0
  [[ $(calls "$c") == "verify verify-images" ]] || ok=0
  check "TC-139-08: 食い違いを片付けて流し直すと、画像の手順から続けて済む" "$ok"
}

tc_verify_failure() {
  local c status ok=1
  c=$(new_case)
  FAKE_VERIFY_STATUS=1 run_migrate "$c"
  status=$?
  [[ $status -eq 2 ]] || ok=0
  [[ $(calls "$c") == "backup recount dump-new migrate import verify" ]] || ok=0
  grep -q '7 に進まない' "$c/out.log" || ok=0
  [[ ! -e $c/state/verify.done ]] || ok=0
  check "TC-139-09: legacy:verify が通らなければ、2 で止まる" "$ok"
}

tc_verify_images_missing() {
  local c status ok=1
  c=$(new_case)
  # 旧から 1 件欠けていて、新でも同じ 1 件が欠ける(verify-images は 1 を返す)
  FAKE_IMAGES_MISSING=1 FAKE_IMAGES_STATUS=1 run_migrate "$c"
  status=$?
  [[ $status -eq 0 ]] || ok=0
  grep -q '画像の missing は 1 件で、旧から欠けていた件数と同じ' "$c/out.log" || ok=0
  check "TC-139-10: legacy:verify-images の missing が、旧から欠けていた件数と同じなら通す" "$ok"

  ok=1
  c=$(new_case)
  FAKE_IMAGES_MISSING=2 FAKE_IMAGES_STATUS=1 run_migrate "$c"
  status=$?
  [[ $status -eq 2 ]] || ok=0
  grep -q 'missing(2)が、旧から欠けていた件数(1)と違う' "$c/out.log" || ok=0
  [[ ! -e $c/state/verify.done ]] || ok=0
  check "TC-139-11: legacy:verify-images の missing が、旧から欠けていた件数と違えば、2 で止まる" "$ok"

  ok=1
  c=$(new_case)
  FAKE_IMAGES_MISSING=none FAKE_IMAGES_STATUS=1 run_migrate "$c"
  status=$?
  [[ $status -eq 2 ]] || ok=0
  grep -q 'missing を読めない' "$c/out.log" || ok=0
  check "TC-139-12: legacy:verify-images が件数を出さずに落ちたら、2 で止まる" "$ok"
}

tc_args_changed() {
  local c status ok=1
  c=$(new_case)
  FAKE_IMPORT_STATUS=1 run_migrate "$c"
  : >"$c/calls.log"
  mkdir -p "$c/other-images"
  run_migrate_with "$c" --old-env "$c/legacy.env" --old-storage "$c/other-images" --verify-db verify_db \
    --new-app "$c/new" --state-dir "$c/state"
  status=$?
  [[ $status -eq 1 ]] || ok=0
  [[ -z $(calls "$c") ]] || ok=0
  grep -q '前に流したときと引数が違う' "$c/out.log" || ok=0
  grep -q -- '--restart を付ける' "$c/out.log" || ok=0
  check "TC-139-13: 前と違う引数で流すと、どの手順も流さずに 1 で止まる" "$ok"
}

tc_restart() {
  local c status ok=1
  c=$(new_case)
  run_migrate "$c"
  : >"$c/calls.log"
  run_migrate "$c" --restart
  status=$?
  [[ $status -eq 0 ]] || ok=0
  [[ $(calls "$c") == "$ALL_CALLS" ]] || ok=0
  check "TC-139-14: --restart を付けると、済みの記録を外して手順 2 から流し直す" "$ok"
}

tc_from() {
  local c status ok=1
  c=$(new_case)
  run_migrate "$c"
  : >"$c/calls.log"
  run_migrate "$c" --from migrate
  status=$?
  [[ $status -eq 0 ]] || ok=0
  [[ $(calls "$c") == "migrate import verify verify-images" ]] || ok=0
  [[ -f $c/state/backup.done && -f $c/state/recount.done && -f $c/state/dump-new.done ]] || ok=0
  check "TC-139-15: --from migrate を付けると、migrate から後ろだけを流し直す(新 DB のダンプは取り直さない)" "$ok"
}

tc_dump_new_failure() {
  local c status ok=1
  c=$(new_case)
  FAKE_DUMP_STATUS=1 run_migrate "$c"
  status=$?
  [[ $status -eq 1 ]] || ok=0
  [[ $(calls "$c") == "backup recount dump-new" ]] || ok=0
  grep -q 'BACKUP_DIR があるかを見る' "$c/out.log" || ok=0
  grep -q '済みの手順: backup recount$' "$c/out.log" || ok=0
  check "TC-139-16: 新 DB のダンプが取れなければ、移さずに止まる" "$ok"
}

tc_recount_different_stops() {
  local c status ok=1
  c=$(new_case)
  FAKE_RECOUNT_STATUS=2 run_migrate "$c"
  status=$?
  [[ $status -eq 2 ]] || ok=0
  [[ $(calls "$c") == "backup recount" ]] || ok=0
  grep -q '\[recount\] で止まった(終了コード 2)' "$c/out.log" || ok=0
  grep -q 'docs/cutover.md の 3 の 2 の基準で' "$c/out.log" || ok=0
  grep -q -- '--accept-recount を付けて流し直す' "$c/out.log" || ok=0
  grep -q 'docs/migration-anomalies.md の件数と違う: 1 件' "$c/state/recount.log" || ok=0
  [[ ! -e $c/state/recount.done ]] || ok=0
  check "TC-819-10: 数え直しが文書と違えば(legacy:count-anomalies が 2)、2 で止まり、新 DB に触らない(違ったものは recount.log に残る)" "$ok"

  ok=1
  : >"$c/calls.log"
  run_migrate "$c"
  status=$?
  [[ $status -eq 0 ]] || ok=0
  [[ $(calls "$c") == "recount dump-new migrate import verify verify-images" ]] || ok=0
  ! grep -q '^accepted=' "$c/state/recount.done" || ok=0
  check "TC-819-11: 旧で直して文書と合うようになってから流し直すと、数え直しから続ける(backup は流さない)" "$ok"
}

tc_recount_accepted() {
  local c status ok=1
  c=$(new_case)
  FAKE_RECOUNT_STATUS=2 run_migrate "$c"
  : >"$c/calls.log"
  FAKE_RECOUNT_STATUS=2 run_migrate "$c" --accept-recount
  status=$?
  [[ $status -eq 0 ]] || ok=0
  [[ $(calls "$c") == "recount dump-new migrate import verify verify-images" ]] || ok=0
  ! grep -q '前に流したときと引数が違う' "$c/out.log" || ok=0
  grep -q -- '--accept-recount が付いているので進む' "$c/out.log" || ok=0
  grep -qx 'accepted=1' "$c/state/recount.done" || ok=0
  check "TC-819-12: 進めると決めて --accept-recount を付けて流し直すと、違う件数のまま進み、進めたことが済みの記録に残る" "$ok"
}

tc_recount_failure() {
  local c status ok=1
  c=$(new_case)
  FAKE_RECOUNT_STATUS=1 run_migrate "$c" --accept-recount
  status=$?
  [[ $status -eq 1 ]] || ok=0
  [[ $(calls "$c") == "backup recount" ]] || ok=0
  grep -q 'LEGACY_DB_\* で旧 DB を読めるかを見る' "$c/out.log" || ok=0
  [[ ! -e $c/state/recount.done ]] || ok=0
  check "TC-819-13: 数え直しの SQL を流せなければ(legacy:count-anomalies が 1)、--accept-recount を付けていても止まる" "$ok"
}

tc_usage_errors() {
  local c ok=1
  c=$(new_case)
  run_migrate_with "$c" --old-env "$c/legacy.env" --old-storage "$c/old-images" && ok=0
  run_migrate "$c" --from nothing && ok=0
  grep -q -- '--from に知らない手順が渡された: nothing' "$c/out.log" || ok=0
  run_migrate "$c" --verify-db && ok=0
  run_migrate_with "$c" --old-env "$c/missing.env" --old-storage "$c/old-images" --verify-db v \
    --new-app "$c/new" --state-dir "$c/state" && ok=0
  grep -q '旧アプリの .env が見つからない' "$c/out.log" || ok=0
  [[ -z $(calls "$c") ]] || ok=0
  check "TC-139-17: 引数が足りない・知らない手順・無いファイルは、何も流さずに 1 で終わる" "$ok"
}

tc_no_process_substitution() {
  # 本番のサーバー(CageFS)には /dev/fd が無く、プロセス置換が失敗する(ops/backup と同じ)。コメントの行は見ない
  local ok=1
  grep -nE '^[^#]*<\(' "$SRC"/cutover/*.sh >&2 && ok=0
  check "TC-139-18: サーバーで動くスクリプト(ops/cutover/*.sh)は、プロセス置換(<(…))を使わない" "$ok"
}

# --- 下回り ---

setup_ops() {
  mkdir -p "$OPS/cutover" "$OPS/backup" "$OPS/migrate" "$WORK/bin"
  cp "$SRC/cutover/migrate-data.sh" "$OPS/cutover/"
  cp "$SRC/migrate/copy-storage.sh" "$OPS/migrate/"

  # 偽物の daily-backup.sh: 本物と同じ形で「ダンプ: <パス>」「check: <パス>」を出し、check.tsv に missing を 1 件書く
  cat >"$OPS/backup/daily-backup.sh" <<'EOF'
#!/usr/bin/env bash
echo backup >>"$FAKE_CALLS"
printf '%s\n' "$*" >"$FAKE_CASE/backup-args.log"
dir=$FAKE_CASE/backups
mkdir -p "$dir/db" "$dir/storage"
printf 'x\n' >"$dir/db/legacy-20261123-030000.sql.gz"
printf 'missing\tt_cocktails\t1\tlost.jpg\n' >"$dir/storage/20261123-030500.check.tsv"
echo "2026-11-23 03:00:00 daily-backup: ダンプ: $dir/db/legacy-20261123-030000.sql.gz"
echo "check: $dir/storage/20261123-030500.check.tsv"
echo "missing: 1"
echo "2026-11-23 03:05:00 daily-backup: 終わった"
exit "${FAKE_BACKUP_STATUS:-0}"
EOF

  # 偽物の dump-db.sh: 標準出力にダンプのパスだけを出す
  cat >"$OPS/backup/dump-db.sh" <<'EOF'
#!/usr/bin/env bash
echo dump-new >>"$FAKE_CALLS"
[[ ${FAKE_DUMP_STATUS:-0} -eq 0 ]] || { echo 'dump-db: BACKUP_DIR が無い' >&2; exit "$FAKE_DUMP_STATUS"; }
mkdir -p "$FAKE_CASE/backups/db"
printf 'x\n' >"$FAKE_CASE/backups/db/app-20261123-030000.sql.gz"
echo "$FAKE_CASE/backups/db/app-20261123-030000.sql.gz"
EOF

  # 偽物の PHP: artisan のコマンドごとに、呼ばれたことを残して決めた終了コードを返す
  cat >"$WORK/bin/php" <<'EOF'
#!/usr/bin/env bash
[[ $1 == artisan ]] || exit 99
shift
printf '%s @%s\n' "$*" "$PWD" >>"$FAKE_CASE/php.log"
case $1 in
  migrate) echo migrate >>"$FAKE_CALLS"; exit "${FAKE_MIGRATE_STATUS:-0}" ;;
  legacy:import) echo import >>"$FAKE_CALLS"; echo 'misc:'; exit "${FAKE_IMPORT_STATUS:-0}" ;;
  legacy:verify) echo verify >>"$FAKE_CALLS"; exit "${FAKE_VERIFY_STATUS:-0}" ;;
  legacy:count-anomalies)
    echo recount >>"$FAKE_CALLS"
    [[ ${FAKE_RECOUNT_STATUS:-0} -ne 2 ]] || echo 'docs/migration-anomalies.md の件数と違う: 1 件(15)'
    exit "${FAKE_RECOUNT_STATUS:-0}"
    ;;
  legacy:verify-images)
    echo verify-images >>"$FAKE_CALLS"
    echo 'found: 3'
    [[ ${FAKE_IMAGES_MISSING:-1} == none ]] || echo "missing: ${FAKE_IMAGES_MISSING:-1}"
    echo 'external: 0'
    exit "${FAKE_IMAGES_STATUS:-1}"
    ;;
esac
exit 98
EOF
  chmod +x "$OPS"/*/*.sh "$WORK/bin/php"
}

new_case() {
  local dir
  dir=$(mktemp -d "$WORK/case.XXXXXX")
  mkdir -p "$dir/old-images" "$dir/new/storage/app/public" "$dir/backups"
  printf 'img\n' >"$dir/old-images/a.jpg"
  printf 'DB_DATABASE=legacy\n' >"$dir/legacy.env"
  printf 'DB_DATABASE=app\n' >"$dir/new/.env"
  : >"$dir/new/artisan"
  : >"$dir/calls.log"
  printf '%s' "$dir"
}

# 決まった引数で流す。足す引数は後ろに付ける
run_migrate() {
  local dir=$1
  shift
  run_migrate_with "$dir" --old-env "$dir/legacy.env" --old-storage "$dir/old-images" --verify-db verify_db \
    --new-app "$dir/new" --state-dir "$dir/state" "$@"
}

run_migrate_with() {
  local dir=$1
  shift
  FAKE_CALLS=$dir/calls.log FAKE_CASE=$dir PHP=$WORK/bin/php HOME=$dir \
    bash "$OPS/cutover/migrate-data.sh" "$@" >"$dir/out.log" 2>&1
}

# copy-storage.sh は本物なので calls には出ない。順は calls.log の行
calls() {
  paste -sd ' ' "$1/calls.log"
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
