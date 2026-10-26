# 種別に頼っていた判定を、新しい木でどう表すか(根の役割)

#153 で決めたこと。決めた経緯は #153 の本文とコメントにある。

旧アプリは、カテゴリの種別(`m_category.kind`。`m_category_kinds` の 5 件)の ID を数字で決め打ちして、3 種類の判定をしていた。新しい木では種別が根のノードになり、ID も振り直すので、判定の手段をここで決める。

| 旧の ID | 旧の種別 |
|---|---|
| 1 | 蒸留酒 |
| 2 | 醸造酒 |
| 3 | 混成酒 |
| 4 | ソフトドリンク |
| 5 | その他 |

## 決まったこと

1. `categories.role` を足し、**根のノードだけが値を持つ**。値は `soft_drink`(旧 kind 4)・`other`(旧 kind 5)・null(ほか)。根以外のノードは null のまま
2. ノードの役割は、閉包表(`category_closures`)でそのノードの根を引き、根の `role` で決める。根自身の役割はその `role`
3. 判定の対応

   | 判定 | 新しい木での条件 | 旧の条件 |
   |---|---|---|
   | その他(ガーニッシュ) | 根の role が `other` | `kind = 5` |
   | 有名な銘柄が要らない | 根の role が `soft_drink` か `other` | `kind = 4` か `5` |
   | 洋酒ではない | 根の role が `soft_drink` か `other` | `kind >= 4` |

4. 材料(`cocktail_ingredients.category_id`)とカクテルのベース(`cocktails.base_category_id`)には、根のノードを使わせない。保存のときの検証で止める
5. 部分木を別の根の下へ移すことは許す。移した後の材料の数は #156 で数え直す
6. 管理画面で根を新しく作るときは、役割(null・`soft_drink`・`other`)を選ばせる。役割を変えられるのは根だけ

列と述語を作るのは #155、旧データから役割を入れるのは #29、`docs/schema.md` に書くのは #19。

## 理由

### 根に役割を持たせる(案 A)

ほかに 2 つの案があった。

- B: ノードごとにフラグ(`is_garnish` など)を持つ。部分木ごとに変えられるが、親を付け替えるたびに配下のフラグを書き直すことになる。旧が上の段の ID を下の段へ複製していて、食い違いの元になっていたのと同じ形
- C: 根の ID を設定ファイルに持つ。移行をやり直して ID が変わると、黙って壊れる

A を採った理由:

- 種別は 5 件しかなく、役割を持つのはそのうち 2 件。根の数だけ値を持てば足りる
- 値を配下へ複製しないので、食い違いが起きない。部分木を別の根へ移すと、役割は何も書き換えずに移った先の根のものになる
- 旧も判定は種別の単位で、種別より細かい単位で変えることはできなかった。A でできなくなることは無い

### 材料とベースに根を使わせない

