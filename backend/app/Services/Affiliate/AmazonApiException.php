<?php

namespace App\Services\Affiliate;

use RuntimeException;

/**
 * Creators API が、時間を置いても通らない失敗を返した(資格情報の誤り・使える条件を満たしていない 403・
 * 要求の誤りなど)。status は Amazon が返した HTTP のステータス。
 */
final class AmazonApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message, $status);
    }
}
