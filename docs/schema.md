# 新しい DB のスキーマ

#19 で決めた設計。後続のマイグレーション(#21・#22・#23)・移行(#29〜#33)・API が、この文書を見て作る。方針の元は Epic #18 の「方針(決定済み)」と #19 のコメント(2026-10-25)。

- DB は MariaDB 10.11。文字コードは `utf8mb4` / `utf8mb4_unicode_ci`(旧 DB は utf8mb3)
- 型は Laravel のマイグレーションの書き方で書く(`id()` は `bigint unsigned` の自動採番、`foreignId()` は同じ型の列)
- 旧 DB の表・列は `backend/database/legacy/schema.sql`(#27)にある。件数は、手元に復元した本番の旧 DB のダンプで数えたもの(2026-10-26)
- 旧 DB には外部キーが無い。新しい DB では外部キーを張る(下の「外部キーと削除」)

## 表の一覧

| 新しい表 | 中身 | 旧 DB | ID | 作る issue |
|---|---|---|---|---|
| `categories` | 木のノード(旧の種別・カテゴリ・ミドル) | `m_category_kinds` + `m_category` + `m_middle` | 振り直す | #21・#155 |
| `category_closures` | 木の閉包表 | 無し(新設) | — | #21 |
| `legacy_id_maps` | 旧 ID と新 ID の対応 | 無し(新設) | — | #21 |
| `brands` | 銘柄 | `m_brand` | 旧 ID のまま | #22 |
| `cocktails` | カクテル | `t_cocktails` | 旧 ID のまま | #22 |
| `cocktail_ingredients` | カクテルの材料 | `t_recipies` | — | #22 |
| `affiliates` | アフィリエイトの商品 | `affiliates` | 振る(旧は複合キー) | #23 |
| `methods` | 技法 | `m_method` | 旧 ID のまま | #23 |
| `ices` | 氷 | `m_ice` | 旧 ID のまま | #23 |
| `dictionaries` | 用語辞典(グラスもここ) | `t_dictionary` | 旧 ID のまま | #23 |
| `dictionary_categories` | 用語辞典の分類 | `m_dictionary_category` | 旧 ID のまま | #23 |
| `paraphrases` | 言い換え辞書 | `paraphrases` | 振る(旧は無し) | #23 |
| `skip_words` | スキップ語 | `skip_db` | 振る(旧は無し) | #23 |
| `settings` | 画面から変える設定 | 無し(新設。#69) | — | #23 |
| `users` | 管理者 | `users` | 旧 ID のまま | Laravel の雛形にある |
| `admin_login_links` | 非常用のログイン URL の発行の記録 | 無し(新設) | — | #115 |

ID の方針は #18 のとおり。カテゴリとミドルを 1 つの表にすると ID がぶつかるので、ノードだけ振り直して `legacy_id_maps` に残す。カクテル・銘柄は URL(`/detail/cocktail/{id}`・`/detail/brand/{id}`)を変えないため旧 ID のまま入れ、カクテルが指す技法・氷・用語辞典(`method_id`・`ice_id`・`glass_dictionary_id`)も旧 ID のまま入れる。旧 ID のまま入れた表も、`legacy_id_maps` に同じ値の対応を入れる(技法・氷・辞典の分類・用語辞典・ユーザーは #33、カクテル・銘柄は #30・#31)。旧に作成・更新日時の列が無い表(用語辞典・言い換え・スキップ語など)は、取り込みで `created_at`・`updated_at` を null にする。旧 ID のまま入れた表は、移行の後の自動採番が旧の最大値の次から始まる。

旧の表で移さないもの:

| 旧の表 | 移さない理由 |
|---|---|
| `migrations` | 旧の Laravel のもの。新しい DB は自分のマイグレーションで作る |
| `m_auto_input` | 旧の自動取り込み(1 つのサイトを番号順に読む)の読んだ位置(`header`・`number`)。旧の取り込みは移植しないと決めた(#68)。**人に確認**(下の「人に確認すること」の 3) |

旧の種別(`m_category_kinds`)は表としては残さず、木の根のノードにする。

## カテゴリ木

### `categories`

木のノード。旧の種別・カテゴリ・ミドルはどれもここに入る。親子は閉包表(`category_closures`)だけで持ち、`parent_id` の列は持たない(同じ事実を 2 か所に持つと食い違うため。親は depth 1 の行で引く)。

| 列 | 型 | null | 中身 | 旧 DB |
|---|---|---|---|---|
| `id` | `id()` | | 新しく振る | — |
| `name` | `string(255)` | | 名前 | `name` |
| `name_search` | `string(255)` | | 検索用に正規化した名前(#44 の `NameNormalizer`) | `name_search` |
| `name_eng` | `string(255)` | | 英名 | `name_eng` |
| `description` | `text` | ○ | 説明 | `description`(`m_middle` は NOT NULL、`m_category_kinds` も NOT NULL) |
| `role` | `string(20)` | ○ | 根の役割。`soft_drink`・`other`・null。**根だけが値を持つ**(#153) | `m_category_kinds.id` が 4 → `soft_drink`、5 → `other` |
| `is_major` | `boolean` | | メジャーか(既定 false) | `is_major`(根は false) |
| `is_like` | `boolean` | | 管理者のおすすめか(既定 false) | `is_like`(根は false) |
| `is_powder` | `boolean` | | 粉末か(既定 false) | `is_powder`(根は false) |
| `famous_brand_id` | `foreignId` → `brands` | ○ | 有名な銘柄(度数の計算に使う。#158) | `famous_brand_id`。0 は null にする(ミドルに 119 件) |
| `youtube` | `string(255)` | ○ | YouTube の動画 | `youtube` |
| `youtube_created_at` | `dateTime` | ○ | 動画の公開日時 | `youtube_created_at` |
| `youtube_start_seconds` | `unsignedInteger` | ○ | 動画の開始位置(秒) | `y_hour`・`y_minute`・`y_second`(下の「YouTube の開始位置」) |
| `image` | `string(255)` | ○ | 画像のファイル名 | `image` |
| `image_txt` | `string(255)` | ○ | 画像に添える文 | `image_txt` |
| `image_url` | `text` | ○ | 画像に添えるリンク先 | `image_url` |
| `sort_order` | `unsignedInteger` | | 兄弟の中での並び順(既定 0) | 無し(下の「並び順」) |
| `created_at`・`updated_at` | `timestamps()` | ○ | | `created_at`・`updated_at` |

インデックス: `name_search`・`name_eng`・`is_major`・`is_like`・`youtube_created_at`(旧と同じ列)。`role` は根の数しか値を持たないので張らない。

- 根の役割の値・判定(「その他」「有名な銘柄が要らない」「洋酒ではない」)は [category-root-role.md](decisions/category-root-role.md)(#153)。役割を変えられるのは根だけ。根以外に値を入れないことは、`CategoryTree` と管理画面の検証で守る。`CategoryTree` は、親のあるノードを役割つきで作ること(`addNode`)と、役割を持つ根をほかのノードの下へ移すこと(`moveSubtree`)を例外で止める(#155)
- モデルでは `role` を `App\Enums\CategoryRole`(`SoftDrink`・`Other`)で読み書きする。判定は `CategoryTree` の述語を使い、`role` の値を直接比べない(下の「ノードの根の役割を引く」)
- 根の判定は、閉包表で祖先を持たない(depth > 0 の行で `descendant_id` にならない)こと。`CategoryTree::rootIds()`(根の ID のサブクエリ)・`isRoot()` がこの定義を持つ
- 有名な銘柄は、そのノードの部分木にぶら下がる銘柄から選ぶ。根の役割が null の部分木では、保存のときの検証で必須にする(DB は null 可。#158)

#### 並び順

旧には並び順の列が無い。移行では、同じ親の下で旧の ID の昇順に 1 から振る(根は `m_category_kinds` の ID の順)。旧の画面がどの順で並べていたかは確かめていない(未確認。#29・#95 で確かめる)。

### `category_closures`

| 列 | 型 | 中身 |
|---|---|---|
| `ancestor_id` | `foreignId` → `categories` | 祖先 |
| `descendant_id` | `foreignId` → `categories` | 子孫 |
| `depth` | `unsignedSmallInteger` | 祖先から子孫までの段数。自分自身は 0 |

- 主キーは (`ancestor_id`, `descendant_id`)。インデックスは (`descendant_id`, `depth`)。祖先を近い順・遠い順に引く(パンくず・根の役割)のに使う
- ノードごとに自分自身の行(depth 0)を持つ
- **書き換えるのは `CategoryTree`(`app/Services/CategoryTree.php`)だけ**。直接 INSERT・UPDATE・DELETE しない(CLAUDE.md)
- 外部キーは `ON DELETE CASCADE`。ノードを消すとき(`CategoryTree::deleteLeaf`)に、そのノードの行も一緒に消えるようにする

### `legacy_id_maps`

| 列 | 型 | 中身 |
|---|---|---|
| `legacy_table` | `string(64)` | 旧の表の名前(`m_category_kinds`・`m_category`・`m_middle`・`m_brand`・`t_cocktails` など) |
| `legacy_id` | `unsignedInteger` | 旧 ID |
| `new_table` | `string(64)` | 新しい表の名前 |
| `new_id` | `unsignedBigInteger` | 新 ID |

- 主キーは (`legacy_table`, `legacy_id`)。インデックスは (`new_table`, `new_id`)(新から旧へ戻す検証 #35 と、旧 URL の転送に使う)
- 外部キーは張らない(指す先の表が行ごとに違うため)

## 銘柄・カクテル・材料

### `brands`

銘柄は木のノードにしない(容量・説明・アフィリエイトを持つ「商品」のため。#18)。木の**どの深さのノードにもぶら下げられる**。

| 列 | 型 | null | 中身 | 旧 DB(`m_brand`) |
|---|---|---|---|---|
| `id` | `id()` | | 旧 ID のまま | `id` |
| `category_id` | `foreignId` → `categories` | | 所属するノード(どの深さでもよい) | ミドルがあれば `middle_category`、無ければ `category` の新ノード(#30)。親が合わない 1 件は [migration-anomalies.md](migration-anomalies.md) の 4 |
| `name` | `string(255)` | | 名前 | `name` |
| `name_search` | `string(255)` | | 検索用に正規化した名前 | `name_search` |
| `name_eng` | `string(255)` | | 英名 | `name_eng` |
| `volume` | `decimal(4,1)` | | 度数(%) | `volume`(`float`。0〜96 で、どれも小数 1 桁に収まる) |
| `description` | `text` | ○ | 説明 | `description` |
| `is_major` | `boolean` | | | `is_major` |
| `is_like` | `boolean` | | | `is_like` |
| `instagram` | `string(255)` | ○ | | `instagram` |
| `youtube` | `string(255)` | ○ | | `youtube` |
| `youtube_created_at` | `dateTime` | ○ | | `youtube_created_at` |
| `youtube_start_seconds` | `unsignedInteger` | ○ | | `y_hour`・`y_minute`・`y_second` |
| `image` | `text` | ○ | | `image` |
| `image_txt` | `text` | ○ | | `image_txt` |
| `image_url` | `text` | ○ | | `image_url` |
| `created_at`・`updated_at` | `timestamps()` | ○ | | `created_at`・`updated_at` |

インデックス: `category_id`・`name_search`・`name`・`name_eng`・`youtube_created_at`・`is_major`・`is_like`(旧と同じ列)。

### `cocktails`

| 列 | 型 | null | 中身 | 旧 DB(`t_cocktails`) |
|---|---|---|---|---|
| `id` | `id()` | | 旧 ID のまま | `id` |
| `name` | `string(255)` | | | `name` |
| `name_search` | `string(255)` | | | `name_search` |
| `name_eng` | `string(255)` | ○ | | `name_eng` |
| `base_category_id` | `foreignId` → `categories` | | ベース。根は使わない(#153) | `base`(`m_category` の ID)を新ノードに付け替える(#31) |
| `method_id` | `foreignId` → `methods` | | 技法 | `method` |
| `glass_dictionary_id` | `foreignId` → `dictionaries` | ○ | グラス(用語辞典の分類「グラス」の項目) | `glass`(533 件が null。null でないものはすべて分類 2「グラス」の項目) |
| `ice_id` | `foreignId` → `ices` | ○ | 氷 | `ice`(4,162 件が null) |
| `volume` | `decimal(4,1)` | | 度数(%)。保存のときに材料から計算して持つ(#158) | `volume`(0〜85.8。移行では計算し直さない) |
| `sweet_degree` | `tinyInteger` | ○ | 甘さ(旧の値は -10〜10) | `sweet_degree` |
| `rate` | `unsignedTinyInteger` | ○ | 評価(旧の値は 1〜5) | `rate` |
| `description` | `text` | ○ | | `description` |
| `note` | `text` | ○ | | `note` |
| `is_draft` | `boolean` | | 下書きか(既定 false) | `is_draft`(`int`。値は 0・1 だけ) |
| `is_major` | `boolean` | | | `is_major` |
| `recipe_count` | `unsignedTinyInteger` | | 材料の数(下の「`recipe_count`」) | 旧の値は使わず数え直す |
| `instagram` | `string(255)` | ○ | | `instagram` |
| `youtube` | `string(255)` | ○ | | `youtube` |
| `youtube_created_at` | `dateTime` | ○ | 動画の公開日時 | `cc_youtube_created_at`(**列の名前を変える**。ほかの表と揃える) |
| `youtube_start_seconds` | `unsignedInteger` | ○ | | `y_hour`・`y_minute`・`y_second` |
| `image` | `text` | ○ | | `image` |
| `image_txt` | `text` | ○ | | `image_txt` |
| `image_url` | `text` | ○ | | `image_url` |
| `created_at`・`updated_at` | `timestamps()` | ○ | | `created_at`・`updated_at` |

インデックス: `name`・`name_eng`・`name_search`・`base_category_id`・`method_id`・`glass_dictionary_id`・`is_draft`・`is_major`・`rate`・`youtube_created_at`・`recipe_count`(旧と同じ列)。

- 旧の `updated_at` は `ON UPDATE current_timestamp()` だった。新しい DB では Eloquent が更新する
- 別名(`cocktail_aliases`)と同じレシピの判定(`cocktails.recipe_signature`)は #82 で書き足す

#### `recipe_count`

[recipe-count.md](decisions/recipe-count.md)(#154)のとおり。

- **定義**: カクテルの材料のうち、任意でなく(`is_optional = false`)、役割が `other` の根の部分木に入っていないものの数。かんたん検索は `recipe_count <= 3`
- **持ち方**: 列に持ち、**`RecipeCounter`(#156)だけが更新する**。更新のきっかけは、材料の保存(#130)・部分木を根をまたいで移したとき(`CategoryTree::moveSubtree` が数え直す。#156・#122)・銘柄の所属を変えたとき(#64)・移行(#31)・数え直しのコマンド(#74)
- 旧の値は使わない(旧は 2 つの数え方が混ざっている)。移行の後に数え直す

### `cocktail_ingredients`

| 列 | 型 | null | 中身 | 旧 DB(`t_recipies`) |
|---|---|---|---|---|
| `cocktail_id` | `foreignId` → `cocktails` | | | `cocktail_id` |
| `position` | `unsignedTinyInteger` | | 材料の並び順(1 から) | 無し。移行で読んだ順に振る(#31) |
| `category_id` | `foreignId` → `categories` | | 材料のノード(いちばん深いノード)。根は使わない(#153) | ミドルがあれば `middle_category`、無ければ `category` の新ノード |
| `brand_id` | `foreignId` → `brands` | ○ | 銘柄 | `brand` |
| `amount` | `string(20)` | | 量(「30ml」「1個」「適量」など。保存のときに書式を整えて持つ。#159) | `amount` |
| `is_optional` | `boolean` | | 任意の材料か | `is_optional` |

- 主キーは (`cocktail_id`, `position`)。インデックスは `category_id`・`brand_id`
- 同じカクテルに同じノード・銘柄の行が 2 つあってもよい(量の違う 9 組が残る。[migration-anomalies.md](migration-anomalies.md) の 2)。そのため主キーにノードを入れない
- 旧の材料の数は 1 カクテルあたり最大 11 行

#### 材料が銘柄を持つときのノード

材料に銘柄があるとき、材料の `category_id` は**銘柄の `category_id`(所属ノード)と同じにする**(#19 のコメントで決めた)。DB の制約ではなく、保存のときの検証で守り、`data:verify`(#157)でずれを見つける。アフィリエイトの `category_id` も同じ規則にする。

- 理由: 「このカクテルの材料はどのノードか」を 2 通りに持つと、検索(材料のノードで引く)と銘柄の詳細(銘柄の所属で引く)で結果が食い違う。同じにしておけば、銘柄の所属を変えたときに材料のノードを一緒に直すだけで済む
- 銘柄の所属を変えたとき(#64)は、その銘柄を使う材料・アフィリエイトの `category_id` も同じトランザクションで書き換え、`recipe_count` を数え直す
- **旧データとぶつかる**: [migration-anomalies.md](migration-anomalies.md) の 11(#257)で、「カテゴリしか指定していない材料の行に、そのカテゴリのミドルの下の銘柄が付いている」62 行をそのまま移すと決めた。そのまま移すと、この 62 行は材料のノード(カテゴリ)と銘柄の所属(ミドル)が違い、この規則と `data:verify` に引っかかる。どうするかは**人に確認**(下の「人に確認すること」の 1)

## アフィリエイト

### `affiliates`

ノード 1 つ、または銘柄 1 つにつき 1 行。旧は (`category_id`, `middle_id`, `brand_id`) の複合キーで、無いところを 0 にしていた。新しい表では `category_id`(いちばん深いノード)と `brand_id`(null 可)にする(#32・[migration-anomalies.md](migration-anomalies.md) の 9・12)。

| 列 | 型 | null | 中身 | 旧 DB(`affiliates`) |
|---|---|---|---|---|
| `id` | `id()` | | | 無し |
| `category_id` | `foreignId` → `categories` | | ノード。銘柄の行は銘柄の所属ノードと同じ | `category_id`・`middle_id` |
| `brand_id` | `foreignId` → `brands` | ○ | 銘柄。ノードの行は null | `brand_id`(0 は null) |
| `brand_key` | `unsignedBigInteger`(生成列) | | `IFNULL(brand_id, 0)` の `storedAs`。一意性の制約にだけ使う | — |
| `amazon_asin` | `string(10)` | ○ | Amazon の商品 ID(#247) | `amazon_detail_url` の `/dp/<10 桁>` から取る(#32) |
| `amazon_title` | `text` | ○ | | `title` |
| `amazon_price` | `unsignedInteger` | ○ | | `price` |
| `amazon_image_large_url` | `text` | ○ | | `img_large_url` |
| `amazon_image_medium_url` | `text` | ○ | | `img_medium_url` |
| `amazon_image_small_url` | `text` | ○ | | `img_small_url` |
| `amazon_detail_url` | `text` | ○ | | `amazon_detail_url` |
| `amazon_search_url` | `text` | ○ | | `amazon_search_url` |
| `amazon_fetched_at` | `dateTime` | ○ | Amazon の値を取った時刻(#247) | `updated_at`(#32) |
| `rakuten_item_code` | `string(255)` | ○ | 楽天の `itemCode`(#247) | `rakuten_detail_url` から取る(取れるかは #32 で確かめる) |
| `rakuten_title` | `text` | ○ | | `rakuten_title` |
| `rakuten_price` | `unsignedInteger` | ○ | | `rakuten_price` |
| `rakuten_image_url` | `text` | ○ | | `rakuten_image_url` |
| `rakuten_detail_url` | `text` | ○ | | `rakuten_detail_url` |
| `rakuten_search_url` | `text` | ○ | | `rakuten_search_url` |
| `rakuten_fetched_at` | `dateTime` | ○ | 楽天の値を取った時刻(#247) | `updated_at`(#32) |
| `human_title` | `string(256)` | ○ | 手で入れた値。期限なしで出す(#247) | `human_title` |
| `human_price` | `unsignedInteger` | ○ | | `human_price` |
| `human_image_url` | `text` | ○ | | `human_image_url` |
| `human_amazon_detail_url` | `text` | ○ | | `human_amazon_detail_url` |
| `human_amazon_search_url` | `text` | ○ | | `human_amazon_search_url` |
| `last_viewed_at` | `dateTime` | ○ | 最後に画面で見られた時刻。取り直す順に使う(#247) | 無し |
| `created_at`・`updated_at` | `timestamps()` | ○ | | `created_at`・`updated_at` |

インデックス: (`category_id`, `brand_key`) に unique・`brand_id`・`last_viewed_at`。

- **一意性**: (`category_id`, `brand_id`) の unique では、MariaDB は null を重複と見なさないので、同じノードの「ノードの行」(`brand_id` が null)が 2 行入ってしまう。null を 0 に置き換えた生成列 `brand_key` を作り、(`category_id`, `brand_key`) に unique を張る。銘柄の行の `category_id` は銘柄で決まるので、これで銘柄 1 つにつき 1 行にもなる。案として「`brand_id` だけの unique と、ノードの行だけの別の生成列の unique」もあったが、索引が 2 つになるので採らなかった。MariaDB 10.11 で、この生成列の unique がノードの行の重複を止めることと、`brand_id` の外部キーの CASCADE と一緒に使えることを確かめた
- 旧の `name`(ノード・銘柄の名前の写し。646 行すべてで名前と一致した)は移さない。検索の言葉は、そのときのノード・銘柄の名前から作る(名前を変えたときに食い違わないように)
- 変わる値(価格・画像の URL・商品名)を出してよい期限、価格の横に出す文言、取り直す間隔は #247(`docs/decisions/affiliate-freshness.md`)で決める。この表は、その判断に要る時刻の列だけを持つ
- 楽天の API で取れる値は `docs/rakuten-api.md`(#246 で書く)、Amazon は [amazon-creators-api.md](amazon-creators-api.md)(#134)

## 辞書・設定・ユーザー

### `methods`(旧 `m_method`。6 件)

`id`(旧 ID のまま)・`name` `string(255)`・`name_eng` `string(255)`・`description` `text`。旧と同じ列。

### `ices`(旧 `m_ice`。2 件)

`id`(旧 ID のまま)・`name` `string(255)`・`name_eng` `string(255)` null 可。旧と同じ列。

### `dictionary_categories`(旧 `m_dictionary_category`。6 件)

`id`(旧 ID のまま)・`name` `string(255)`・`name_eng` `string(255)`。旧の分類は 製法・グラス・カクテル分類・器具・スタイル・単位。

### `dictionaries`(旧 `t_dictionary`。29 件)

| 列 | 型 | null | 旧 DB |
|---|---|---|---|
| `id` | `id()` | | 旧 ID のまま(`cocktails.glass_dictionary_id` が指す) |
| `dictionary_category_id` | `foreignId` → `dictionary_categories` | | `category` |
| `name`・`name_eng`・`name_search` | `string(255)` | | 同じ名前の列 |
| `description` | `text` | | `description` |
| `youtube` | `string(255)` | ○ | `youtube` |
| `youtube_start_seconds` | `unsignedInteger` | ○ | `y_hour`・`y_minute`・`y_second`(旧の値はすべて null) |
| `image`・`image_txt` | `string(255)` | ○ | 同じ名前の列 |
| `image_url` | `text` | ○ | `image_url` |
| `created_at`・`updated_at` | `timestamps()` | ○ | 無し |

インデックス: `dictionary_category_id`・`name_search`。

### `paraphrases`(旧 `paraphrases`。277 件)

`id`・`name` `string(255)` unique・`converted` `string(255)`・`middle` `string(255)` null 可・`note` `text` null 可・`command` `string(255)` null 可・`timestamps()`。

旧と同じ列に `id` を足す。`converted`・`middle` は**名前のまま**持つ(ノードの ID にしない)。自動取り込み(#68)が名前で引くため(#33)。

### `skip_words`(旧 `skip_db`。5 件)

`id`・`name` `string(256)` unique・`timestamps()`。旧と同じ列に `id` を足す。

### `settings`(新設。#69)

| 列 | 型 | null | 中身 |
|---|---|---|---|
| `id` | `id()` | | |
| `key` | `string(100)` | | unique |
| `value` | `text` | ○ | `Crypt` で暗号化した値 |
| `updated_by` | `foreignId` → `users` | ○ | 最後に変えた人(#69 の「変えた人と日時を記録する」) |
| `created_at`・`updated_at` | `timestamps()` | ○ | |

#23 の本文は `key`・`value`・`updated_at` だけだが、#69 が変えた人を記録するので `updated_by` を足した。変えた履歴まで残すか(1 件ごとの記録の表を作るか)は #69 で決める。

### `users`

Laravel の雛形のマイグレーション(`0001_01_01_000000_create_users_table.php`)のまま使う。旧の `twitter_id` は移さない(X でのログインはやめた。#61)。パスキーと非常用のログイン URL に要る表は、選んだライブラリに合わせて #115・#116 で作る。

手元のダンプには `users` の行が入っていなかったので、件数は確かめていない(未確認。#33 は管理者 1 件を移す)。

### `admin_login_links`(新設。#115)

非常用のログイン URL(`php artisan admin:login-link`)を 1 回しか使えないようにする、発行ごとの記録。

| 列 | 型 | null | 中身 |
|---|---|---|---|
| `id` | `id()` | | |
| `user_id` | `foreignId` → `users` | | ログインさせるユーザー |
| `token_hash` | `char(64)` | | URL に載せたトークンの SHA-256。unique。トークンそのものは持たない(ダンプが漏れても URL を組み立てられないように) |
| `expires_at` | `timestamp` | | 発行から 10 分後。URL の署名の期限と同じ秒 |
| `used_at` | `timestamp` | ○ | 使った時刻。入っていれば使えない |
| `created_at`・`updated_at` | `timestamps()` | ○ | |

## YouTube の開始位置

旧の `y_hour`・`y_minute`・`y_second` を、秒の 1 列 `youtube_start_seconds` にまとめる(#19 のコメントで決めた)。管理画面では時・分・秒で入力し、保存のときに秒にする。対象は `categories`・`brands`・`cocktails`・`dictionaries`。

移行の変換:

- 3 列とも null → null
- どれか 1 つでも値がある → null を 0 として `y_hour * 3600 + y_minute * 60 + y_second`

旧 DB では `y_hour` はどの表でもすべて null で、値があるのは分と秒だけ(カクテルで 99 件。分は最大 11、秒は 0〜59)。動画の無いカクテルに開始位置だけが入っているものは無い。

## 外部キーと削除

| 外部キー | 削除のとき |
|---|---|
| `category_closures.ancestor_id`・`descendant_id` → `categories` | CASCADE |
| `brands.category_id` → `categories` | RESTRICT |
| `categories.famous_brand_id` → `brands` | RESTRICT |
| `cocktails.base_category_id` → `categories` | RESTRICT |
| `cocktails.method_id` → `methods`・`glass_dictionary_id` → `dictionaries`・`ice_id` → `ices` | RESTRICT |
| `cocktail_ingredients.cocktail_id` → `cocktails` | CASCADE(カクテルを消すと材料も消える。旧は材料を残していた。[migration-anomalies.md](migration-anomalies.md) の 1) |
| `cocktail_ingredients.category_id` → `categories`・`brand_id` → `brands` | RESTRICT |
| `affiliates.category_id` → `categories` | RESTRICT |
| `affiliates.brand_id` → `brands` | CASCADE(銘柄のアフィリエイトは銘柄に付いたもの) |
| `dictionaries.dictionary_category_id` → `dictionary_categories` | RESTRICT |
| `settings.updated_by` → `users` | SET NULL |
| `admin_login_links.user_id` → `users` | CASCADE(ユーザーを消すと、そのユーザーの URL も使えなくなる) |

- `categories.famous_brand_id` → `brands` と `brands.category_id` → `categories` は互いを指す。`categories` を作る #21 の時点では `brands` が無いので、`famous_brand_id` の外部キーは `brands` を作る #22 のマイグレーションで足す

### カテゴリを消すとき

**子ノード・銘柄・材料・アフィリエイトのどれかがあれば消させない**(#19 のコメントで決めた)。`CategoryTree::deleteLeaf`(#91)が先に調べて、どれがあるかを理由として返す(管理画面に出す。#121)。消せるのは葉だけで、部分木をまとめて消す操作は作らない。

- そのほかに、**カクテルのベース(`cocktails.base_category_id`)に使われているノード**も消させない。外部キーの RESTRICT でも消せないが、理由を画面に出せるよう `deleteLeaf` の調べる対象に足す(人に確認: 下の 2)
- 有名な銘柄(`famous_brand_id`)は、消すノード自身の部分木の銘柄を指す。銘柄があれば消させないので、有名な銘柄を指したままのノードが消えることは無い

## 検索

### 「ラム」の配下をすべて含めて検索する

閉包表で、「ラム」を祖先に持つノード(「ラム」自身を含む)を材料に使うカクテルを引く。

```sql
SELECT DISTINCT c.id, c.name
FROM categories AS rum
JOIN category_closures AS cc ON cc.ancestor_id = rum.id
JOIN cocktail_ingredients AS i ON i.category_id = cc.descendant_id
JOIN cocktails AS c ON c.id = i.cocktail_id
WHERE rum.id = :rum_id
  AND c.is_draft = false;
```

- `cc.ancestor_id = rum.id` は主キー(`ancestor_id`, `descendant_id`)の先頭で引ける
- 条件検索の一致はこれだけではなく、選んだノードの**祖先**(より大まかな指定。「ホワイトラム」を選んだときの「ラム」の材料)も一致する。一致の定義と 3 つの方式は [search.md](search.md) の「新しい木での意味」(#161)

### ノードの根の役割を引く

根は、そのノードのいちばん遠い祖先。インデックス (`descendant_id`, `depth`) で引ける。

```sql
SELECT root.role
FROM category_closures AS cc
JOIN categories AS root ON root.id = cc.ancestor_id
WHERE cc.descendant_id = :node_id
ORDER BY cc.depth DESC
LIMIT 1;
```

アプリからは `CategoryTree` の次のメソッドで引く(#155)。どれも、木に入っていないノードでは例外にする。

| メソッド | 返すもの | 旧 |
|---|---|---|
| `rootRole(Category)` | ノードの根の役割(`CategoryRole` か null)。根自身ならその役割 | 種別の ID(`m_category.kind`) |
| `isOther(Category)` | 根の役割が `other` か | `KindFactory::is_garnish_fromKindId`(kind 5) |
| `isFamousBrandOptional(Category)` | 根の役割が `soft_drink` か `other` か(有名な銘柄が要らない) | `KindFactory::is_kind5_fromKindId`(kind 4・5) |
| `isNonLiquor(Category)` | 根の役割が `soft_drink` か `other` か(洋酒ではない) | `kind >= 4`(`AffiliateFactory`) |
| `otherNodeIds()` | `isOther` が真になるノードの ID のサブクエリ(クエリの中で判定するとき。あるもの検索・材料の数) | — |

材料とベースに根を使わせない検証(下の「材料とベースに使ってよいノード」)は、検証の規則 `App\Rules\NotRootCategory` で行う。

### 検索フォーム(#95)に出す深さ

**すべての段を出す**(#19 の推奨)。旧はカテゴリとミドルの 2 段を種別ごとに出していた。新しい木は任意の深さになり、ミドルの下にもノードを置けるので、途中で切ると深いノードを選べなくなる。根(旧の種別)は見出しとして出す。

## 材料とベースに使ってよいノード

材料(`cocktail_ingredients.category_id`)とベース(`cocktails.base_category_id`)には、**根以外のどのノードでも使ってよい**。根は使わない。保存のときの検証で止め、`data:verify`(#157)でも確かめる(#153)。

旧の材料とベースは `m_category`(2 段目)か `m_middle`(3 段目)を指し、種別を指すことはできなかった。移行では、根以外のノードだけを指すことになる(旧のベース 5,707 件はすべて `m_category` にある)。

## 人に確認すること

1. **材料が銘柄を持つときのノードと、移行の 62 行**(上の「材料が銘柄を持つときのノード」)。案:
   - **a(おすすめ)**: 規則を「銘柄の所属ノードは、材料のノードと同じか、その子孫」に緩める。62 行をそのまま移せ、検索の一致は材料のノードで決まるので旧と同じ結果になる(migration-anomalies.md の 11 の理由と同じ)。銘柄の所属を変えたときは、材料のノードの祖先から外れる材料だけを直す
   - b: 規則は「同じ」のまま、移行した 62 行だけを例外にする。`data:verify` は 62 行を失敗ではなく警告にする。管理画面でそのカクテルを保存し直すと検証で止まるので、そのときに直してもらう
   - c: 移行で 62 行の材料のノードを銘柄の所属(ミドル)に付け替える。#257 で採らないと決めた案(検索の結果と #35 の検証が変わる)
2. **カクテルのベースに使われているノードも消させない**。#19 のコメントの「子ノード・銘柄・材料・アフィリエイト」と #24・#91 の `deleteLeaf` の条件には入っていないが、外部キーで消せないので、理由を返せるよう `deleteLeaf` の条件に足した
3. **`m_auto_input` を移さない**。#19 の本文のたたき台には `auto_input_states` があるが、#23・#33 の対象には無く、旧の自動取り込みは移植しないと決めた(#68)。値は旧 DB とダンプに残る
4. **`affiliates.brand_id` の CASCADE**。銘柄を消すと、その銘柄のアフィリエイト(手で入れた値を含む)も消える。手で入れた値を残したいなら RESTRICT にする