旧のレシピ(`t_recipies.category`)とベース(`t_cocktails.base`)は `m_category` の ID で、種別(`m_category_kinds`)を指すことはできなかった。根は旧の種別にあたるので、使わせなければ旧と同じになる。根を材料に使えると、「洋酒」のような大きすぎる材料がレシピに入り、検索の一致(#161)にも根の扱いを足すことになる。

### 根をまたぐ移動を許す

移動を止めると、木の作り直し(分類の見直し)ができなくなる。移動で変わるものは次のとおりで、それぞれ扱いを決めてある。

- 材料の数(`recipe_count`。#154 の定義では「その他」の材料を数えない): #156 で数え直す
- あるもの検索の結果(「その他」の材料は持っていなくてよい): 判定のたびに根を引くので、移した時点から新しい役割で検索される
- 量の表記(「その他」で `1` → `1個`): #153 の本文では移動で変わるものに挙げているが、変わるのは移した後に材料を保存し直したときだけ。旧も保存のときに整えた値を持ち(`RecipeFactory::saveRecipe` が `format_amount` を通す)、表示は持った値をそのまま出していた。新しい木でも保存のときに整えて持つ(#159)ので、移しても保存済みの表記は変わらない。度数も保存したときの値のままで、ずれは `cocktails:strength-diff`(#160)で一覧にする

### 懸念と、その扱い

- 役割は根の単位でしか持てないので、「洋酒」の根の下に「その他」扱いの部分木は作れない。旧も種別の単位だったので、できなくなることは無い。必要になったら B(ノードごとのフラグ)への変更を別の issue にする
- 判定のたびに閉包表で根を引く JOIN が入る。閉包表のインデックス(`descendant_id`, `depth`)で引けるので重くはない見込み。#96 の計測に含める

## 旧ソースで確かめた事実

本番の BE(cocktail-legacy の `laravel13/`)と、本番の画面のビルド(`production-public/`)・画面の Vue のソース(`laravel6/resources/js/`)で確かめた。パスは cocktail-legacy の中のもの。

### 判定の部品

`laravel13/app/Classes/Factory/KindFactory.php` が、種別の ID で判定する。

- `is_kind5_fromKindId`: `kind == 5 || kind == 4`。名前は kind5 だが、ソフトドリンク(4)も含む(コメントに「ソフトドリンクも有名な銘柄要らんかった」とある)。「有名な銘柄が要らない」の判定
- `is_garnish_fromKindId`: `kind == 5` だけ。「その他」の判定

どの判定も、2 段目のカテゴリ(`m_category`)の `kind` を見る。ミドル(`m_middle`)や銘柄は、親のカテゴリの `kind` で判定する。新しい木では、どの深さのノードでも根を引けば同じ判定になる。

### その他(kind 5)

- あるもの検索(`laravel13/app/Classes/Factory/CocktailFactory.php` の `cocktailSearch`、`searchMethod` が `having`): 持っていない任意でない材料が 1 つでもあるカクテルを除く。その条件は「(材料のカテゴリが選んだカテゴリに無く、かつそのカテゴリの `kind` が 5 でない)か、材料のミドルが選んだミドルに無い」。`kind <> 5` で除いているのは**カテゴリの句だけ**で、ミドルの句には種別の条件が無い。ミドルの句の意味は #161・#92 で扱う
- 材料の数の数え直し(`laravel13/app/Http/Controllers/SystemController.php` の `calRecipeCount`): 任意でない材料のうち、カテゴリの `kind` が 5 でないものを数える。保存のときの数え方(`RecipeCalc::recipe_count`)は「その他」も数えていて、2 つが食い違う。どちらに揃えるかは #154
- 量の書式(`laravel13/app/Classes/Factory/RecipeCalc.php` の `format_amount`): 量が数字だけのとき、`1` でカテゴリが「その他」なら `個`、それ以外は `ml` を付ける。度数の計算(`convert_amount_int`)もこの関数を通す

### 有名な銘柄が要らない(kind 4・5)

- ノードの編集の検証(`laravel13/app/Http/Requests/Detail/Edit/EditCategoryValidate.php`・`EditMiddleValidate.php`): kind 4・5 では有名な銘柄(`brand_*`)を必須にしない。ただし銘柄の名前・英名・度数・説明のどれかが入っていれば、銘柄の検証をかける。カテゴリはフォームで選んだ `kind`、ミドルは親のカテゴリの `kind` で判定する
- 画面の検証: `Admin/Super/Detail/CategoryEdit.vue` は `POST Cocktail/iskind5/kind/check`(種別の ID で判定)、`MiddleEdit.vue` は `POST Cocktail/iskind5/check`(カテゴリの ID で判定)を呼び、kind 4・5 なら有名な銘柄の入力の検証を外す。本番のビルドにもこの 2 つの呼び出しがある
- `DetailEditController@is_kind5_check`(`Cocktail/iskind5/check`)を呼んでいるのは、本番のビルドでも Vue のソースでも `MiddleEdit.vue` だけ。カクテルの編集画面からは呼んでおらず、「その他」だけのカクテルを警告する処理は見つからなかった
- `Admin/Super/Detail/DictionaryEdit.vue`(用語の編集)も `CategoryEdit.vue` を写したもので `Cocktail/iskind5/kind/check` を呼ぶが、有名な銘柄の入力欄が無いので、判定の結果は何にも効かない

### 洋酒ではない(kind 4 以上)は、本番では効いていない

`laravel13/app/Classes/Factory/AffiliateFactory.php` の `exeSearch` は、楽天の検索の前に次のように判定している。

```php
$is_not_liqueur=Category::find($affiliate->category_id)->value("kind")>=4;
$rakuten=RakutenFactory::generateFromWord($word,$is_not_liqueur);
```

これは 2 か所で意図どおりに動いていない。

1. `Category::find(...)` はモデルを返し、モデルの `value()` は Eloquent の `Model::__call` で**新しいクエリ**に渡される(Laravel 13 の `Model::__call` も最後は `forwardCallTo($this->newQuery(), ...)`)。そのため `select kind from m_category limit 1` になり、探したカテゴリではなく表の先頭の行の `kind` を読む。旧システムを手元で動かし、kind 4・5 のカテゴリで `value("kind")` が 1 を返し、`$is_not_liqueur` が false になることと、そのときのクエリを確かめた(手元で動くのは Laravel 6 の BE。この行は `laravel13/` と同じ)
2. `laravel13/app/Classes/Factory/RakutenFactory.php` の `generateFromWord(string $name)` は引数を 1 つしか受け取らず、`generateURL($name)` を `$is_not_liqueur` の既定値 false で呼ぶ。渡した値は捨てられる

結果として、本番の楽天の検索は、種別によらず**いつもジャンル `510915` で絞っている**(`generateURL` は `$is_not_liqueur` が false のときにジャンルを付ける)。ここで決めた「洋酒ではない」の判定(根の role が `soft_drink` か `other`)を楽天の検索に使うと、ソフトドリンク・その他の検索結果が旧と変わる。楽天の検索でこの判定を使うか(旧と変わることを意図した差分とするか、旧に合わせていつも絞るか)は、楽天の検索を作る issue(#72・#73)で決める。

### 判定ではなく、種別で分けて見せているもの

次は役割の判定ではなく、種別(根)ごとにまとめて見せているだけなので、根の名前と木の形で置き換わる。

- 検索フォームのカテゴリの一覧(`laravel13/app/Classes/SearchCommon/Search.php` の `get_kinds_for_form`・`Forms/Search/Cocktail/form.vue`)は、種別ごとにカテゴリを分けて出す
- カテゴリ・ミドル・銘柄の詳細(`laravel13/app/Http/Controllers/DetailController.php`)は、種別の名前を出す

### 旧 DB の件数

旧 DB の種別ごとのカテゴリ(`m_category`)の数: 蒸留酒 15・醸造酒 5・混成酒 116・ソフトドリンク 89・その他 56。
