#!/usr/bin/env bash
#
# バックアップの古い世代を消す。サーバーの BACKUP_DIR にも、手元の PC に取った写しにも使う。
#
#   ops/backup/prune-backups.sh <バックアップのディレクトリ> [--daily N] [--weekly N] [--dry-run]
#   ops/backup/prune-backups.sh --check [--daily N] [--weekly N]
#
#   <バックアップのディレクトリ>  BACKUP_DIR(db/ と storage/ を持つディレクトリ)
#   --daily N   日次の世代数(既定 7。1 以上)
#   --weekly N  週次の世代数(既定 4。0 なら週次は残さない)
#   --dry-run   消すものを出すだけで、消さない
#   --check     世代数の指定を確かめるだけで、ディレクトリを見ない(誤りなら 1)
#
# 残すもの(db/ のダンプは DB 名ごとに、storage/ のアーカイブはそれだけで数える):
#   日次  ある組のうち新しい日から N 日分の、それぞれの日でいちばん新しい組
#   週次  ある組のうち新しい週(ISO 週。月曜始まり)から N 週分の、それぞれの週でいちばん新しい組
#   日次と週次は重なってよい(今週の週次は、日次の 1 つでもある)。どちらにも入らない組を消す
#
# 1 組は、dump-db.sh・archive-storage.sh が同じ日時の名前で出したファイル:
#   db/<DB名>-<YYYYMMDD-HHMMSS>.sql.gz・.sha256・.counts.tsv
#   storage/<YYYYMMDD-HHMMSS>.tar.gz・.tar.gz.sha256・.check.tsv
#
# 終了コード:
#   0  消し終えた(消すものが無かったときも)
#   1  引数のエラー、ファイルを消せなかった
#
# 前提と決めごと:
# - 世代は、今日の日付ではなく、ディレクトリにある組の日時から数える。定期実行が止まったまま気づかなくても、
#   日がたつだけでは消えない。いちばん新しい組は必ず残る
# - 本体(.sql.gz・.tar.gz)のある日時だけを組として数える。本体の無いハッシュ・一覧だけのファイルは組に数えず、
#   消しもしない
# - 名前が上の形に合わないもの(作りかけの .partial、作業用の .work.*、日付として読めない日時、手で置いたファイル)には
#   触らない
# - db/ と storage/ は別々に数える。2 つのスクリプトは日時を別々に取るので、同じ回でも秒がずれる
# - プロセス置換(<(…))を使わない。本番のサーバー(CageFS)には /dev/fd が無く、プロセス置換が失敗する

set -euo pipefail

prune_backups() {
  local backup_dir='' daily=7 weekly=4 dry_run=0 check_only=0
  while [[ $# -gt 0 ]]; do
    case $1 in
      --daily) daily=$(require_count "$1" "${2-}" 1) || exit 1; shift 2 ;;
      --weekly) weekly=$(require_count "$1" "${2-}" 0) || exit 1; shift 2 ;;
      --dry-run) dry_run=1; shift ;;
      --check) check_only=1; shift ;;
      -*) usage ;;
      *)
        [[ -z $backup_dir ]] || usage
        backup_dir=$1
        shift
        ;;
    esac
  done
  ((check_only == 0)) || return 0
  [[ -n $backup_dir ]] || usage
  [[ -d $backup_dir ]] || die "バックアップのディレクトリが無い: $backup_dir"

  if [[ -d $backup_dir/db ]]; then
    local prefixes prefix
    prefixes=$(db_prefixes "$backup_dir/db")
    while IFS= read -r prefix; do
      [[ -n $prefix ]] || continue
      prune_group "$backup_dir/db" "$prefix-" "$DB_MAIN" "$daily" "$weekly" "$dry_run" "${DB_SIDES[@]}"
    done <<<"$prefixes"
  fi
  if [[ -d $backup_dir/storage ]]; then
    prune_group "$backup_dir/storage" '' "$STORAGE_MAIN" "$daily" "$weekly" "$dry_run" "${STORAGE_SIDES[@]}"
  fi
}

DB_MAIN=.sql.gz
DB_SIDES=(.sha256 .counts.tsv)
STORAGE_MAIN=.tar.gz
STORAGE_SIDES=(.tar.gz.sha256 .check.tsv)
STAMP_PATTERN='[0-9]{8}-[0-9]{6}'

