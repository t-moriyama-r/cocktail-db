#!/usr/bin/env bash
#
# ops/backup/prune-backups.sh のテスト。DB は使わない(一時ディレクトリに名前だけの組を作って流す)。
#
#   bash ops/backup/tests/prune-backups.test.sh

set -uo pipefail

SCRIPT=$(cd "$(dirname "$0")/.." && pwd)/prune-backups.sh
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

FAILED=0
PASSED=0

run_tests() {
  tc_usage_errors
  tc_daily_keeps_newest_days
  tc_daily_counts_days_not_sets
  tc_weekly_keeps_newest_of_each_week
  tc_default_generations
  tc_stale_backups_are_not_aged_out
  tc_db_and_storage_counted_separately
  tc_db_names_counted_separately
  tc_unknown_files_untouched
  tc_dry_run

  printf '\n%d passed, %d failed\n' "$PASSED" "$FAILED"
  [[ $FAILED -eq 0 ]]
}

tc_usage_errors() {
  local dir
  dir=$(new_backup_dir)
  local ok=1 out
  out=$(bash "$SCRIPT" 2>&1) && ok=0
  out=$(bash "$SCRIPT" "$WORK/nothing" 2>&1) && ok=0
  [[ $out == *"ディレクトリが無い"* ]] || ok=0
  out=$(bash "$SCRIPT" "$dir" --daily 0 2>&1) && ok=0
  [[ $out == *"--daily には 1 以上"* ]] || ok=0
  bash "$SCRIPT" "$dir" --daily x >/dev/null 2>&1 && ok=0
  bash "$SCRIPT" "$dir" --weekly -1 >/dev/null 2>&1 && ok=0
  bash "$SCRIPT" "$dir" --weekly >/dev/null 2>&1 && ok=0
  # --check は世代数だけを確かめ、ディレクトリを見ない
  bash "$SCRIPT" --check --daily 0 >/dev/null 2>&1 && ok=0
  bash "$SCRIPT" --check --daily 3 --weekly 0 >/dev/null 2>&1 || ok=0
  check "TC-17-01: 引数の誤り(ディレクトリが無い・--daily 0・数でない世代数)では 1 で止まる(--check も同じ判定)" "$ok"
}

tc_daily_keeps_newest_days() {
  local dir
  dir=$(new_backup_dir)
  local day
  for day in 20261016 20261017 20261018 20261019 20261020; do
    make_db_set "$dir" app "$day-030000"
  done
  bash "$SCRIPT" "$dir" --daily 3 --weekly 0 >/dev/null
  local ok=1
  [[ $(db_stamps "$dir" app) == "20261018-030000 20261019-030000 20261020-030000" ]] || ok=0
  # 消した組は、本体・ハッシュ・行数の 3 つとも消えている
  [[ -z $(find "$dir/db" -name 'app-20261016-*' -o -name 'app-20261017-*') ]] || ok=0
  [[ -f $dir/db/app-20261018-030000.sha256 && -f $dir/db/app-20261018-030000.counts.tsv ]] || ok=0
  check "TC-17-02: --daily 3 で、新しい 3 日分の組だけが残り、古い組は 3 つのファイルとも消える" "$ok"
}

tc_daily_counts_days_not_sets() {
  local dir
  dir=$(new_backup_dir)
  make_db_set "$dir" app 20261019-030000
  make_db_set "$dir" app 20261020-030000
  make_db_set "$dir" app 20261020-150000
  bash "$SCRIPT" "$dir" --daily 2 --weekly 0 >/dev/null
  local ok=1
  [[ $(db_stamps "$dir" app) == "20261019-030000 20261020-150000" ]] || ok=0
  check "TC-17-03: 同じ日に 2 組あると、その日のいちばん新しい組を残し、日の数で数える" "$ok"
}

