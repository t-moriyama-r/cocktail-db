<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PasskeyCeremony;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Laravel\Passkeys\Support\WebAuthn;

/**
 * パスキーで管理画面にログインする(#311)。ログインの前に呼ぶので、管理 API(/api/admin/*)の外に置く。
 *
 * 開始でチャレンジ(1 回きりの乱数)を作ってセッションに置き、完了でブラウザが返した署名をそのチャレンジと
 * 登録済みの公開鍵で確かめる。チャレンジは完了のときにセッションから取り出して消すので、同じ応答を 2 回は使えない。
 */
class PasskeyLoginController extends Controller
{
    /**
     * ログインを始める。ブラウザの navigator.credentials.get() に渡す options を返す。
     *
     * ユーザー名を入れずにログインする(端末が覚えているパスキーから選ぶ)ので、options にパスキーの一覧は載せない。
     *
     * @return array{options: array<array-key, mixed>}
     */
    public function options(Request $request, GenerateVerificationOptions $generate): array
    {
        $options = $generate();
        $request->session()->put('passkey.verification_options', WebAuthn::toJson($options));

        return ['options' => WebAuthn::toBrowserArray($options)];
    }

    /**
     * ログインを終える。navigator.credentials.get() の結果を確かめ、そのパスキーの持ち主でログインする。
     *
     * 登録されていない・消したパスキー、チャレンジやオリジンの合わない応答は 422(credential のエラー)。
     *
     * @return array{id: int, name: string}
     */
    public function login(PasskeyVerificationRequest $request, VerifyPasskey $verify): array
    {
        $options = $request->verificationOptions();
        $passkey = PasskeyCeremony::run(fn () => $verify($request->credential(), $options));

        /** @var User $admin */
        $admin = $passkey->user;
        // Auth::login がセッション ID を振り直すので、ログインの前の ID を知っている人はこのセッションを使えない
        Auth::login($admin);

        return ['id' => $admin->id, 'name' => $admin->name];
    }
}
