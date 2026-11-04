# 旧サイトが保存した検索条件を、開いたときに新しい保存へ移す

#175 で決めたこと。旧サイトは条件検索の条件をブラウザの localStorage(キー `searchInstant`・`searchUser`)に、旧のカテゴリ・ミドル・技法の ID のまま保存していた。同じドメインで切り替えるので、旧の値が利用者のブラウザに残る。ノードの ID は振り直したので、そのまま読むと別のノードとして黙って戻ってしまう。

新しい保存(#104。キー `cocktail-db.search.instant.v1`・`cocktail-db.search.user.v1`)は旧のキーを読まないので、開いたときに旧の値を新しいノードに直して移す。実装は `frontend/src/features/search/legacySearchStorage.ts`(移す処理)と `LegacySearchMigration.tsx`(公開ページの枠で動かし、お知らせを出す)。

## 決まったこと

1. 公開ページを開いたとき(どのページでも)に旧のキーがあれば、`POST /api/legacy/search-condition`(#290)で旧の ID を新しいノード・技法の ID に直し、同じ役割の新しいキーに保存して、旧のキーを消す(`searchInstant` → 「リロード」用、`searchUser` → 「読み込み」用)。検索方法(`searchMethod`)・かんたん検索(`is_easily`)はそのまま移す(#175 の追記)
2. 直し方(子のミドルがすべて・一部・0 個のとき)は BE が受け持つ(#290 の `LegacySearchCondition`。規則は #175 の追記と `docs/decisions/search-screen.md` の決まったこと 2)
3. 開いたときにフォームへ戻すことはしない。戻すのは、今までどおり「読み込み」「リロード」を押したとき(`docs/decisions/search-screen.md` の 4)。移した後は、開いているフォームの「読み込み」「リロード」も押せるようになる
4. 戻せなかった ID があれば、その数を本文の上に出す: 「旧サイトで保存した検索条件(「読み込み」「リロード」で戻す条件)を、今のサイトの材料に直しました。戻せなかった材料・製法 N 件は、条件から外しています。」。N は、API の `dropped_count`(対応の無い ID・親のカテゴリが選ばれていないミドルなど)と、ID として読めなかった値(数字でない・0 以下・小数・真偽値)の数を、2 つの保存先で足したもの。「閉じる」で消すまで、画面を移っても出したままにする
5. API が失敗した(通信の失敗・4xx・5xx)・新しいキーに保存できなかったときは、旧のキーを消さず、何も出さない。次に開いたときにやり直す(#175 の追記)
6. 旧のキーの値が壊れている(JSON でない・`null`・配列など、オブジェクトでない)ときは、やり直しても直らないので API を呼ばずに旧のキーを消し、「旧サイトで保存した検索条件が壊れていて読めなかったため、消しました。」と出す
7. 新しいキーにもう保存がある(前に API が失敗しているあいだに、新しい画面で「保存」「検索」した)ときは、新しい方を残して旧のキーを消す(API は呼ばない)
8. 旧の値の技法の配列が無いときは、すべての技法として移す(新しいキーに技法を書かない)。空の配列のときは、技法を 1 つも選んでいない条件として移す(新では、検索するとすべての技法として送る。`docs/decisions/search-screen.md` の 6)

## 旧との差分

テストの表では `差分あり(意図: docs/decisions/legacy-search-storage.md)` としてここを指す。

- 旧の保存値が壊れているとき: 旧は「読み込み」「リロード」を押せるかを決めるところ(`form.vue` の `is_can_loadUser`・`is_can_loadInstant`)で `JSON.parse` が例外を投げ、検索のフォームが出なくなる(旧ソースから。旧を動かしての見え方は確かめていない) → 新は開いたときに壊れた値を消し、消したことを出す。フォームは出る(#175 の受け入れ条件「壊れた JSON でも画面が壊れない」)

旧の保存値で、カテゴリにチェックがあり子のミドルが 0 個のものを親 1 つに直す(旧より広くなる)差分は、`docs/decisions/search-screen.md` の「旧との差分」にある。

## 理由

- 2 つの保存先の役割(「検索」で残す・「保存」で残す)を変えずに移せば、利用者は旧サイトと同じボタンで同じ条件に戻せる
- 戻せなかったものを黙って捨てると、「読み込み」で戻した条件が保存したときと違うことに気づけない。件数を出せば、選び直す必要があると分かる
- 壊れた値は、何度やり直しても読めない。残すと開くたびに同じお知らせが出るので、1 度だけ知らせて消す
- 新しいキーに保存があるのは、利用者が新しい画面で残した条件で、旧の値より新しい

## 旧ソースで確かめた事実

画面(`laravel6/resources/js/Modules/localStorageHelper.js`・`components/Forms/Search/Cocktail/form.vue`。本番のビルド `production-public/js/app.js` も同じ)を読んだ。旧システムを動かしての確認はしていない。

| どこ | 何をしているか |
|---|---|
| `localStorageHelper.js` の `props`・`generateObj` | `{searchMethod, methods, categories, middles, is_easily}` を `JSON.stringify` して、`searchInstant`(`saveInstant`)・`searchUser`(`saveUser`)に置く |
| `localStorageHelper.js` の `loadInstant`・`loadUser` | `JSON.parse(localStorage.getItem(…))` をそのまま返す。値を確かめない |
| `form.vue` 57〜73 行目 | `searchMethod` は `or`・`and`・`having` のどれか |
| `form.vue` 102・145・147 行目 | `methods` は技法の `method_id`、`categories`・`middles` はカテゴリ・ミドルの `id`(どれも数) |
| `form.vue` 289〜294 行目(`is_can_loadUser`・`is_can_loadInstant`) | 「読み込み」「リロード」を押せるかを `!!localStorageHelper.loadUser()`・`loadInstant()` で決める(壊れた値では `JSON.parse` が例外を投げる) |
| `form.vue` 428〜434 行目 | 読んだ値を、確かめずにフォームの値に入れる |