tc_weekly_keeps_newest_of_each_week() {
  local dir
  dir=$(new_backup_dir)
  # 2026-09-28(月)〜2026-10-25(日)の 4 週。ISO 週は月曜始まり
  make_days "$dir" 20260928 28
  bash "$SCRIPT" "$dir" --daily 2 --weekly 3 >/dev/null
  local ok=1
  # 日次: 10-24・10-25。週次: W43 の 10-25・W42 の 10-18(日)・W41 の 10-11(日)。W40 は 4 週目なので残らない
  [[ $(db_stamps "$dir" app) == "20261011-030000 20261018-030000 20261024-030000 20261025-030000" ]] || ok=0
  [[ $(storage_stamps "$dir") == "20261011-030500 20261018-030500 20261024-030500 20261025-030500" ]] || ok=0
  check "TC-17-04: --weekly 3 で、新しい 3 週のそれぞれでいちばん新しい組を残す(週の境は月曜。日次と重なってよい)" "$ok"
}

tc_default_generations() {
  local dir
  dir=$(new_backup_dir)
  # 2026-09-16〜2026-10-25 の 40 日分
  make_days "$dir" 20260916 40
  bash "$SCRIPT" "$dir" >/dev/null
  local ok=1
  local expected="20261004-030000 20261011-030000 20261018-030000"
  expected+=" 20261019-030000 20261020-030000 20261021-030000 20261022-030000 20261023-030000 20261024-030000 20261025-030000"
  [[ $(db_stamps "$dir" app) == "$expected" ]] || ok=0
  check "TC-17-05: 既定(日次 7・週次 4)では、直近 7 日と、直近 4 週の各週の最新(1 つは日次と重なる)の 10 組が残る" "$ok"
}

tc_stale_backups_are_not_aged_out() {
  local dir
  dir=$(new_backup_dir)
  # 定期実行が止まって、何か月も前の組しか無い
  make_days "$dir" 20250101 10
  bash "$SCRIPT" "$dir" --daily 3 --weekly 0 >/dev/null
  bash "$SCRIPT" "$dir" --daily 3 --weekly 0 >/dev/null
  local ok=1
  [[ $(db_stamps "$dir" app) == "20250108-030000 20250109-030000 20250110-030000" ]] || ok=0
  check "TC-17-06: 今日の日付ではなく、ある組の日時で数える(古い組しか無くても新しい方から残し、流し直しても減らない)" "$ok"
}

tc_db_and_storage_counted_separately() {
  local dir
  dir=$(new_backup_dir)
  local day
  for day in 20261018 20261019 20261020; do
    make_db_set "$dir" app "$day-030000"
  done
  # 画像のアーカイブは 1 組しか無い(日時の秒も DB とずれている)
  make_storage_set "$dir" 20261020-030512
  bash "$SCRIPT" "$dir" --daily 2 --weekly 0 >/dev/null
  local ok=1
  [[ $(db_stamps "$dir" app) == "20261019-030000 20261020-030000" ]] || ok=0
  [[ $(storage_stamps "$dir") == "20261020-030512" ]] || ok=0
  [[ -f $dir/storage/20261020-030512.tar.gz.sha256 && -f $dir/storage/20261020-030512.check.tsv ]] || ok=0
  check "TC-17-07: db/ と storage/ は別々に数える(DB の世代で画像のアーカイブを消さない)" "$ok"
}

tc_db_names_counted_separately() {
  local dir
  dir=$(new_backup_dir)
  local day
  for day in 20261018 20261019 20261020; do
    make_db_set "$dir" app "$day-030000"
  done
  make_db_set "$dir" app-old 20261001-030000
  bash "$SCRIPT" "$dir" --daily 2 --weekly 0 >/dev/null
  local ok=1
  [[ $(db_stamps "$dir" app) == "20261019-030000 20261020-030000" ]] || ok=0
  [[ $(db_stamps "$dir" app-old) == "20261001-030000" ]] || ok=0
  check "TC-17-08: ダンプは DB 名ごとに数える(名前が前方一致する別の DB のダンプを混ぜない)" "$ok"
}

