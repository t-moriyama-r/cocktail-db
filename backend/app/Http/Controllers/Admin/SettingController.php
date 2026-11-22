<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingRequest;
use App\Http\Resources\Admin\SettingResource;
use App\Models\Setting;
use App\Models\User;
use App\Support\SettingDefinition;
use App\Support\SettingDefinitions;
use App\Support\SettingEntry;
use App\Support\Settings;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * 管理画面の設定(#132。決めたことは docs/decisions/settings.md)。定義(SettingDefinitions)にある項目だけを読み書きする。
 */
class SettingController extends Controller
{
    public function __construct(
        private readonly SettingDefinitions $definitions,
        private readonly Settings $settings,
    ) {}

    /**
     * 定義にある項目をすべて、定義の順で返す。秘密の項目は値を返さず、末尾 4 文字だけを返す。
     */
    public function index(): AnonymousResourceCollection
    {
        $rows = Setting::query()->with('updatedBy')->whereIn('key', $this->definitions->keys())->get()->keyBy('key');

        return SettingResource::collection(array_values(array_map(
            fn (SettingDefinition $definition) => $this->entry($definition, $rows->get($definition->key)),
            $this->definitions->all(),
        )));
    }

    /**
     * 1 項目の値を書き換え、変えた人と日時を残す。value が null なら、保存した値を消して .env の値に戻す。
     * 書き換えた後の項目を返す(秘密の項目は、一覧と同じく末尾 4 文字だけ)。
     */
    public function update(UpdateSettingRequest $request): SettingResource
    {
        $key = $request->string('key')->value();
        $definition = $this->definitions->find($key) ?? throw new \LogicException("定義に無いキー: {$key}");
        $value = $request->validated('value');
        $user = $request->user();

        $this->settings->set($key, is_string($value) ? $value : null, $user instanceof User ? $user : null);

        $row = Setting::query()->with('updatedBy')->where('key', $key)->first();

        return SettingResource::make($this->entry($definition, $row));
    }

    private function entry(SettingDefinition $definition, ?Setting $row): SettingEntry
    {
        return new SettingEntry($definition, $this->settings->resolve($definition->key), $row);
    }
}
