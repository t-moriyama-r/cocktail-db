<?php

namespace App\Services\Affiliate;

use RuntimeException;

/**
 * 楽天の商品検索 API が、時間を置いても通らない失敗を返した(パラメーターの誤り・アプリ ID やアクセスキーの誤りなど)。
 * status は楽天が返した HTTP のステータス、error は本文の error(wrong_parameter など。無ければ null)。
 */
final class RakutenApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status, public readonly ?string $error = null)
    {
        parent::__construct($message, $status);
    }
}
