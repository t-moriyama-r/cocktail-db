# 検証のメッセージの言語

#651 で決めたこと。API の 422(`errors` と `message`)のメッセージを日本語にする。

## 決まったこと

1. **`lang/ja` を置き、ロケールを `ja` にする**(FormRequest ごとに `messages()` を書く案は採らない)
   - サーバーの `.env` は前から `APP_LOCALE=ja`(`deploy-env.yml`)で、`lang/ja` が無いため `APP_FALLBACK_LOCALE`(`en`)の英語が出ていた。`backend/lang/ja/validation.php` を置けば、サーバーの設定を変えずに日本語になる
   - 手元と CI の `.env` の元(`backend/.env.example`)も `APP_LOCALE=ja` にし、`config/app.php` の既定も `ja` にする。テストもサーバーと同じ日本語のメッセージで動く
   - FormRequest ごとに書く案は、規則を足すたびに書き足す手間があり、書き忘れると英語に戻る。全体を日本語にしても困るところが無い(下の 3)ので採らない
2. **メッセージの形**
   - `backend/lang/ja/validation.php` は、Laravel の `lang/en/validation.php` と同じキーをすべて持つ(テストで確かめる。Laravel を上げて規則が増えたら足す)
   - 欄の名前(`:attribute`)は、英語のキーのまま出す(`valueの形が正しくありません。`)。キーの `_` は Laravel が空白にして出す(`per pageは50以下にしてください。`)。管理画面は欄の下にメッセージを出し、どの欄かは位置で分かるため。欄の名前を日本語にするかは、#692 で決める
   - `message`(1 つめのメッセージ)に付く「ほかの誤りの数」は `backend/lang/ja.json` で `(ほか N 件)` にする
   - 規則のクロージャで出す独自のメッセージも、同じ言語の行を使う(条件検索の ID に真偽値・小数を送ったときの `validation.integer`)。もとから日本語で書いたメッセージ(`NotRootCategory`・記法の誤りなど)は変えない
3. **公開の API も日本語になる**。ロケールは管理と公開で分けない
   - 変わるのは 422 のメッセージの文字だけで、`errors` のキー・状態コード・本文の形は変わらない(`openapi/openapi.json` も変わらない)
   - FE はメッセージの文字で分岐していない。管理画面(設定・銘柄・用語辞典など)はメッセージを画面にそのまま出す。公開の画面は 422 のメッセージを出さない
4. 変えないもの: 検証でないエラーの `message`(`Unauthenticated.`・`CSRF token mismatch.` など)は、言語の行を通らないので英語のまま

旧の 422 のメッセージは、旧システムを動かして確かめていない(#651 の PR のテストの表では `未確認`)。
