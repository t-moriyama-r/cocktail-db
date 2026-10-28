# 旧システムを手元で動かす

リプレイスのテストで、期待値を旧システムの実際の挙動から決めるときに使う(`CLAUDE.md` の「テストの書き方」)。旧システム(Laravel 6 + Vue 2)を、旧ソースと本番 DB のダンプから手元の Docker で起動する。

本番の旧システムには書き込まない。読むだけの確認でも、手元で動かせるなら手元を使う。

## 用意するもの

- 旧ソースのリポジトリ `t-moriyama-r/cocktail-legacy`(private)の clone。動かすのはその中の `laravel6/`(以下 `<旧ソース>`)。何がどのディレクトリにあるかは、そのリポジトリの README
- 本番の旧 DB のダンプ(以下 `<ダンプ>`。`legacy_schema.sql` と `legacy_data.sql`)。本番データなので、このリポジトリにも cocktail-legacy にも commit しない
- Docker

旧ソースは、`vendor/` を入れるとき以外は書き換えない。下の手順では読み取り専用でマウントする。

## 起動

下のコマンドは、このリポジトリ(cocktail-db)の clone の直下で流す(ダンプの確かめに `ops/migrate/check-legacy-dump.sh` を使う)。

Git Bash で動かすときは、先に `export MSYS_NO_PATHCONV=1` をする(コンテナ内のパス `/tmp/views` を Windows のパスに書き換えられるのを防ぐ)。

```bash
LEGACY_SRC=<旧ソース>        # cocktail-legacy の clone の laravel6
LEGACY_DUMP=<ダンプ>
WORK=<作業用のディレクトリ>   # リポジトリの外

# 設定のキャッシュがあると、下の DB_* の指定が効かない(「はまりどころ」)
if test -e "$LEGACY_SRC/bootstrap/cache/config.php"; then
  echo "設定のキャッシュがあるため中止します。キャッシュを除いた作業用のコピーを使ってください。" >&2
  exit 1
fi

# 1. PHP 7.4 + pdo_mysql のイメージ(初回だけ)
mkdir -p "$WORK"
printf 'FROM php:7.4-cli\nRUN docker-php-ext-install pdo_mysql\nWORKDIR /app\n' > "$WORK/Dockerfile"
docker build -t cocktail-legacy-php "$WORK"

# 2. DB
docker network create cocktail-legacy
docker run -d --rm --name cocktail-legacy-db --network cocktail-legacy \
  -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=legacy mariadb:10.11
# 起動を待つ。待てなければ中止する
ready=
for i in $(seq 1 60); do
  if docker exec cocktail-legacy-db mariadb -uroot -proot legacy -e 'SELECT 1' >/dev/null 2>&1; then ready=1; break; fi
  sleep 1
done
if test -z "$ready"; then
  echo "MariaDB が起動しなかったため中止します。" >&2
  docker rm -f cocktail-legacy-db >/dev/null 2>&1 || true
  docker network rm cocktail-legacy >/dev/null 2>&1 || true
  exit 1
fi
# legacy_data.sql はテーブル定義込み。users はデータ側に無いので、schema から定義だけ入れる
# 投入に失敗したら・入れた DB が欠けていたら止める。データが欠けたまま動かすと、間違った「旧での結果」を採ってしまう
# DB は調べられるよう消さずに残す。やり直す前に、下の「片付け」で消す
load_failed() { echo "ダンプの投入か、投入した DB の確かめに失敗したため中止します。調べ終えたら下の「片付け」で消してから、最初からやり直してください。" >&2; exit 1; }
test -f "$LEGACY_DUMP/legacy_data.sql" && test -f "$LEGACY_DUMP/legacy_schema.sql" || load_failed
docker exec -i cocktail-legacy-db mariadb -uroot -proot legacy < "$LEGACY_DUMP/legacy_data.sql" || load_failed
awk '/CREATE TABLE `users`/,/;$/' "$LEGACY_DUMP/legacy_schema.sql" | docker exec -i cocktail-legacy-db mariadb -uroot -proot legacy || load_failed
sed -n '/^ALTER TABLE `users`/,/;$/p' "$LEGACY_DUMP/legacy_schema.sql" | docker exec -i cocktail-legacy-db mariadb -uroot -proot legacy || load_failed
# 入れた DB に、旧アプリが使う表(users を含む)がそろい、主な表に行があるかを確かめる。欠けていれば、無い表・空の表を出して止める
ops/migrate/check-legacy-dump.sh legacy docker exec cocktail-legacy-db mariadb -uroot -proot || load_failed

# 3. 依存を入れる(初回だけ)。composer.lock が古く package:discover が落ちるので --no-scripts で入れる
#    (生成済みの bootstrap/cache/packages.php は cocktail-legacy に commit してある)
#    Windows のフォルダをマウントすると展開が遅く(数分〜10 分ほど)、既定の 300 秒で打ち切られるので、時間の上限を外す
if ! test -f "$LEGACY_SRC/vendor/autoload.php"; then
  docker run --rm -v "$LEGACY_SRC:/app" -w /app -e COMPOSER_PROCESS_TIMEOUT=0 composer:2 \
    install --ignore-platform-reqs --no-scripts --no-interaction \
    || { echo "依存を入れられなかったため中止します。" >&2; exit 1; }
fi

# 4. アプリ。.env は無いので APP_KEY も渡す(手元で動かすだけなので、毎回作った値でよい)
docker run -d --rm --name cocktail-legacy-app --network cocktail-legacy -p 127.0.0.1:8000:8000 \
  -v "$LEGACY_SRC:/app:ro" -v "$LEGACY_SRC:/cocktail:ro" \
  -e DB_CONNECTION=mysql -e DB_HOST=cocktail-legacy-db -e DB_PORT=3306 -e DB_DATABASE=legacy \
  -e DB_USERNAME=root -e DB_PASSWORD=root -e APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" \
  -e APP_ENV=local -e APP_DEBUG=true -e APP_URL=http://127.0.0.1:8000 -e PHP_CLI_SERVER_WORKERS=6 \
  -e LOG_CHANNEL=stderr -e SESSION_DRIVER=cookie -e CACHE_DRIVER=array -e VIEW_COMPILED_PATH=/tmp/views \
  cocktail-legacy-php sh -c "mkdir -p /tmp/views && php -S 0.0.0.0:8000 -t public server.php"
```

