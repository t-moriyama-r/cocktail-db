# 旧データの異常と、移行での扱い

旧 DB の食い違いを、移行(`legacy:import`。#31 ほか)で黙って捨てたり直したりしないように、1 件ずつ扱いを書き残す。#28 で決めた。

- 件数は、手元に復元した本番の旧 DB のダンプで数えたもの(2026-10-26。カクテル 5,707 件・材料の行 26,788 行)。**移行の直前に、本番の旧 DB で同じ SQL を流して数え直す**(下の「移行の直前にやること」)
- 各節の SQL は、旧 DB(旧のテーブル名のまま)に流せばそのまま件数が出る
- 扱いは、#28 で人が確かめたもの(2026-10-25 のコメント)。それより後に見つかったものは、下の「決まっていないこと」に分けている

## 一覧

| # | 異常 | 件数 | 扱い |
|---|---|---|---|
| 1 | カクテルが消えた後に残った材料の行 | 3,251 行(857 カクテル分) | 移さない |
| 2 | 1 つのカクテルの中で、材料・銘柄まで同じ行 | 13 組(移すカクテルの中。消えたカクテルの分も入れると 16 組) | 量も同じなら 1 行にまとめ、違えば両方移す |
| 3 | 材料の行のミドルの親が、その行のカテゴリと合わない | 2 行 | ミドルの親に合わせる |
| 4 | 銘柄のミドルの親が、その銘柄のカテゴリと合わない | 1 件 | ミドルの親に合わせる |
| 5 | `recipe_count` が材料の行の数と合わない | 1,580 件 | 移行の後に数え直す |
| 6 | `name_search` の重複(カテゴリ・ミドル・銘柄) | 34 件(表をまたぐものは 26 件) | そのまま移す |
| 7 | ミドルと、直下の銘柄を両方持つカテゴリ | 43 件 | そのまま移す |
| 8 | 銘柄の名前が、カテゴリ・ミドルの名前と同じ | 22 件 | そのまま移す |
| 9 | アフィリエイトのキーの 0(ミドル無し 378 件・銘柄無し 138 件) | — | 0 を NULL にして、いちばん深いノードと銘柄に付け替える |
| 10 | 材料・量まで同じで、名前が違うカクテル | 45 組(95 カクテル) | 別々のまま移す |

## 1. カクテルが消えた後に残った材料の行

旧の削除の処理は `t_cocktails` の行だけを消し、`t_recipies` の行を消していなかった。カクテルとしては戻せない(名前も技法も無い)。

- **扱い**: 移さない。旧 DB とダンプには残る
- 新しいアプリでは、カクテルを消すときに材料の行も消す

```sql
-- 3,251 行・857 カクテル
SELECT COUNT(*) AS rows_, COUNT(DISTINCT r.cocktail_id) AS cocktails
FROM t_recipies r LEFT JOIN t_cocktails c ON c.id = r.cocktail_id
WHERE c.id IS NULL;
```

## 2. 1 つのカクテルの中で、材料・銘柄まで同じ行

同じカクテルに、カテゴリ・ミドル・銘柄がすべて同じ行が 2 行ある。どの組も 2 行ずつで、どちらも任意でない(`is_optional = 0`)。

| | 組 | そのうち量も同じ |
|---|---|---|
| 移すカクテルの中 | 13 組(26 行) | 4 組(カクテル 2455・2456・4938・6443) |
| (参考)消えたカクテルの分も入れる | 16 組 | — |

#28 の「16 組」は、消えたカクテルの分(1 で移さない)も入れた数。移行で扱うのは 13 組。

- **扱い**: 量も同じなら 1 行にまとめる(上の 4 組。4 行減る)。量が違えば両方移す(9 組。「1個」と「2個」、「1個」と「20ml」など、別の使い方をしているものがある)
- まとめた 4 組は、#35 の検証(旧の材料の組と新の材料の組が一致する)で、旧の側も同じようにまとめてから比べる
- `recipe_count` は、まとめた後の行で数える(移行の中で、まとめるのは数え直しより先)。[recipe-count.md](decisions/recipe-count.md) の「決まったことに書かれていないこと」で残していた点は、この 4 組については 1 行と数えることになる。量の違う 9 組は 2 行のまま残るので 2 と数える

```sql
-- 移すカクテルの中: 13 組・26 行・量も同じ 4 組
SELECT COUNT(*) AS groups_, SUM(n) AS rows_, SUM(same_amount) AS same_amount_groups FROM (
  SELECT r.cocktail_id, COUNT(*) AS n, COUNT(DISTINCT r.amount) = 1 AS same_amount
  FROM t_recipies r JOIN t_cocktails c ON c.id = r.cocktail_id
  GROUP BY r.cocktail_id, r.category, r.middle_category, r.brand
  HAVING COUNT(*) > 1
) g;
-- 消えたカクテルの分も入れる: 16 組
SELECT COUNT(*) AS groups_ FROM (
  SELECT 1 FROM t_recipies r
  GROUP BY r.cocktail_id, r.category, r.middle_category, r.brand
  HAVING COUNT(*) > 1
) g;
```

## 3・4. ミドルの親とカテゴリが合わない

ミドル 126 の親はカテゴリ 197 だが、次の行はカテゴリを 2 にしている。

| | 件数 | どれ |
|---|---|---|
| 3. 材料の行 | 2 行 | カクテル 1681・5071 の、ミドル 126 の行(銘柄は無し) |
| 4. 銘柄 | 1 件 | 銘柄 251(ミドル 126) |

- **扱い**: ミドルの親(カテゴリ 197)に合わせる。新しい木ではミドルのノードの下に付くので、カテゴリの列の値は使わない
- 材料の行・銘柄・アフィリエイトの、ほかの参照先はすべて存在する(カテゴリ・ミドル・銘柄の ID で、旧の表に無いものは 0 件)

```sql
-- 3. 材料の行: 2 行
SELECT COUNT(*) FROM t_recipies r
JOIN t_cocktails c ON c.id = r.cocktail_id
JOIN m_middle m ON m.id = r.middle_category
WHERE m.parent <> r.category;
-- 4. 銘柄: 1 件
SELECT COUNT(*) FROM m_brand b
JOIN m_middle m ON m.id = b.middle_category
WHERE m.parent <> b.category;
```

## 5. `recipe_count` が材料の行の数と合わない

1,580 件は、`recipe_count` を材料の**全行**(任意・その他も含む)の数と比べたもの。旧の 2 つの数え方(#154)のどちらと比べた数でもない。2 つの数え方で数えると次のとおり(詳しくは [recipe-count.md](decisions/recipe-count.md))。

| 比べたもの | 合わない件数 |
|---|---|
| 材料の全行 | 1,580 |
| 定義 1: 任意でない材料すべて(旧の保存のとき) | 1,425 |
| 定義 2: 任意でなく、その他(種別 5)でない材料(旧の数え直しのとき) | 744 |

- **扱い**: 旧の値は使わず、移行の後に `RecipeCounter`(#156)で定義 2 で数え直す(#154 で決めた)

```sql
-- 材料の全行と比べる: 1,580 件
SELECT COUNT(*) FROM t_cocktails c
WHERE c.recipe_count <> (SELECT COUNT(*) FROM t_recipies r WHERE r.cocktail_id = c.id);
-- 定義 1: 1,425 件
SELECT COUNT(*) FROM t_cocktails c
WHERE c.recipe_count <> (SELECT COUNT(*) FROM t_recipies r WHERE r.cocktail_id = c.id AND r.is_optional = 0);
-- 定義 2: 744 件
SELECT COUNT(*) FROM t_cocktails c
WHERE c.recipe_count <> (SELECT COUNT(*) FROM t_recipies r JOIN m_category m ON r.category = m.id
                         WHERE r.cocktail_id = c.id AND r.is_optional = 0 AND m.kind <> 5);
```

## 6. `name_search` の重複

カテゴリ・ミドル・銘柄の `name_search` を合わせて、2 回以上出てくるものが 34 件。#28 の「34 件」はこの数で、同じ表の中の重複も入っている。

| | 件数 |
|---|---|
| 3 つの表を合わせて重複する | 34 |
| ― そのうち、2 つ以上の表にまたがる | 26(銘柄とカテゴリが同じ名前、など) |
| ― 同じ表の中だけで重複する | 8(カテゴリ 1・ミドル 3・銘柄 4。ミドルは「ホワイト」「ブラウン」「グリーン」) |

- **扱い**: そのまま移す。名前で 1 件を引く処理(自動取り込み)は、複数見つかったら候補を返すように直す(#68)

```sql
-- 3 つの表を合わせて重複する: 34 件
SELECT COUNT(*) FROM (
  SELECT name_search FROM (
    SELECT name_search FROM m_category
    UNION ALL SELECT name_search FROM m_middle
    UNION ALL SELECT name_search FROM m_brand
  ) u
  GROUP BY name_search HAVING COUNT(*) > 1
) d;
-- 2 つ以上の表にまたがる: 26 件
SELECT COUNT(*) FROM (
  SELECT name_search FROM (
    SELECT 'category' AS t, name_search FROM m_category
    UNION ALL SELECT 'middle', name_search FROM m_middle
    UNION ALL SELECT 'brand', name_search FROM m_brand
  ) u
  GROUP BY name_search HAVING COUNT(DISTINCT t) > 1
) d;
```

## 7. ミドルと、直下の銘柄を両方持つカテゴリ

旧では銘柄はミドルの下か、ミドルを持たないカテゴリの直下に付く想定だったが、両方を持つカテゴリがある。

- **扱い**: そのまま移す。新しい木では、ノードが子のノードと銘柄の両方を持つのは普通の形

```sql
-- 43 件
SELECT COUNT(*) FROM m_category k
WHERE EXISTS (SELECT 1 FROM m_middle m WHERE m.parent = k.id)
  AND EXISTS (SELECT 1 FROM m_brand b WHERE b.category = k.id AND b.middle_category IS NULL);
```

## 8. 銘柄の名前が、カテゴリ・ミドルの名前と同じ

22 件とも、その銘柄が属するカテゴリかミドル自身の名前と同じ。

- **扱い**: そのまま移す

```sql
-- 22 件(属するカテゴリ・ミドルの名前と同じものに限っても 22 件)
SELECT COUNT(*) FROM m_brand b
WHERE EXISTS (SELECT 1 FROM m_category k WHERE k.name = b.name)
   OR EXISTS (SELECT 1 FROM m_middle m WHERE m.name = b.name);
```

## 9. アフィリエイトのキーの 0

旧の `affiliates` は、カテゴリ・ミドル・銘柄の 3 つの ID をキーにし、無いものを 0 にしている(NULL ではない)。646 行の内訳は次のとおり。

| 形 | 行 | ミドル | 銘柄 |
|---|---|---|---|
| カテゴリだけ | 138 | 0 | 0 |
| ミドルを持たないカテゴリの直下の銘柄 | 240 | 0 | あり |
| ミドルの下の銘柄 | 268 | あり | あり |

#28 の「ミドル無し 378 件」は上の 2 つの形の合計(138 + 240)、「銘柄無し 138 件」は 1 つめの形。ミドルだけ(銘柄 0)の行は無い。銘柄の行はすべての銘柄に 1 行ずつある(508 銘柄)。

- **扱い**: 0 を NULL にし、カテゴリだけの行はそのカテゴリのノードに、銘柄の行はその銘柄に付け替える

```sql
-- 646 行・ミドル 0 が 378・銘柄 0 が 138・ミドルも銘柄も 0 が 138
SELECT COUNT(*) AS total, SUM(middle_id = 0) AS middle0, SUM(brand_id = 0) AS brand0,
       SUM(middle_id = 0 AND brand_id = 0) AS both0
FROM affiliates;
```

## 10. 材料・量まで同じで、名前が違うカクテル

材料の組(カテゴリ・ミドル・銘柄・量・任意)がまったく同じで、名前の違うカクテルが 45 組(95 カクテル)ある。

- **扱い**: 別々のまま移す。別名と同じレシピの表示は #82・#83 で作る

```sql
-- 45 組・95 カクテル
SET SESSION group_concat_max_len = 100000;
SELECT COUNT(*) AS groups_, SUM(n) AS cocktails FROM (
  SELECT s, COUNT(*) AS n FROM (
    SELECT c.id, c.name,
      GROUP_CONCAT(CONCAT_WS(':', r.category, IFNULL(r.middle_category, '-'), IFNULL(r.brand, '-'), r.amount, r.is_optional)
                   ORDER BY r.category, r.middle_category, r.brand, r.amount SEPARATOR ',') AS s
    FROM t_cocktails c JOIN t_recipies r ON r.cocktail_id = c.id
    GROUP BY c.id
  ) sig
  GROUP BY s HAVING COUNT(DISTINCT name) > 1
) g;
```

## 決まっていないこと

#28 で扱いを確かめた後に、数え直していて見つかったもの。扱いの案を書き、人に確かめる(#28 の PR のコメント)。

### 11. カテゴリしか指定していない材料の行に、そのカテゴリのミドルの下の銘柄が付いている

材料の行のミドルが NULL で、銘柄はそのカテゴリの子のミドルに属している。移すカクテルの中で 62 行(62 カクテル・5 銘柄。消えたカクテルの分も入れると 70 行)。カテゴリが食い違うもの・ミドルが食い違うものは無い。

- **案: そのまま移す**(材料のノードはカテゴリのまま)。#31 の 4 は材料のノードを「ミドルがあればミドル、無ければカテゴリ」で決めるので、そのとおりに移すとこの形になる。新しい木では、銘柄は材料のノードの子孫に付いていることになる。旧の検索はミドルが NULL の行を「カテゴリしか指定していない材料」として扱っていた([search.md](search.md) の「材料の一致」)ので、ノードを変えないほうが検索の結果が旧と同じになる
- もう 1 つの案: 銘柄の属するミドルに付け替える。材料の行の意味としてはこちらが正確だが、検索の結果と、#35 の検証の比べ方が変わる
- 新しいアプリで、材料のノードと銘柄の組に「銘柄はノードの直下」という制約を置くなら、この 62 行が引っかかる

```sql
-- 62 行・62 カクテル・5 銘柄
SELECT COUNT(*) AS rows_, COUNT(DISTINCT r.cocktail_id) AS cocktails, COUNT(DISTINCT r.brand) AS brands
FROM t_recipies r
JOIN t_cocktails c ON c.id = r.cocktail_id
JOIN m_brand b ON b.id = r.brand
WHERE r.middle_category IS NULL AND b.middle_category IS NOT NULL AND b.category = r.category;
```

### 12. アフィリエイトの、ミドルの親とカテゴリが合わない行

4 の銘柄 251 のアフィリエイトの行(カテゴリ 2・ミドル 126・銘柄 251)が、4 と同じく食い違っている。1 行。

- **案: 4 と同じくミドルの親に合わせる**。銘柄の行は銘柄に付け替える(9)ので、カテゴリの列の値は移行の結果に出ない。付け替えの前に、行のキーと銘柄の所属が合っているかを確かめる処理を入れるなら、そこで引っかかる

```sql
-- 1 行
SELECT COUNT(*) FROM affiliates a
JOIN m_middle m ON m.id = a.middle_id
WHERE m.parent <> a.category_id;
```

### 13. 名前も材料・量も同じカクテル

名前も材料の組(カテゴリ・ミドル・銘柄・量・任意)も同じカクテルが 50 組(105 カクテル)ある。

| | 組 |
|---|---|
| 名前も材料の組も同じ | 50 |
| ― 技法・グラス・氷・度数・説明まで同じ | 9 |
| ― 下書き(`is_draft`)のカクテルを含む | 28 |

旧では、同じ名前のカクテルが複数あるのは普通の形(レシピの違い。703 の名前が 2 件以上ある)。上の 50 組は、そのうちレシピまで同じもの。

- **案: 別々のまま移す**(10 と同じ)。旧 ID のまま移すので、URL も残る。まとめるなら、どちらの ID を残すか・消す方の URL をどう転送するか・下書きをどう扱うかを決める必要があり、移行とは別の issue にする

```sql
-- 50 組・105 カクテル
SET SESSION group_concat_max_len = 100000;
SELECT COUNT(*) AS groups_, SUM(n) AS cocktails FROM (
  SELECT s, name, COUNT(*) AS n FROM (
    SELECT c.id, c.name,
      GROUP_CONCAT(CONCAT_WS(':', r.category, IFNULL(r.middle_category, '-'), IFNULL(r.brand, '-'), r.amount, r.is_optional)
                   ORDER BY r.category, r.middle_category, r.brand, r.amount SEPARATOR ',') AS s
    FROM t_cocktails c JOIN t_recipies r ON r.cocktail_id = c.id
    GROUP BY c.id
  ) sig
  GROUP BY s, name HAVING COUNT(*) > 1
) g;
```

## 移行の直前にやること

本番の旧 DB で、この文書の SQL をすべて流して数え直す。件数が変わっていたら、この文書を直し、増えたものの扱いが今のままでよいかを確かめる(#31 の前に行う)。
