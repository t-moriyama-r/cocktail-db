<?php

namespace App\Services\Affiliate;

use RuntimeException;

/**
 * 楽天の商品検索 API が、いまは応じられないと返した(429 の上限・5xx・つながらない)。時間を置けば通りうる失敗。
 */
final class RakutenRetryLaterException extends RuntimeException {}
