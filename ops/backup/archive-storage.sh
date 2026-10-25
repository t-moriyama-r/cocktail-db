#!/usr/bin/env bash
#
# 旧アプリがアップロードした画像(storage/app/public)を tar.gz に固めて退避し、
# DB の画像列が指すファイルとアーカイブの中身を突き合わせる。サーバー上で実行する。
#
# 使い方:
#   archive-storage.sh <.env のパス> [画像のディレクトリ]
#   archive-storage.sh --verify <アーカイブ> <画像のディレクトリ>
#
#   画像のディレクトリを省くと、<.env のあるディレクトリ>/storage/app/public を使う。
#   --verify は、作り済みのアーカイブを展開して、ディレクトリとファイルの数・ハッシュが
#   一致するかだけを確かめる(DB には繋がない)。
#
# .env から読むもの(Laravel の .env の書式。値の前後の引用符は外す):
#   DB_HOST・DB_PORT・DB_DATABASE・DB_USERNAME・DB_PASSWORD  画像列を読む旧 DB の接続
#   BACKUP_DIR  出力先。公開ディレクトリ(public_html)の下は拒む
#
# 出力($BACKUP_DIR/storage/。ディレクトリは 700、ファイルは 600):
#   <YYYYMMDD-HHMMSS>.tar.gz         画像のディレクトリの中身(相対パス)
#   <YYYYMMDD-HHMMSS>.tar.gz.sha256  そのディレクトリで `sha256sum -c` できる形
#   <YYYYMMDD-HHMMSS>.check.tsv      突き合わせの一覧(種別・表・id・ファイル名のタブ区切り)
#     missing       DB にあってアーカイブに無いファイル
#     unreferenced  アーカイブにあって DB のどの画像列にも無いファイル
#     external      画像列に入っている外部の URL(ファイルではないので突き合わせない)
#
# 日時の書式と出力先の形は、DB のダンプ(dump-db.sh)とそろえてあり、同じ時刻に動かした
# 2 つの出力を日時で組にできる。DB を先に読んでから固めるので、DB に繋がらないときは
# 何も出力しない。
#
# 終了コード:
#   0  退避と検証ができた(欠けているファイルがあっても 0。件数は出力と check.tsv で見る)
#   1  引数・設定・DB の接続などのエラー
#   2  検証に通らなかった(展開したアーカイブが元のディレクトリと一致しない、
#      または固めている間に画像のディレクトリが変わり続けた)。出力先には何も置かない
#
# 欠けがあっても 0 にするのは、旧データには前から欠けがありうるため。欠けで失敗に
# すると、定期実行したときにバックアップそのものが毎回失敗扱いになる。
#
# パスワードはコマンドラインに出さず、権限 600 の一時ファイル(--defaults-extra-file)で
# クライアントに渡し、終わったら消す。クライアントは MYSQL_CLIENT で変えられる(既定は
# mariadb があればそれ、無ければ mysql)。

set -euo pipefail