usage() {
  sed -n '5,12p' "$0" >&2
  exit 1
}

die() {
  printf 'prune-backups: %s\n' "$*" >&2
  exit 1
}

require_count() {
  local name=$1 value=$2 min=$3
  if [[ ! $value =~ ^[0-9]+$ ]] || ((10#$value < min)); then
    die "$name には $min 以上の整数を渡す"
  fi
  printf '%d' "$((10#$value))"
}

# db/ にあるダンプの DB 名(<DB名>-<日時>.sql.gz の <DB名>)を重ねずに出す
db_prefixes() {
  local dir=$1 name
  local pattern="^([^.].*)-${STAMP_PATTERN}\\.sql\\.gz\$"
  for name in "$dir"/*"$DB_MAIN"; do
    [[ -f $name && ! -L $name ]] || continue
    name=${name##*/}
    if [[ $name =~ $pattern ]]; then
      printf '%s\n' "${BASH_REMATCH[1]}"
    fi
  done | LC_ALL=C sort -u
}

# 1 つのまとまり(ある DB 名のダンプ、または画像のアーカイブ)の世代を数えて、残さない組を消す
prune_group() {
  local dir=$1 prefix=$2 main=$3 daily=$4 weekly=$5 dry_run=$6
  shift 6
  local sides=("$@")
  local label=${dir##*/}/${prefix%-}

  local -A kept_days=() kept_weeks=()
  local stamps stamp day week keep kept=0 removed=0
  stamps=$(group_stamps "$dir" "$prefix" "$main")
  while IFS= read -r stamp; do
    [[ -n $stamp ]] || continue
    day=${stamp%%-*}
    if ! week=$(date -d "$day" +%G-W%V 2>/dev/null) || [[ $(date -d "$day" +%Y%m%d 2>/dev/null) != "$day" ]]; then
      printf 'prune-backups: 日付として読めないので触らない: %s/%s%s%s\n' "$dir" "$prefix" "$stamp" "$main" >&2
      continue
    fi
    keep=0
    # 新しい順に見るので、その日・その週で最初に出た組がいちばん新しい
    if [[ -z ${kept_days[$day]+x} ]] && ((${#kept_days[@]} < daily)); then
      kept_days[$day]=1
      keep=1
    fi
    if [[ -z ${kept_weeks[$week]+x} ]] && ((${#kept_weeks[@]} < weekly)); then
      kept_weeks[$week]=1
      keep=1
    fi
    if ((keep)); then
      kept=$((kept + 1))
      continue
    fi
    # 途中で落ちても本体の無い組として数えられずに残らないよう、本体を最後に消す
    remove_set "$dir/$prefix$stamp" "$dry_run" "${sides[@]}" "$main"
    removed=$((removed + 1))
  done <<<"$stamps"

  if ((dry_run)); then
    printf 'prune-backups: %s 残す %d 組、消す予定 %d 組\n' "$label" "$kept" "$removed"
  else
    printf 'prune-backups: %s 残した %d 組、消した %d 組\n' "$label" "$kept" "$removed"
  fi
}

# 本体のある日時を、新しい順に出す
group_stamps() {
  local dir=$1 prefix=$2 main=$3 path name
  local escaped_prefix escaped_main
  escaped_prefix=$(regex_escape "$prefix")
  escaped_main=$(regex_escape "$main")
  local pattern="^${escaped_prefix}(${STAMP_PATTERN})${escaped_main}\$"
  for path in "$dir/$prefix"*"$main"; do
    [[ -f $path && ! -L $path ]] || continue
    name=${path##*/}
    if [[ $name =~ $pattern ]]; then
      printf '%s\n' "${BASH_REMATCH[1]}"
    fi
  done | LC_ALL=C sort -r
}

remove_set() {
  local stem=$1 dry_run=$2
  shift 2
  local suffix
  for suffix in "$@"; do
    [[ -f $stem$suffix && ! -L $stem$suffix ]] || continue
    if ((dry_run)); then
      printf 'prune-backups: 消す予定: %s\n' "$stem$suffix"
    else
      rm -f -- "$stem$suffix" || die "消せなかった: $stem$suffix"
      printf 'prune-backups: 消した: %s\n' "$stem$suffix"
    fi
  done
}

regex_escape() {
  printf '%s' "$1" | sed 's/[][\.^$*+?(){}|]/\\&/g'
}

prune_backups "$@"
