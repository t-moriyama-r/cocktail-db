# 旧の Twitter ログインの URL の扱い

#117 で決めたこと。新では X(Twitter)でのログインをやめた(#61)。旧のログインの URL をブックマークしている人が、新に切り替えた後に開いても迷わないよう、旧の 3 つの URL を管理画面(`/admin`)へ移す。

## 決まったこと

1. 旧の 3 つの URL を、GET で開くと `/admin` へ 302 で移す(`backend/routes/web.php`)
   - `/auth/twitter`(旧のログイン)
   - `/Cocktail/auth/twitter/callback`(旧の X からのコールバック)
   - `/auth/logout`(旧のログアウト)
2. 移すだけで、ほかのことはしない。X へは行かず、ログイン・ログアウトもしない。付いてきたクエリ(コールバックの `oauth_token` など)は移った先に持ち越さない
3. 受けるのは旧と同じく GET だけ。ほかのメソッドは 405 のまま
4. 状態コードは旧のリダイレクトと同じ 302 にする。301 にしないのは、ブラウザが覚えてしまい、後で行き先を変えられなくなるため

## 旧との差分

テストの表では `差分あり(意図: docs/decisions/legacy-login-urls.md)` としてここを指す。

- `/auth/twitter`: 旧は X の認可の画面へ移した → 新は `/admin` へ移す
- `/Cocktail/auth/twitter/callback`: 旧は X から戻ったユーザーでログインして管理画面へ移した(X のアカウントが登録されていなければ 403) → 新はログインせずに `/admin` へ移す
- `/auth/logout`: 旧はログアウトしてから `/` へ移した → 新はログアウトせずに `/admin` へ移す(ログイン中のセッションは切れない)

## 旧ソースで確かめた事実

本番の BE(`laravel13/`)を読んだ。旧システムを動かして確かめてはいない。

| どこ | 何をしているか |
|---|---|
| `laravel13/routes/web.php` | 3 つの URL を `Route::get` で `Auth\TwitterController` の `redirectToProvider`・`handleProviderCallback`・`logout` に向けている |
| `laravel13/app/Http/Controllers/Auth/TwitterController.php` | `redirectToProvider` は Socialite で X へ移す。`handleProviderCallback` は `twitter_id` でユーザーを探してログインし、`admin` の名前のルートへ移す(見つからなければ 403)。`logout` は `Auth::logout()` の後に `/` へ移す |