tc_unknown_files_untouched() {
  local dir
  dir=$(new_backup_dir)
  local day
  for day in 20261018 20261019 20261020; do
    make_db_set "$dir" app "$day-030000"
    make_storage_set "$dir" "$day-030500"
  done
  local keep=(
    "db/.dump-db.abcd1234.partial"
    "db/app-20261399-030000.sql.gz"
    "db/app-20261001-030000.sha256"
    "db/notes.txt"
    "storage/.work.abc123/x.jpeg"
    "storage/20261001-030000.check.tsv"
    "storage/README"
    "logs/daily.log"
  )
  local f
  for f in "${keep[@]}"; do
    mkdir -p "$dir/$(dirname "$f")"
    printf 'x\n' >"$dir/$f"
  done
  bash "$SCRIPT" "$dir" --daily 1 --weekly 0 >/dev/null 2>"$WORK/unknown.err"
  local ok=1
  for f in "${keep[@]}"; do
    [[ -e $dir/$f ]] || ok=0
  done
  # 読めない日付(13 月 99 日)のダンプは数えず、残す
  [[ $(db_stamps "$dir" app) == "20261020-030000 20261399-030000" ]] || ok=0
  [[ $(storage_stamps "$dir") == "20261020-030500" ]] || ok=0
  grep -q '日付として読めない' "$WORK/unknown.err" || ok=0
  check "TC-17-09: 名前が組の形に合わないもの(作りかけ・作業用・読めない日付・本体の無いハッシュ・ほかのファイル)には触らない" "$ok"
}

tc_dry_run() {
  local dir
  dir=$(new_backup_dir)
  make_days "$dir" 20261016 5
  local before after out
  before=$(cd "$dir" && find . -type f | LC_ALL=C sort)
  out=$(bash "$SCRIPT" "$dir" --daily 2 --weekly 0 --dry-run)
  after=$(cd "$dir" && find . -type f | LC_ALL=C sort)
  local ok=1
  [[ $before == "$after" ]] || ok=0
  [[ $out == *"消す予定: $dir/db/app-20261016-030000.sql.gz"* ]] || ok=0
  [[ $out == *"db/app 残す 2 組、消す予定 3 組"* ]] || ok=0
  check "TC-17-10: --dry-run は消すものを出すだけで、何も消さない" "$ok"
}

# ---- 補助 ----

new_backup_dir() {
  local dir
  dir=$(mktemp -d "$WORK/backup.XXXXXX")
  mkdir -p "$dir/db" "$dir/storage"
  printf '%s' "$dir"
}

make_db_set() {
  local dir=$1 name=$2 stamp=$3 ext
  for ext in sql.gz sha256 counts.tsv; do
    printf '%s\n' "$stamp" >"$dir/db/$name-$stamp.$ext"
  done
}

make_storage_set() {
  local dir=$1 stamp=$2 ext
  for ext in tar.gz tar.gz.sha256 check.tsv; do
    printf '%s\n' "$stamp" >"$dir/storage/$stamp.$ext"
  done
}

# 始まりの日から n 日分、毎日 1 組ずつ(ダンプは 03:00:00、アーカイブは 03:05:00)
make_days() {
  local dir=$1 start=$2 n=$3 i day
  for ((i = 0; i < n; i++)); do
    day=$(date -d "$start + $i day" +%Y%m%d)
    make_db_set "$dir" app "$day-030000"
    make_storage_set "$dir" "$day-030500"
  done
}

# 残っているダンプの日時を、古い順に空白区切りで出す
db_stamps() {
  local dir=$1 name=$2 f out=()
  for f in "$dir/db/$name"-*.sql.gz; do
    [[ -f $f ]] || continue
    f=${f##*/}
    f=${f#"$name"-}
    f=${f%.sql.gz}
    [[ $f =~ ^[0-9]{8}-[0-9]{6}$ ]] || continue
    out+=("$f")
  done
  printf '%s' "${out[*]}"
}

storage_stamps() {
  local dir=$1 f out=()
  for f in "$dir"/storage/*.tar.gz; do
    [[ -f $f ]] || continue
    f=${f##*/}
    out+=("${f%.tar.gz}")
  done
  printf '%s' "${out[*]}"
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
