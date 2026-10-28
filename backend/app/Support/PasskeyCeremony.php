<?php

namespace App\Support;

use Closure;
use InvalidArgumentException;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Webauthn\Exception\WebauthnException;

/**
 * ブラウザが返したパスキーの応答を確かめる処理(登録・ログインの完了。#311)を、送られた値の誤りを 422 にして流す。
 *
 * laravel/passkeys は、登録済みでない・使い回しなどを 422(credential のエラー)にするが、確かめに使う webauthn-lib は、
 * チャレンジ・オリジン・署名が合わないと WebauthnException を、署名などの形が壊れていると InvalidArgumentException を
 * 投げ、そのままでは 500 になる。どれも送られた値の誤りなので、同じ 422 にそろえる。
 */
final class PasskeyCeremony
{
    /**
     * @template T
     *
     * @param  Closure(): T  $ceremony
     * @return T
     *
     * @throws InvalidPasskeyException
     */
    public static function run(Closure $ceremony): mixed
    {
        try {
            return $ceremony();
        } catch (WebauthnException|InvalidArgumentException) {
            throw InvalidPasskeyException::make('Unable to verify passkey. Please try again.');
        }
    }
}
