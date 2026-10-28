#!/usr/bin/env bash
#
# 旧 DB のダンプを入れた DB に、旧アプリが使う表がそろっていて、主な表が空でないかを確かめる。
# 手元で旧システムを動かす手順(docs/legacy-local.md)で、ダンプを入れた後・旧システムを起こす前に流す。
# 欠けたデータで旧システムを動かすと、テストの期待値(「旧での結果」)を間違って採ってしまうため。
#
# 使い方:
#   ops/migrate/check-legacy-dump.sh <DB 名> <MariaDB のクライアントのコマンド…>
#
#   例: ops/migrate/check-legacy-dump.sh legacy docker exec cocktail-legacy-db mariadb -uroot -proot
#
#   クライアントのコマンドの後ろに「--batch --skip-column-names <DB 名> -e <SQL>」を足して呼ぶ。
#   手元の mariadb でも、コンテナの中の mariadb(docker exec)でも、同じ形で渡せる。
#
# 確かめること:
#   - backend/database/legacy/schema.sql(本番の旧 DB から書き出した DDL。#27)にある表が、すべて実テーブルとしてある
#   - 主な表(下の MAIN_TABLES)に 1 行以上ある
#   users は表があることだけを確かめる(ダンプのデータの側に users の行が無いため。docs/legacy-local.md)
#
# 終了コード:
#   0  そろっている
#   1  引数のエラー、schema.sql を読めない、DB に接続できない・問い合わせに失敗した
#   2  欠けている(無い表・空の表を出す)

set -euo pipefail

# 主な表。本番の旧 DB のダンプでは、どれも 1 行以上ある(件数は docs/schema.md・docs/migration-anomalies.md)
MAIN_TABLES=(
	m_category_kinds
	m_category
	m_middle
	m_brand
	t_cocktails
	t_recipies
	m_method
	m_ice
	m_dictionary_category
	t_dictionary
	affiliates
	paraphrases
	skip_db
)

SCHEMA_FILE=$(cd "$(dirname "$0")/../.." && pwd)/backend/database/legacy/schema.sql

check_legacy_dump() {
	if [[ $# -lt 2 ]]; then
		die "使い方: $0 <DB 名> <MariaDB のクライアントのコマンド…>"
	fi
	DB=$1
	shift
	CLIENT=("$@")
	[[ -n $DB ]] || die "DB 名が空"

	local expected
	expected=$(expected_tables) || die "旧 DB の DDL を読めない: backend/database/legacy/schema.sql"
	[[ -n $expected ]] || die "旧 DB の DDL に表が 1 つも無い: backend/database/legacy/schema.sql"

	# 接続できないのを「表が全部無い」と取り違えないよう、先に接続を確かめる
	sql "SELECT 1" >/dev/null || die "DB に接続できない: $DB"

	local actual
	actual=$(sql "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'") \
		|| die "表の一覧を読めない: $DB"

	local missing=() empty=() table has_rows
	while IFS= read -r table; do
		if ! grep -Fxq -- "$table" <<<"$actual"; then
			missing+=("$table")
		fi
	done <<<"$expected"

	for table in "${MAIN_TABLES[@]}"; do
		grep -Fxq -- "$table" <<<"$actual" || continue
		has_rows=$(sql "SELECT EXISTS (SELECT 1 FROM \`$table\`)") || die "行を数えられない: $table"
		[[ $has_rows == 1 ]] || empty+=("$table")
	done

	if [[ ${#missing[@]} -gt 0 || ${#empty[@]} -gt 0 ]]; then
		printf 'check-legacy-dump: ダンプが欠けている(%s)\n' "$DB" >&2
		for table in "${missing[@]}"; do printf '  表が無い: %s\n' "$table" >&2; done
		for table in "${empty[@]}"; do printf '  行が無い: %s\n' "$table" >&2; done
		return 2
	fi

	printf 'check-legacy-dump: そろっている(%s 表。うち主な %s 表に行がある)\n' \
		"$(grep -c . <<<"$expected")" "${#MAIN_TABLES[@]}"
}

DB=
CLIENT=()

die() {
	printf 'check-legacy-dump: %s\n' "$*" >&2
	exit 1
}

# schema.sql の CREATE TABLE の表の名前を、書かれた順に出す
expected_tables() {
	[[ -r $SCHEMA_FILE ]] || return 1
	# ` は SQL の識別子の囲みで、シェルに展開させない
	# shellcheck disable=SC2016
	sed -n 's/^CREATE TABLE `\([^`]*\)`.*/\1/p' "$SCHEMA_FILE"
}

sql() {
	"${CLIENT[@]}" --batch --skip-column-names "$DB" -e "$1"
}

check_legacy_dump "$@"
