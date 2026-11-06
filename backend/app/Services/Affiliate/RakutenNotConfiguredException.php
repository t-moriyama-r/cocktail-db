<?php

namespace App\Services\Affiliate;

use RuntimeException;

/**
 * 楽天のアプリ ID かアクセスキーが設定に無いのに、商品のコードで取り直そうとした。
 * 「見つからない」(RakutenClient::getItem の null)と取り違えて、商品のコードを消さないようにするため投げる。
 */
final class RakutenNotConfiguredException extends RuntimeException {}
