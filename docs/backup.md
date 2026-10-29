# バックアップ

本番の DB と、アップロードされた画像の定期バックアップの決まりと、サーバー・Backblaze B2 に入れる手順。決めたことは #17 のコメント(2026-10-25)と #373(2026-10-27)にある。

- サーバーの `BACKUP_DIR` に **毎日** 1 組(DB のダンプと画像のアーカイブ)取る
- **毎日**、その組をサーバーで公開鍵で暗号化して、サーバーの外の **Backblaze B2**(US West)に送る。復号用の秘密鍵は、サーバーにも B2 にも置かない(#373。それまでの、週 1 回手元の PC に rsync で取る運用は外す。下の「手元の PC の取り込みを外す」)
- サーバーの世代は **日次 7・週次 4**。B2 の写しは、ライフサイクルとオブジェクトロックの保持期間で **35 日** 残す(サーバーのキーでは消せない)
- **月 1 回**、ダンプを使い捨ての DB に戻して、元の DB と同じかを確かめる

## スクリプト

すべて `ops/backup/` にある。使い方・終了コードは、それぞれのファイルの先頭に書いてある。

| スクリプト | どこで | すること |
|---|---|---|
| `daily-backup.sh` | サーバーの cron(毎日) | 下の 3 つを順に呼び、すべて通ったら `prune-backups.sh` で古い世代を消す。`--verify-restore <DB>` を付けると、ダンプの直後に復元を確かめる(月 1 回)。`--offsite <設定ファイル>` を付けると、組ができた後に `offsite-upload.sh` で B2 に送る |
| `dump-db.sh` | (`daily-backup.sh` から) | DB のダンプ(#14)。`BACKUP_DIR/db/` |
| `verify-restore.sh` | (`daily-backup.sh` から、月 1 回) | ダンプを使い捨ての DB に戻し、テーブルごとの行数と `CHECKSUM TABLE` を元の DB と比べる(#16) |
| `archive-storage.sh` | (`daily-backup.sh` から) | 画像を tar.gz に固め、DB の画像列と突き合わせる(#15)。`BACKUP_DIR/storage/` |
| `offsite-upload.sh` | (`daily-backup.sh` から) | その日の組の 6 つのファイルを gpg で暗号化し、B2 の native API で送る(#373) |
| `prune-backups.sh` | サーバー・手元 | 古い世代を消す |
| `pull-backups.sh` | 手元の PC(定期では動かさない) | サーバーの `BACKUP_DIR` を rsync で取り、ハッシュを確かめ、手元の古い世代を消す。B2 に移したので定期では動かさない。B2 が使えないときに手で取る手段として残す |

テストは `ops/backup/tests/` にある。`prune-backups`・`daily-backup`・`pull-backups`・`offsite-upload` のテストは DB を使わない(`pull-backups` は rsync、`offsite-upload` は gpg・perl が要る。B2 には繋がず、curl を偽物に差し替える)。

```bash
bash ops/backup/tests/prune-backups.test.sh
bash ops/backup/tests/daily-backup.test.sh
bash ops/backup/tests/pull-backups.test.sh
bash ops/backup/tests/offsite-upload.test.sh
```

## 世代の数え方

`prune-backups.sh` は、db/ のダンプを DB 名ごとに、storage/ のアーカイブをそれだけで、別々に数える(2 つのスクリプトは日時を別々に取るので、同じ回でも秒がずれる)。

- **日次 7**: ある組のうち、新しい日から 7 日分。同じ日に何組かあれば、その日のいちばん新しい組
- **週次 4**: ある組のうち、新しい週(ISO 週。月曜始まり)から 4 週分の、それぞれの週でいちばん新しい組
- 日次と週次は重なる(今週の週次は日次の 1 つでもある)。毎日 1 組ずつ取っていれば、残るのは 9〜10 組(直近 7 日と、それより前の週の日曜の分。いちばん新しい組が日曜なら 10 組)で、およそ 4 週前まで戻れる
- どちらにも入らない組を消す。世代は今日の日付ではなく、ある組の日時で数えるので、定期実行が止まっても日がたつだけでは消えない。いちばん新しい組は必ず残る
- 名前が組の形(`<DB名>-<YYYYMMDD-HHMMSS>.sql.gz` など)に合わないもの(作りかけの `.partial`、作業用の `.work.*`、手で置いたファイル)には触らない

`daily-backup.sh` は、ダンプ・退避・復元の確かめのどれかが失敗した日は、古い世代を消さない。失敗が続いても、最後に取れた良い組が残る。

B2 に送れなかった日は、古い世代を**消す**(終了コード 3 で知らせる)。サーバーの中のバックアップは取れていて、世代は組の日時で数えるので、送れない日が続いてもサーバーの最新の組は残る。止めると、送れないあいだサーバーの容量だけが増え続けるため。

## サーバーに入れる

デプロイ(`README.md` の「デプロイ」)はアプリの `backend/` だけを送るので、`ops/backup/` は手で置く。バックアップするのは、本番で動いているアプリ(切り替えまでは旧アプリ)の DB と画像。

1. スクリプトを置く(手元のリポジトリから)

   ```bash
   ssh <user>@<host> 'mkdir -p ~/cocktail-ops && chmod 700 ~/cocktail-ops'
   scp -r ops/backup <user>@<host>:~/cocktail-ops/
   ```

2. アプリの `.env` に `BACKUP_DIR` を足す。**`~/` を使わず、絶対パスで書く**(`archive-storage.sh` は `~/` を展開しない。`daily-backup.sh` は、2 つの書いた先がずれたら止まる)。公開ディレクトリ(`public_html`)の下にはしない

   ```bash
   echo "$HOME"   # サーバーで。<ホームの絶対パス> を調べる
   # アプリの .env に足す
   BACKUP_DIR=<ホームの絶対パス>/backups
   ```

   `.env` を別に作る(アプリの `.env` を触らない)なら、`DB_*` と `BACKUP_DIR` を書いた権限 600 のファイルを作り、画像のディレクトリを `--storage-dir <アプリのディレクトリ>/storage/app/public` で渡す(`archive-storage.sh` は、渡さないと `.env` のあるディレクトリの `storage/app/public` を見る)

   画像が `.env` のあるディレクトリの `storage/app/public` に無いときも `--storage-dir` を渡す。下の 3 の手で流すときと、5 の cron の 2 行の両方に付ける。どこにあるかは、アプリの `public/storage` のリンク先を見る(`ls -l <アプリのディレクトリ>/public/storage`。旧アプリは、別のディレクトリの `storage/app/public` を指している)

3. 一度、手で流す。`BACKUP_DIR/db/` と `BACKUP_DIR/storage/` に 1 組ずつできることを確かめる

   ```bash
   bash ~/cocktail-ops/backup/daily-backup.sh <アプリのディレクトリ>/.env
   ls -l <ホームの絶対パス>/backups/db <ホームの絶対パス>/backups/storage
   ```

   世代の削除は、世代数を小さくした `--dry-run` で、消す予定の組が出ることを確かめる(消さない)

   ```bash
   bash ~/cocktail-ops/backup/prune-backups.sh <ホームの絶対パス>/backups --daily 1 --weekly 0 --dry-run
   ```

4. 月 1 回の復元の確かめに使う、捨ててよい DB を cPanel の「MySQL データベース」で作り(例: `<user>_cocktail_verify`)、アプリの DB ユーザーに全権限を付ける。中身は空のままにする(`verify-restore.sh` は、空でない DB には戻さない。終わったら空に戻す)

5. cPanel の「Cron ジョブ」に 2 つ登録する(毎日 3:00。毎月 1 日だけは復元の確かめを付ける)。cPanel の画面では、失敗を知らせるメールアドレスは画面の上の「Cron の電子メール」に入れ、ジョブごとに時刻(分・時・日・月・曜日)とコマンドを入れる(`MAILTO=`・`PATH=` の行は書けないので、`PATH` はコマンドの先頭に付ける)

   | 分 | 時 | 日 | 月 | 曜日 | コマンド |
   |---|---|---|---|---|---|
   | 0 | 3 | 2-31 | * | * | 下の 1 つ目 |
   | 0 | 3 | 1 | * | * | 下の 2 つ目 |

   ```bash
   mkdir -p $HOME/backup-logs && PATH=/usr/local/bin:/usr/bin:/bin bash $HOME/cocktail-ops/backup/daily-backup.sh <アプリのディレクトリ>/.env >>$HOME/backup-logs/daily-backup.log 2>&1 || echo "daily-backup が失敗した(終了コード $?)。ログ: backup-logs/daily-backup.log"
   mkdir -p $HOME/backup-logs && PATH=/usr/local/bin:/usr/bin:/bin bash $HOME/cocktail-ops/backup/daily-backup.sh <アプリのディレクトリ>/.env --verify-restore <user>_cocktail_verify >>$HOME/backup-logs/daily-backup.log 2>&1 || echo "daily-backup(復元の確かめ付き)が失敗した(終了コード $?)。ログ: backup-logs/daily-backup.log"
   ```

   SSH で `crontab -e` から入れるときは、先頭に `MAILTO=<失敗を知らせるメールアドレス>` の行を置き、`0 3 2-31 * * <1 つ目>`・`0 3 1 * * <2 つ目>` の 2 行にする。どちらで入れても、`crontab -l` で 2 行あることを確かめる

   - 出力はログに書くので、cron のメールは失敗したときの `echo` の 1 行だけになる
   - cron の `PATH` は短いことがある。`mariadb-dump`・`mysqldump` が見つからないと失敗するので、サーバーで `command -v mariadb-dump mysqldump` を見て、その場所がコマンドの `PATH=` に入っているかを確かめる
   - cron のコマンドの中の `%` は改行の意味になる。足すときは `\%` にする(上のコマンドには無い)
   - ログは 1 回数行なので、年に一度ほど消せばよい

6. 次の日に、ログの最後が「終わった」になっていること、db/ と storage/ の組が 1 つ増えていることを確かめる

## B2 に送る(サーバーの外の写し)

`daily-backup.sh` に `--offsite <設定ファイル>` を付けると、その日の組ができた後に `offsite-upload.sh` が、組の 6 つのファイル(`db/` のダンプ・`.counts.tsv`・`.sha256`、`storage/` のアーカイブ・`.check.tsv`・`.tar.gz.sha256`)を 1 つずつ gpg の公開鍵で暗号化し、`<B2_PREFIX>db/<名前>.gpg`・`<B2_PREFIX>storage/<名前>.gpg` の名前で B2 に送る。`.sha256` を最後に送るので、B2 に `.sha256.gpg` がある組は、ほかのファイルも送り終えている。

- 送るのは B2 の native API(`b2_authorize_account`・`b2_get_upload_url`・`b2_upload_file`)を curl で呼ぶ。サーバーには `aws` コマンドも rclone も無く、curl の `--aws-sigv4` はサーバーの版(7.76)ではアップロードの署名の扱いを確かめられないため。native API は署名が要らず、curl と sha1sum と perl(JSON を読む)だけで送れる。1 つのファイルは 5GB まで(今は 1 組 50MB ほど)
- 暗号化は gpg(サーバーにある。age は無い)。鍵束は使い捨ての一時ディレクトリに作り、`--recipient-file` で公開鍵のファイルを直接使う
- キーに消す権限・保持期間を変える権限(`deleteFiles`・`bypassGovernance`・`writeFileRetentions`・`writeBucketRetentions`)があると、送らずに止まる。公開鍵のファイルに秘密鍵が入っていても止まる
- 送るのに失敗すると、1 つのファイルにつき 3 回まで送り直す。それでも送れなければ、`daily-backup.sh` は古い世代を消したうえで終了コード 3 で終わり、cron のメールに「daily-backup が失敗した(終了コード 3)」が届く。ログに、どのファイルで、HTTP のステータスと B2 のエラーが何だったかが出る
- ダンプ・退避が取れなかった日は送らない。復元の確かめが通らなかった日は、組はそろっているので送る

### B2 のバケットとキーを用意する(人の作業。一度だけ)

1. Backblaze のアカウントを作る(リージョンは **US West**)
2. バケットを作る。**Private**、**Object Lock を有効**にする(後から有効にできない)。バケットの名前と ID はリポジトリ・issue・PR に書かない
3. バケットの設定で、保持期間を決める
   - **Object Lock の Default Retention**: モードは **Governance**、期間は **35 日**。Governance は、`bypassGovernance` の権限を持つキー(マスターのキー)だけが期限の前に消せる。サーバーに置くキーには付けないので、サーバーが乗っ取られても消せない。間違えて送ったものは人がマスターのキーで消せる
   - **Lifecycle Settings**: 送る名前の先頭(`B2_PREFIX`。例: `cocktail/`)に、`Days till hide` を **35**、`Days till delete` を **1** で付ける(日付で数える。保持期間の切れた 36 日目に消える)
   - 35 日にするのは、サーバーの世代(日次 7・週次 4。およそ 4〜5 週戻れる)に合わせるため。B2 には毎日の組を全部残すので、今の量(1 組 50MB ほど)で 1.8GB ほど。無料の 10GB に収まる
4. サーバーに置くキーを作る。権限は **`listFiles`・`writeFiles` だけ**、対象はこのバケットだけにする。Web の画面ではほかの権限が付くことがあるので、手元の PC で B2 の CLI(`b2`)から作る

   ```bash
   b2 account authorize                       # マスターのキーで(手元だけで使う)
   b2 key create --bucket <バケットの名前> cocktail-backup-upload listFiles,writeFiles
   ```

   出た keyID とキーの値は、下の「サーバーに置く」で設定ファイルに書く。チャット・issue・PR には貼らない

### 暗号化の鍵の組を作る(人の作業。一度だけ)

手元の PC(Git Bash の `gpg` でよい)で作る。期限は付けない(期限が切れると暗号化できず、毎日の送信が失敗するため)。

```bash
gpg --quick-gen-key 'cocktail-db backup' future-default default never   # パスフレーズを聞かれたら付ける
gpg --armor --export 'cocktail-db backup' >cocktail-backup-public.asc
gpg --armor --export-secret-keys 'cocktail-db backup' >cocktail-backup-secret.asc
```

- `cocktail-backup-secret.asc` とパスフレーズを、パスワード マネージャーなどに保管する。保管したら手元のファイルを消す。**サーバーにも B2 にも置かない**。無くすと B2 の写しは戻せない
- サーバーに置くのは `cocktail-backup-public.asc` だけ

### サーバーに置く(人の作業)

1. スクリプトを置き直す(`offsite-upload.sh` が増えた。上の「サーバーに入れる」の 1 と同じ `scp -r ops/backup …`)
2. 公開鍵と設定ファイルを置く。どちらも権限 600

   ```bash
   scp cocktail-backup-public.asc <user>@<host>:~/cocktail-ops/backup-public.asc
   ssh <user>@<host> 'umask 077 && cat >~/cocktail-ops/offsite.conf && chmod 600 ~/cocktail-ops/offsite.conf ~/cocktail-ops/backup-public.asc'
   ```

   設定ファイルの中身(`KEY=VALUE`。値を引用符で囲まない。パスは `~/` を使わず絶対パス):

   ```text
   B2_KEY_ID=<上で作ったキーの keyID>
   B2_APPLICATION_KEY=<上で作ったキーの値>
   B2_BUCKET_ID=<バケットの ID>
   OFFSITE_PUBLIC_KEY=<ホームの絶対パス>/cocktail-ops/backup-public.asc
   B2_PREFIX=cocktail/
   ```

3. 一度、手で送る。ログの最後が「1 組送り終えた(6 ファイル)」になり、B2 の画面に 6 つのファイルが出ることを確かめる(その日の組が既にあるなら、`offsite-upload.sh` だけを流してもよい)

   ```bash
   bash ~/cocktail-ops/backup/offsite-upload.sh <ホームの絶対パス>/cocktail-ops/offsite.conf \
     <ホームの絶対パス>/backups/db/<DB名>-<日時>.sql.gz <ホームの絶対パス>/backups/storage/<日時>.tar.gz
   ```

4. cron の 2 つのコマンド(上の「サーバーに入れる」の 5)の `daily-backup.sh <アプリのディレクトリ>/.env` の後ろに、`--offsite $HOME/cocktail-ops/offsite.conf` を足す
5. 次の日に、ログに「B2 に送った」が出ていること、B2 にその日の組(6 ファイル)が増えていることを確かめる
6. サーバーのキーで消せないことを確かめる。手元の PC で、**サーバーに置いたキー**を環境変数で渡して消そうとし、消す権限が無いと断られる(`unauthorized` など、削除を拒むエラー)ことを見る。断られなかったら、キーを作り直す。バケットの名前の誤り・認証の失敗など、ほかのエラーで止まったときは、消そうとするところまで届いていないので、直してやり直す

   ```bash
   B2_APPLICATION_KEY_ID=<サーバーのキーの keyID> B2_APPLICATION_KEY=<サーバーのキーの値> \
     b2 rm --versions b2://<バケットの名前>/cocktail/db/<B2 にある名前>.sql.gz.gpg
   ```

## B2 から取って戻す

1. 戻したい日の組の 6 つのファイルを、手元の PC に取る。B2 の画面(Browse Files)から落とすか、手元のマスターのキーで `b2 file download` を使う(サーバーのキーは使わない。`readFiles` の権限が無い)

   ```bash
   mkdir -p restore/db restore/storage
   b2 file download b2://<バケットの名前>/cocktail/db/<DB名>-<日時>.sql.gz.gpg restore/db/<DB名>-<日時>.sql.gz.gpg
   # .counts.tsv.gpg・.sha256.gpg と、storage/ の 3 つも同じように取る
   ```

2. パスワード マネージャーから秘密鍵を取り出して取り込み、`.gpg` を外して復号する(パスフレーズを聞かれる)

   ```bash
   gpg --import cocktail-backup-secret.asc
   for f in restore/db/*.gpg restore/storage/*.gpg; do gpg --output "${f%.gpg}" --decrypt "$f"; done
   ```

3. ハッシュを確かめる。どちらも「OK」と出ることを見る

   ```bash
   (cd restore/db && sha256sum -c <DB名>-<日時>.sha256)
   (cd restore/storage && sha256sum -c <日時>.tar.gz.sha256)
   ```

4. 戻す。DB は `gunzip -c restore/db/<DB名>-<日時>.sql.gz | mariadb <戻す先の DB>`(ダンプは `CREATE DATABASE`・`USE` を含まない)、画像は戻す先の `storage/app/public` で `tar -xzf <日時>.tar.gz`
5. 終わったら、手元の秘密鍵(`gpg --delete-secret-keys 'cocktail-db backup'`)と、取ったファイル・復号したファイルを消す。中身は本番のデータ

## 手元の PC の取り込みを外す

B2 に送れていることを確かめてから(上の「サーバーに置く」の 5・6 と、「B2 から取って戻す」の 1〜3 が通ってから)外す。それまでは、週 1 回の取り込みを動かしたままにする。

1. 手元の PC のタスク スケジューラで、タスク「cocktail-db バックアップの取り込み」を消す
2. 手元に取った写し(WSL の `~/cocktail-backups` など)を消す。中身は本番のデータで、暗号化していない
3. 取り込みにだけ使っていたサーバーに入る鍵を、cPanel の「SSH アクセス」で外し、手元の `~/.ssh/config` の `cocktail-backup` を消す

`pull-backups.sh` はリポジトリに残す。定期では動かさないが、B2 が使えないときに、手でサーバーから写しを取る手段にする(使い方はファイルの先頭)。

## 月 1 回の復元の確かめ

毎月 1 日の cron(上の 5)が、ダンプの直後に `verify-restore.sh` を流す。ログに「復元の確かめが通った」と出ていればよい。

- 通らなかった(終了コード 2)ときは、ログの「- が元、+ が復元」の行を見る。元の DB と比べるのは今の中身なので、ダンプと確かめの間に書き込みがあると一致しない。書き込みの少ない時間に、手でダンプからやり直す

  ```bash
  bash ~/cocktail-ops/backup/daily-backup.sh <アプリのディレクトリ>/.env --verify-restore <user>_cocktail_verify
  ```

- `CHECKSUM TABLE` は列の型・行の形式にもよるので、ダンプを取ったのと違う版の MariaDB に戻すと合わないことがある。同じサーバーで確かめる
- 確かめが通らなかった日も画像の退避は取るが、古い世代は消さない
- B2 の写しから戻すときは、上の「B2 から取って戻す」のとおり復号してから、db/ の中で `sha256sum -c <名前>.sha256` を確かめ、`gunzip -c <名前>.sql.gz | mariadb <戻す先の DB>` で入れる(`dump-db.sh` のダンプは `CREATE DATABASE`・`USE` を含まない)