archive_storage() {
	if [[ "${1:-}" == "--verify" ]]; then
		[[ $# -eq 3 ]] || usage
		[[ -d "$3" ]] || die "画像のディレクトリが見つからない: $3"
		make_work_dir "${TMPDIR:-/tmp}"
		hash_manifest "$3" >"$WORK_DIR/source.sha256"
		verify_archive "$2" "$WORK_DIR/source.sha256" >/dev/null
		echo "verified: アーカイブと $3 の中身が一致した"
		return 0
	fi
	[[ $# -ge 1 && $# -le 2 ]] || usage

	local env_file=$1
	[[ -f "$env_file" ]] || die ".env が見つからない: $env_file"
	local storage_dir=${2:-"$(dirname "$env_file")/storage/app/public"}
	[[ -d "$storage_dir" ]] || die "画像のディレクトリが見つからない: $storage_dir"

	local backup_dir
	backup_dir=$(env_value BACKUP_DIR "$env_file")
	[[ -n "$backup_dir" ]] || die ".env に BACKUP_DIR が無い"
	[[ -n "$(env_value DB_DATABASE "$env_file")" ]] || die ".env に DB_DATABASE が無い"
	refuse_public_dir "$backup_dir"

	umask 077
	local out_dir="$backup_dir/storage"
	mkdir -p "$out_dir"
	chmod 700 "$out_dir"

	make_work_dir "$out_dir"

	local stamp archive
	stamp=$(date +%Y%m%d-%H%M%S)
	archive="$out_dir/$stamp.tar.gz"
	[[ ! -e "$archive" ]] || die "同じ名前の出力が既にある: $archive"

	write_defaults_file "$env_file" "$WORK_DIR/my.cnf"
	query_image_columns "$WORK_DIR/my.cnf" "$(env_value DB_DATABASE "$env_file")" >"$WORK_DIR/db.tsv"

	# 検証に通ってから出力先に置く。通らなかったアーカイブに .sha256 を付けて残すと、
	# 後で sha256sum -c が通り、正しい退避と取り違える。
	local file_count
	file_count=$(create_verified_archive "$storage_dir" "$WORK_DIR/archive.tar.gz" "$WORK_DIR/files.txt")
	mv "$WORK_DIR/archive.tar.gz" "$archive"
	(cd "$out_dir" && sha256sum "$stamp.tar.gz" >"$stamp.tar.gz.sha256")
	echo "archive: $archive"
	echo "files: $file_count (展開して数とハッシュが元と一致した)"

	reconcile "$WORK_DIR/db.tsv" "$WORK_DIR/files.txt" >"$out_dir/$stamp.check.tsv"
	report "$out_dir/$stamp.check.tsv"
}

usage() {
	sed -n '6,13p' "$0" >&2
	exit 1
}

die() {
	echo "error: $*" >&2
	exit 1
}

# 一時ファイル(接続情報・展開した画像)は、途中で失敗しても残さない。
make_work_dir() {
	WORK_DIR=$(mktemp -d "$1/.work.XXXXXX")
	trap 'rm -rf "$WORK_DIR"' EXIT
}

# Laravel の .env は `KEY=value` の行で、値を引用符で囲むことがある。シェルとして
# 読み込む(source)と値の中の $ や ` が展開されるので、行を文字列として切り出す。
env_value() {
	local key=$1 file=$2 line value
	line=$(grep -E "^[[:space:]]*${key}[[:space:]]*=" "$file" | tail -n 1 || true)
	value=${line#*=}
	value=${value%$'\r'}
	value=$(printf '%s' "$value" | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//')
	if [[ "$value" =~ ^\"(.*)\"$ || "$value" =~ ^\'(.*)\'$ ]]; then
		value=${BASH_REMATCH[1]}
	fi
	printf '%s' "$value"
}

# 公開ディレクトリの下に置くと、アーカイブ(全画像)とファイル名の一覧が URL で取れてしまう。
refuse_public_dir() {
	local dir
	dir=$(realpath -m -- "$1")
	case "/$dir/" in
	*/public_html/*) die "公開ディレクトリ(public_html)の下には書かない: BACKUP_DIR を見直す" ;;
	esac
}

# my.cnf の値は引用符で囲み、中の \ と " をエスケープする(パスワードに記号が入りうるため)。
write_defaults_file() {
	local env_file=$1 out=$2
	{
		echo "[client]"
		print_cnf_line host "$(env_value DB_HOST "$env_file")"
		print_cnf_line port "$(env_value DB_PORT "$env_file")"
		print_cnf_line user "$(env_value DB_USERNAME "$env_file")"
		print_cnf_line password "$(env_value DB_PASSWORD "$env_file")"
	} >"$out"
	chmod 600 "$out"
}

print_cnf_line() {
	local name=$1 value=$2
	[[ -n "$value" ]] || return 0
	value=${value//\\/\\\\}
	value=${value//\"/\\\"}
	printf '%s="%s"\n' "$name" "$value"
}

# 旧アプリは画像をディレクトリの直下に置き、画像列にはファイル名だけを入れる。
# 出力は「表・id・画像列の値」のタブ区切り。NULL と空文字は画像が無い行なので除く。
query_image_columns() {
	local cnf=$1 database=$2 client
	client=${MYSQL_CLIENT:-$(command -v mariadb || command -v mysql || true)}
	[[ -n "$client" ]] || die "mariadb / mysql のクライアントが見つからない(MYSQL_CLIENT で指定する)"
	local table sql=""
	for table in t_cocktails m_category m_middle m_brand t_dictionary; do
		[[ -z "$sql" ]] || sql+=" UNION ALL "
		sql+="SELECT '$table', id, image FROM \`$table\` WHERE image IS NOT NULL AND image <> ''"
	done
	"$client" --defaults-extra-file="$cnf" --batch --skip-column-names --raw \
		--default-character-set=utf8mb4 "$database" -e "$sql" ||
		die "DB から画像列を読めなかった"
}

# 旧アプリは動いたまま退避するので、固めている間に画像が足されたり消されたりしうる。
# そのときのアーカイブは、固めた後のディレクトリとも前のディレクトリとも一致せず、
# 検証が誤報になる(あるいは途中の変化を見逃す)。固める前後でディレクトリのハッシュの
# 一覧が変わらなかった回だけを採り、変わった回は固め直す。
# 標準出力にファイルの数を出し、3 つ目の引数のファイルにアーカイブの中のファイルの相対パスを書く。
create_verified_archive() {
	local storage_dir=$1 archive=$2 files_out=$3 attempt
	for attempt in 1 2 3; do
		hash_manifest "$storage_dir" >"$WORK_DIR/before.sha256"
		tar -C "$storage_dir" -czf "$archive" .
		hash_manifest "$storage_dir" >"$WORK_DIR/after.sha256"
		if cmp -s "$WORK_DIR/before.sha256" "$WORK_DIR/after.sha256"; then
			verify_archive "$archive" "$WORK_DIR/before.sha256" "$files_out"
			return
		fi
		echo "warning: 固めている間に画像のディレクトリが変わったので固め直す($attempt 回目)" >&2
	done
	echo "error: 画像のディレクトリが変わり続け、変わらない状態で固められなかった" >&2
	exit 2
}

# 期待するハッシュの一覧(hash_manifest の出力)と、アーカイブを展開したディレクトリの一覧を
# 比べる。一覧が一致すれば、ファイルの数・名前・中身がすべて一致している。
# 標準出力にファイルの数を出す。3 つ目の引数を渡すと、アーカイブの中のファイルの相対パスを書く。
verify_archive() {
	local archive=$1 expected=$2 files_out=${3:-}
	[[ -f "$archive" ]] || die "アーカイブが見つからない: $archive"
	local tmp="$WORK_DIR/extracted"
	rm -rf "$tmp"
	mkdir "$tmp"
	tar -C "$tmp" -xzf "$archive"
	hash_manifest "$tmp" >"$WORK_DIR/extracted.sha256"
	if ! cmp -s "$expected" "$WORK_DIR/extracted.sha256"; then
		echo "error: 展開したアーカイブが元のディレクトリと一致しない: $archive" >&2
		diff "$expected" "$WORK_DIR/extracted.sha256" >&2 || true
		exit 2
	fi
	[[ -z "$files_out" ]] || (cd "$tmp" && find . -type f -printf '%P\n' | LC_ALL=C sort >"$files_out")
	wc -l <"$WORK_DIR/extracted.sha256"
	rm -rf "$tmp"
}

hash_manifest() {
	(cd "$1" && find . -type f -print0 | LC_ALL=C sort -z | xargs -0 -r sha256sum)
}

# 画像列の値は、直下のファイル名(`abc.jpeg`)と、外部の URL(`https://…`)がある。
# URL はアップロードしたファイルではないので、欠けとしては数えず external として出す。
# .gitignore などのドットファイルは、Laravel が置くもので画像ではないので unreferenced に出さない。
reconcile() {
	local db_tsv=$1 files=$2
	# 1 つ目のファイルを FILENAME で見分ける(NR == FNR だと、ファイルが 0 件のときに DB の行を取り違える)
	LC_ALL=C awk -F '\t' -v OFS='\t' -v files="$files" '
		FILENAME == files { present[$0] = 1; next }
		{
			name = $3
			if (name ~ /^https?:\/\//) { print "external", $1, $2, name; next }
			referenced[name] = 1
			if (!(name in present)) print "missing", $1, $2, name
		}
		END {
			for (f in present) {
				n = split(f, parts, "/")
				if (parts[n] ~ /^\./) continue
				if (!(f in referenced)) print "unreferenced", "-", "-", f
			}
		}
	' "$files" "$db_tsv" | LC_ALL=C sort -t "$(printf '\t')" -k1,1 -k2,2 -k4,4
}

report() {
	local check=$1 kind
	echo "check: $check"
	for kind in missing unreferenced external; do
		echo "$kind: $(grep -c "^$kind"$'\t' "$check" || true)"
	done
	if grep -q "^missing"$'\t' "$check"; then
		echo "DB にあってファイルが無いもの(表・id・ファイル名):"
		grep "^missing"$'\t' "$check" | cut -f 2- | sed 's/^/  /'
	fi
}

archive_storage "$@"
