# ノードの詳細の API

#42 で決めたこと。公開の `GET /api/categories/{id}`(カテゴリ木のノードの詳細)の決まり。画面は #105・#106 で作る。

## 決まったこと

1. **旧のカテゴリの詳細(`DetailController@category`)とミドルの詳細(`@middle`)を、任意の深さのノードの 1 つの API にする**。根(大元。`docs/decisions/category-root-role.md`)も返す(`docs/decisions/public-detail.md` の「決まったことに書かれていないこと」のとおり、新の大元には詳細ページがある)
2. **ノードの位置は、根からのパンくず(`path`)で返す**。根が先頭で、このノードが末尾(`CategoryTree::path`)。旧はカテゴリの詳細で種別(`kind`)を、ミドルの詳細で親のカテゴリと種別を別の項目で返していた。カクテルの詳細の材料(`docs/decisions/cocktail-detail.md` の決まったこと 4)と同じ形にそろえた
3. **子のノード(`children`)は、直下の子だけを兄弟の並び順で返す**(`CategoryTree::children`)。旧のカテゴリの詳細の「ミドルカテゴリ」(`middles`)にあたる。0 件なら空の並び(画面は欄を出して「登録なし」の文を出す。`docs/decisions/public-detail.md` の既定)
4. **銘柄の一覧(`brands`)は、このノードと配下すべてのノードに所属する銘柄を、ID の順で返す**(`docs/decisions/public-detail.md` の決まったこと 2)。銘柄ごとに、所属ノードの根からのパンくず(`path`)・度数(`strength`)・動画(`youtube`)を返す。旧の銘柄の表は所属を「親カテゴリ」「ミドルカテゴリ」の 2 列で出していた。どの段を出すかは画面が決める(同じ文書の決まったこと 3)
5. **有名な銘柄(`famous_brand`)は、銘柄の一覧の 1 件と同じ形で返す**。決めていないノードでは null
6. **「これを使ったカクテル」は返さない**。画面は旧と同じくランダムの 5 件と「再取得」で、#174 のノード起点の API で取る(`docs/decisions/public-detail.md` の決まったこと 1)。#42 の本文の「そのノード(配下を含む)を使うカクテル(ページング)」は、#283 の 1 で全件のページングを出さないと決まったので作らない
7. **アフィリエイトは返さない**。画面が `GET /api/affiliates`(#47)にノードの ID を渡して取る。有名な銘柄があればその銘柄の行を返す選び方は、そちらが受け持つ(`AffiliateController`)
8. **説明文は、文字とリンクの区切りの並びで返す**(#286。`docs/decisions/description-segments.md`)
9. **無いノードの ID は 404**。数字でない ID・int に収まらない桁数の ID も、経路に合わずに 404(カクテル・用語の詳細と同じ)

## 旧との差分

テストの表では `差分あり(意図: docs/decisions/category-detail.md)` としてここを指す。旧システムは手元で動かしていない(旧の挙動は、cocktail-legacy の `laravel13/app/Http/Controllers/DetailController.php` の `category`・`middle` を読んだもの)。

- 無い ID: 旧は `abort(500)` で 500 → 新は 404(決まったこと 9。カクテルの詳細(`docs/decisions/cocktail-detail.md`)・用語の詳細・アフィリエイト(`docs/decisions/affiliate-api.md`)と同じ扱い)
- ノードの位置: 旧はカテゴリの詳細で種別、ミドルの詳細で親のカテゴリと種別を別の項目で返していた → 新は根からのパンくずで返す(決まったこと 2)
- 銘柄の所属: 旧は銘柄ごとにカテゴリ・ミドルの 2 段を別の項目で返していた → 新は所属ノードの根からのパンくずで返す(決まったこと 4)
- 銘柄の一覧の並び: 旧は `ORDER BY` を付けずに引いていた(並びは DB に任せていた) → 新は ID の順に決める(決まったこと 4)

応答の項目の名前と形(`category` → 応答の直下、`middles` → `children`、`famousBrand` → `famous_brand`、YouTube の開始位置の `y_h`・`y_m`・`y_s` → `youtube_start_seconds` など)は、新しい画面(#105・#106)に合わせて変えた。表示する中身は変えていない。
