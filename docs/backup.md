# バックアップ

本番の DB と、アップロードされた画像の定期バックアップの決まりと、サーバー・手元の PC に入れる手順。決めたことは #17 のコメント(2026-10-25)にある。

- サーバーの `BACKUP_DIR` に **毎日** 1 組(DB のダンプと画像のアーカイブ)取る
- **週 1 回**、手元の PC に rsync で取る(Dropbox などの同期フォルダには置かない)
- 世代は **日次 7・週次 4**(サーバーも手元も同じ)
- **月 1 回**、ダンプを使い捨ての DB に戻して、元の DB と同じかを確かめる

## スクリプト

すべて `ops/backup/` にある。使い方・終了コードは、それぞれのファイルの先頭に書いてある。

| スクリプト | どこで | すること |
|---|---|---|
| `daily-backup.sh` | サーバーの cron(毎日) | 下の 3 つを順に呼び、すべて通ったら `prune-backups.sh` で古い世代を消す。`--verify-restore <DB>` を付けると、ダンプの直後に復元を確かめる(月 1 回) |
| `dump-db.sh` | (`daily-backup.sh` から) | DB のダンプ(#14)。`BACKUP_DIR/db/` |
| `verify-restore.sh` | (`daily-backup.sh` から、月 1 回) | ダンプを使い捨ての DB に戻し、テーブルごとの行数と `CHECKSUM TABLE` を元の DB と比べる(#16) |
| `archive-storage.sh` | (`daily-backup.sh` から) | 画像を tar.gz に固め、DB の画像列と突き合わせる(#15)。`BACKUP_DIR/storage/` |
| `prune-backups.sh` | サーバー・手元 | 古い世代を消す |
| `pull-backups.sh` | 手元の PC(週 1 回) | サーバーの `BACKUP_DIR` を rsync で取り、ハッシュを確かめ、手元の古い世代を消す |

テストは `ops/backup/tests/` にある。`prune-backups`・`daily-backup`・`pull-backups` のテストは DB を使わない(`pull-backups` は rsync が要る)。

```bash
bash ops/backup/tests/prune-backups.test.sh
bash ops/backup/tests/daily-backup.test.sh
bash ops/backup/tests/pull-backups.test.sh
```

## 世代の数え方

`prune-backups.sh` は、db/ のダンプを DB 名ごとに、storage/ のアーカイブをそれだけで、別々に数える(2 つのスクリプトは日時を別々に取るので、同じ回でも秒がずれる)。

- **日次 7**: ある組のうち、新しい日から 7 日分。同じ日に何組かあれば、その日のいちばん新しい組
- **週次 4**: ある組のうち、新しい週(ISO 週。月曜始まり)から 4 週分の、それぞれの週でいちばん新しい組
- 日次と週次は重なる(今週の週次は日次の 1 つでもある)。毎日 1 組ずつ取っていれば、残るのは 10 組(直近 7 日と、その前の 3 週の日曜の分)で、およそ 4 週前まで戻れる
- どちらにも入らない組を消す。世代は今日の日付ではなく、ある組の日時で数えるので、定期実行が止まっても日がたつだけでは消えない。いちばん新しい組は必ず残る
- 名前が組の形(`<DB名>-<YYYYMMDD-HHMMSS>.sql.gz` など)に合わないもの(作りかけの `.partial`、作業用の `.work.*`、手で置いたファイル)には触らない

`daily-backup.sh` は、ダンプ・退避・復元の確かめのどれかが失敗した日は、古い世代を消さない。失敗が続いても、最後に取れた良い組が残る。

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

## 手元の PC に取る(週 1 回)

Windows では WSL(Ubuntu など)の bash で動かす(Git Bash には rsync が無い)。

1. WSL に rsync を入れ、このリポジトリを clone する

   ```bash
   sudo apt install rsync
   git clone https://github.com/t-moriyama-r/cocktail-db.git ~/cocktail-db
   ```

2. サーバーに入る鍵を作り、cPanel の「SSH アクセス」で公開鍵を登録して許可する。接続先は `~/.ssh/config` に書く(リポジトリには書かない)

   ```sshconfig
   Host cocktail-backup
     HostName <host>
     User <user>
     Port <port>
     IdentityFile ~/.ssh/<鍵のファイル>
   ```

3. 取る。保管先は WSL の中(`~/cocktail-backups` など)か、同期していない Windows のフォルダ(`/mnt/c/...`)にする

   ```bash
   bash ~/cocktail-db/ops/backup/pull-backups.sh cocktail-backup:<ホームの絶対パス>/backups ~/cocktail-backups
   ```

   - `--delete` は付けていない。サーバーのバックアップが消された・壊されたときに、手元の写しまで消さないため。手元の世代は、取った後に `prune-backups.sh` が手元の組の日時で数えて消す
   - 取った後に、手元のすべての組を `.sha256` で確かめる。合わない・無いものがあれば 2 で終わり、手元の世代は消さない
   - `/mnt/c/...` に置くと、Windows のファイルシステムでは権限(700・600)が付かないことがある。中身は本番のデータなので、WSL の中に置くのを勧める

4. タスク スケジューラに週 1 回で登録する(「操作」のプログラムに `wsl.exe`、引数に次。「スケジュールされた時刻にタスクを開始できなかった場合、すぐにタスクを実行する」を有効にする。PC が止まっていた週を取りこぼさないため)

   ```text
   -d Ubuntu -- bash -lc "bash ~/cocktail-db/ops/backup/pull-backups.sh cocktail-backup:<ホームの絶対パス>/backups ~/cocktail-backups >>~/cocktail-backups.log 2>&1"
   ```

   スクリプトを新しくするときは、WSL の `~/cocktail-db` で `git pull` する。

## 月 1 回の復元の確かめ

毎月 1 日の cron(上の 5)が、ダンプの直後に `verify-restore.sh` を流す。ログに「復元の確かめが通った」と出ていればよい。

- 通らなかった(終了コード 2)ときは、ログの「- が元、+ が復元」の行を見る。元の DB と比べるのは今の中身なので、ダンプと確かめの間に書き込みがあると一致しない。書き込みの少ない時間に、手でダンプからやり直す

  ```bash
  bash ~/cocktail-ops/backup/daily-backup.sh <アプリのディレクトリ>/.env --verify-restore <user>_cocktail_verify
  ```

- `CHECKSUM TABLE` は列の型・行の形式にもよるので、ダンプを取ったのと違う版の MariaDB に戻すと合わないことがある。同じサーバーで確かめる
- 確かめが通らなかった日も画像の退避は取るが、古い世代は消さない
- 手元の写しから戻すときは、db/ の中で `sha256sum -c <名前>.sha256` を確かめてから、`gunzip -c <名前>.sql.gz | mariadb <戻す先の DB>` で入れる(`dump-db.sh` のダンプは `CREATE DATABASE`・`USE` を含まない)
