# 運用

サーバーで人が手を動かして入れる運用の手順。デプロイ・サーバー上の配置・PHP の版は `README.md`、バックアップの cron は `docs/backup.md` にあり、ここでは繰り返さない。

## 定期実行(scheduler)の cron

新アプリの定期実行は、Laravel の scheduler にまとめて登録してある(`backend/routes/console.php`。`php artisan schedule:list` で一覧が出る)。サーバーの cron からは、`php artisan schedule:run` を毎分 1 回呼ぶだけにし、何をいつ動かすかは scheduler が決める。今の登録は次の 1 つ。

| コマンド | 間隔 | 決めたところ |
|---|---|---|
| `affiliates:collect --refresh --new`(アフィリエイトの商品の取り直しと、新しく探す) | 10 分ごと(`.env` の `AFFILIATES_COLLECT_INTERVAL_MINUTES`。前の回が終わっていなければ重ねて流さない) | `docs/decisions/affiliate-freshness.md` の 5・#249 |

### どの環境に登録するか

| 環境 | 登録するとき |
|---|---|
| stg | 今(#779 で登録して確かめる) |
| 本番 | 切り替え(#79)の手順の中。旧アプリの cron を止め、旧の収集が終わったこと(止めたときに動いていた回が最後まで流れたこと)を確かめてから登録する。それより前には登録しない |
| dev | 登録しない |

- 新アプリの scheduler が書くのは、その環境の `.env` の DB(新 DB)だけ。旧 DB には書かない
- 旧アプリの cron(旧の scheduler の `schedule:run`。旧はアフィリエイトの収集を毎分呼んでいた)は、切り替え(#79)の最初に止める。本番では、旧アプリの cron と新アプリの cron を同時に動かさない(同じアフィリエイトを 2 つのアプリが集めないように)。止めた旧の cron の行を消すのは、切り替えの数週間後の片付け(#81)
- Amazon・楽天の 1 秒あたりの回数の上限は、資格情報(楽天はアプリ ID)ごとに数える(`docs/decisions/affiliate-freshness.md`)。同じ資格情報を入れた環境の cron を複数動かすと、上限を分け合うことになる。dev に登録しないのはこのため。切り替えの後に stg の cron を残すかは、ステージングをどうするかと一緒に #81 で決める

### 登録する

1. SSH でサーバーに入り、その環境のアプリ本体(`README.md` の「サーバー上の配置」。以下 `<アプリ本体>`)で、scheduler に登録したものが出ることを確かめる

   ```bash
   cd ~/<アプリ本体> && php artisan schedule:list
   ```

   `php artisan affiliates:collect --refresh --new` が `*/10 * * * *`(`.env` で間隔を変えていれば、その間隔)で出ればよい。

2. cPanel の「Cron ジョブ」に 1 つ登録する。時刻は分・時・日・月・曜日をすべて `*`(毎分)にし、コマンドに次を入れる。失敗を知らせるメールアドレスは、画面の上の「Cron の電子メール」に入れる(`docs/backup.md` の「サーバーに入れる」の 5 と同じ。`MAILTO=`・`PATH=` の行は書けないので、`PATH` はコマンドの先頭に付ける)

   ```bash
   cd $HOME/<アプリ本体> && PATH=/usr/local/bin:/usr/bin:/bin php artisan schedule:run --whisper >>storage/logs/schedule-run.log 2>&1 || echo "schedule:run が失敗した(終了コード $?)。ログ: <アプリ本体>/storage/logs/schedule-run.log"
   ```

   SSH で `crontab -e` から入れるときは、先頭に `MAILTO=<失敗を知らせるメールアドレス>` の行を置き、`* * * * * <上のコマンド>` の 1 行を足す。どちらで入れても、`crontab -l` で行が増えていること(バックアップの 2 行も残っていること)を確かめる

   - `cd` してから動かす。`php` は今いるディレクトリで版が決まり、アプリ本体(`public_html` の外)ではデプロイが流す `php artisan` と同じ、サーバー全体の既定の版になる(`README.md` の「サーバーの PHP の版」)。CLI の版を変えるときは、デプロイのワークフローと一緒にこのコマンドの `php` も版の付いたコマンドにする
   - cron の `PATH` は短いことがあるので、`php` のある場所を先頭に付ける。サーバーで `command -v php` を見て、その場所が `PATH=` に入っているかを確かめる
   - `--whisper` を付けると、動かすものが無い分(10 分のうち 9 回)は何も書かない。ログに残るのは、動かした回の「`affiliates:collect --refresh --new` を動かした・DONE か FAIL か」の数行だけで、1 日に 150 回ほど
   - `storage/` はデプロイで消えない(`README.md` の「サーバー上の配置」)ので、ログは残り続ける。年に一度ほど消せばよい
   - コマンドが失敗しても、`schedule:run` は終了コード 0 で終わるので、cron のメールは来ない。失敗はログの FAIL の行と、Laravel のログ(`storage/logs/` の `laravel*.log`)の例外で見る。メールが来るのは、`schedule:run` そのものが動かなかったとき(`php` が見つからない、など)だけ
   - cron のコマンドの中の `%` は改行の意味になる。足すときは `\%` にする(上のコマンドには無い)

### 確かめる

登録してから 10 分ほど待って、サーバーで次を見る。

```bash
cd ~/<アプリ本体> && tail -n 20 storage/logs/schedule-run.log
```

- `php artisan affiliates:collect --refresh --new` の行が、**登録した後の時刻**の DONE で出ていればよい。cron を登録し直したときは、前の DONE がログに残っているので、それを成功の判定に使わない。FAIL なら、Laravel のログ(`storage/logs/` の `laravel*.log`)の同じ時刻の例外を見る
- DONE だけでは、Amazon・楽天の認証情報が設定されているかは分からない(無いと、警告を出して何も集めずに成功で終わる。その警告は下の `affiliates-collect-<YYYY-MM-DD>.log` に残る)。登録の前後に一度、サーバーで手動で動かし、「認証情報が設定に無い」で始まる警告が出ないことを確かめる。警告は API に問い合わせる前に出る。設定済みの提供元には、少数の問い合わせが発生することがある

  ```bash
  cd ~/<アプリ本体> && php artisan affiliates:collect --new --limit=1
  ```

- Amazon・楽天の上限・一時的な失敗は、コマンドの失敗にはならず、Laravel のログに warning(`affiliates:collect --refresh: …`・`affiliates:collect --new: …`)が出る。その回の残りは次の回にまわる
- 収集の件数(「取り直した(Amazon): N 件(保存 …)」「探した: N 件(…)」)と、商品ごとの行・警告は、日ごとのファイル `storage/logs/affiliates-collect-<YYYY-MM-DD>.log` に残る(scheduler が動かしたコマンドの出力は既定で捨てられるので、`backend/routes/console.php` でファイルに足していく指定をしている。#778)。回ごとに、動かした時刻の `[YYYY-MM-DD HH:MM:SS] affiliates:collect --refresh --new` の行が先に出る(ファイルの日付も時刻も、アプリの時刻帯の UTC。`config/app.php` の `timezone`)。登録した後の時刻の見出しの下に、件数の行が出ていればよい

  ```bash
  cd ~/<アプリ本体> && tail -n 40 "$(ls -t storage/logs/affiliates-collect-*.log | head -n 1)"   # いちばん新しい日のファイル
  ```

  - 1 回あたり、件数の行が 4 つ(Amazon・楽天の片方だけ設定していれば 3 つ)と、取り直した・探した商品ごとに 1 行(既定の件数で 15 行ほど)。1 日に 1 ファイルで、数百 KB ほど
  - 日付が 14 日より前のファイルは、scheduler が動かすたびに消す(Laravel の `daily` のログと同じ日数)。人が消さなくてよい

### 止める

cPanel の「Cron ジョブ」で、上の行を消す(SSH なら `crontab -e` で消す)。消した後は、次の回から動かない。動いている途中の回は最後まで流れる。
