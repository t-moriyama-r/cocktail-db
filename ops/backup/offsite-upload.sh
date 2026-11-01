#!/usr/bin/env bash
#
# その日の 1 組(DB のダンプと画像のアーカイブ)を公開鍵で暗号化し、Backblaze B2 に送る。サーバーで、
# daily-backup.sh から呼ぶ前提。
#
#   ops/backup/offsite-upload.sh <送り先の設定ファイル> <ダンプの .sql.gz> <画像の .tar.gz>
#
# 設定ファイル(権限 600。KEY=VALUE を 1 行ずつ。値を引用符で囲まない。同じキーは最後のものを使う):
#   B2_KEY_ID            アプリケーション キーの keyID
#   B2_APPLICATION_KEY   アプリケーション キーの値
#   B2_BUCKET_ID         送り先のバケットの ID
#   OFFSITE_PUBLIC_KEY   暗号化に使う公開鍵のファイル(gpg --export --armor で書き出したもの)の絶対パス
#   B2_PREFIX            あれば、B2 での名前の先頭に付ける(例: cocktail/)
#
# 送るもの(1 組。どれも <元の名前>.gpg として暗号化したもの。B2 での名前は <B2_PREFIX><db か storage>/<元の名前>.gpg):
#   db/<DB名>-<日時>.sql.gz・.counts.tsv・.sha256
#   storage/<日時>.tar.gz・.check.tsv・.tar.gz.sha256
#   .sha256 を最後に送る。B2 に .sha256 がある組は、ほかのファイルも送り終えている
#
# 終了コード:
#   0  6 つとも送った
#   1  引数・設定のエラー(送り始める前に止まる)
#   3  暗号化か送信に失敗した
#
# 前提と決めごと:
# - 送るのは B2 の native API(b2_authorize_account・b2_get_upload_url・b2_upload_file)。サーバーには aws コマンドも
#   rclone も無く、curl の --aws-sigv4 はサーバーの版(7.76)ではアップロードの署名の扱いが確かめられないため、
#   署名の要らない native API を curl で呼ぶ。JSON は perl の JSON::PP で読む(サーバーに jq が無い)
# - API は v4 で呼ぶ。今の b2 CLI でバケットを限定して作ったキーは、v3 以前の b2_authorize_account では
#   bad_request で断られ、v4 でだけ認証できるため
# - キーの値とトークンはコマンドラインに出さない(ps で見えるため)。curl の設定として標準入力から渡す
# - キーに消す権限(deleteFiles・bypassGovernance)や保持期間を変える権限があれば、送らずに 1 で止まる。
#   サーバーが乗っ取られたときに、B2 の写しまで消されないようにするため。古い写しは B2 のライフサイクルと
#   オブジェクトロックの保持期間で消える(docs/backup.md)
# - 暗号化は gpg の公開鍵暗号。復号用の秘密鍵はサーバーに置かないので、公開鍵のファイルに秘密鍵が入っていたら止まる。
#   鍵束は使い捨ての一時ディレクトリに作り、終わったら消す
# - 暗号化したファイルは BACKUP_DIR の下の作業用のディレクトリ(.offsite.*。prune-backups.sh は触らない)に
#   書いてから送り、終わったら消す。native API は送る前に大きさと SHA-1 を要るため、パイプでは送れない
# - 1 つのファイルは 3 回まで送り直す(B2 は、失敗したら送り先の URL を取り直すよう求めている)。
#   回数と待つ秒数は OFFSITE_ATTEMPTS・OFFSITE_RETRY_WAIT で変えられる
# - curl・gpg は OFFSITE_CURL・OFFSITE_GPG で差し替えられる(テストで送り先を偽物にするため)
# - プロセス置換(<(…))を使わない。本番のサーバー(CageFS)には /dev/fd が無く、プロセス置換が失敗する

set -uo pipefail

