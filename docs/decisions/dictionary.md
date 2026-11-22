# 用語辞典の API

#46 で決めたこと。公開の `GET /api/dictionaries`(一覧)・`GET /api/dictionaries/{id}`(詳細)の決まり。画面は #56。

## 決まったこと

1. **一覧の分類は ID の順**(旧と同じ。旧は並べ替えずに取っていて、主キーの順で返っていた)。用語の無い分類も、旧と同じく空の並びで返す(出さないのは画面。#56)
2. **一覧の用語は名前の順**。旧の画面(`Dictionary/index.vue` の `sortedList`)が、全角の英数字を半角に・カタカナをひらがなにそろえた名前を文字のコードの順に並べていたのを、API で行う。同じ名前は ID の順
3. **一覧の用語は ID・名前・英名だけを返す**。旧の画面は一覧でほかの項目を使っていなかった
4. **用語の種類(`kind`)と技法の ID(`method_id`)を詳細で返す**。技法に結び付いた用語(`methods.dictionary_id`。#40)は `method` と、その技法の ID。分類「グラス」(`dictionary_categories` の ID 2。旧 ID のまま)の用語は `glass`。それ以外は `other`。旧の画面は分類の名前(「製法」「グラス」)で判定し、技法から探すときは用語の ID を技法の ID として渡していた(ID がたまたま同じことに頼っていた)
5. **説明文は、文字とリンクの区切りの並びにして返す**(`DescriptionParser`。ほかの詳細の API と同じ。決まりは [description-segments.md](description-segments.md))。#46 では保存した記法のまま返していて、#542 で並びにした
6. **画像(`image`)は保存した値のまま返す**(カクテルの詳細と同じ)。動画の開始位置は、旧の時・分・秒(`y_h`・`y_m`・`y_s`)をまとめた秒(`youtube_start_seconds`。`docs/schema.md`)で返す

## 旧との差分

テストの表では `差分あり(意図: docs/decisions/dictionary.md)` としてここを指す。旧の挙動は本番の旧ソース(`laravel13/` の `DictionaryController`・リソース、画面は `laravel6/resources/js/components/Dictionary/`)から読み取ったもので、旧を動かして確かめてはいない。

- 一覧の用語の順: 旧の API は並べ替えずに返し、画面が名前の順に並べていた → 新は API が名前の順で返す(並べ方は旧の画面と同じ)
- 一覧の用語の項目: 旧は詳細と同じ項目(説明文・画像・動画など)をすべて返していた → 新は ID・名前・英名だけ
- 用語の種類: 旧は返さず、画面が分類の名前で判定していた → 新は `kind` と `method_id` を返す。分類「製法」の用語でも、技法に結び付いていなければ `other`
- 無い ID の詳細: 旧は 500(見つからない用語の項目を読んで落ちる) → 新は 404。数字でない・0 の ID は、旧は 422(`IdValidate`) → 新は 404(経路に合わない)
- 説明文: 旧も新もリンクの区切りの並びで返す(#542)。区切りの形などの差分は [description-segments.md](description-segments.md) の「旧との差分」
- 画像: 旧は公開の URL(`asset('storage/…')`)にして返していた → 新は保存した値のまま
- 動画の開始位置: 旧は時・分・秒の 3 つ(`y_h`・`y_m`・`y_s`) → 新は秒の 1 つ(`youtube_start_seconds`)

応答の項目の名前と形(旧の `result.dictionary` → `data`、`columns` → `dictionaries`、`dictionary_category` → `category`)は、新しい画面(#56)に合わせて変えた。表示する中身は変えていない。
