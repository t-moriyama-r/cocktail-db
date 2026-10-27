<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AdminLoginLinks;
use Illuminate\Console\Command;

/**
 * 非常用のログイン URL を発行して、標準出力に URL だけを出す(#115)。
 *
 * 管理者は 1 人なので、ふだんは引数なしで使う。ユーザーが 2 人以上いるときに誰で入るかを
 * 取り違えないよう、そのときは email の指定を求める。
 * URL のホストは APP_URL から作るので、本番では APP_URL が公開している URL になっている必要がある。
 */
final class IssueAdminLoginLink extends Command
{
    protected $signature = 'admin:login-link {email? : ログインするユーザーの email(ユーザーが 2 人以上いるときに指定する)}';

    protected $description = '管理画面に入る、期限付きで 1 回しか使えないログイン URL を出す';

    public function handle(AdminLoginLinks $links): int
    {
        $user = $this->targetUser();
        if ($user === null) {
            return self::FAILURE;
        }

        $this->line($links->issue($user));

        return self::SUCCESS;
    }

    private function targetUser(): ?User
    {
        $email = $this->argument('email');
        if (is_string($email)) {
            $user = User::query()->where('email', $email)->first();
            if ($user === null) {
                $this->error("{$email} のユーザーがいない");
            }

            return $user;
        }

        $users = User::query()->limit(2)->get();
        if ($users->count() === 1) {
            return $users->first();
        }

        $this->error($users->isEmpty()
            ? 'ユーザーがいない。旧 DB から管理者を移してから(#33)発行する'
            : 'ユーザーが 2 人以上いる。ログインするユーザーの email を指定する');

        return null;
    }
}
