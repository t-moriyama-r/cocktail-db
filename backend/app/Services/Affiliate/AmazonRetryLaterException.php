<?php

namespace App\Services\Affiliate;

use RuntimeException;
use Throwable;

/**
 * Creators API が、いまは応じられないと返した(429 の上限・5xx・つながらない)。時間を置けば通りうる失敗。
 *
 * retryAfterSeconds は、Amazon が待つ秒数を返したときだけ入る。
 */
final class AmazonRetryLaterException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $retryAfterSeconds = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
