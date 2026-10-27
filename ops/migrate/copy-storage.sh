#!/usr/bin/env bash
#
# 旧アプリがアップロードした画像(storage/app/public)を、新アプリの storage/app/public に
# コピーする。サーバー上で実行する。
#
# 使い方:
#   copy-storage.sh [--dry-run] <旧の画像のディレクトリ> <新の画像のディレクトリ>
#
#   --dry-run を付けると、何もコピーせずに、コピーするもの・飛ばすもの・食い違うものを出す。
#
# 1 ファイルずつ、新の側の同じ相対パスと比べる:
#   copied    新に無い。コピーする(更新日時と権限も写す)
#   skipped   新に同じ中身のファイルがある。飛ばす
#   conflict  新に同じ名前で中身の違うファイルがある。上書きせずに一覧に出す
#             コピーを置く直前に新アプリが同じ名前を作ったときも、上書きせず、中身を比べ直して分ける
#
# 旧の側のファイルは消さない・変えない。新の側にだけあるファイルにも触らない。
# .gitignore などのドットファイルは、Laravel が置くもので画像ではないので扱わない。
#
# 終了コード:
#   0  すべてコピーしたか、同じ中身があって飛ばした
#   1  引数のエラー、旧の画像の一覧を取れなかった、またはコピーに失敗した
#   2  中身の違う同じ名前のファイルがあった(それ以外はコピーしてある)
#
# 食い違いを上書きしないのは、新アプリで差し替えた画像を、旧の画像で黙って戻さないため。
# どちらを残すかは人が決める。

set -euo pipefail

copy_storage() {
	local dry_run=0
	if [[ "${1:-}" == "--dry-run" ]]; then
		dry_run=1
		shift
	fi
	[[ $# -eq 2 ]] || usage

	local source_dir=$1 dest_dir=$2
	[[ -d "$source_dir" ]] || die "旧の画像のディレクトリが見つからない: $source_dir"
	[[ -d "$dest_dir" ]] || die "新の画像のディレクトリが見つからない: $dest_dir"
	refuse_overlap "$source_dir" "$dest_dir"

	# 列挙に失敗したのに「0 件コピーした」で終わらないように、一覧は先に一時ファイルへ取る
	local copied=0 skipped=0 conflicts=() path
	# trap は関数を抜けた後に動くので、local ではなくスクリプトの変数に持つ(local だと、後始末で未定義になる)
	LIST_FILE=$(mktemp)
	trap 'rm -f -- "$LIST_FILE"' EXIT
	list_files "$source_dir" >"$LIST_FILE" || die "旧の画像の一覧を取れなかった: $source_dir"

	while IFS= read -r -d '' path; do
		if [[ ! -e "$dest_dir/$path" && ! -L "$dest_dir/$path" ]]; then
			if [[ $dry_run -eq 1 ]]; then
				copied=$((copied + 1))
				continue
			fi
			# 置く直前に新アプリが同じ名前を作っていたら(1 が返る)、下の比べ方に回す
			if copy_file "$source_dir/$path" "$dest_dir/$path"; then
				copied=$((copied + 1))
				continue
			fi
		fi
		if cmp -s "$source_dir/$path" "$dest_dir/$path"; then
			skipped=$((skipped + 1))
		else
			conflicts+=("$path")
		fi
	done <"$LIST_FILE"

	[[ $dry_run -eq 0 ]] || echo "dry-run: 何もコピーしていない"
	echo "copied: $copied"
	echo "skipped: $skipped"
	echo "conflict: ${#conflicts[@]}"
	if [[ ${#conflicts[@]} -gt 0 ]]; then
		echo "新に同じ名前で中身の違うファイルがある(上書きしていない):"
		printf '  %s\n' "${conflicts[@]}"
		exit 2
	fi
}

usage() {
	sed -n '6,9p' "$0" >&2
	exit 1
}

die() {
	echo "error: $*" >&2
	exit 1
}

# 同じディレクトリや入れ子を渡すと、コピーしたものをまたコピー元として読む。
refuse_overlap() {
	local source dest
	source=$(realpath -- "$1")
	dest=$(realpath -- "$2")
	case "$dest/" in
	"$source/"*) die "新の画像のディレクトリが、旧の画像のディレクトリと同じか、その中にある" ;;
	esac
	case "$source/" in
	"$dest/"*) die "旧の画像のディレクトリが、新の画像のディレクトリの中にある" ;;
	esac
}

# 相対パスを NUL 区切りで出す。名前に空白・改行が入っていても崩れないように。
# ドットで始まる名前のファイルは除く。
list_files() {
	(cd "$1" && find . -type f ! -name '.*' -printf '%P\0' | LC_ALL=C sort -z)
}

# 一時ファイルにコピーしてから、ハードリンクで本来の名前に置く。途中で止まっても、壊れた
# ファイルが本来の名前で残らない(残ると、次に流したときに conflict と見なされる)。
# ln は置き先が既にあれば(壊れたシンボリックリンクも含めて)失敗するので、新アプリが
# 同じ名前で先に作ったファイルを上書きしない。-T は、置き先がディレクトリのとき、その中へ
# 置いてしまわないため。
# 戻り値: 0 置いた / 1 置き先が既にあった(置いていない)。コピーに失敗したら終了する。
copy_file() {
	local source=$1 dest=$2 tmp
	mkdir -p "$(dirname "$dest")"
	tmp=$(mktemp "$(dirname "$dest")/.copy-storage.XXXXXX")
	if ! cp -p -- "$source" "$tmp"; then
		rm -f -- "$tmp"
		die "コピーに失敗した: $source"
	fi
	if ln -T -- "$tmp" "$dest" 2>/dev/null; then
		rm -f -- "$tmp"
		return 0
	fi
	rm -f -- "$tmp"
	[[ -e "$dest" || -L "$dest" ]] || die "コピーに失敗した: $source"
	return 1
}

copy_storage "$@"
