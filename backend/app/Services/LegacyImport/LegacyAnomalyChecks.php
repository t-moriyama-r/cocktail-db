<?php

namespace App\Services\LegacyImport;

/**
 * docs/migration-anomalies.md の、旧 DB に流す SQL と、文書に書いた件数(#819)。
 *
 * 切り替えの日に、本番の旧 DB でこの SQL をすべて流して数え直す(docs/cutover.md の 3 の 2。legacy:count-anomalies)。
 * SQL は文書のものをそのまま写す。文書の SQL を変えたら、ここも同じに変える(LegacyAnomalyChecksTest が、文書の旧 DB の
 * SQL とここの SQL が同じ組かを確かめる)。件数を変えたら、文書の一覧・各節の件数と同じにする。
 * 10 の「新しい DB」の SQL は移した後に新 DB で流すものなので、ここには入れない。
 */
final class LegacyAnomalyChecks
{
    /**
     * どの SQL よりも先に、同じ接続で 1 回流す文(10・13 の GROUP_CONCAT が切れないように。文書の 10・13 の先頭の文)。
     */
    public const PRELUDE = 'SET SESSION group_concat_max_len = 100000';

    /**
     * @return list<LegacyAnomalyCheck>
     */
    public static function all(): array
    {
        return [
            new LegacyAnomalyCheck('1', 'カクテルが消えた後に残った材料の行', <<<'SQL'
                SELECT COUNT(*) AS rows_, COUNT(DISTINCT r.cocktail_id) AS cocktails
                FROM t_recipies r LEFT JOIN t_cocktails c ON c.id = r.cocktail_id
                WHERE c.id IS NULL
                SQL, ['rows_', 'cocktails'], [['3251', '857']]),
            new LegacyAnomalyCheck('2', '材料・銘柄まで同じ行(移すカクテルの中)', <<<'SQL'
                SELECT COUNT(*) AS groups_, SUM(n) AS rows_, SUM(same_amount) AS same_amount_groups FROM (
                  SELECT r.cocktail_id, COUNT(*) AS n, COUNT(DISTINCT IFNULL(r.amount, '<null>')) = 1 AS same_amount
                  FROM t_recipies r JOIN t_cocktails c ON c.id = r.cocktail_id
                  GROUP BY r.cocktail_id, r.category, r.middle_category, r.brand
                  HAVING COUNT(*) > 1
                ) g
                SQL, ['groups_', 'rows_', 'same_amount_groups'], [['13', '26', '4']]),
            new LegacyAnomalyCheck('2 消えたカクテルの分も', '材料・銘柄まで同じ行(消えたカクテルの分も入れる)', <<<'SQL'
                SELECT COUNT(*) AS groups_ FROM (
                  SELECT 1 FROM t_recipies r
                  GROUP BY r.cocktail_id, r.category, r.middle_category, r.brand
                  HAVING COUNT(*) > 1
                ) g
                SQL, ['groups_'], [['16']]),
            new LegacyAnomalyCheck('3', '材料の行のミドルの親とカテゴリが合わない', <<<'SQL'
                SELECT COUNT(*) FROM t_recipies r
                JOIN t_cocktails c ON c.id = r.cocktail_id
                JOIN m_middle m ON m.id = r.middle_category
                WHERE m.parent <> r.category
                SQL, ['COUNT(*)'], [['2']]),
            new LegacyAnomalyCheck('4', '銘柄のミドルの親とカテゴリが合わない', <<<'SQL'
                SELECT COUNT(*) FROM m_brand b
                JOIN m_middle m ON m.id = b.middle_category
                WHERE m.parent <> b.category
                SQL, ['COUNT(*)'], [['1']]),
            new LegacyAnomalyCheck('5 全行', 'recipe_count が材料の全行の数と合わない', <<<'SQL'
                SELECT COUNT(*) FROM t_cocktails c
                WHERE c.recipe_count <> (SELECT COUNT(*) FROM t_recipies r WHERE r.cocktail_id = c.id)
                SQL, ['COUNT(*)'], [['1580']]),
            new LegacyAnomalyCheck('5 定義 1', 'recipe_count が任意でない材料の数と合わない', <<<'SQL'
                SELECT COUNT(*) FROM t_cocktails c
                WHERE c.recipe_count <> (SELECT COUNT(*) FROM t_recipies r WHERE r.cocktail_id = c.id AND r.is_optional = 0)
                SQL, ['COUNT(*)'], [['1425']]),
            new LegacyAnomalyCheck('5 定義 2', 'recipe_count が任意でなくその他でない材料の数と合わない', <<<'SQL'
                SELECT COUNT(*) FROM t_cocktails c
                WHERE c.recipe_count <> (SELECT COUNT(*) FROM t_recipies r JOIN m_category m ON r.category = m.id
                                         WHERE r.cocktail_id = c.id AND r.is_optional = 0 AND m.kind <> 5)
                SQL, ['COUNT(*)'], [['744']]),
            new LegacyAnomalyCheck('6', 'name_search の重複(3 つの表を合わせて)', <<<'SQL'
                SELECT COUNT(*) FROM (
                  SELECT name_search FROM (
                    SELECT name_search FROM m_category
                    UNION ALL SELECT name_search FROM m_middle
                    UNION ALL SELECT name_search FROM m_brand
                  ) u
                  GROUP BY name_search HAVING COUNT(*) > 1
                ) d
                SQL, ['COUNT(*)'], [['34']]),
            new LegacyAnomalyCheck('6 表をまたぐ', 'name_search の重複(2 つ以上の表にまたがる)', <<<'SQL'
                SELECT COUNT(*) FROM (
                  SELECT name_search FROM (
                    SELECT 'category' AS t, name_search FROM m_category
                    UNION ALL SELECT 'middle', name_search FROM m_middle
                    UNION ALL SELECT 'brand', name_search FROM m_brand
                  ) u
                  GROUP BY name_search HAVING COUNT(DISTINCT t) > 1
                ) d
                SQL, ['COUNT(*)'], [['26']]),
            new LegacyAnomalyCheck('7', 'ミドルと直下の銘柄を両方持つカテゴリ', <<<'SQL'
                SELECT COUNT(*) FROM m_category k
                WHERE EXISTS (SELECT 1 FROM m_middle m WHERE m.parent = k.id)
                  AND EXISTS (SELECT 1 FROM m_brand b WHERE b.category = k.id AND b.middle_category IS NULL)
                SQL, ['COUNT(*)'], [['43']]),
            new LegacyAnomalyCheck('8', '銘柄の名前がカテゴリ・ミドルの名前と同じ', <<<'SQL'
                SELECT COUNT(*) FROM m_brand b
                WHERE EXISTS (SELECT 1 FROM m_category k WHERE k.name = b.name)
                   OR EXISTS (SELECT 1 FROM m_middle m WHERE m.name = b.name)
                SQL, ['COUNT(*)'], [['22']]),
            new LegacyAnomalyCheck('8 属するもの', '銘柄の名前が属するカテゴリ・ミドルの名前と同じ', <<<'SQL'
                SELECT COUNT(*) FROM m_brand b
                WHERE EXISTS (SELECT 1 FROM m_category k WHERE k.id = b.category AND k.name = b.name)
                   OR EXISTS (SELECT 1 FROM m_middle m WHERE m.id = b.middle_category AND m.name = b.name)
                SQL, ['COUNT(*)'], [['22']]),
            new LegacyAnomalyCheck('9', 'アフィリエイトのキーの 0', <<<'SQL'
                SELECT COUNT(*) AS total, SUM(middle_id = 0) AS middle0, SUM(brand_id = 0) AS brand0,
                       SUM(middle_id = 0 AND brand_id = 0) AS both0
                FROM affiliates
                SQL, ['total', 'middle0', 'brand0', 'both0'], [['646', '378', '138', '138']]),
            // cocktails は 13 でまとめた後の数で、文書に件数が無いので比べない
            new LegacyAnomalyCheck('10', '材料・量まで同じで名前が違うカクテル', <<<'SQL'
                SELECT COUNT(*) AS groups_, SUM(n) AS cocktails FROM (
                  SELECT s, COUNT(DISTINCT name) AS n FROM (
                    SELECT c.id, c.name,
                      GROUP_CONCAT(CONCAT_WS(':', r.category, IFNULL(r.middle_category, '-'), IFNULL(r.brand, '-'), IFNULL(r.amount, '-'), r.is_optional)
                                   ORDER BY r.category, r.middle_category, r.brand, r.amount, r.is_optional SEPARATOR ',') AS s
                    FROM t_cocktails c JOIN t_recipies r ON r.cocktail_id = c.id
                    GROUP BY c.id
                  ) sig
                  GROUP BY s HAVING COUNT(DISTINCT name) > 1
                ) g
                SQL, ['groups_'], [['45']]),
            new LegacyAnomalyCheck('11', 'カテゴリだけの材料の行に、ミドルの下の銘柄', <<<'SQL'
                SELECT COUNT(*) AS rows_, COUNT(DISTINCT r.cocktail_id) AS cocktails, COUNT(DISTINCT r.brand) AS brands
                FROM t_recipies r
                JOIN t_cocktails c ON c.id = r.cocktail_id
                JOIN m_brand b ON b.id = r.brand
                WHERE r.middle_category IS NULL AND b.middle_category IS NOT NULL AND b.category = r.category
                SQL, ['rows_', 'cocktails', 'brands'], [['62', '62', '5']]),
            new LegacyAnomalyCheck('12', 'アフィリエイトのミドルの親とカテゴリが合わない', <<<'SQL'
                SELECT COUNT(*) FROM affiliates a
                JOIN m_middle m ON m.id = a.middle_id
                WHERE m.parent <> a.category_id
                SQL, ['COUNT(*)'], [['1']]),
            new LegacyAnomalyCheck('13', '名前も材料・量も同じカクテル', <<<'SQL'
                SELECT COUNT(*) AS groups_, SUM(n) AS cocktails FROM (
                  SELECT s, name, COUNT(*) AS n FROM (
                    SELECT c.id, c.name,
                      GROUP_CONCAT(CONCAT_WS(':', r.category, IFNULL(r.middle_category, '-'), IFNULL(r.brand, '-'), IFNULL(r.amount, '-'), r.is_optional)
                                   ORDER BY r.category, r.middle_category, r.brand, r.amount, r.is_optional SEPARATOR ',') AS s
                    FROM t_cocktails c JOIN t_recipies r ON r.cocktail_id = c.id
                    GROUP BY c.id
                  ) sig
                  GROUP BY s, name HAVING COUNT(*) > 1
                ) g
                SQL, ['groups_', 'cocktails'], [['50', '105']]),
            new LegacyAnomalyCheck('14 A〜E', '説明文の記法の外に @・＠ が残る', <<<'SQL'
                SELECT t, c, id FROM (
                  SELECT 'm_category_kinds' AS t, 'description' AS c, id, description AS txt FROM m_category_kinds
                  UNION ALL SELECT 'm_category', 'description', id, description FROM m_category
                  UNION ALL SELECT 'm_middle', 'description', id, description FROM m_middle
                  UNION ALL SELECT 'm_method', 'description', id, description FROM m_method
                  UNION ALL SELECT 't_dictionary', 'description', id, description FROM t_dictionary
                  UNION ALL SELECT 'm_brand', 'description', id, description FROM m_brand
                  UNION ALL SELECT 't_cocktails', 'description', id, description FROM t_cocktails
                  UNION ALL SELECT 't_cocktails', 'note', id, note FROM t_cocktails
                ) d
                WHERE REGEXP_REPLACE(txt, '@[cCbBpPmMdD][1-9][0-9]*@', '') REGEXP '[@＠]'
                ORDER BY t, c, id
                SQL, ['t', 'c', 'id'], [
                ['t_cocktails', 'description', '153'],
                ['t_cocktails', 'description', '3620'],
                ['t_cocktails', 'description', '3621'],
                ['t_cocktails', 'description', '5189'],
                ['t_cocktails', 'description', '5629'],
                ['t_cocktails', 'note', '11'],
                ['t_cocktails', 'note', '238'],
                ['t_cocktails', 'note', '1334'],
            ]),
            // 文書は「6 個」とカクテルの ID だけを書いている(どれが 2 個かは書いていない)ので、行の数と、ID の組で比べる
            new LegacyAnomalyCheck('14 F', '無いカクテルを指す @c<ID>@', <<<'SQL'
                WITH RECURSIVE refs (t, c, id, rest, ref) AS (
                  SELECT t, c, id, CAST(txt AS CHAR(65535)), CAST(NULL AS CHAR(32)) FROM (
                    SELECT 'm_category_kinds' AS t, 'description' AS c, id, description AS txt FROM m_category_kinds
                    UNION ALL SELECT 'm_category', 'description', id, description FROM m_category
                    UNION ALL SELECT 'm_middle', 'description', id, description FROM m_middle
                    UNION ALL SELECT 'm_method', 'description', id, description FROM m_method
                    UNION ALL SELECT 't_dictionary', 'description', id, description FROM t_dictionary
                    UNION ALL SELECT 'm_brand', 'description', id, description FROM m_brand
                    UNION ALL SELECT 't_cocktails', 'description', id, description FROM t_cocktails
                    UNION ALL SELECT 't_cocktails', 'note', id, note FROM t_cocktails
                  ) d
                  WHERE txt REGEXP '@[cC][1-9][0-9]*@'
                  UNION ALL
                  SELECT t, c, id,
                         SUBSTRING(rest, INSTR(rest, REGEXP_SUBSTR(rest, '@[cCbBpPmMdD][1-9][0-9]*@')) + CHAR_LENGTH(REGEXP_SUBSTR(rest, '@[cCbBpPmMdD][1-9][0-9]*@'))),
                         REGEXP_SUBSTR(rest, '@[cCbBpPmMdD][1-9][0-9]*@')
                  FROM refs WHERE rest REGEXP '@[cCbBpPmMdD][1-9][0-9]*@'
                )
                SELECT r.t, r.c, r.id, r.ref FROM refs r
                LEFT JOIN t_cocktails x ON x.id = CAST(SUBSTRING(r.ref, 3, CHAR_LENGTH(r.ref) - 3) AS UNSIGNED)
                WHERE LOWER(LEFT(r.ref, 2)) = '@c' AND x.id IS NULL
                ORDER BY r.t, r.c, r.id
                SQL, ['t', 'c', 'id'], [
                ['t_cocktails', 'description', '5202'],
                ['t_cocktails', 'description', '5294'],
                ['t_cocktails', 'description', '5362'],
                ['t_cocktails', 'description', '5488'],
                ['t_cocktails', 'description', '6112'],
            ], distinct: true, countRows: 6),
            // category・middle_category は、文書が値を書いていない(ミドルが NULL か 0)ので比べない
            new LegacyAnomalyCheck('15', 'ミドルの有名な銘柄が親のカテゴリの直下', <<<'SQL'
                SELECT mi.id AS middle_id, b.id AS brand_id, b.category, b.middle_category
                FROM m_middle mi JOIN m_brand b ON b.id = mi.famous_brand_id
                WHERE (b.middle_category IS NULL OR b.middle_category = 0) AND b.category = mi.parent
                SQL, ['middle_id', 'brand_id'], [['58', '138']]),
        ];
    }
}
