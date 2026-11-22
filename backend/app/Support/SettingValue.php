<?php

namespace App\Support;

/**
 * 設定の 1 項目の、いま効いている値とその出どころ(Settings::resolve。#132)。
 */
final readonly class SettingValue
{
    public const SOURCE_SAVED = 'saved';

    public const SOURCE_ENV = 'env';

    /**
     * @param  self::SOURCE_*|null  $source  画面で保存した値(saved)・.env の値(env)・どちらにも無い(null)
     */
    public function __construct(
        public ?string $value,
        public ?string $source,
    ) {}
}
