<?php

namespace App\Services;

use App\Models\AdminLoginLink;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * 非常用のログイン URL(#115)を発行し、開かれたときに 1 回だけ使わせる。
 *
 * パスキーの端末をなくしても管理画面に入れるようにするためのもので、サーバーに入れる人だけが発行できる。
 * URL の署名(APP_KEY)で、ユーザー・トークン・期限の書き換えを防ぐ。署名だけでは期限内なら何度でも
 * 使えるので、発行ごとの記録で 1 回きりにする。
 */
final class AdminLoginLinks
{
    private const LIFETIME_SECONDS = 600;

    public function issue(User $user): string
    {
        $token = bin2hex(random_bytes(32));
        // 期限は秒で持つ。署名の期限(expires)も DB も秒なので、端数があると境界で両者が食い違う
        $expiresAt = Carbon::now()->startOfSecond()->addSeconds(self::LIFETIME_SECONDS);

        AdminLoginLink::query()->create([
            'user_id' => $user->id,
            'token_hash' => self::hash($token),
            'expires_at' => $expiresAt,
        ]);

        return URL::temporarySignedRoute('admin.login-link', $expiresAt, [
            'user' => $user->id,
            'token' => $token,
        ]);
    }

    /**
     * 署名を確かめた後の URL のユーザーとトークンから、ログインさせるユーザーを返す。使えなければ null。
     *
     * 同じ URL が同時に 2 回開かれても 1 回しか通さないように、使った印は「まだ使っていない」ことを
     * 条件にした 1 回の UPDATE で付け、書き換えた行があったときだけ通す。
     */
    public function redeem(int $userId, string $token): ?User
    {
        $link = AdminLoginLink::query()->where('token_hash', self::hash($token))->first();
        if ($link === null || $link->user_id !== $userId || $link->used_at !== null || self::isExpired($link)) {
            return null;
        }

        $consumed = AdminLoginLink::query()
            ->whereKey($link->getKey())
            ->whereNull('used_at')
            ->update(['used_at' => Carbon::now()]);

        return $consumed === 1 ? $link->user()->first() : null;
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * 署名の期限の判定(期限の秒を過ぎたら切れる。ちょうどはまだ使える)と同じ境界にする。
     */
    private static function isExpired(AdminLoginLink $link): bool
    {
        return Carbon::now()->getTimestamp() > $link->expires_at->getTimestamp();
    }
}
