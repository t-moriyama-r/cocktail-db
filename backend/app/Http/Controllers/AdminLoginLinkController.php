<?php

namespace App\Http\Controllers;

use App\Services\AdminLoginLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminLoginLinkController extends Controller
{
    /**
     * 非常用のログイン URL(php artisan admin:login-link が出したもの)でログインさせ、管理画面へ移す。
     *
     * 署名はルートの signed ミドルウェアが確かめる。使えない理由(署名の不一致・使用済み・期限切れなど)は
     * 返事で区別しない。どれも 403 にして、URL を試す人に手がかりを与えない。
     */
    public function __invoke(Request $request, AdminLoginLinks $links, string $user): RedirectResponse
    {
        $token = $request->query('token');
        $admin = is_string($token) ? $links->redeem((int) $user, $token) : null;
        abort_if($admin === null, 403);

        // Auth::login がセッション ID を振り直すので、ログインの前の ID を知っている人はこのセッションを使えない
        Auth::login($admin);

        return redirect('/admin');
    }
}
