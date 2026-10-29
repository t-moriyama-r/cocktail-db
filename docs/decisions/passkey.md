# パスキーでのログイン(ライブラリと API)

#311 で決めたこと。旧は X(Twitter)でのログインだけで、新では引き継がない(#61)。管理画面へのログインは、パスキーと、端末をなくしたときの非常用のログイン URL(#115)の 2 つにする。画面(登録・一覧・削除・ログイン)は FE の issue で作る。

## 決まったこと

1. ライブラリは **`laravel/passkeys`**(Laravel の公式。中で `web-auth/webauthn-lib` を使う)にする(下の「ライブラリの比べ方」)
2. ライブラリが置くルート(`/passkeys/login`・`/user/passkeys` など)は使わず(`Passkeys::ignoreRoutes()`)、ライブラリの処理(`Actions`)を、`/api` の下に置いた自分たちのコントローラから呼ぶ。管理 API の決まり(未ログインは 401・書き込みは CSRF を確かめる。#118)と、OpenAPI(FE の型の元)に乗せるため
3. API(`openapi/openapi.json` に出る)

   | API | ログイン | 中身 |
   |---|---|---|
   | `POST /api/auth/passkey/login-options` | 要らない | ログインの開始。`navigator.credentials.get()` に渡す options を返し、チャレンジをセッションに置く |
   | `POST /api/auth/passkey/login` | 要らない | ログインの完了。確かめられたら、そのパスキーの持ち主でログインして `{id, name}` を返す |
   | `POST /api/admin/passkeys/registration-options` | 要る | 登録の開始。`navigator.credentials.create()` に渡す options を返す。登録済みのパスキーは除外に載せる |
   | `POST /api/admin/passkeys` | 要る | 登録の完了。名前(1〜255 文字)を付けて保存し、201 で返す |
   | `GET /api/admin/passkeys` | 要る | 自分のパスキーの一覧(名前・登録した日時・最後に使った日時)。登録した順 |
   | `DELETE /api/admin/passkeys/{id}` | 要る | 自分のパスキーを 1 つ消す(204) |
   | `POST /api/admin/logout` | 要る | ログアウト(204)。セッションを捨て、CSRF のトークンも作り直す |

4. **最後の 1 つのパスキーも消せる**。消すと、次からは非常用のログイン URL(`php artisan admin:login-link`。サーバーに入れる人だけが出せる)でしか入れない。端末をなくした・盗まれたときに、そのパスキーが最後の 1 つでもすぐ消せることを優先する(消せないと、新しい端末を用意して登録し終えるまで、なくした端末で入れる状態が続く)。消した後も、いまのセッションはログインしたまま続く。画面では、最後の 1 つを消す前に確かめる(FE の issue)
5. ほかの人のパスキーを消そうとすると、無い ID と同じく 404 にする(あるかどうかを教えない)
6. 確かめられない応答は、どれも 422(`errors.credential`)にする。登録していない・消したパスキー、使い終わったチャレンジ(同じ応答の 2 回目・開始を呼ばずに完了だけ)、別のオリジン(フィッシングのサイト)で作った応答、書き換えた署名。webauthn-lib はチャレンジ・オリジン・署名の不一致や形の壊れた値で例外を投げるので、`App\Support\PasskeyCeremony` が 500 にせず 422 にそろえる
7. ログインの開始・完了は、同じ IP から 1 分に 10 回まで(11 回目から 429)。開始と完了で 1 回のログインなので、5 回まで試せる
8. 登録は、ログインしているセッションだけで許す(パスワードやパスキーでの再確認は求めない)。管理者は 1〜2 人で、ログインそのものがパスキーか非常用 URL なので、ライブラリの既定の `password.confirm`(パスワードの再確認)は使えない
9. 本人確認(指紋・PIN など)を必ず求め(`userVerification: required`)、端末が覚えるパスキー(discoverable credential)にする。ログインのときにユーザー名を入れなくてよい(ライブラリの既定のまま)

## 設定(環境ごと)

- パスキーは **RP ID(ドメイン)に結び付く**。RP ID と受け付けるオリジンは、ライブラリの既定のまま `APP_URL` から取る(`config('passkeys.relying_party_id')`・`allowed_origins`)。`APP_URL` を、画面を開く URL(`https://…`)と同じにしておかないと、登録もログインも 422 になる
- dev・stg・本番はドメインが違うので、パスキーは環境ごとに登録し直す(ある環境で登録したパスキーで、ほかの環境には入れない)
- 端末に渡すユーザーの ID(user handle)は、`APP_KEY` から作る(`PASSKEYS_USER_HANDLE_SECRET` が無いとき)。`APP_KEY` を変えても登録済みのパスキーでのログインは続く(登録のときの ID を DB に持つ)が、変えた後に同じ人が登録すると、端末には別の人として並ぶ。`APP_KEY` を変えるときは、`PASSKEYS_USER_HANDLE_SECRET` に元の `APP_KEY` を入れておく

## ライブラリの比べ方

2026-10-28 に Packagist で見た。

| | `laravel/passkeys`(選んだ) | `laragear/webauthn` | `spatie/laravel-passkeys` | `web-auth/webauthn-lib`(直接使う) |
|---|---|---|---|---|
| 作っているところ | Laravel の公式 | 個人(Laragear) | Spatie | Spomky-Labs(WebAuthn の PHP の実装の本体) |
| 保守 | v0.2.1(2026-05-18)。まだ 0.x | v5.0.2(2026-05-08)。README に「`laravel/passkeys` に置き換わり、もう保守しない」とある | v1.8.1(2026-06-05) | v5.3.9(2026-09-10)。よく更新される |
| Laravel 13 | 対応(`^11\|^12\|^13`) | 対応(`12.*\|13.*`) | 対応(`^11\|^12\|^13`) | Laravel に依らない |
| 依存の大きさ | webauthn-lib とその依存(Symfony の serializer・property-access など)。このリポジトリに新しく入ったのは、webauthn-lib を含めて 10 パッケージ | 小さい(自前で CBOR などを読む。`laragear/meta-model` だけ) | webauthn-lib とその依存(`laravel/passkeys` とほぼ同じ)に、`spatie/laravel-package-tools` | 上と同じ 10 パッケージ |
| 書く量 | 少ない。登録・ログイン・削除の処理(`Actions`)、表、モデル、ユーザーのトレイトがある | 少ない | 少ない。Livewire の画面の部品も一緒に持つ(開発の依存に `livewire/livewire`) | 多い。チャレンジの持ち方・表・モデル・JSON の読み書きをすべて自分で書く |

選んだ理由:

- 保守が続くかがいちばん大事(認証の部品なので、脆弱性の直しが届かないと困る)。`laragear/webauthn` は保守が終わっている。公式の `laravel/passkeys` は、Laravel 本体の版に合わせて保守される見込みが高い
- 中の検証は、いちばん使われていてよく更新される `webauthn-lib` が行う。`webauthn-lib` を直接使うより、書く量(=自分たちで間違える場所)が少ない
- `spatie/laravel-passkeys` も同じ `webauthn-lib` の上にあり依存も変わらないが、Livewire の画面の部品を一緒に持つ。このアプリの画面は React で、API だけを使うので、公式のほうが余分が少ない

気を付けること:

- `laravel/passkeys` はまだ 0.x。`composer.json` は `^0.2.1` にしてあり、0.3 以降(壊れる変更が入りうる)には勝手に上がらない。上げるときは、このテスト(`tests/Feature/Auth/PasskeyApiTest.php`)が通るかで確かめる
- ライブラリが置くルート・画面向けの応答は使っていない(上の 2)。上げたときにルートが増えても、`Passkeys::ignoreRoutes()` で置かれないことを TC-311-20 が確かめる

## 旧との差分

旧にパスキーは無い(旧は X でのログインだけ。#61 で引き継がないと決めた)。テストの表の「旧での結果」は、すべて `旧に無い` にしている。