http://127.0.0.1:8000 を開く。

片付け(途中で止まったときも、これで消してからやり直す):

```bash
docker rm -f cocktail-legacy-app cocktail-legacy-db; docker network rm cocktail-legacy
```

## はまりどころ

- **`http://127.0.0.1:8000` で開く**。旧画面のビルド成果物に、API の呼び先 `http://127.0.0.1:8000` が埋め込まれている。`localhost` や別のポートで開くと API が呼ばれず、画面の中身が空になる。新アプリの `php artisan serve`(既定で 8000)と同時に動かすときは、新アプリのポートを変える
- **画面は `laravel6/public/` のビルドを使い、`production-public/` は使わない**。`production-public/`(本番の画面)には本番の API の URL が埋め込まれていて、手元で開くと本番の API を呼ぶ
- 動くのは Laravel 6 の BE で、本番の BE(cocktail-legacy の `laravel13/`)とは認証まわりのルートなどが違う。カクテルの表示・検索の API は同じ(違いは cocktail-legacy の README の「本番との違い」)
- **旧ソースの `public/index.php` は `../../cocktail` を読む**(本番の配置)。そのため旧ソースを `/app` と `/cocktail` の 2 か所にマウントしている
- 接続先は環境変数で渡す。旧ソースの `.env` より環境変数が優先される(設定のキャッシュ `bootstrap/cache/config.php` が無いとき)。`.env` は書き換えない
- POST の API は CSRF のトークンが要る。トークンを付けずに curl で呼ぶと 419 になる。トップのページで受け取った `XSRF-TOKEN` のクッキーを `X-XSRF-TOKEN` のヘッダーに付ければ、curl でも呼べる(下の「POST の API を curl で呼ぶ」)。セッションを `cookie` にしているのは、`array` だとトークンが保てず 419 になるため
- 画面の読み込みに数秒かかる。Playwright で `networkidle` を待つとタイムアウトするので、`load` の後に待つ
- jQuery の `.load` で出る `url.indexOf is not a function` は、旧の本番でも出るもので画面に影響しない

## POST の API を curl で呼ぶ

旧の API(`/api/cocktailDetail` など)は POST で、CSRF のトークンが要る。画面から操作しなくても、トップのページで受け取ったクッキーを使えば curl で呼べる。

1. クッキーを保存しながらトップのページを開く。セッションのクッキーと `XSRF-TOKEN` のクッキーが入る
2. `XSRF-TOKEN` の値は URL エンコードされて保存されているので、デコードして `X-XSRF-TOKEN` のヘッダーに付ける
3. 保存したクッキーを送りながら POST する

```bash
JAR=<作業用のディレクトリ>/cookies.txt   # リポジトリの外

# 1. トップを開き、クッキーを保存する
curl -s -o /dev/null -c "$JAR" http://127.0.0.1:8000/
# 2. XSRF-TOKEN を取り出して URL デコードする(%3D → = など)
token=$(awk '$6 == "XSRF-TOKEN" { print $7 }' "$JAR")
token=$(printf '%b' "${token//%/\\x}")
test -n "$token" || { echo "XSRF-TOKEN のクッキーが取れなかったため中止します。" >&2; exit 1; }
# 3. 同じクッキーを送り、トークンをヘッダーに付けて呼ぶ
curl -si -b "$JAR" -c "$JAR" -H "X-XSRF-TOKEN: $token" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"id": 1}' http://127.0.0.1:8000/api/cocktailDetail
```

- `X-XSRF-TOKEN` のヘッダーか、セッションのクッキー(`-b`)のどちらかが欠けると 419 になる
- 続けて呼ぶときは、手順 1・2 をやり直さず、同じ `$JAR` と `$token` を使ってよい(`-c` で更新したクッキーを次の呼び出しに渡す)

## 本番の Google Analytics に計測を送らない

旧画面は本番の Google Analytics に計測を送る。手元で開くときは止める。Playwright なら:

```js
await page.route(/google-analytics|googletagmanager|doubleclick/, (route) => route.abort());
```

手で開くときは、ブラウザの拡張などで上のドメインへの通信を止める。

## 結果の残し方

テストケースの表の「旧での結果」を埋めるときは、旧システムで同じ操作をした記録(画面のスクリーンショット、API の応答)を PR に貼る。貼る前に、ホスト名・ユーザー名・DB 名・秘密の値・絶対パス・本番の個人情報が写っていないか目視で確かめる。
