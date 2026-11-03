<?php

namespace App\Support;

/**
 * 画面から変える設定の 1 項目の定義(#132)。一覧は SettingDefinitions。
 */
final readonly class SettingDefinition
{
    /**
     * @param  string  $key  settings 表の key。config/settings.php のキーと同じ
     * @param  string  $label  画面に出す名前
     * @param  string  $description  画面に出す説明
     * @param  bool  $secret  true なら、管理 API は値を返さず、末尾 4 文字だけを返す
     * @param  list<string>  $rules  保存する値の検証の規則(null で .env に戻すときは当てない)
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public bool $secret,
        public array $rules,
    ) {}
}