offsite_upload() {
  [[ $# -eq 3 ]] || usage
  local config=$1 dump=$2 archive=$3
  [[ -f $config && -r $config ]] || die "設定ファイルが読めない: $config"
  [[ $dump == *.sql.gz && -f $dump ]] || die "ダンプ(.sql.gz)が無い: $dump"
  [[ $archive == *.tar.gz && -f $archive ]] || die "画像のアーカイブ(.tar.gz)が無い: $archive"
  perl -MJSON::PP -e 1 2>/dev/null || die "perl の JSON::PP が無い(B2 の応答を読むのに使う)"

  local key_id app_key bucket_id public_key prefix
  key_id=$(require_config "$config" B2_KEY_ID) || exit 1
  app_key=$(require_config "$config" B2_APPLICATION_KEY) || exit 1
  bucket_id=$(require_config "$config" B2_BUCKET_ID) || exit 1
  public_key=$(require_config "$config" OFFSITE_PUBLIC_KEY) || exit 1
  prefix=$(read_config "$config" B2_PREFIX)
  [[ -f $public_key && -r $public_key ]] || die "公開鍵のファイルが読めない: $public_key"
  if grep -q 'PRIVATE KEY BLOCK' "$public_key"; then
    die "公開鍵のファイルに秘密鍵が入っている。サーバーには公開鍵だけを置く: $public_key"
  fi

  # 送るファイルの組。.sha256 を最後に置く
  local dump_stem=${dump%.sql.gz} files=()
  files+=("$dump" "$dump_stem.counts.tsv" "${archive%.tar.gz}.check.tsv" "$archive")
  files+=("$archive.sha256" "$dump_stem.sha256")
  local f
  for f in "${files[@]}"; do
    [[ -f $f ]] || die "組のファイルが無い: $f"
  done

  umask 077
  WORK_DIR=$(mktemp -d "$(dirname "$(dirname "$dump")")/.offsite.XXXXXXXX") || die "作業用のディレクトリを作れない"
  trap cleanup EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM
  mkdir "$WORK_DIR/gnupg" "$WORK_DIR/out"

  b2_authorize "$key_id" "$app_key" || exit "$?"

  local name sub
  for f in "${files[@]}"; do
    name=${f##*/}
    sub=$(basename "$(dirname "$f")")
    encrypt "$public_key" "$f" "$WORK_DIR/out/$name.gpg" || fail "暗号化に失敗した: $name"
    upload_with_retry "$bucket_id" "$WORK_DIR/out/$name.gpg" "$prefix$sub/$name.gpg" || fail "送れなかった: $prefix$sub/$name.gpg"
    rm -f -- "$WORK_DIR/out/$name.gpg"
    log "送った: $prefix$sub/$name.gpg"
  done
  log "1 組送り終えた(${#files[@]} ファイル)"
}

CURL=${OFFSITE_CURL:-curl}
GPG=${OFFSITE_GPG:-gpg}
ATTEMPTS=${OFFSITE_ATTEMPTS:-3}
RETRY_WAIT=${OFFSITE_RETRY_WAIT:-10}
AUTH_URL=https://api.backblazeb2.com/b2api/v4/b2_authorize_account
WORK_DIR=''
API_URL=''
API_TOKEN=''

# 鍵が消す権限を持っていないかも、ここで確かめる
b2_authorize() {
  local key_id=$1 app_key=$2 resp=$WORK_DIR/authorize.json code
  [[ $key_id$app_key != *[\"\\]* ]] || die "B2_KEY_ID・B2_APPLICATION_KEY に \" と \\ は使えない"
  code=$(b2_curl "$resp" "$AUTH_URL" <<<"user = \"$key_id:$app_key\"") || code=000
  if [[ $code != 200 ]]; then
    log "B2 の認証に失敗した(HTTP $code$(b2_error "$resp"))"
    return 3
  fi
  API_URL=$(json_get "$resp" apiInfo.storageApi.apiUrl)
  API_TOKEN=$(json_get "$resp" authorizationToken)
  [[ -n $API_URL && -n $API_TOKEN ]] || { log "B2 の認証の応答に apiUrl・authorizationToken が無い"; return 3; }

  local caps cap
  caps=$(json_get "$resp" apiInfo.storageApi.allowed.capabilities)
  rm -f -- "$resp"
  grep -qx writeFiles <<<"$caps" || die "B2 のキーに writeFiles の権限が無い"
  for cap in deleteFiles bypassGovernance writeFileRetentions writeBucketRetentions; do
    if grep -qx "$cap" <<<"$caps"; then
      die "B2 のキーに $cap の権限がある。書き込みと一覧だけのキーにする(docs/backup.md)"
    fi
  done
}

encrypt() {
  local public_key=$1 src=$2 out=$3
  "$GPG" --batch --quiet --no-tty --homedir "$WORK_DIR/gnupg" --trust-model always \
    --compress-algo none --recipient-file "$public_key" --output "$out.partial" --encrypt "$src" &&
    mv -- "$out.partial" "$out"
}

upload_with_retry() {
  local bucket_id=$1 file=$2 b2_name=$3 attempt sha1
  sha1=$(sha1sum <"$file") || return 1
  sha1=${sha1%% *}
  for ((attempt = 1; attempt <= ATTEMPTS; attempt++)); do
    if upload_once "$bucket_id" "$file" "$b2_name" "$sha1"; then
      return 0
    fi
    if ((attempt < ATTEMPTS)); then
      log "送り直す($attempt 回目が失敗。$RETRY_WAIT 秒待つ)"
      sleep "$RETRY_WAIT"
    fi
  done
  return 1
}

# 送るたびに送り先の URL を取り直す(B2 の決まり。失敗した URL は使い回さない)
upload_once() {
  local bucket_id=$1 file=$2 b2_name=$3 sha1=$4 resp=$WORK_DIR/response.json code
  code=$(b2_curl "$resp" "$API_URL/b2api/v4/b2_get_upload_url" -X POST \
    -H 'Content-Type: application/json' --data "{\"bucketId\":\"$bucket_id\"}" \
    <<<"header = \"Authorization: $API_TOKEN\"") || code=000
  if [[ $code != 200 ]]; then
    log "送り先の URL を取れなかった(HTTP $code$(b2_error "$resp"))"
    return 1
  fi
  local upload_url upload_token
  upload_url=$(json_get "$resp" uploadUrl)
  upload_token=$(json_get "$resp" authorizationToken)
  [[ -n $upload_url && -n $upload_token ]] || { log "b2_get_upload_url の応答に uploadUrl・authorizationToken が無い"; return 1; }

  code=$(b2_curl "$resp" "$upload_url" -X POST -T "$file" \
    -H "X-Bz-File-Name: $(url_encode "$b2_name")" -H 'Content-Type: application/octet-stream' \
    -H "X-Bz-Content-Sha1: $sha1" <<<"header = \"Authorization: $upload_token\"") || code=000
  if [[ $code != 200 ]]; then
    log "送れなかった: $b2_name(HTTP $code$(b2_error "$resp"))"
    return 1
  fi
  local got
  got=$(json_get "$resp" contentSha1)
  if [[ $got != "$sha1" ]]; then
    log "B2 の受け取った SHA-1($got)が、送ったもの($sha1)と違う: $b2_name"
    return 1
  fi
}

# 標準入力に curl の設定(認証の行)を受け取る。HTTP のステータスを出す
b2_curl() {
  local out=$1 url=$2
  shift 2
  : >"$out"
  "$CURL" -sS --connect-timeout 30 --max-time 3600 --config - -o "$out" -w '%{http_code}' "$@" "$url"
}

# B2 のエラーの応答({"status":…,"code":…,"message":…})を短く出す。秘密は入らない
b2_error() {
  local code message
  code=$(json_get "$1" code 2>/dev/null)
  message=$(json_get "$1" message 2>/dev/null)
  [[ -z $code$message ]] || printf '。%s: %s' "$code" "$message"
}

# <ファイル> の JSON から、. 区切りのキーの値を出す。配列なら 1 行に 1 つ
json_get() {
  perl -MJSON::PP -e '
    local $/;
    my $d = eval { decode_json(<STDIN>) };
    for my $k (split /\./, $ARGV[0]) {
      last unless defined $d;
      $d = ref $d eq "HASH" ? $d->{$k} : undef;
    }
    exit 0 unless defined $d;
    print ref $d eq "ARRAY" ? join("\n", @$d) : $d;
  ' "$2" <"$1"
}

# B2 の X-Bz-File-Name は URL エンコードする(/ はそのまま)
url_encode() {
  perl -e 'my $s = $ARGV[0]; $s =~ s{([^A-Za-z0-9_.~/-])}{sprintf("%%%02X", ord $1)}ge; print $s' "$1"
}

require_config() {
  local value
  value=$(read_config "$1" "$2")
  [[ -n $value ]] || { log "設定ファイルの $2 が空" >&2; return 1; }
  printf '%s' "$value"
}

read_config() {
  local file=$1 key=$2 line value=''
  while IFS= read -r line || [[ -n $line ]]; do
    line=${line%$'\r'}
    [[ $line == "$key="* ]] && value=${line#"$key="}
  done <"$file"
  printf '%s' "$value"
}

cleanup() {
  [[ -z $WORK_DIR ]] || rm -rf -- "$WORK_DIR"
}

usage() {
  sed -n '6p' "$0" >&2
  exit 1
}

die() {
  log "$*"
  exit 1
}

fail() {
  log "$*"
  exit 3
}

log() {
  printf '%s offsite-upload: %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

offsite_upload "$@"
