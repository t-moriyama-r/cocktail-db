# 画面から変える設定(項目の定義と管理 API)

#132 で決めたこと。設定の保存と読み出し(暗号化・`.env` へのフォールバック)は #131(`App\Support\Settings`)、画面は #133 で作った(下の「画面(#133)」)。旧には設定の画面が無く、鍵などはすべてサーバーのファイルに書いていた(旧に無い)。

## 決まったこと

1. 画面から変えられる項目は、コードの一覧 `App\Support\SettingDefinitions` で定義する(キー・名前・説明・秘密かどうか・検証の規則)。いまの項目は、コードが `Settings` から読んでいる 10 個

   | キー | 秘密 | 形 |
   |---|---|---|
   | `amazon.credential_id` | ○ | 空白を含まない ASCII、255 文字まで |
   | `amazon.credential_secret` | ○ | 同上 |
   | `amazon.partner_tag` | | 英数字と `.`・`_`・`-`、100 文字まで(リンクの URL に入る) |
   | `rakuten.application_id` | ○ | 空白を含まない ASCII、255 文字まで(#72) |
   | `rakuten.access_key` | ○ | 同上(#72) |
   | `rakuten.affiliate_id` | | `amazon.partner_tag` と同じ |
   | `ga4.measurement_id` | | `G-` に英大文字と数字が続く、30 文字まで(HTML のタグに入る。#59。`docs/decisions/analytics.md`) |
   | `affiliates.interval_minutes` | | 1〜59 の整数(収集の間隔の分。#826) |
   | `affiliates.refresh_limit` | | 1〜100 の整数(1 回に取り直す件数。Amazon・楽天それぞれ。#826) |
   | `affiliates.new_limit` | | 1〜100 の整数(1 回に新しく探す件数。#826) |

   - Credential ID は OAuth の Client ID で、厳密には秘密ではないが、画面に出しても使い道が無いので秘密として扱う。パートナータグと楽天のアフィリエイト ID は公開のリンクに載る値なので、秘密にしない
   - 楽天のアプリ ID は、規約 第5条が他人に知られないよう管理するよう求めているので、アクセスキーと同じく秘密として扱う(#72。`docs/decisions/rakuten-client.md`)
   - #69 の案にあるアフィリエイト収集の間隔と 1 回の件数は、読むコード(#249 の `affiliates:collect` と scheduler)が入ったときに足されないままだったので、#826 で足した(`App\Services\Affiliate\AffiliateCollectSettings`)。`.env` の `AFFILIATES_COLLECT_INTERVAL_MINUTES`・`AFFILIATES_REFRESH_LIMIT`・`AFFILIATES_NEW_LIMIT` は、それまでの `config/affiliates.php` から `config/settings.php` に移した。どちらにも無ければ既定値(10 分・10 件・5 件。`docs/decisions/affiliate-freshness.md` の 5)。GA4 の測定 ID は、読むコード(`App\Support\Ga4Tag`)を作った #59 で足した
   - 収集の値は、数字だけの整数で受ける(`+5`・`05`・`5.0` は 422。`affiliates:collect` の `--limit` と同じ形)。範囲の外の値で形の誤りまで重ねて出さないよう、最初の誤りで止める
   - 件数の上限を 100 にしたのは、楽天は 1 秒に 1 回なので、取り直し 100 件・新しく探す 100 件でも 1 回が数分で終わり、重ねて流さない印(`routes/console.php` の 30 分)より十分短いため。Amazon の 1 日の回数(`docs/decisions/affiliate-freshness.md` の 5)は、間隔と件数の掛け算で増えるので、上げるときはそちらの計算で確かめる
   - 間隔は cron の「N 分ごと」(`*/N`)で登録するので、60 を割り切れない値(7 など)は、毎時 0 分にそろえて数え直す(時の変わり目だけ間隔が短くなる)。#249 からの作り
   - 画面で変えた間隔は、次の `schedule:run` から効く(`schedule:run` は回ごとに別のプロセスで `routes/console.php` を読む)。`routes/console.php` は artisan のどのコマンドでも読まれるので、設定を読めないとき(DB に繋がらない・表がまだ無い)は落とさずに `.env` の値を使い、ログに残す
   - GA4 の測定 ID は、公開ページの HTML に出る値なので、秘密にしない
   - `APP_KEY`・DB の接続・`APP_URL` は載せない(#69 の 5)
2. API(`openapi/openapi.json` に出る。どちらもログインが要る)

   | API | 中身 |
   |---|---|
   | `GET /api/admin/settings` | 定義にある項目をすべて、定義の順で返す |
   | `PUT /api/admin/settings` | 本文 `{key, value}` で 1 項目を書き換え、書き換えた後の項目を返す |

   - キーに `.` が入るので、キーは経路に載せず本文で受ける。定義に無いキーは 422(`errors.key`)
   - `value` は必須の欄(省くと 422)。`null` か空の文字を送ると、保存した値を消して `.env` の値に戻す(行は残し、変えた人と日時を記録する)
3. 返す 1 項目の形: `key`・`label`・`description`・`secret`・`is_set`(値があるか)・`value`・`last_four`・`source`(`saved`: 画面で保存した値 / `env`: `.env` の値 / `null`: どちらにも無い)・`updated_at`・`updated_by`(`{id, name}`)
   - `.env` の値が空の文字(`AMAZON_CREDENTIAL_ID=` のように値の無い行)は、値が無いものとして扱う(`is_set: false`・`source: null`。`Settings::get` も既定値を返す。#689)
4. **秘密の項目は、値を平文で返さない**。`value` はいつも `null` で、`last_four` に末尾 4 文字だけを返す。`.env` から来た値でも同じ。値が 8 文字より短いときは、4 文字でも値のほとんどが分かるので、`last_four` も `null` にする(画面は `is_set` で「設定済み」だけを出す)。秘密でない項目は `value` に値を返し、`last_four` は `null`
5. 変えた人と日時は、`settings` 表の行の `updated_by`・`updated_at`(最後に変えた 1 回分)。変えた履歴を 1 件ごとに残す表は作らない(#69 で 2026-11-26 にオーナーが決めた。#826)

## 画面(#133)

管理画面の `/admin/settings`(メニューの「設定」)。旧に設定の画面は無い(旧に無い)ので、「旧との差分」は無い。

1. 項目は、`GET /api/admin/settings` が返す順に並べる。画面に項目を書き込まない。API に無い「画面から変えられないもの」(`APP_KEY`・DB の接続・`APP_URL`)だけは、説明を画面に書いておく
2. 書き換えの API が 1 項目ずつなので、保存のボタンも項目ごとに置く
3. 秘密の項目は「設定済み(末尾 4 文字)」/「設定済み」(末尾 4 文字が返らない短い値)/「未設定」を出し、値の欄(パスワードの欄)はいつも空にする。入れたときだけ上書きし、空のまま保存しても API を呼ばない(値を消さない)。入れた値を DOM の属性に残さないよう、欄の値は React の state で持たない
4. 秘密でない項目は、いまの値を欄に入れて出す。空にして保存すると `null` を送り、API が `.env` の値に戻す(上の 2)。画面にもそう書く
5. 秘密の項目を画面から `.env` の値に戻す操作は置かない(空のまま保存しても消さない、と決めたため)。要るときは別の issue にする
6. 規則に合わない値(422)は、その項目の欄の下に、サーバーのメッセージ(`errors` の中身。日本語。`docs/decisions/validation-messages.md`)を「この値は保存できません。」に続けて出す
7. 項目ごとに、いまの値の出どころ(画面で保存した値・`.env` の値・どこにも無い)と、最後に変えた人と日時(日本時間)を出す。変えた人のユーザーが消されていれば「削除されたユーザー」と出す
