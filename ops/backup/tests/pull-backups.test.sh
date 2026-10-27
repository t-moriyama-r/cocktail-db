#!/usr/bin/env bash
#
# ops/backup/pull-backups.sh のテスト。サーバーの代わりに、手元のディレクトリを rsync の取り元にして流す(ssh は使わない)。
#
#   bash ops/backup/tests/pull-backups.test.sh
#
# rsync が無いときは SKIP する。PULL_TEST_REQUIRE_RSYNC=1 のときは、飛ばさずに失敗にする(CI に入れるときはこれ)。

set -uo pipefail

SCRIPT=$(cd "$(dirname "$0")/.." && pwd)/pull-backups.sh
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

FAILED=0
PASSED=0
SKIPPED=0

run_tests() {
  tc_usage_errors
  if command -v rsync >/dev/null 2>&1; then
    tc_pulls_db_and_storage_only
    tc_keeps_local_copies_and_prunes
    tc_hash_mismatch_keeps_generations
    tc_missing_source
  elif [[ ${PULL_TEST_REQUIRE_RSYNC:-} == 1 ]]; then
    fail "rsync を使うケース" "PULL_TEST_REQUIRE_RSYNC=1 なのに rsync が無い"
  else
    skip "TC-17-19〜TC-17-22(rsync を使うケース)" "rsync が無い"
  fi

  printf '\n%d passed, %d failed, %d skipped\n' "$PASSED" "$FAILED" "$SKIPPED"
  [[ $FAILED -eq 0 ]]
}

tc_usage_errors() {
  local ok=1
  bash "$SCRIPT" >/dev/null 2>&1 && ok=0
  bash "$SCRIPT" "$WORK/src" >/dev/null 2>&1 && ok=0
  bash "$SCRIPT" "$WORK/src" "$WORK/dest" --daily >/dev/null 2>&1 && ok=0
  [[ ! -e $WORK/dest ]] || ok=0
  check "TC-17-18: 引数の誤り(取り元・保管先が無い・値の無い --daily)では、何も作らずに 1 で止まる" "$ok"
}

tc_pulls_db_and_storage_only() {
  local src dest
  src=$(new_source)
  dest=$WORK/pull1/dest
  make_set "$src" 20261027
  printf 'x\n' >"$src/db/.dump-db.abcd1234.partial"
  mkdir -p "$src/storage/.work.abc123" "$src/logs"
  printf 'x\n' >"$src/storage/.work.abc123/a.jpeg"
  printf 'x\n' >"$src/logs/daily.log"
  printf 'x\n' >"$src/top.txt"
  local out status
  out=$(bash "$SCRIPT" "$src" "$dest" 2>&1)
  status=$?
  local ok=1
  [[ $status -eq 0 ]] || ok=0
  local f
  for f in db/app-20261027-030000.sql.gz db/app-20261027-030000.sha256 db/app-20261027-030000.counts.tsv \
    storage/20261027-030500.tar.gz storage/20261027-030500.tar.gz.sha256 storage/20261027-030500.check.tsv; do
    [[ -f $dest/$f ]] || ok=0
  done
  [[ ! -e $dest/db/.dump-db.abcd1234.partial && ! -e $dest/storage/.work.abc123 ]] || ok=0
  [[ ! -e $dest/logs && ! -e $dest/top.txt ]] || ok=0
  [[ $(stat -c %a "$dest") == 700 && $(stat -c %a "$dest/db") == 700 ]] || ok=0
  [[ $(stat -c %a "$dest/db/app-20261027-030000.sql.gz") == 600 ]] || ok=0
  [[ $(stat -c %a "$dest/storage/20261027-030500.tar.gz") == 600 ]] || ok=0
  check "TC-17-19: db/ と storage/ の組だけを取り(. で始まる作りかけ・ほかのものは取らない)、ディレクトリ 700・ファイル 600 にする" "$ok"
  [[ $ok -eq 1 ]] || printf '%s\n' "$out" >&2
}

