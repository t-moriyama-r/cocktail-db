<?php

namespace App\Support;

use App\Models\Setting;

/**
 * 管理 API が返す設定の 1 項目(#132)。定義・いま効いている値・最後に変えた記録(settings 表の行)をまとめたもの。
 */
final readonly class SettingEntry
{
    public function __construct(
        public SettingDefinition $definition,
        public SettingValue $value,
        public ?Setting $row,
    ) {}
}