tc_keeps_local_copies_and_prunes() {
  local src dest
  src=$(new_source)
  dest=$WORK/pull2/dest
  make_set "$src" 20261018
  make_set "$src" 20261019
  bash "$SCRIPT" "$src" "$dest" >/dev/null 2>&1
  # サーバー側で古い組が消され(あるいは消され尽くし)、新しい組が増えた
  rm -f "$src"/db/* "$src"/storage/*
  make_set "$src" 20261020
  make_set "$src" 20261021
  local status
  bash "$SCRIPT" "$src" "$dest" --daily 3 --weekly 0 >/dev/null 2>&1
  status=$?
  local ok=1
  [[ $status -eq 0 ]] || ok=0
  [[ $(stamps "$dest") == "20261019 20261020 20261021" ]] || ok=0
  # 取り元が空になっても、手元の写しは消えない
  rm -f "$src"/db/* "$src"/storage/*
  bash "$SCRIPT" "$src" "$dest" --daily 3 --weekly 0 >/dev/null 2>&1 || ok=0
  [[ $(stamps "$dest") == "20261019 20261020 20261021" ]] || ok=0
  check "TC-17-20: 取り元で消えた組を手元から消さず(--delete しない)、手元の世代は prune-backups.sh で数えて消す" "$ok"
}

tc_hash_mismatch_keeps_generations() {
  local src dest
  src=$(new_source)
  dest=$WORK/pull3/dest
  make_set "$src" 20261018
  make_set "$src" 20261019
  make_set "$src" 20261020
  printf 'broken\n' >>"$src/db/app-20261020-030000.sql.gz"
  local out status
  out=$(bash "$SCRIPT" "$src" "$dest" --daily 1 --weekly 0 2>&1)
  status=$?
  local ok=1
  [[ $status -eq 2 ]] || ok=0
  [[ $out == *"ハッシュが合わない: $dest/db/app-20261020-030000.sha256"* ]] || ok=0
  [[ $(stamps "$dest") == "20261018 20261019 20261020" ]] || ok=0
  # ハッシュの無い本体も、確かめられないので同じく失敗にする
  rm -rf "$dest"
  make_set "$src" 20261021
  rm -f "$src/storage/20261021-030500.tar.gz.sha256" "$src/db/app-20261020-030000.sql.gz"
  printf 'x\n' >"$src/db/app-20261020-030000.sql.gz"
  rm -f "$src/db/app-20261020-030000.sha256"
  out=$(bash "$SCRIPT" "$src" "$dest" --daily 1 --weekly 0 2>&1)
  status=$?
  [[ $status -eq 2 ]] || ok=0
  [[ $out == *"ハッシュが無い: $dest/storage/20261021-030500.tar.gz"* ]] || ok=0
  [[ $out == *"ハッシュが無い: $dest/db/app-20261020-030000.sql.gz"* ]] || ok=0
  check "TC-17-21: ハッシュの合わない・無いファイルがあると、手元の古い世代を消さずに 2 で終わる" "$ok"
}

tc_missing_source() {
  local out status
  out=$(bash "$SCRIPT" "$WORK/no-such-source" "$WORK/pull4/dest" 2>&1)
  status=$?
  local ok=1
  [[ $status -eq 1 ]] || ok=0
  [[ $out == *"rsync が失敗した"* ]] || ok=0
  check "TC-17-22: 取り元に繋がらない・無いときは、rsync の失敗として 1 で終わる" "$ok"
}

# ---- 補助 ----

new_source() {
  local dir
  dir=$(mktemp -d "$WORK/src.XXXXXX")
  mkdir -p "$dir/db" "$dir/storage"
  printf '%s' "$dir"
}

# dump-db.sh・archive-storage.sh と同じ形の 1 組(ハッシュはディレクトリの中で sha256sum -c できる形)
make_set() {
  local src=$1 day=$2
  local db=app-$day-030000 st=$day-030500
  printf 'dump %s\n' "$day" | gzip -c >"$src/db/$db.sql.gz"
  printf 'users\t1\n' >"$src/db/$db.counts.tsv"
  (cd "$src/db" && sha256sum "$db.sql.gz" >"$db.sha256")
  printf 'archive %s\n' "$day" | gzip -c >"$src/storage/$st.tar.gz"
  : >"$src/storage/$st.check.tsv"
  (cd "$src/storage" && sha256sum "$st.tar.gz" >"$st.tar.gz.sha256")
}

# 手元に残っているダンプの日を、古い順に空白区切りで出す
stamps() {
  local f out=()
  for f in "$1"/db/app-*.sql.gz; do
    [[ -f $f ]] || continue
    f=${f##*/app-}
    out+=("${f%%-*}")
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

fail() {
  FAILED=$((FAILED + 1))
  printf 'FAIL %s %s\n' "$1" "$2"
}

skip() {
  SKIPPED=$((SKIPPED + 1))
  printf 'SKIP %s(%s)\n' "$1" "$2"
}

run_tests
